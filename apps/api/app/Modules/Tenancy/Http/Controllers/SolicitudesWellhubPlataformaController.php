<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Integraciones\SistemaWellhub;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SolicitudWellhub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Superadmin: los gimnasios que eligieron a AgendaUno en Wellhub y no se supo de qué
 * negocio son (ADR 0202). Se asignan a un negocio (se le pone el Gym ID) o se descartan.
 */
class SolicitudesWellhubPlataformaController
{
    public function __construct(private readonly SistemaWellhub $sistema) {}

    public function index(Request $request): JsonResponse
    {
        $estado = (string) $request->query('estado', SolicitudWellhub::PENDIENTE);

        return response()->json(['data' => SolicitudWellhub::query()
            ->when(in_array($estado, [SolicitudWellhub::PENDIENTE, SolicitudWellhub::ASIGNADA, SolicitudWellhub::DESCARTADA], true), fn ($q) => $q->where('estado', $estado))
            ->with('estudio')
            ->orderByDesc('recibida_en')
            ->limit(200)
            ->get()
            ->map(fn (SolicitudWellhub $s): array => $this->presentar($s))
            ->values()->all()]);
    }

    public function asignar(Request $request): JsonResponse
    {
        $solicitud = $this->solicitud($request);
        $validado = $request->validate(['estudio' => ['required', 'string', 'max:80']]);
        $estudio = Estudio::query()->where('slug', mb_strtolower((string) $validado['estudio']))->first();
        if (! $estudio instanceof Estudio) {
            throw ValidationException::withMessages(['estudio' => ['No hay un negocio con ese código.']]);
        }
        $error = $this->sistema->asignar($solicitud, $estudio);
        if ($error !== null) {
            throw ValidationException::withMessages(['estudio' => [$error]]);
        }

        return response()->json(['data' => $this->presentar($solicitud->refresh()->load('estudio'))]);
    }

    public function descartar(Request $request): JsonResponse
    {
        $solicitud = $this->solicitud($request);
        $solicitud->forceFill(['estado' => SolicitudWellhub::DESCARTADA, 'atendida_en' => now()])->save();

        return response()->json(['data' => $this->presentar($solicitud)]);
    }

    private function solicitud(Request $request): SolicitudWellhub
    {
        return SolicitudWellhub::query()->where('ulid', (string) $request->route('solicitud'))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(SolicitudWellhub $s): array
    {
        return [
            'id' => $s->ulid,
            'gym_id' => $s->gym_id,
            'gimnasio' => $s->gimnasio,
            'campos' => $s->campos ?? [],
            'estado' => $s->estado,
            'nota' => $s->nota,
            'negocio' => $s->estudio === null ? null : ['slug' => $s->estudio->slug, 'nombre' => $s->estudio->nombre],
            'recibida_en' => $s->recibida_en->toIso8601String(),
        ];
    }
}
