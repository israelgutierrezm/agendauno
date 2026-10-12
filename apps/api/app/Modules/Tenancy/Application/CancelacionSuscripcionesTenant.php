<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Pasarelas\PasarelaConSuscripcion;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Support\ReporteDeErrores;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cancelar una suscripción de la pasarela (Mercado Pago, OpenPay) se confirma con
 * ella (ADR 0134): la domiciliación queda `cancelando` hasta que la pasarela la da por
 * cancelada (al consultarla o por su aviso). Mientras tanto se reintenta, con esperas
 * cada vez más largas y sin rendirse; tras varios intentos sin confirmar, queda «por
 * conciliar» para cancelarla a mano en el panel de la pasarela.
 *
 * Nunca habla con la pasarela dentro de una transacción: se marca y se intenta al
 * confirmarse lo que esté abierto.
 */
class CancelacionSuscripcionesTenant
{
    /**
     * Minutos de espera tras cada intento sin confirmar (el último se repite).
     */
    private const ESPERAS = [5, 15, 60, 180, 360, 720];

    /**
     * Intentos sin confirmar antes de pedir que alguien la cancele a mano.
     */
    public const INTENTOS_PARA_AVISAR = 5;

    /**
     * Minutos que un intento tiene reclamada la domiciliación: otra corrida no la pide
     * a la vez.
     */
    private const MINUTOS_RECLAMO = 10;

    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly IncidenciasCobroTenant $incidencias,
    ) {}

    /**
     * Pide cancelar la suscripción: deja de ser el pago automático de la membresía y
     * se intenta al momento (después de la transacción en curso, si la hay). Si ya se
     * estaba cancelando o ya se canceló, no hace nada.
     */
    public function solicitar(DomiciliacionTenant $domiciliacion): void
    {
        $marcada = DomiciliacionTenant::query()
            ->whereKey($domiciliacion->getKey())
            ->whereIn('estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
            ->update([
                'estado' => DomiciliacionTenant::CANCELANDO,
                'cancelacion_solicitada_en' => Carbon::now(),
                'cancelacion_intentos' => 0,
                'cancelacion_proximo_en' => Carbon::now(),
                'cancelacion_error' => null,
            ]);
        $domiciliacion->refresh();
        if ($marcada !== 1) {
            return;
        }

        DB::connection('tenant')->afterCommit(fn () => $this->intentar($domiciliacion));
    }

    /**
     * Pide la cancelación a la pasarela y la confirma. Devuelve `confirmada`,
     * `pendiente` (se reintentará) u `ocupada` (otra corrida la tiene, o no le toca).
     */
    public function intentar(DomiciliacionTenant $domiciliacion): string
    {
        if (! $this->reclamar($domiciliacion)) {
            return 'ocupada';
        }

        $pasarela = $this->registro->resolver($domiciliacion->proveedor);
        if (! $pasarela instanceof PasarelaConSuscripcion) {
            // Sin suscripción en la pasarela no hay nada que cancelar allá.
            $this->confirmar($domiciliacion);

            return 'confirmada';
        }

        try {
            $llaves = $this->registro->llaves($domiciliacion->proveedor);
            // Primero se pregunta: si ya está cancelada (su aviso se perdió o la
            // cancelaron en su panel), no se vuelve a pedir.
            if ($this->cancelada($pasarela, $domiciliacion, $llaves)) {
                $this->confirmar($domiciliacion);

                return 'confirmada';
            }
            $pasarela->cancelarSuscripcion($domiciliacion, $llaves);
            if ($this->cancelada($pasarela, $domiciliacion, $llaves)) {
                $this->confirmar($domiciliacion);

                return 'confirmada';
            }
            $this->reintentarDespues($domiciliacion, 'La pasarela aún no la da por cancelada.');
        } catch (Throwable $e) {
            ReporteDeErrores::reportarAtrapado($e);
            $this->reintentarDespues($domiciliacion, 'La pasarela no respondió: '.Str::limit($e->getMessage(), 180));
        }

        return 'pendiente';
    }

    /**
     * La pasarela la dio por cancelada (al consultarla o por su aviso): queda
     * cancelada y, si estaba «por conciliar», se cierra sola.
     */
    public function confirmar(DomiciliacionTenant $domiciliacion): void
    {
        $domiciliacion->update([
            'estado' => DomiciliacionTenant::CANCELADA,
            'cancelada_en' => Carbon::now(),
            'cancelacion_proximo_en' => null,
            'cancelacion_error' => null,
        ]);

        IncidenciaCobroTenant::query()
            ->where('tipo', IncidenciaCobroTenant::CANCELACION_SIN_CONFIRMAR)
            ->where('domiciliacion_id', $domiciliacion->getKey())
            ->where('estado', IncidenciaCobroTenant::ABIERTA)
            ->get()
            ->each(fn (IncidenciaCobroTenant $i) => $this->incidencias->cerrar($i, 'La pasarela confirmó la cancelación.'));
    }

    /**
     * Las cancelaciones a las que ya les toca otro intento (la corrida periódica).
     * Devuelve cuántas se confirmaron.
     */
    public function reintentarPendientes(): int
    {
        $confirmadas = 0;

        DomiciliacionTenant::query()
            ->where('estado', DomiciliacionTenant::CANCELANDO)
            ->where(fn ($q) => $q
                ->whereNull('cancelacion_proximo_en')
                ->orWhere('cancelacion_proximo_en', '<=', Carbon::now()))
            ->orderBy('id')
            ->get()
            ->each(function (DomiciliacionTenant $domiciliacion) use (&$confirmadas): void {
                if ($this->intentar($domiciliacion) === 'confirmada') {
                    $confirmadas++;
                }
            });

        return $confirmadas;
    }

    /**
     * Toma la domiciliación para este intento: solo si se está cancelando y ya le
     * toca. El siguiente intento queda reservado mientras dura este.
     */
    private function reclamar(DomiciliacionTenant $domiciliacion): bool
    {
        $ahora = Carbon::now();
        $reclamada = DomiciliacionTenant::query()
            ->whereKey($domiciliacion->getKey())
            ->where('estado', DomiciliacionTenant::CANCELANDO)
            ->where(fn ($q) => $q
                ->whereNull('cancelacion_proximo_en')
                ->orWhere('cancelacion_proximo_en', '<=', $ahora))
            ->update(['cancelacion_proximo_en' => $ahora->copy()->addMinutes(self::MINUTOS_RECLAMO)]);
        $domiciliacion->refresh();

        return $reclamada === 1;
    }

    /**
     * @param  array<string, string>  $llaves
     */
    private function cancelada(PasarelaConSuscripcion $pasarela, DomiciliacionTenant $domiciliacion, array $llaves): bool
    {
        // Sin referencia en la pasarela (nunca se creó allá) no hay nada que cancelar.
        if ((string) $domiciliacion->suscripcion_externa === '') {
            return true;
        }

        return $pasarela->consultarSuscripcion($domiciliacion, $llaves)['estado'] === 'cancelada';
    }

    private function reintentarDespues(DomiciliacionTenant $domiciliacion, string $error): void
    {
        $intentos = (int) $domiciliacion->cancelacion_intentos + 1;
        $espera = self::ESPERAS[min($intentos, count(self::ESPERAS)) - 1];
        $domiciliacion->update([
            'cancelacion_intentos' => $intentos,
            'cancelacion_proximo_en' => Carbon::now()->addMinutes($espera),
            'cancelacion_error' => Str::limit($error, 250),
        ]);

        // Una sola vez: después de varios intentos, alguien la cancela a mano en el
        // panel de la pasarela (los reintentos siguen y la cierran al confirmarse).
        if ($intentos === self::INTENTOS_PARA_AVISAR) {
            $this->incidencias->porDomiciliacion(
                IncidenciaCobroTenant::CANCELACION_SIN_CONFIRMAR,
                $domiciliacion,
                'La pasarela no ha confirmado que canceló este pago automático y podría seguir cobrando. Cancélalo en su panel (referencia '
                    .$domiciliacion->suscripcion_externa.'); aquí se cerrará solo cuando lo confirme.',
            );
        }
    }
}
