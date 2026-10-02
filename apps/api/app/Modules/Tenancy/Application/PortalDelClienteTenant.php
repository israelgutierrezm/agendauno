<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\AccesoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Documento;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\WaiverTenant;
use Carbon\CarbonImmutable;

/**
 * Qué partes de su cuenta le sirven a un cliente (ADR 0091): «Mis créditos», el
 * expediente y el pase de entrada aparecen solo cuando el negocio de verdad los usa.
 * A quien solo quiere cortarse el cabello no le sirve una tarjeta vacía de «Sin
 * paquete».
 *
 * - créditos: tiene (o tuvo) un bono, paquete o membresía; o el negocio trabaja con
 *   clases y vende planes;
 * - pase: el negocio controla accesos (acceso abierto, o ya registra entradas);
 * - expediente: el negocio pide consentimientos o fichas, o la persona tiene
 *   documentos.
 */
class PortalDelClienteTenant
{
    /** Ventana de la asistencia que se le muestra (días). */
    private const DIAS_ASISTENCIA = 30;

    /**
     * @return array{creditos: bool, pase: bool, expediente: bool}
     */
    public function capacidades(Estudio $estudio, PersonaTenant $persona): array
    {
        $tieneDerechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->exists();
        $flags = $estudio->perfilConfig()['flags'];

        return [
            'creditos' => $tieneDerechos
                || ($estudio->modalidad() === ModalidadServicio::Clases && ProductoTenant::query()->where('archivado', false)->exists()),
            'pase' => (bool) ($flags['acceso_abierto'] ?? false) || AccesoTenant::query()->exists(),
            'expediente' => WaiverTenant::query()->where('activo', true)->exists()
                || Formulario::query()->where('activo', true)->exists()
                || Documento::query()->where('persona_id', $persona->getKey())->exists(),
        ];
    }

    /** A cuántas clases o citas llegó en los últimos 30 días. */
    public function asistencias(PersonaTenant $persona): int
    {
        return ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereHas('asistencia', fn ($q) => $q->where('estado', EstadoAsistencia::Presente->value))
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>=', CarbonImmutable::now()->subDays(self::DIAS_ASISTENCIA)))
            ->count();
    }
}
