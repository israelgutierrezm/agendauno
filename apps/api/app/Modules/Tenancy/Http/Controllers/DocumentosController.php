<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AccesoExpedienteTenant;
use App\Modules\Tenancy\EstadoDocumento;
use App\Modules\Tenancy\Models\Documento;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TipoDocumento;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos de personas (miembros/instructores) del estudio, tenant-local. Se
 * cargan por persona, el staff los valida (aprobar/rechazar) y se descargan de un
 * disco privado con nombre no enumerable, namespaced por estudio. Todo aislado por
 * tenant (opera sobre la BD del estudio resuelto). Los documentos del personal
 * (instructores) solo los ve quien administra al equipo (AccesoExpedienteTenant).
 */
class DocumentosController
{
    private const LIMITE = 200;

    public function index(Request $request): JsonResponse
    {
        $documentos = Documento::query()
            ->with(['persona', 'tipo'])
            ->when($request->query('persona_id'), function ($consulta, $ulid): void {
                $persona = PersonaTenant::query()->where('ulid', $ulid)->first();
                $consulta->where('persona_id', $persona instanceof PersonaTenant ? $persona->getKey() : 0);
            })
            ->when($request->query('estado'), function ($consulta, $estado): void {
                $consulta->where('estado', $estado);
            })
            ->when(! $this->usuario($request)->puede('usuarios.gestionar'), function ($consulta): void {
                $consulta->whereHas('persona', fn ($p) => $p->where('tipo', TipoPersonaTenant::Miembro->value));
            })
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $documentos->map(fn (Documento $documento): array => $this->presentar($documento))->all(),
        ]);
    }

    public function subir(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'persona_id' => ['required', 'string'],
            'tipo_documento_id' => ['nullable', 'string'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
        ]);

        $estudio = $this->estudio($request);
        $persona = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();
        abort_unless(AccesoExpedienteTenant::puedeVer($this->usuario($request), $persona), 403);
        $tipoId = $this->resolverTipo($validado['tipo_documento_id'] ?? null);

        $archivo = $request->file('archivo');
        $extension = $archivo->extension() !== '' ? $archivo->extension() : 'bin';
        $ruta = $archivo->storeAs("documentos/{$estudio->id}", Str::random(40).'.'.$extension, 'local');

        $documento = Documento::query()->create([
            'persona_id' => $persona->id,
            'tipo_documento_id' => $tipoId,
            'nombre' => $validado['nombre'] ?? $archivo->getClientOriginalName(),
            'ruta' => $ruta,
            'mime' => $archivo->getMimeType(),
            'estado' => EstadoDocumento::Pendiente->value,
            'subido_en' => now(),
        ]);

        return response()->json(['data' => $this->presentar($documento->load(['persona', 'tipo']))], 201);
    }

    public function validar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'estado' => ['required', 'in:aprobado,rechazado'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $modelo = Documento::query()->where('ulid', (string) $request->route('documento'))->firstOrFail();
        $usuario = $this->usuario($request);
        $this->autorizar($usuario, $modelo);

        $modelo->update([
            'estado' => $validado['estado'],
            'motivo' => $validado['estado'] === EstadoDocumento::Rechazado->value ? ($validado['motivo'] ?? null) : null,
            'validado_por' => $usuario->getKey(),
            'validado_en' => now(),
        ]);

        return response()->json(['data' => $this->presentar($modelo->refresh()->load(['persona', 'tipo']))]);
    }

    public function ver(Request $request): StreamedResponse
    {
        $modelo = Documento::query()->where('ulid', (string) $request->route('documento'))->firstOrFail();
        $this->autorizar($this->usuario($request), $modelo);

        return Storage::disk('local')->download($modelo->ruta, $modelo->nombre);
    }

    private function autorizar(Usuario $usuario, Documento $documento): void
    {
        $persona = $documento->persona;
        abort_unless($persona instanceof PersonaTenant && AccesoExpedienteTenant::puedeVer($usuario, $persona), 403);
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }

    private function resolverTipo(mixed $ulid): ?int
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        return TipoDocumento::query()->where('ulid', $ulid)->value('id');
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
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
            'persona' => $documento->persona !== null
                ? $documento->persona->nombreCompleto()
                : null,
            'tipo' => $documento->tipo?->nombre,
            'subido_en' => $documento->subido_en?->toIso8601String(),
            'validado_en' => $documento->validado_en?->toIso8601String(),
        ];
    }
}
