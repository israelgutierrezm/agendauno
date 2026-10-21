<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
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
 * - pase: el negocio controla la entrada (acceso libre: gimnasio, CrossFit, HYROX;
 *   ADR 0105). En un estudio, entrar es tomar la clase: no hay pase.
 * - expediente: el negocio pide consentimientos o fichas, o la persona tiene
 *   documentos.
 */
class PortalDelClienteTenant
{
    /** Ventana de la asistencia que se le muestra (días). */
    private const DIAS_ASISTENCIA = 30;

    public function __construct(private readonly ModalidadNegocioTenant $modalidad) {}

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
                || ($this->modalidad->esClases() && ProductoTenant::query()->where('archivado', false)->exists()),
            'pase' => (bool) ($flags['acceso_abierto'] ?? false),
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

    /**
     * Su resumen (comparativo del 2026-10-20): clases a las que vino este mes, cuántas
     * semanas seguidas lleva viniendo (de lunes a domingo, contando la actual si ya
     * vino) y cuántas lleva en total. En la zona del negocio.
     *
     * @return array{clases_mes: int, racha_semanas: int, total: int}
     */
    public function resumen(PersonaTenant $persona, string $zona): array
    {
        $fechas = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereHas('asistencia', fn ($q) => $q->where('estado', EstadoAsistencia::Presente->value))
            ->with('sesion:id,inicia_en')
            ->get(['id', 'sesion_id'])
            ->map(static fn (ReservaTenant $r): ?CarbonImmutable => $r->sesion === null ? null : CarbonImmutable::instance($r->sesion->inicia_en)->setTimezone($zona))
            ->filter();
        $ahora = CarbonImmutable::now($zona);
        $semanas = $fechas->map(static fn (CarbonImmutable $f): string => $f->startOfWeek(CarbonImmutable::MONDAY)->toDateString())->unique()->flip();

        // La racha: desde esta semana (si ya vino) o la anterior, hacia atrás.
        $semana = $ahora->startOfWeek(CarbonImmutable::MONDAY);
        if (! $semanas->has($semana->toDateString())) {
            $semana = $semana->subWeek();
        }
        $racha = 0;
        while ($semanas->has($semana->toDateString())) {
            $racha++;
            $semana = $semana->subWeek();
        }

        return [
            'clases_mes' => $fechas->filter(static fn (CarbonImmutable $f): bool => $f->format('Y-m') === $ahora->format('Y-m') && $f->lessThanOrEqualTo($ahora))->count(),
            'racha_semanas' => $racha,
            'total' => $fechas->count(),
        ];
    }
}
