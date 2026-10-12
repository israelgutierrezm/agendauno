<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Cuotas\ConceptoCuota;
use App\Modules\Tenancy\Exceptions\BajaDeMembresiaNoPermitida;
use App\Modules\Tenancy\Membresias\BajaAnticipada;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\BajaAcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\QuienCancela;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dar de baja una membresía (plan de mejoras B04, decisiones del dueño del 2026-10-10).
 * Terminar una membresía es distinto de cancelar una reserva.
 *
 * - **Fecha efectiva:** al terminar el periodo pagado (el día antes de su próximo
 *   cobro). Si ese cobro ya venció (está en mora), la baja es hoy.
 * - **Adeudo:** la mensualidad vencida sin pagar no se perdona en silencio. Se conserva
 *   como cargo aparte («Mensualidad pendiente»), sin reabrir la renovación al pagarlo,
 *   salvo que el negocio decida anularla.
 * - **Penalización:** solo si no cumplió su permanencia, con lo que se pactó al
 *   contratar: sin penalización, un monto fijo o las mensualidades que falten al precio
 *   vigente del plan (las renovaciones también usan el vigente). Quien tiene
 *   `cuotas.exentar` la puede exentar.
 * - **Al confirmar:** ya no se renueva ni se cobra solo. Sus planes valen hasta la fecha
 *   efectiva; las reservas posteriores se cancelan. La baja queda registrada con su
 *   cálculo y quién la hizo, y el acuerdo pasa a cancelado el día que termina.
 *
 * {@see self::simular()} es la misma cuenta que {@see self::ejecutar()}: lo que se ve
 * antes de confirmar es lo que pasa.
 */
