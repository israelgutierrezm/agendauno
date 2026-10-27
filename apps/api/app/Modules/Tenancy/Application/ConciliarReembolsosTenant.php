<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;
use App\Modules\Tenancy\Pasarelas\PasarelaConDevoluciones;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * Devoluciones hechas en el panel de la pasarela (ADR 0209): se revisan los cobros en
     * línea aprobados de estos días, cada uno a lo más una vez en este lapso de horas y
     * hasta este número por pasada (los que llevan más sin revisar primero).
     */
    private const DIAS_DEVOLUCIONES_EXTERNAS = 30;

    private const HORAS_ENTRE_REVISIONES = 24;

    private const COBROS_POR_PASADA = 50;

    public function __construct(
        private readonly ReembolsarPagoTenant $reembolsos,
        private readonly IncidenciasCobroTenant $incidencias,
        private readonly RegistroDePasarelasTenant $registro,
    ) {}

    public function ejecutar(): int
    {
        return $this->aclararPendientes() + $this->devolucionesEnLaPasarela();
    }

    /**
     * Las devoluciones hechas directo en el panel de la pasarela cuyo aviso no llegó: se
     * le pregunta qué devoluciones tiene cada cobro aprobado reciente y se registran las
     * que falten ({@see ReembolsarPagoTenant::desdeLaPasarela()}, idempotente con el
     * aviso). Devuelve cuántas se registraron.
     */
    public function devolucionesEnLaPasarela(?Carbon $desde = null, ?Carbon $hasta = null): int
    {
        $registradas = 0;
        $ahora = Carbon::now();
        // Con fechas (ADR 0227: conciliación ampliada a pagos más viejos), todos los de ese
        // rango sin el tope de cada pasada; sin ellas, los de estos días.
        $porFechas = $desde !== null;

        PagoTenant::query()
            ->whereIn('proveedor', ProveedorPasarela::enLinea())
            ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value])
            ->whereNotNull('referencia_externa')
            ->where('created_at', '>=', $desde ?? $ahora->copy()->subDays(self::DIAS_DEVOLUCIONES_EXTERNAS))
            ->when($hasta !== null, fn ($q) => $q->where('created_at', '<=', $hasta))
            ->when(! $porFechas, fn ($q) => $q
                ->where(fn ($q2) => $q2->whereNull('revisado_en')->orWhere('revisado_en', '<=', $ahora->copy()->subHours(self::HORAS_ENTRE_REVISIONES)))
                ->orderByRaw('revisado_en is not null')
                ->orderBy('revisado_en')
                ->limit(self::COBROS_POR_PASADA))
            ->orderBy('id')
            ->get()
            ->each(function (PagoTenant $pago) use (&$registradas): void {
                try {
                    $registradas += $this->revisarPago($pago);
                } catch (Throwable $e) {
                    // Sin llaves o sin respuesta: se vuelve a preguntar en la próxima revisión.
                    Log::warning('No se pudieron consultar las devoluciones del cobro.', ['pago' => $pago->ulid, 'error' => $e->getMessage()]);
                }
            });

        return $registradas;
    }

    /**
     * Pregunta a la pasarela las devoluciones de un cobro y registra las que falten
     * (idempotente con el aviso). Es también «Reconsultar este pago» (ADR 0227): un error
     * de la pasarela se lanza para decirlo. Devuelve cuántas registró.
     */
    public function revisarPago(PagoTenant $pago): int
    {
        $pasarela = $this->registro->resolver($pago->proveedor);
        if (! $pasarela instanceof PasarelaConDevoluciones) {
            return 0;
        }
        try {
            $devoluciones = $pasarela->devoluciones($pago, $this->registro->llaves($pago->proveedor));
        } finally {
            $pago->update(['revisado_en' => Carbon::now()]);
        }
        $registradas = 0;
        foreach ($devoluciones as $devolucion) {
            $antes = ReembolsoTenant::query()->where('referencia_externa', $devolucion['id'])->exists();
            $this->reembolsos->desdeLaPasarela($pago->refresh(), $devolucion['id'], $devolucion['monto_minor'], $devolucion['moneda'], $devolucion['llave']);
            if (! $antes && ReembolsoTenant::query()->where('referencia_externa', $devolucion['id'])->exists()) {
                $registradas++;
            }
        }

        return $registradas;
    }

    private function aclararPendientes(): int
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
