<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use App\Modules\Tenancy\Models\MedicionUso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Ficha de un estudio para el operador de la plataforma (PlatformAdmin): datos del
 * negocio y su contacto, uso medido, cargos de renta y facturas; y las acciones de
 * soporte sobre su cuenta (suspender, reactivar, extender la prueba gratis).
 */
class PlataformaEstudiosController
{
    private const MESES_USO = 6;

    private const CARGOS = 12;

    public function show(string $estudio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();

        $uso = MedicionUso::query()
            ->where('estudio_id', $modelo->getKey())
            ->orderByDesc('periodo')
            ->limit(self::MESES_USO)
            ->get()
            ->map(static fn (MedicionUso $m): array => [
                'periodo' => $m->periodo,
                'metrica' => $m->metrica,
                'cantidad' => $m->cantidad,
                'congelada' => $m->congelada,
            ])->values()->all();

        $facturas = FacturaPlataforma::query()
            ->where('estudio_id', $modelo->getKey())
            ->get()
            ->keyBy('cargo_renta_id');

        $cargos = CargoRenta::query()
            ->where('estudio_id', $modelo->getKey())
            ->orderByDesc('periodo')
            ->limit(self::CARGOS)
            ->get()
            ->map(static fn (CargoRenta $c): array => [
                'id' => $c->ulid,
                'periodo' => $c->periodo,
                'monto_minor' => $c->monto_minor,
                'moneda' => $c->moneda,
                'estado' => $c->estado->value,
                'vence_en' => $c->vence_en?->toDateString(),
                'pagado_en' => $c->pagado_en?->toIso8601String(),
                'factura' => $facturas->get($c->getKey())?->estado->value,
            ])->values()->all();

        return response()->json(['data' => [
            ...self::resumen($modelo),
            'contacto' => [
                'nombre' => $modelo->nombreContacto(),
                'email' => $modelo->contacto_email,
                'whatsapp' => $modelo->whatsappCompleto(),
            ],
            'onboarding_completo' => (bool) $modelo->onboarding_completo,
            'uso' => $uso,
            'cargos' => $cargos,
        ]]);
    }

    /**
     * Suspende el acceso al estudio (todas sus rutas responden 404 mientras tanto).
     */
    public function suspender(Request $request, string $estudio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        $validado = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);
        if (! $modelo->estado->operativo()) {
            throw ValidationException::withMessages(['estado' => ['El estudio no está activo.']]);
        }

        $modelo->update(['estado' => EstadoEstudio::Suspended->value]);
        Log::info('plataforma.estudio.suspendido', ['estudio' => $modelo->slug, 'motivo' => $validado['motivo'] ?? null]);

        return response()->json(['data' => self::resumen($modelo->refresh())]);
    }

    /**
     * Devuelve el acceso: vuelve a prueba si aún no termina; si no, queda activo.
     */
    public function reactivar(string $estudio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        if ($modelo->estado !== EstadoEstudio::Suspended) {
            throw ValidationException::withMessages(['estado' => ['Solo se reactiva un estudio suspendido.']]);
        }

        $enPrueba = $modelo->trial_termina_en !== null && ! $modelo->trial_termina_en->isPast();
        $modelo->update(['estado' => ($enPrueba ? EstadoEstudio::Trialing : EstadoEstudio::Active)->value]);
        Log::info('plataforma.estudio.reactivado', ['estudio' => $modelo->slug]);

        return response()->json(['data' => self::resumen($modelo->refresh())]);
    }

    /**
     * Extiende la prueba gratis N días (desde hoy si ya había terminado). Los meses
     * cubiertos por la prueba no generan cargo de renta.
     */
    public function extenderPrueba(Request $request, string $estudio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        $validado = $request->validate(['dias' => ['required', 'integer', 'min:1', 'max:90']]);
        if (! $modelo->estado->operativo()) {
            throw ValidationException::withMessages(['estado' => ['Reactiva el estudio antes de extender su prueba.']]);
        }

        $base = $modelo->trial_termina_en !== null && ! $modelo->trial_termina_en->isPast()
            ? Carbon::parse($modelo->trial_termina_en->toDateString())
            : Carbon::today();

        $modelo->update([
            'trial_termina_en' => $base->addDays((int) $validado['dias'])->toDateString(),
            'estado' => EstadoEstudio::Trialing->value,
            'estado_facturacion' => EstadoFacturacion::Trial->value,
        ]);
        Log::info('plataforma.estudio.prueba_extendida', ['estudio' => $modelo->slug, 'dias' => $validado['dias']]);

        return response()->json(['data' => self::resumen($modelo->refresh())]);
    }

    /**
     * Lo que se ve de un estudio en la lista y en la cabecera de su ficha.
     *
     * @return array<string, mixed>
     */
    public static function resumen(Estudio $e): array
    {
        return [
            'slug' => $e->slug,
            'nombre' => $e->nombre,
            'estado' => $e->estado->value,
            'estado_facturacion' => $e->estado_facturacion->value,
            'perfil' => $e->perfil_negocio->value,
            'modalidad' => $e->modalidad()->value,
            'modo_cobro' => $e->modo_cobro->value,
            'cuota_fija_minor' => $e->cuota_fija_minor,
            'trial_termina_en' => $e->trial_termina_en?->toDateString(),
            'moneda' => $e->moneda,
            'publicado' => (bool) $e->publicado,
            'en_directorio' => $e->enDirectorio(),
            'pais' => $e->pais,
            'ciudad' => $e->ciudad,
            'creado_en' => $e->created_at?->toIso8601String(),
        ];
    }
}
