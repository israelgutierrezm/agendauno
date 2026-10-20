<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use App\Modules\Tenancy\Pagos\Exceptions\PagoNoReembolsable;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;

/**
 * Lo que el negocio conserva de una cita con anticipo (ADR 0161) cuando el cliente
 * cancela tarde o no llega: solo el anticipo (decisión del dueño, ADR 0181). Lo demás
 * que ya pagó (el resto, o lo que pagó de más si pagó todo al agendar) se le devuelve:
 * lo pagado en línea, solo y por la misma pasarela; lo pagado en caja, en caja, a mano.
 */
class AnticipoDeCitaTenant
{
    public function __construct(private readonly ReembolsarPagoTenant $reembolsar) {}

    /**
     * Lo pagado de más sobre el anticipo: cuánto, y de eso cuánto se pagó en línea.
     *
     * @return array{anticipo_minor: int, pagado_minor: int, de_mas_minor: int, en_linea_minor: int}
     */
    public function pagadoDeMas(ReservaTenant $reserva): array
    {
        $reserva->loadMissing(['orden', 'saldoOrden']);
        $pagadas = collect([$reserva->orden, $reserva->saldoOrden])
            ->filter(static fn (?OrdenTenant $o): bool => $o?->estado === EstadoOrden::Pagada);
        $pagado = (int) $pagadas->sum('total_minor');
        $anticipo = min((int) $reserva->anticipo_minor, $pagado);
        $deMas = max(0, $pagado - $anticipo);

        return [
            'anticipo_minor' => $anticipo,
            'pagado_minor' => $pagado,
            'de_mas_minor' => $deMas,
            'en_linea_minor' => min($deMas, array_sum(array_map(static fn (array $p): int => $p['monto'], $this->porDevolverEnLinea($reserva, $deMas)))),
        ];
    }

    /**
     * Devuelve lo pagado en línea de más sobre el anticipo. Idempotente: cada devolución
     * lleva la llave de la reserva y del pago. Si la pasarela no puede, queda para el
     * negocio en Pagos.
     */
    public function devolverLoDeMas(ReservaTenant $reserva, string $motivo, string $llave): void
    {
        if ($reserva->anticipo_minor === null) {
            return;
        }
        $deMas = $this->pagadoDeMas($reserva)['de_mas_minor'];
        foreach ($this->porDevolverEnLinea($reserva, $deMas) as ['pago' => $pago, 'monto' => $monto]) {
            try {
                // Solo dinero: la cita ya se canceló o no se atendió.
                $this->reembolsar->ejecutar($pago, $monto, $motivo, null, false, false, $llave.':'.$pago->ulid);
            } catch (PagoNoReembolsable) {
                // Ya devuelto, o la pasarela no devuelve en línea: lo resuelve el negocio.
            }
        }
    }

    /**
     * De qué pagos en línea sale lo de más: del resto, todo lo suyo; si pagó todo en una
     * sola orden, lo que pasa del anticipo.
     *
     * @return list<array{pago: PagoTenant, monto: int}>
     */
    private function porDevolverEnLinea(ReservaTenant $reserva, int $deMas): array
    {
        if ($deMas <= 0) {
            return [];
        }
        $ordenes = $reserva->saldo_orden_id !== null ? [(int) $reserva->saldo_orden_id] : [(int) $reserva->orden_id];
        $pagos = PagoTenant::query()
            ->whereIn('orden_id', $ordenes)
            ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value])
            ->whereIn('proveedor', ProveedorPasarela::enLinea())
            ->orderBy('id')
            ->get();

        $salida = [];
        $falta = $deMas;
        foreach ($pagos as $pago) {
            if ($falta <= 0) {
                break;
            }
            $libre = (int) $pago->monto_minor - (int) $pago->reembolsos()->whereIn('estado', EstadoReembolso::comprometidos())->sum('monto_minor');
            $monto = min($libre, $falta);
            if ($monto > 0) {
                $salida[] = ['pago' => $pago, 'monto' => $monto];
                $falta -= $monto;
            }
        }

        return $salida;
    }
}
