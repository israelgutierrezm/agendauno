<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonImmutable;

/**
 * Datos legibles de una clase o cita para los eventos que terminan en un mensaje
 * (confirmación, recordatorios). Son también los marcadores de las plantillas:
 * {{actividad}}, {{fecha}}, {{hora}}, {{sucursal}}, {{con}}. Fecha y hora van en la
 * zona horaria de la sesión.
 */
final class DatosDeSesion
{
    /**
     * @return array<string, string>
     */
    public static function para(SesionTenant $sesion): array
    {
        $sesion->loadMissing(['oferta', 'sucursal', 'instructor']);

        $inicio = CarbonImmutable::instance($sesion->inicia_en);
        $local = $inicio
            ->setTimezone($sesion->zona_horaria ?: $sesion->sucursal?->zona_horaria ?: self::zonaDelNegocio())
            ->locale('es');

        return [
            'sesion_id' => (string) $sesion->ulid,
            'tipo' => $sesion->tipo->value,
            'inicia_en' => $inicio->toIso8601String(),
            'actividad' => (string) $sesion->oferta?->nombre,
            'fecha' => $local->isoFormat('dddd D [de] MMMM'),
            'hora' => $local->format('H:i'),
            'sucursal' => (string) $sesion->sucursal?->nombre,
            'con' => $sesion->instructor?->nombreCorto() ?? '',
        ];
    }

    /**
     * Zona horaria del negocio en contexto (o la de CDMX si no hay).
     */
    public static function zonaDelNegocio(): string
    {
        return app(GestorDeConexionTenant::class)->actual()?->zona_horaria ?: 'America/Mexico_City';
    }
}
