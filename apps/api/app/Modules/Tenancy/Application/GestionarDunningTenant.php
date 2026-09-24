<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
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
    private const GRACIA_DIAS = 7;

    /**
     * Backoff de reintentos (días desde el fallo) según el número de intento.
     *
     * @var list<int>
     */
    private const REINTENTOS_DIAS = [1, 3, 7];

    public function __construct(
        private readonly RegistrarEventoTenant $eventos,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * Registra un fallo de cobro de la membresía: abre o avanza su proceso de dunning.
     */
    public function registrarFallo(AcuerdoTenant $acuerdo, string $motivo): ProcesoDunningTenant
    {
        return DB::connection('tenant')->transaction(function () use ($acuerdo, $motivo): ProcesoDunningTenant {
            $proceso = $this->procesoAbierto($acuerdo, bloquear: true);

            if ($proceso === null) {
                $proceso = ProcesoDunningTenant::query()->create([
                    'acuerdo_id' => $acuerdo->getKey(),
                    'estado' => EstadoDunning::EnMora->value,
                    'intentos' => 1,
                    'gracia_hasta' => Carbon::now()->addDays(self::GRACIA_DIAS),
                    'proximo_intento_en' => $this->proximoIntento(1),
                    'ultimo_motivo' => $motivo,
                ]);

                $this->emitir('cobro.fallido', $acuerdo, $proceso);

                return $proceso;
            }

            $proceso->intentos++;
            $proceso->ultimo_motivo = $motivo;
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
     * Fecha del próximo reintento según el intento (backoff creciente, con tope).
     */
    private function proximoIntento(int $intento): Carbon
    {
        $dias = self::REINTENTOS_DIAS[min($intento, count(self::REINTENTOS_DIAS)) - 1];

        return Carbon::now()->addDays($dias);
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
            'enlace' => rtrim((string) config('turnouno.url_app'), '/').'/entrar?estudio='.rawurlencode($slug),
        ]);
    }
}
