<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use Carbon\CarbonImmutable;

/**
 * Datos legibles de una orden pagada para el recibo. Son también los marcadores de la
 * plantilla: {{folio}}, {{fecha}}, {{detalle}} (una línea por concepto), {{total}} y
 * {{metodo}}.
 */
final class DatosDeOrden
{
    /**
     * @return array<string, string>
     */
    public static function para(OrdenTenant $orden): array
    {
        $orden->loadMissing(['lineas.producto', 'sesion.oferta']);
        $moneda = (string) ($orden->moneda ?: 'MXN');

        $renglones = $orden->lineas
            ->map(static function (LineaOrdenTenant $linea) use ($moneda): string {
                $nombre = (string) $linea->producto?->nombre ?: 'Producto';
                $cantidad = (int) $linea->cantidad > 1 ? ' × '.$linea->cantidad : '';

                return $nombre.$cantidad.' · '.self::dinero((int) $linea->subtotal_minor, $moneda);
            })
            ->all();

        if ($renglones === [] && $orden->sesion !== null) {
            $sesion = DatosDeSesion::para($orden->sesion);
            $renglones[] = trim($sesion['actividad'].' · '.$sesion['fecha'].', '.$sesion['hora'], ' ·,');
        }
        if ((int) $orden->descuento_minor > 0) {
            $renglones[] = 'Descuento · -'.self::dinero((int) $orden->descuento_minor, $moneda);
        }

        $pagada = CarbonImmutable::instance($orden->pagada_en ?? now())
            ->setTimezone(DatosDeSesion::zonaDelNegocio())
            ->locale('es');

        return [
            'folio' => strtoupper(substr((string) $orden->ulid, -8)),
            'fecha' => $pagada->isoFormat('D [de] MMMM [de] YYYY'),
            'detalle' => implode("\n", $renglones),
            'total' => self::dinero((int) $orden->total_minor, $moneda),
            'metodo' => self::metodo($orden),
        ];
    }

    /**
     * 89900 MXN → "$899.00 MXN".
     */
    public static function dinero(int $minor, string $moneda): string
    {
        return '$'.number_format($minor / 100, 2, '.', ',').' '.strtoupper($moneda);
    }

    private static function metodo(OrdenTenant $orden): string
    {
        $valor = $orden->metodo_pago;
        if (! is_string($valor) || $valor === '') {
            $pago = PagoTenant::query()
                ->where('orden_id', $orden->getKey())
                ->where('estado', EstadoPago::Aprobado->value)
                ->latest('id')
                ->first();
            $valor = $pago?->metodo?->value;
        }

        return is_string($valor) ? (MetodoPago::tryFrom($valor)?->etiqueta() ?? ucfirst($valor)) : '';
    }
}
