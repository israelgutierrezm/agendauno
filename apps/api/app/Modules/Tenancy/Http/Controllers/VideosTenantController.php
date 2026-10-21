<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ResolverDerechoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VideoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Biblioteca de videos del negocio (ADR 0184). El negocio publica enlaces (YouTube,
 * Vimeo u otro sitio con https) y elige qué planes los incluyen; el cliente ve los de
 * sus planes vigentes y, de los demás, solo su título y qué plan los incluye. Solo
 * enlaces: nada de código pegado.
 */
class VideosTenantController
{
    private const POR_NEGOCIO = 500;

    public function __construct(
        private readonly RegistrarAuditoria $auditoria,
        private readonly CuentaEnUsoTenant $cuenta,
        private readonly ResolverDerechoTenant $resolver,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => VideoTenant::query()->with('productos:id,ulid,nombre')->orderBy('orden')->orderBy('id')->get()
            ->map(fn (VideoTenant $v): array => $this->presentar($v, true))->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(VideoTenant::query()->count() >= self::POR_NEGOCIO, 422, 'Llegaste al máximo de videos.');
        $validado = $this->validar($request, true);
        $video = VideoTenant::query()->create($this->atributos($validado));
        $video->productos()->sync($this->productosDe($validado['planes'] ?? []));
        $this->registrar($request, 'video.creado', $video);

        return response()->json(['data' => $this->presentar($video->load('productos:id,ulid,nombre'), true)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $video = VideoTenant::query()->where('ulid', (string) $request->route('video'))->firstOrFail();
        $validado = $this->validar($request, false);
        $video->update($this->atributos($validado, $video));
        if (array_key_exists('planes', $validado)) {
            $video->productos()->sync($this->productosDe($validado['planes'] ?? []));
        }
        $this->registrar($request, 'video.actualizado', $video);

        return response()->json(['data' => $this->presentar($video->load('productos:id,ulid,nombre'), true)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $video = VideoTenant::query()->where('ulid', (string) $request->route('video'))->firstOrFail();
        $this->registrar($request, 'video.borrado', $video);
        $video->delete();

        return response()->json(['data' => ['id' => $video->ulid]]);
    }

    /** Los videos publicados para el cliente: los de sus planes vigentes, con enlace. */
    public function mios(Request $request): JsonResponse
    {
        $persona = $this->cuenta->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');
        $productos = $this->resolver->vigentesDe($persona)
            ->map(static fn (DerechoTenant $d): ?int => $d->acuerdo?->getAttribute('producto_comercial_id') !== null ? (int) $d->acuerdo->getAttribute('producto_comercial_id') : null)
            ->filter()->unique()->values()->all();
        $conPlan = $productos !== [];

        $videos = VideoTenant::query()->where('publicado', true)->with('productos:id,ulid,nombre')->orderBy('orden')->orderBy('id')->get()
            ->map(function (VideoTenant $v) use ($productos, $conPlan): array {
                $incluidoEn = $v->productos->modelKeys();
                $acceso = $conPlan && ($incluidoEn === [] || array_intersect($incluidoEn, $productos) !== []);

                return [
                    ...$this->presentar($v, false),
                    'disponible' => $acceso,
                    // El enlace solo para quien lo tiene incluido.
                    'url' => $acceso ? $v->url : null,
                ];
            })->values()->all();

        return response()->json(['data' => $videos]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, bool $nuevo): array
    {
        $req = $nuevo ? 'required' : 'sometimes';
        $validado = $request->validate([
            'titulo' => [$req, 'string', 'min:2', 'max:150'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:500'],
            'url' => [$req, 'string', 'max:500', 'url:https'],
            'duracion_minutos' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:60'],
            'publicado' => ['sometimes', 'boolean'],
            'orden' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'planes' => ['sometimes', 'array', 'max:100'],
            'planes.*' => ['string', 'distinct'],
        ]);

        return $validado;
    }

    /**
     * @param  array<string, mixed>  $validado
     * @return array<string, mixed>
     */
    private function atributos(array $validado, ?VideoTenant $video = null): array
    {
        $atributos = [];
        foreach (['titulo', 'descripcion', 'url', 'duracion_minutos', 'categoria', 'publicado', 'orden'] as $campo) {
            if (array_key_exists($campo, $validado)) {
                $valor = $validado[$campo];
                $atributos[$campo] = is_string($valor) ? (trim($valor) === '' ? null : trim($valor)) : $valor;
            }
        }
        if ($video === null) {
            $atributos['publicado'] ??= true;
            $atributos['orden'] ??= (int) VideoTenant::query()->max('orden') + 1;
        }

        return $atributos;
    }

    /**
     * @param  list<string>  $ulids
     * @return list<int>
     */
    private function productosDe(array $ulids): array
    {
        $ids = ProductoTenant::query()->whereIn('ulid', $ulids)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if (count($ids) !== count(array_unique($ulids))) {
            throw ValidationException::withMessages(['planes' => ['Algún plan no existe.']]);
        }

        return $ids;
    }

    private function registrar(Request $request, string $accion, VideoTenant $video): void
    {
        $actor = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($actor instanceof Usuario ? $actor : null, $accion, 'video', (string) $video->ulid, null, ['titulo' => $video->titulo]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(VideoTenant $v, bool $conUrl): array
    {
        return [
            'id' => $v->ulid,
            'titulo' => $v->titulo,
            'descripcion' => $v->descripcion,
            'url' => $conUrl ? $v->url : null,
            'duracion_minutos' => $v->duracion_minutos,
            'categoria' => $v->categoria,
            'publicado' => $v->publicado,
            'orden' => $v->orden,
            'planes' => $v->productos->map(static fn (ProductoTenant $p): array => ['id' => $p->ulid, 'nombre' => $p->nombre])->values()->all(),
        ];
    }
}
