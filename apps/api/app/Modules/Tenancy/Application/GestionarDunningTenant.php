<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\MotivoFalloCobro;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dunning tenant-local (R10): gestiona la morosidad de una membresía cuyo cobro
 * falló. Al primer fallo abre un proceso EN MORA con periodo de gracia (el acuerdo
 * sigue `Activo`, el socio puede reservar); cada nuevo fallo reintenta y, si venció
 * la gracia, SUSPENDE el acuerdo (bloquea reservas/acceso porque deja de estar
 * `Activo`). Al regularizar el pago cierra el proceso y reactiva el acuerdo.
 *
 * Los reintentos de cargo REAL contra la pasarela quedan diferidos junto con el
 * cobro recurrente + llaves en vivo; aquí se modela la máquina de estados y su
 * política, que es independiente del proveedor.
 */
class GestionarDunningTenant
{
    /** Lo que le llega al alumno si el equipo registra el fallo sin escribir el motivo. */
    private const MOTIVO_MANUAL = 'Cobro rechazado';

    public function __construct(
        private readonly RegistrarEventoTenant $eventos,
        private readonly GestorDeConexionTenant $gestor,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * Registra un fallo de cobro de la membresía: abre o avanza su proceso de dunning.
     *
     * Guarda por qué, para quien administra: la clave del motivo (null si el equipo lo
     * registra a mano) y el detalle (el motivo del banco, lo interno de por qué no se
     * pudo intentar o el texto que escribió el equipo). Al alumno le llega la frase de
     * siempre ({@see MotivoFalloCobro::paraCliente()}); a mano, el texto del equipo.
     * `$tarjeta` (marca y terminación) solo la usa el rechazo del pago automático.
     */
    public function registrarFallo(AcuerdoTenant $acuerdo, ?MotivoFalloCobro $motivo, ?string $detalle = null, ?string $tarjeta = null): ProcesoDunningTenant
    {
        $detalle = self::recortar($detalle);
        $porQue = [
            'ultimo_motivo' => self::recortar($motivo?->paraCliente($detalle, $tarjeta) ?? $detalle) ?? self::MOTIVO_MANUAL,
            'ultimo_motivo_clave' => $motivo?->value,
            'ultimo_motivo_detalle' => $detalle,
        ];

        return DB::connection('tenant')->transaction(function () use ($acuerdo, $porQue): ProcesoDunningTenant {
            $proceso = $this->procesoAbierto($acuerdo, bloquear: true);

            if ($proceso === null) {
                $proceso = ProcesoDunningTenant::query()->create([
                    'acuerdo_id' => $acuerdo->getKey(),
                    'estado' => EstadoDunning::EnMora->value,
                    'intentos' => 1,
                    'gracia_hasta' => Carbon::now()->addDays($this->parametros->entero('cobranza.dias_gracia_adeudo')),
                    'proximo_intento_en' => $this->proximoIntento(1),
                    ...$porQue,
                ]);

                $this->emitir('cobro.fallido', $acuerdo, $proceso);

                return $proceso;
            }

            $proceso->intentos++;
            $proceso->fill($porQue);
            $proceso->proximo_intento_en = $this->proximoIntento($proceso->intentos);

            $suspendeAhora = $proceso->estado === EstadoDunning::EnMora && $proceso->gracia_hasta->isPast();
            if ($suspendeAhora) {
                $this->suspender($proceso, $acuerdo);
            }

            $proceso->save();

            $this->emitir($suspendeAhora ? 'membresia.suspendida' : 'cobro.fallido', $acuerdo, $proceso);

            return $proceso;
        });
    }

    /**
     * Registra que el pago se resolvió: cierra el proceso y reactiva el acuerdo si
     * estaba suspendido. Devuelve el proceso cerrado o null si no había uno abierto.
     */
    public function registrarPago(AcuerdoTenant $acuerdo): ?ProcesoDunningTenant
    {
        return DB::connection('tenant')->transaction(function () use ($acuerdo): ?ProcesoDunningTenant {
            $proceso = $this->procesoAbierto($acuerdo, bloquear: true);
            if ($proceso === null) {
                return null;
            }

            $proceso->estado = EstadoDunning::Regularizado;
            $proceso->regularizado_en = Carbon::now();
            $proceso->proximo_intento_en = null;
            $proceso->save();

            if ($acuerdo->estado === EstadoAcuerdo::Suspendido) {
                $acuerdo->update(['estado' => EstadoAcuerdo::Activo->value]);
            }

            $this->emitir('membresia.regularizada', $acuerdo, $proceso);

            return $proceso;
        });
    }

    /**
     * Suspende los procesos EN MORA cuya gracia venció (para el escalado agendado).
     * Devuelve cuántos se suspendieron.
     */
    public function escalarVencidos(): int
    {
        $suspendidos = 0;

        ProcesoDunningTenant::query()
            ->where('estado', EstadoDunning::EnMora->value)
            ->where('gracia_hasta', '<', Carbon::now())
            ->with('acuerdo')
            ->get()
            ->each(function (ProcesoDunningTenant $proceso) use (&$suspendidos): void {
                $acuerdo = $proceso->acuerdo;
                if (! $acuerdo instanceof AcuerdoTenant) {
                    return;
                }

                DB::connection('tenant')->transaction(function () use ($proceso, $acuerdo, &$suspendidos): void {
                    $bloqueado = ProcesoDunningTenant::query()->whereKey($proceso->getKey())->lockForUpdate()->first();
                    if (! $bloqueado instanceof ProcesoDunningTenant
                        || $bloqueado->estado !== EstadoDunning::EnMora
                        || $bloqueado->gracia_hasta->isFuture()) {
                        return;
                    }

                    $this->suspender($bloqueado, $acuerdo);
                    $bloqueado->save();
                    $this->emitir('membresia.suspendida', $acuerdo, $bloqueado);
                    $suspendidos++;
                });
            });

        return $suspendidos;
    }

    /**
     * Marca el proceso como suspendido y saca el acuerdo de `Activo` (bloquea
     * reservas/acceso). NO guarda el proceso: el llamador decide cuándo persistirlo.
     */
    private function suspender(ProcesoDunningTenant $proceso, AcuerdoTenant $acuerdo): void
    {
        $proceso->estado = EstadoDunning::Suspendido;
        $proceso->suspendido_en = Carbon::now();
        $proceso->proximo_intento_en = null;

        // Cancelada o en pausa se queda como está (la pausa no se cobra).
        if (! in_array($acuerdo->estado, [EstadoAcuerdo::Cancelado, EstadoAcuerdo::Pausado], true)) {
            $acuerdo->update(['estado' => EstadoAcuerdo::Suspendido->value]);
        }
    }

    /**
     * Fecha del próximo reintento según el intento (backoff creciente, con tope): los
     * días los fija el negocio (`cobranza.reintento_N_dias`; 1, 3 y 7 por omisión).
     */
    private function proximoIntento(int $intento): Carbon
    {
        $reintentos = [
            $this->parametros->entero('cobranza.reintento_1_dias'),
            $this->parametros->entero('cobranza.reintento_2_dias'),
            $this->parametros->entero('cobranza.reintento_3_dias'),
        ];
        $dias = $reintentos[max(1, min($intento, count($reintentos))) - 1];

        return Carbon::now()->addDays($dias);
    }

    /** Un texto sin espacios de más y que cabe en su columna (null si viene vacío). */
    private static function recortar(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto !== '' ? mb_substr($texto, 0, 255) : null;
    }

    private function procesoAbierto(AcuerdoTenant $acuerdo, bool $bloquear = false): ?ProcesoDunningTenant
    {
        $query = ProcesoDunningTenant::query()
            ->where('acuerdo_id', $acuerdo->getKey())
            ->whereIn('estado', [EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value]);

        if ($bloquear) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function emitir(string $tipo, AcuerdoTenant $acuerdo, ProcesoDunningTenant $proceso): void
    {
        $acuerdo->loadMissing(['persona', 'producto']);
        $slug = (string) $this->gestor->actual()?->slug;

        $this->eventos->registrar($tipo, 'acuerdo', $acuerdo->ulid, [
            'acuerdo' => $acuerdo->ulid,
            'persona_id' => $acuerdo->persona?->ulid,
            'dunning' => $proceso->ulid,
            'estado' => $proceso->estado->value,
            'intentos' => $proceso->intentos,
            // Para el aviso al cliente: qué debe y dónde pagarlo (su cuenta).
            'producto' => (string) $acuerdo->producto?->nombre,
            'motivo' => (string) $proceso->ultimo_motivo,
            'enlace' => MarcaProducto::actual()->urlWeb().'/entrar?estudio='.rawurlencode($slug),
        ]);
    }
}