class BajaDeMembresiaTenant
{
    public function __construct(
        private readonly FechasNegocioTenant $fechas,
        private readonly DeudaDeRenovacionTenant $deudas,
        private readonly DomiciliacionesTenant $domiciliaciones,
        private readonly ReservasTenant $reservas,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @return array{efectiva_el: string, en_permanencia: bool, permanencia_hasta: string|null, modo: string|null, meses_restantes: int, precio_base_minor: int|null, penalizacion_minor: int, adeudo_minor: int, moneda: string, condiciones: string|null}
     */
    public function simular(AcuerdoTenant $acuerdo): array
    {
        $this->exigirQueSePueda($acuerdo);
        $acuerdo->loadMissing('producto');
        $hoy = CarbonImmutable::parse($this->fechas->hoy());
        $proximo = $acuerdo->proxima_cobro_en === null ? null : CarbonImmutable::parse($acuerdo->proxima_cobro_en->toDateString());

        // Al terminar el periodo pagado; en mora, hoy.
        $efectiva = $proximo !== null && $proximo->greaterThan($hoy) ? $proximo->subDay() : $hoy;
        $adeudo = $proximo !== null && $proximo->lessThanOrEqualTo($hoy)
            ? (int) OrdenTenant::query()
                ->where('renueva_acuerdo_id', $acuerdo->getKey())
                ->where('estado', EstadoOrden::Pendiente->value)
                ->sum('total_minor')
            : 0;

        // Los periodos que faltan para cumplir la permanencia, desde el día siguiente.
        $hasta = $acuerdo->permanencia_hasta === null ? null : CarbonImmutable::parse($acuerdo->permanencia_hasta->toDateString());
        $meses = 0;
        if ($hasta !== null) {
            for ($dia = $efectiva->addDay(); $dia->lessThan($hasta) && $meses < 240; $dia = $dia->addMonthsNoOverflow(1)) {
                $meses++;
            }
        }
        $enPermanencia = $meses > 0;
        $modo = $enPermanencia ? ($acuerdo->baja_anticipada ?? BajaAnticipada::SinPenalizacion) : null;
        $precio = (int) ($acuerdo->producto->precio_minor ?? 0);
        // Con «meses restantes», el porcentaje pactado de lo que falte (ADR 0137; vacío =
        // 100 %), redondeado al centavo.
        $porcentaje = $modo === BajaAnticipada::MesesRestantes ? (int) ($acuerdo->penalizacion_porcentaje ?? 100) : null;
        $penalizacion = match ($modo) {
            BajaAnticipada::MontoFijo => (int) ($acuerdo->penalizacion_minor ?? 0),
            BajaAnticipada::MesesRestantes => intdiv($meses * $precio * (int) $porcentaje + 50, 100),
            default => 0,
        };

        return [
            'efectiva_el' => $efectiva->toDateString(),
            'en_permanencia' => $enPermanencia,
            'permanencia_hasta' => $hasta?->toDateString(),
            'modo' => $modo?->value,
            'meses_restantes' => $meses,
            'precio_base_minor' => $modo === BajaAnticipada::MesesRestantes ? $precio : null,
            'porcentaje' => $porcentaje,
            'penalizacion_minor' => $penalizacion,
            'adeudo_minor' => $adeudo,
            'moneda' => (string) ($acuerdo->producto->moneda ?? 'MXN'),
            'condiciones' => is_string($acuerdo->condiciones['texto'] ?? null) ? $acuerdo->condiciones['texto'] : null,
        ];
    }

    public function ejecutar(AcuerdoTenant $acuerdo, ?string $motivo, bool $exentarPenalizacion, bool $conservarAdeudo, ?Usuario $actor): BajaAcuerdoTenant
    {
        $baja = DB::connection('tenant')->transaction(function () use ($acuerdo, $motivo, $exentarPenalizacion, $conservarAdeudo, $actor): BajaAcuerdoTenant {
            $bloqueado = AcuerdoTenant::query()->whereKey($acuerdo->getKey())->lockForUpdate()->firstOrFail();
            $calculo = $this->simular($bloqueado);
            $efectiva = $calculo['efectiva_el'];
            $penalizacion = $exentarPenalizacion ? 0 : $calculo['penalizacion_minor'];
            $adeudo = $conservarAdeudo ? $calculo['adeudo_minor'] : 0;

            // Ya no se renueva: sin próximo cobro y sin la renovación por cobrar.
            $this->deudas->anular([(int) $bloqueado->getKey()], $actor);
            $bloqueado->update([
                'baja_efectiva_el' => $efectiva,
                'proxima_cobro_en' => null,
                ...($efectiva <= $this->fechas->hoy() ? ['estado' => EstadoAcuerdo::Cancelado->value] : []),
            ]);
            // Sus planes (y sus clases extra) valen hasta la fecha efectiva.
            $derechos = DerechoTenant::query()->where('acuerdo_id', $bloqueado->getKey())->pluck('id')->all();
            DerechoTenant::query()
                ->where(fn ($q) => $q->whereIn('id', $derechos)->orWhereIn('extra_de_id', $derechos))
                ->where(fn ($q) => $q->whereNull('valido_hasta')->orWhereDate('valido_hasta', '>', $efectiva))
                ->update(['valido_hasta' => $efectiva]);

            $orden = $this->cargos($bloqueado, $penalizacion, $adeudo, $calculo['moneda']);

            $baja = BajaAcuerdoTenant::query()->create([
                'acuerdo_id' => $bloqueado->getKey(),
                'efectiva_el' => $efectiva,
                'en_permanencia' => $calculo['en_permanencia'],
                'modo' => $calculo['modo'],
                'meses_restantes' => $calculo['meses_restantes'],
                'precio_base_minor' => $calculo['precio_base_minor'],
                'penalizacion_minor' => $calculo['penalizacion_minor'],
                'penalizacion_exenta' => $exentarPenalizacion && $calculo['penalizacion_minor'] > 0,
                'adeudo_minor' => $calculo['adeudo_minor'],
                'adeudo_conservado' => $adeudo > 0,
                'orden_id' => $orden?->getKey(),
                'motivo' => $motivo,
                'usuario_id' => $actor?->getKey(),
            ]);

            $this->auditoria->registrar($actor, 'membresia.baja', 'acuerdo', (string) $bloqueado->ulid, null, [
                'efectiva_el' => $efectiva,
                'penalizacion_minor' => $penalizacion,
                'adeudo_minor' => $adeudo,
            ], $motivo);
            if ($exentarPenalizacion && $calculo['penalizacion_minor'] > 0) {
                $this->auditoria->registrar($actor, 'cuota.exentada', 'acuerdo', (string) $bloqueado->ulid, [
                    'concepto' => ConceptoCuota::PenalizacionBaja->value,
                    'monto_minor' => $calculo['penalizacion_minor'],
                ], null, $motivo);
            }

            return $baja;
        });

        // Fuera de la transacción: el cobro automático (habla con la pasarela) y las
        // reservas que caen después de la fecha efectiva.
        $this->domiciliaciones->desactivar($acuerdo);
        $this->cancelarReservasPosteriores($acuerdo, $baja->efectiva_el->toDateString(), $actor);

        return $baja;
    }

    /**
     * Pasa a canceladas las membresías cuya baja ya llegó (su último día ya pasó en la
     * fecha del negocio). Corre a diario; devuelve cuántas.
     */
    public function aplicarVencidas(): int
    {
        return AcuerdoTenant::query()
            ->whereNotNull('baja_efectiva_el')
            ->whereDate('baja_efectiva_el', '<', $this->fechas->hoy())
            ->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
            ->update(['estado' => EstadoAcuerdo::Cancelado->value]);
    }

    private function exigirQueSePueda(AcuerdoTenant $acuerdo): void
    {
        if ($acuerdo->dia_ancla === null) {
            throw new BajaDeMembresiaNoPermitida('Solo se da de baja una membresía que se renueva; un paquete vence solo.');
        }
        if ($acuerdo->estado === EstadoAcuerdo::Cancelado) {
            throw new BajaDeMembresiaNoPermitida('Esta membresía ya está cancelada.');
        }
        if ($acuerdo->baja_efectiva_el !== null) {
            throw new BajaDeMembresiaNoPermitida('Esta membresía ya tiene una baja programada.');
        }
    }

    /**
     * La penalización y el adeudo conservado, en una orden por cobrar (referencias
     * únicas: no se generan dos veces).
     */
    private function cargos(AcuerdoTenant $acuerdo, int $penalizacion, int $adeudo, string $moneda): ?OrdenTenant
    {
        $lineas = array_filter([
            $penalizacion > 0 ? [ConceptoCuota::PenalizacionBaja, $penalizacion, 'penalizacion'] : null,
            $adeudo > 0 ? [ConceptoCuota::AdeudoMensualidad, $adeudo, 'adeudo'] : null,
        ]);
        if ($lineas === []) {
            return null;
        }

        $acuerdo->loadMissing('persona');
        $orden = OrdenTenant::query()->create([
            'persona_id' => $acuerdo->persona_id,
            'sucursal_id' => $acuerdo->persona?->sucursal_id,
            'estado' => EstadoOrden::Pendiente->value,
            'total_minor' => $penalizacion + $adeudo,
            'moneda' => $moneda,
        ]);
        foreach ($lineas as [$concepto, $monto, $sufijo]) {
            $orden->lineas()->create([
                'concepto' => $concepto->value,
                'referencia' => "baja:{$acuerdo->ulid}:{$sufijo}",
                'beneficiario_id' => $acuerdo->persona_id,
                'cantidad' => 1,
                'precio_unitario_minor' => $monto,
                'subtotal_minor' => $monto,
            ]);
        }

        return $orden;
    }

    private function cancelarReservasPosteriores(AcuerdoTenant $acuerdo, string $efectiva, ?Usuario $actor): void
    {
        $derechos = DerechoTenant::query()->where('acuerdo_id', $acuerdo->getKey())->pluck('id')->all();
        $limite = CarbonImmutable::parse($efectiva, $this->fechas->zona())->endOfDay()->utc();

        ReservaTenant::query()
            ->whereIn('derecho_id', $derechos)
            ->whereIn('estado', [
                EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
                EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
            ])
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', $limite))
            ->get()
            ->each(fn (ReservaTenant $r) => $this->reservas->cancelar($r, QuienCancela::Negocio, $actor));
    }
}
