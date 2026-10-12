<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pasarelas\CobroDeSuscripcion;
use App\Modules\Tenancy\Pasarelas\PasarelaConSuscripcion;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Support\ReporteDeErrores;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pago automático por suscripción (Mercado Pago, OpenPay): la pasarela cobra cada
 * periodo por su cuenta y aquí se asienta lo que cobró. Se registra una sola vez por
 * cobro (su referencia en la pasarela), la pida el aviso (webhook) o una corrida.
 *
 * Antes de liquidar se compara (ADR 0134):
 * - si el importe y la moneda son los de la renovación, la paga (orden → fulfillment →
 *   la fecha avanza y la mora se regulariza);
 * - si cobró menos u otra moneda, el pago queda registrado sin liquidar y «por
 *   conciliar» (dar por pagada o devolver); si cobró de más, se liquida y se puede
 *   devolver el excedente;
 * - un cobro que llega después de pedir la cancelación tampoco se pierde: paga la
 *   renovación que se debía (y se avisa) o queda registrado para devolverlo.
 *
 * Cada rechazo de una suscripción vigente abre o avanza la mora con el motivo.
 */
class ConciliarSuscripcionTenant
{
    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly DeudaDeRenovacionTenant $deudas,
        private readonly FulfillmentTenant $fulfillment,
        private readonly GestionarDunningTenant $dunning,
        private readonly DomiciliacionesTenant $domiciliaciones,
        private readonly CancelacionSuscripcionesTenant $cancelaciones,
        private readonly IncidenciasCobroTenant $incidencias,
    ) {}

    /**
     * La pasarela avisó que la suscripción cambió: se activa al autorizarse, se da por
     * cancelada si ya no cobra (o se confirma la cancelación que se pidió), y se
     * concilian sus cobros, también los de una que se cancela o ya se canceló.
     */
    public function actualizar(DomiciliacionTenant $domiciliacion): void
    {
        $pasarela = $this->pasarela($domiciliacion);
        if (! $pasarela instanceof PasarelaConSuscripcion) {
            return;
        }

        $consulta = $pasarela->consultarSuscripcion($domiciliacion, $this->registro->llaves($domiciliacion->proveedor));
        if ($consulta['estado'] === 'activa' && $domiciliacion->estado === DomiciliacionTenant::PENDIENTE) {
            $this->domiciliaciones->activarSuscripcion($domiciliacion, $consulta['tarjeta']);
        } elseif ($consulta['estado'] === 'cancelada' && $domiciliacion->estado === DomiciliacionTenant::CANCELANDO) {
            $this->cancelaciones->confirmar($domiciliacion);
        } elseif ($consulta['estado'] === 'cancelada' && in_array($domiciliacion->estado, [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE], true)) {
            // La canceló el cliente o la pasarela por su cuenta.
            $domiciliacion->update(['estado' => DomiciliacionTenant::CANCELADA, 'cancelada_en' => Carbon::now()]);
        }

        if ($domiciliacion->refresh()->estado !== DomiciliacionTenant::PENDIENTE) {
            $this->conciliar($domiciliacion);
        }
    }

    /**
     * Asienta los cobros que la pasarela hizo y aún no están registrados.
     * Devuelve 'cobrado', 'fallido', 'por_revisar' (lo último asentado) o
     * 'sin_cambios'.
     */
    public function conciliar(DomiciliacionTenant $domiciliacion): string
    {
        $pasarela = $this->pasarela($domiciliacion);
        $acuerdo = $domiciliacion->acuerdo;
        if (! $pasarela instanceof PasarelaConSuscripcion || ! $acuerdo instanceof AcuerdoTenant
            || (string) $domiciliacion->suscripcion_externa === '') {
            return 'sin_cambios';
        }

        $resultado = 'sin_cambios';
        foreach ($pasarela->cobrosDeSuscripcion($domiciliacion, $this->registro->llaves($domiciliacion->proveedor)) as $cobro) {
            $asentado = $this->asentar($domiciliacion, $acuerdo, $cobro);
            if ($asentado !== null) {
                $resultado = $asentado;
            }
        }

        return $resultado;
    }

    /**
     * Concilia las suscripciones que se están cancelando o se cancelaron en los
     * últimos días: un cobro suyo cuyo aviso no llegó tampoco se pierde (ADR 0134).
     * Devuelve en cuántas se asentó algo.
     */
    public function conciliarCanceladas(int $dias = 35): int
    {
        $asentadas = 0;

        DomiciliacionTenant::query()
            ->whereNotNull('suscripcion_externa')
            ->where(fn ($q) => $q
                ->where('estado', DomiciliacionTenant::CANCELANDO)
                ->orWhere(fn ($q2) => $q2
                    ->where('estado', DomiciliacionTenant::CANCELADA)
                    ->where('cancelada_en', '>=', Carbon::now()->subDays($dias))))
            ->orderBy('id')
            ->get()
            ->each(function (DomiciliacionTenant $domiciliacion) use (&$asentadas): void {
                try {
                    if ($this->conciliar($domiciliacion) !== 'sin_cambios') {
                        $asentadas++;
                    }
                } catch (Throwable $e) {
                    // La pasarela no respondió: se vuelve a consultar en la siguiente corrida.
                    ReporteDeErrores::reportarAtrapado($e);
                }
            });

        return $asentadas;
    }

    /**
     * ¿La renovación pendiente espera a que el negocio revise un cobro con otro
     * importe? Entonces no se le avisa al cliente que su cobro no llegó.
     */
    public function enRevision(AcuerdoTenant $acuerdo): bool
    {
        return IncidenciaCobroTenant::query()
            ->where('tipo', IncidenciaCobroTenant::MONTO_DISTINTO)
            ->where('estado', IncidenciaCobroTenant::ABIERTA)
            ->whereIn('orden_id', OrdenTenant::query()
                ->where('renueva_acuerdo_id', $acuerdo->getKey())
                ->where('estado', EstadoOrden::Pendiente->value)
                ->select('id'))
            ->exists();
    }

    /**
     * Registra un cobro (una sola vez). Devuelve 'cobrado', 'fallido', 'por_revisar'
     * o null si ya estaba o no hay nada que asentar.
     */
    private function asentar(DomiciliacionTenant $domiciliacion, AcuerdoTenant $acuerdo, CobroDeSuscripcion $cobro): ?string
    {
        if ($cobro->id === '') {
            return null;
        }

        /** @var array{0: string|null, 1: list<array{tipo: string, detalle: string, datos: array<string, mixed>, pago: PagoTenant|null}>} $resultado */
        $resultado = DB::connection('tenant')->transaction(function () use ($domiciliacion, $acuerdo, $cobro): array {
            // Serializa por domiciliación: el aviso y las corridas pueden coincidir.
            $bloqueada = DomiciliacionTenant::query()->whereKey($domiciliacion->getKey())->lockForUpdate()->firstOrFail();
            $registrado = PagoTenant::query()
                ->where('proveedor', $domiciliacion->proveedor)
                ->where('referencia_externa', $cobro->id)
                ->exists();
            if ($registrado) {
                return [null, []];
            }

            $vigente = $bloqueada->estado === DomiciliacionTenant::ACTIVA;
            if (! $cobro->aprobado) {
                // Un intento rechazado de una suscripción que ya se cancela no cobró nada.
                return $vigente ? $this->rechazo($bloqueada, $acuerdo, $cobro) : [null, []];
            }

            return $vigente || ! $this->trasCancelar($bloqueada, $cobro)
                ? $this->aprobado($bloqueada, $acuerdo, $cobro)
                : $this->trasCancelacion($bloqueada, $acuerdo, $cobro);
        });

        [$estado, $incidencias] = $resultado;
        foreach ($incidencias as $i) {
            $this->incidencias->porDomiciliacion($i['tipo'], $domiciliacion, $i['detalle'], $i['datos'], $i['pago'], $cobro->id);
        }

        if ($estado === 'cobrado') {
            $this->domiciliaciones->registrarCargo($domiciliacion, null);
        } elseif ($estado === 'fallido') {
            $motivo = $cobro->motivo ?? 'El banco rechazó el cargo.';
            $this->domiciliaciones->registrarCargo($domiciliacion, $motivo);
            $this->dunning->registrarFallo($acuerdo, 'No pudimos cobrar tu pago automático: '.$motivo);
        }

        return $estado;
    }

    /**
     * Un rechazo de la suscripción vigente: queda el intento en la renovación.
     *
     * @return array{0: string|null, 1: list<array{tipo: string, detalle: string, datos: array<string, mixed>, pago: PagoTenant|null}>}
     */
    private function rechazo(DomiciliacionTenant $domiciliacion, AcuerdoTenant $acuerdo, CobroDeSuscripcion $cobro): array
    {
        $orden = $this->deudas->de($acuerdo);
        if ($orden === null) {
            return [null, []];
        }
        $this->registrarPago($domiciliacion, $orden, $cobro, $orden->total_minor, $orden->moneda);

        return ['fallido', []];
    }

    /**
     * Un cobro aprobado de la suscripción: liquida la renovación si cobró lo que se
     * debía (o más); si cobró menos u otra moneda, queda por revisar.
     *
     * @return array{0: string|null, 1: list<array{tipo: string, detalle: string, datos: array<string, mixed>, pago: PagoTenant|null}>}
     */
    private function aprobado(DomiciliacionTenant $domiciliacion, AcuerdoTenant $acuerdo, CobroDeSuscripcion $cobro): array
    {
        $orden = $this->deudas->de($acuerdo);
        if ($orden === null) {
            return [null, []];
        }
        [$monto, $moneda] = $this->importe($cobro, $orden);
        $pago = $this->registrarPago($domiciliacion, $orden, $cobro, $monto, $moneda);

        if ($moneda !== $orden->moneda || $monto < $orden->total_minor) {
            return ['por_revisar', [[
                'tipo' => IncidenciaCobroTenant::MONTO_DISTINTO,
                'detalle' => 'La pasarela cobró '.DatosDeOrden::dinero($monto, $moneda).' y la renovación es de '
                    .DatosDeOrden::dinero($orden->total_minor, $orden->moneda).'. Da por pagada la renovación o devuelve el cobro.',
                'datos' => ['esperado_minor' => $orden->total_minor, 'moneda_esperada' => $orden->moneda, 'cobrado_minor' => $monto, 'moneda_cobrada' => $moneda],
                'pago' => $pago,
            ]]];
        }

        $this->fulfillment->cumplir($orden);
        if ($monto > $orden->total_minor) {
            $excedente = $monto - $orden->total_minor;

            return ['cobrado', [[
                'tipo' => IncidenciaCobroTenant::MONTO_DISTINTO,
                'detalle' => 'La pasarela cobró '.DatosDeOrden::dinero($monto, $moneda).' por una renovación de '
                    .DatosDeOrden::dinero($orden->total_minor, $orden->moneda).': se aplicó y sobran '
                    .DatosDeOrden::dinero($excedente, $moneda).'. Devuelve el excedente.',
                'datos' => ['esperado_minor' => $orden->total_minor, 'moneda_esperada' => $orden->moneda, 'cobrado_minor' => $monto, 'moneda_cobrada' => $moneda, 'excedente_minor' => $excedente],
                'pago' => $pago,
            ]]];
        }

        return ['cobrado', []];
    }

    /**
     * La pasarela cobró después de que se pidió cancelar. Si paga exacto la renovación
     * que se debe, se aplica (y se avisa por si el cliente no la quería); si no, el
     * pago queda registrado para devolverlo.
     *
     * @return array{0: string|null, 1: list<array{tipo: string, detalle: string, datos: array<string, mixed>, pago: PagoTenant|null}>}
     */
    private function trasCancelacion(DomiciliacionTenant $domiciliacion, AcuerdoTenant $acuerdo, CobroDeSuscripcion $cobro): array
    {
        $pendiente = OrdenTenant::query()
            ->where('renueva_acuerdo_id', $acuerdo->getKey())
            ->where('estado', EstadoOrden::Pendiente->value)
            ->latest('id')
            ->first();
        $destino = $pendiente ?? $this->ultimaOrden($acuerdo);
        if (! $destino instanceof OrdenTenant) {
            return [null, []];
        }
        [$monto, $moneda] = $this->importe($cobro, $destino);
        $pago = $this->registrarPago($domiciliacion, $destino, $cobro, $monto, $moneda);
        $cobrado = DatosDeOrden::dinero($monto, $moneda);

        if ($pendiente instanceof OrdenTenant && $moneda === $pendiente->moneda && $monto === $pendiente->total_minor) {
            $this->fulfillment->cumplir($pendiente);

            return ['cobrado', [[
                'tipo' => IncidenciaCobroTenant::COBRO_TRAS_CANCELAR,
                'detalle' => "La pasarela cobró {$cobrado} después de que se pidió quitar este pago automático; se aplicó a la renovación que se debía. Si el cliente no la quería, devuélvelo.",
                'datos' => ['cobrado_minor' => $monto, 'moneda_cobrada' => $moneda, 'aplicado' => true],
                'pago' => $pago,
            ]]];
        }

        return ['por_revisar', [[
            'tipo' => IncidenciaCobroTenant::COBRO_TRAS_CANCELAR,
            'detalle' => "La pasarela cobró {$cobrado} después de que se pidió quitar este pago automático y no hay una renovación por pagar con ese importe. Devuélvelo o resuélvelo con el cliente.",
            'datos' => ['cobrado_minor' => $monto, 'moneda_cobrada' => $moneda, 'aplicado' => false],
            'pago' => $pago,
        ]]];
    }

    /**
     * ¿El cobro es posterior a la solicitud de cancelación? Sin fecha de la pasarela,
     * se supone que sí (es lo prudente: queda por revisar, no se aplica a ciegas).
     */
    private function trasCancelar(DomiciliacionTenant $domiciliacion, CobroDeSuscripcion $cobro): bool
    {
        $solicitada = $domiciliacion->cancelacion_solicitada_en ?? $domiciliacion->cancelada_en;

        return $cobro->fecha === null || $solicitada === null || $cobro->fecha->greaterThanOrEqualTo($solicitada);
    }

    /**
     * Lo que cobró la pasarela: su importe y su moneda; si no los dice, los de la orden.
     *
     * @return array{0: int, 1: string}
     */
    private function importe(CobroDeSuscripcion $cobro, OrdenTenant $orden): array
    {
        return [
            $cobro->montoMinor > 0 ? $cobro->montoMinor : (int) $orden->total_minor,
            $cobro->moneda ?? (string) $orden->moneda,
        ];
    }

    private function registrarPago(DomiciliacionTenant $domiciliacion, OrdenTenant $orden, CobroDeSuscripcion $cobro, int $monto, string $moneda): PagoTenant
    {
        return PagoTenant::query()->create([
            'orden_id' => $orden->getKey(),
            'proveedor' => $domiciliacion->proveedor,
            'metodo' => MetodoPago::Tarjeta->value,
            'estado' => ($cobro->aprobado ? EstadoPago::Aprobado : EstadoPago::Rechazado)->value,
            'monto_minor' => $monto,
            'moneda' => $moneda,
            'referencia_externa' => $cobro->id,
            'domiciliacion_id' => $domiciliacion->getKey(),
            'aprobado_en' => $cobro->aprobado ? now() : null,
        ]);
    }

    /**
     * La última orden de la membresía (su renovación más reciente o la compra que la
     * abrió): ahí queda identificado un cobro que no paga nada.
     */
    private function ultimaOrden(AcuerdoTenant $acuerdo): ?OrdenTenant
    {
        $renovacion = OrdenTenant::query()->where('renueva_acuerdo_id', $acuerdo->getKey())->latest('id')->first();
        if ($renovacion instanceof OrdenTenant) {
            return $renovacion;
        }
        $linea = $acuerdo->linea_orden_id !== null ? LineaOrdenTenant::query()->find($acuerdo->linea_orden_id) : null;

        return $linea instanceof LineaOrdenTenant ? $linea->orden : null;
    }

    private function pasarela(DomiciliacionTenant $domiciliacion): ?PasarelaConSuscripcion
    {
        $pasarela = $this->registro->resolver($domiciliacion->proveedor);

        return $pasarela instanceof PasarelaConSuscripcion ? $pasarela : null;
    }
}
