<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\MensajesWhatsAppTenant;
use App\Modules\Tenancy\Application\PlantillasWhatsAppNegocio;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PlantillaWhatsAppNegocio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Las plantillas de WhatsApp del negocio para sus campañas (ADR 0190): escribirlas,
 * mandarlas a aprobar a Meta, ver cómo van y archivarlas. Con lo que cuesta cada
 * mensaje según su categoría.
 */
class PlantillasWhatsAppNegocioController
{
    public function __construct(
        private readonly PlantillasWhatsAppNegocio $plantillas,
        private readonly MensajesWhatsAppTenant $saldo,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $precio = $this->saldo->precioMinor();
        $cobra = $this->saldo->cobra();

        return response()->json([
            'data' => $this->plantillas->delNegocio($estudio)->map(fn (PlantillaWhatsAppNegocio $p): array => $this->presentar($p))->values()->all(),
            'meta' => [
                'disponible' => $this->plantillas->disponible($estudio),
                'marcadores' => PlantillasWhatsAppNegocio::MARCADORES,
                'max_cuerpo' => PlantillasWhatsAppNegocio::MAX_CUERPO,
                'cobra' => $cobra,
                // Lo que gasta y cuesta (sin IVA) cada mensaje de cada categoría.
                'categorias' => array_map(fn (string $c): array => [
                    'clave' => $c,
                    'mensajes' => $this->saldo->unidadesPor($c),
                    'precio_minor' => $cobra ? $this->saldo->unidadesPor($c) * $precio : null,
                ], PlantillaWhatsAppNegocio::CATEGORIAS),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $plantilla = $this->plantillas->crear($estudio, $this->validar($request));

        return response()->json(['data' => $this->presentar($plantilla)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $plantilla = $this->plantilla($request);

        return response()->json(['data' => $this->presentar($this->plantillas->actualizar($plantilla, $this->validar($request)))]);
    }

    public function enviar(Request $request): JsonResponse
    {
        $plantilla = $this->plantillas->enviar($this->plantilla($request));
        $this->auditoria->registrar($this->actor($request), 'whatsapp.plantilla_enviada', 'plantilla_whatsapp', (string) $plantilla->ulid, null, ['titulo' => $plantilla->titulo, 'categoria' => $plantilla->categoria]);

        return response()->json(['data' => $this->presentar($plantilla)]);
    }

    public function revisar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->plantillas->sincronizar($this->plantilla($request)))]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $plantilla = $this->plantilla($request);
        $this->plantillas->archivar($plantilla);
        $this->auditoria->registrar($this->actor($request), 'whatsapp.plantilla_archivada', 'plantilla_whatsapp', (string) $plantilla->ulid, ['titulo' => $plantilla->titulo], null);

        return response()->json(['data' => $this->presentar($plantilla)]);
    }

    /**
     * @return array{titulo: string, categoria: string, cuerpo: string, pie?: string|null}
     */
    private function validar(Request $request): array
    {
        /** @var array{titulo: string, categoria: string, cuerpo: string, pie?: string|null} $validado */
        $validado = $request->validate([
            'titulo' => ['required', 'string', 'min:3', 'max:80'],
            'categoria' => ['required', Rule::in(PlantillaWhatsAppNegocio::CATEGORIAS)],
            'cuerpo' => ['required', 'string', 'min:10', 'max:'.PlantillasWhatsAppNegocio::MAX_CUERPO],
            'pie' => ['nullable', 'string', 'max:60'],
        ]);

        return $validado;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(PlantillaWhatsAppNegocio $p): array
    {
        return [
            'id' => $p->ulid,
            'titulo' => $p->titulo,
            'categoria' => $p->categoria,
            'cuerpo' => $p->cuerpo,
            'pie' => $p->pie,
            'estado' => $p->estado,
            'motivo' => $p->motivo,
            'editable' => $p->editable(),
            'enviada_en' => $p->enviada_en?->toIso8601String(),
            'revisada_en' => $p->revisada_en?->toIso8601String(),
        ];
    }

    private function plantilla(Request $request): PlantillaWhatsAppNegocio
    {
        return PlantillaWhatsAppNegocio::query()
            ->where('estudio_id', $this->estudio($request)->getKey())
            ->where('ulid', (string) $request->route('plantilla'))
            ->where('estado', '!=', 'archivada')
            ->firstOrFail();
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
