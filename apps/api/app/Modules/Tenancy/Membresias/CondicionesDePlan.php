<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Membresias;

use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Las condiciones de un plan con permanencia (plan de mejoras B04), en palabras del
 * cliente, y la constancia de que las aceptó: el texto que vio, su versión (huella del
 * texto), cuándo, por dónde (en su cuenta o en el negocio) y quién lo registró.
 *
 * Es la fórmula funcional del sistema: la redacción legal del contrato la revisa un
 * profesional y no se valida aquí.
 */
final class CondicionesDePlan
{
    /** ¿Este plan pide aceptar condiciones antes de comprarlo? */
    public static function aplica(ProductoTenant $producto): bool
    {
        return (int) ($producto->permanencia_meses ?? 0) > 0;
    }

    public static function texto(ProductoTenant $producto): ?string
    {
        $meses = (int) ($producto->permanencia_meses ?? 0);
        if ($meses <= 0) {
            return null;
        }
        $plazo = $meses === 1 ? '1 mes' : "{$meses} meses";
        $baja = match ($producto->baja_anticipada) {
            BajaAnticipada::MontoFijo => 'pagas una penalización de '.self::dinero((int) ($producto->penalizacion_minor ?? 0), $producto->moneda).'.',
            BajaAnticipada::MesesRestantes => self::mesesRestantes($producto),
            default => 'no pagas penalización.',
        };

        return "Permanencia mínima de {$plazo} desde la fecha de compra. Si das de baja antes de cumplirla, {$baja} "
            .'La baja aplica al terminar el periodo pagado.';
    }

    /**
     * La constancia de aceptación, para guardar con la compra.
     *
     * @return array{texto: string, version: string, aceptado_en: string, via: string, usuario_id: int|null, ip: string|null}|null
     */
    public static function aceptadas(ProductoTenant $producto, string $via, ?Usuario $usuario = null, ?string $ip = null): ?array
    {
        $texto = self::texto($producto);
        if ($texto === null) {
            return null;
        }

        return [
            'texto' => $texto,
            'version' => substr(hash('sha256', $texto), 0, 16),
            'aceptado_en' => now()->toIso8601String(),
            'via' => $via,
            'usuario_id' => $usuario?->getKey() === null ? null : (int) $usuario->getKey(),
            'ip' => $ip,
        ];
    }

    /**
     * Las mensualidades que falten o, con porcentaje (ADR 0137), esa parte de ellas.
     * Al 100 % el texto es el de siempre: no cambia la versión de lo ya aceptado.
     */
    private static function mesesRestantes(ProductoTenant $producto): string
    {
        $porcentaje = (int) ($producto->penalizacion_porcentaje ?? 100);

        return $porcentaje < 100
            ? "pagas el {$porcentaje} % de las mensualidades que falten para cumplirla, al precio vigente del plan."
            : 'pagas las mensualidades que falten para cumplirla, al precio vigente del plan.';
    }

    private static function dinero(int $minor, string $moneda): string
    {
        return '$'.number_format($minor / 100, 2).' '.$moneda;
    }
}
