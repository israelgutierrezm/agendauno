<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use Illuminate\Support\Carbon;

/**
 * Aclara las devoluciones en línea que quedaron sin respuesta: las inciertas (la
 * pasarela no respondió) y las solicitadas que se quedaron a medias (p. ej. el
 * proceso se cortó antes de pedirlas). Se vuelven a pedir con la MISMA llave, así la
 * pasarela devuelve la respuesta de la primera vez en lugar de devolver otra vez.
 *
 * Solo dentro de la ventana en que la pasarela recuerda la llave (Stripe, 24 h) y en
 * pasarelas que la respetan; pasado eso, o en las que no (OpenPay), queda "por
 * conciliar" para que alguien la revise en el panel de la pasarela y la marque.
 *
 * Las pendientes (la pasarela la recibió y la confirma después) se esperan a su aviso;
 * si no llega en {@see self::HORAS_PENDIENTE} horas, también quedan por conciliar: si
 * no, el monto seguiría apartado sin que nadie pudiera cerrarla.
 * Debe correr con la conexión del estudio activa.
 */
class ConciliarReembolsosTenant
{
    /**
     * Horas en que se reintenta con la misma llave (Stripe la recuerda 24 h).
     */
    private const HORAS_REINTENTO = 23;

    /**
     * Minutos antes de dar por "a medias" una solicitada (que aún no se pidió).
     */
    private const MINUTOS_A_MEDIAS = 2;

    /**
     * Horas que se espera el aviso de una devolución pendiente.
     */
    private const HORAS_PENDIENTE = 48;

    public function __construct(
        private readonly ReembolsarPagoTenant $reembolsos,
        private readonly IncidenciasCobroTenant $incidencias,
    ) {}

    public function ejecutar(): int
    {
        $aclaradas = 0;

        ReembolsoTenant::query()
            ->where(fn ($q) => $q
                ->where('estado', EstadoReembolso::Incierto->value)
                ->orWhere(fn ($q2) => $q2
                    ->where('estado', EstadoReembolso::Solicitado->value)
                    ->where('created_at', '<', Carbon::now()->subMinutes(self::MINUTOS_A_MEDIAS)))
                ->orWhere(fn ($q3) => $q3
                    ->where('estado', EstadoReembolso::Pendiente->value)
                    ->where('updated_at', '<', Carbon::now()->subHours(self::HORAS_PENDIENTE))
                    ->whereNotIn('id', IncidenciaCobroTenant::query()
                        ->select('reembolso_id')
                        ->whereNotNull('reembolso_id')
                        ->where('estado', IncidenciaCobroTenant::ABIERTA))))
            ->orderBy('id')
            ->get()
            ->each(function (ReembolsoTenant $reembolso) use (&$aclaradas): void {
                if ($reembolso->estado === EstadoReembolso::Pendiente) {
                    $this->incidencias->porReembolso(
                        IncidenciaCobroTenant::REEMBOLSO_INCIERTO,
                        $reembolso,
                        'La pasarela no ha confirmado la devolución. Revisa en su panel si se hizo y márcala aquí.',
                    );

                    return;
                }

                $vigente = $reembolso->created_at !== null
                    && $reembolso->created_at->greaterThan(Carbon::now()->subHours(self::HORAS_REINTENTO));

                if (! $vigente || ! $this->reembolsos->reintentable($reembolso)) {
                    $this->incidencias->porReembolso(
                        IncidenciaCobroTenant::REEMBOLSO_INCIERTO,
                        $reembolso,
                        'No se pudo confirmar la devolución con la pasarela. Revisa en su panel si se hizo y márcala aquí.',
                        ['motivo' => $reembolso->motivo_fallo],
                    );

                    return;
                }

                try {
                    $this->reembolsos->pedirAPasarela($reembolso);
                } catch (TenancyException) {
                    // La pasarela contestó que no: ya quedó fallida con su motivo.
                }

                if ($reembolso->refresh()->estado !== EstadoReembolso::Incierto) {
                    $aclaradas++;
                }
            });

        return $aclaradas;
    }
}
