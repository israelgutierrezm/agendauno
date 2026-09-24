<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\EstadoDocumento;
use App\Modules\Tenancy\Models\Documento;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TipoDocumento;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Mis documentos" (autoservicio del alumno): ve los documentos que pide el negocio
 * y cómo va cada uno (falta, en revisión, aprobado o rechazado con su motivo), y
 * sube el suyo. Lo que sube queda en revisión hasta que el equipo lo valida. Solo
 * ve y descarga los propios.
 */
class MisDocumentosTenantController
{
    public function __construct(private readonly PersonaDeUsuarioTenant $personas) {}

    public function index(Request $request): JsonResponse
    {
        $persona = $this->persona($request);

        $documentos = Documento::query()
            ->where('persona_id', $persona->getKey())
            ->orderByDesc('id')
            ->get();

        $requisitos = TipoDocumento::query()
            ->where('activo', true)
            ->whereIn('aplica_a', ['miembro', 'todos'])
            ->orderByDesc('obligatorio')
            ->orderBy('nombre')
            ->get()
            ->map(function (TipoDocumento $tipo) use ($documentos): array {
                $ultimo = $documentos->firstWhere('tipo_documento_id', $tipo->getKey());

                return [
                    'tipo' => [
                        'id' => $tipo->ulid,
                        'nombre' => $tipo->nombre,
                        'descripcion' => $tipo->descripcion,
                        'obligatorio' => $tipo->obligatorio,
                    ],
                    'documento' => $ultimo instanceof Documento ? $this->presentar($ultimo) : null,
                ];
            })
            ->all();

        return response()->json(['data' => [
            'requisitos' => $requisitos,
            // Lo que subió sin un requisito (p. ej. porque se lo pidieron en recepción).
            'otros' => $documentos->whereNull('tipo_documento_id')->map(fn (Documento $d): array => $this->presentar($d))->values()->all(),
        ]]);
    }

    public function subir(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $validado = $request->validate([
            'tipo_documento_id' => ['required', 'string'],
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
        ]);

        $tipo = TipoDocumento::query()
            ->where('ulid', $validado['tipo_documento_id'])
            ->where('activo', true)
            ->whereIn('aplica_a', ['miembro', 'todos'])
            ->firstOrFail();

        $archivo = $request->file('archivo');
        $extension = $archivo->extension() !== '' ? $archivo->extension() : 'bin';
        $ruta = $archivo->storeAs('documentos/'.$this->estudio($request)->getKey(), Str::random(40).'.'.$extension, 'local');

        $documento = Documento::query()->create([
            'persona_id' => $persona->getKey(),
            'tipo_documento_id' => $tipo->getKey(),
            'nombre' => $archivo->getClientOriginalName(),
            'ruta' => $ruta,
            'mime' => $archivo->getMimeType(),
            'estado' => EstadoDocumento::Pendiente->value,
            'subido_en' => now(),
        ]);

        return response()->json(['data' => $this->presentar($documento)], 201);
    }

    public function ver(Request $request): StreamedResponse
    {
        $persona = $this->persona($request);
        $documento = Documento::query()
            ->where('ulid', (string) $request->route('documento'))
            ->where('persona_id', $persona->getKey())
            ->firstOrFail();

        return Storage::disk('local')->download($documento->ruta, $documento->nombre);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Documento $documento): array
    {
        return [
            'id' => $documento->ulid,
            'nombre' => $documento->nombre,
            'estado' => $documento->estado->value,
            'motivo' => $documento->motivo,
            'subido_en' => $documento->subido_en?->toIso8601String(),
        ];
    }

    private function persona(Request $request): PersonaTenant
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        $persona = $this->personas->buscar($usuario);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de alumno en este negocio.');

        return $persona;
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
