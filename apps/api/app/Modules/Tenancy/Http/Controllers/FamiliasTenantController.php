<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcanceClientesTenant;
use App\Modules\Tenancy\Application\FamiliasTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Familias\EstadoTutela;
use App\Modules\Tenancy\Familias\Parentesco;
use App\Modules\Tenancy\Models\ConfiguracionFamiliasTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TutelaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Familias del lado del negocio (B05, ADR 0123): activarlas y redactar la autorización,
 * ver quién está a cargo de quién en la ficha, poner a un participante a cargo de un
 * responsable, registrar que el responsable aceptó en el mostrador y retirar.
 */
class FamiliasTenantController
{
    public function __construct(
        private readonly FamiliasTenant $familias,
        private readonly ResolverAccesoTenant $acceso,
        private readonly AlcanceClientesTenant $alcance,
    ) {}

    public function configuracion(): JsonResponse
    {
        return response()->json(['data' => self::presentarConfiguracion($this->familias->configuracion())]);
    }

    public function guardarConfiguracion(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'activo' => ['required', 'boolean'],
            // Vacío = el texto base de la plataforma.
            'texto_autorizacion' => ['nullable', 'string', 'max:5000'],
        ]);
        $config = $this->familias->guardarConfiguracion((bool) $validado['activo'], $validado['texto_autorizacion'] ?? null, $this->actor($request));

        return response()->json(['data' => self::presentarConfiguracion($config)]);
    }

    /** Quién está a cargo de esta persona y a quién tiene a su cargo. */
    public function deMiembro(Request $request): JsonResponse
    {
        $persona = $this->personaDeRuta($request);
        $config = $this->familias->configuracion();
        $tutelas = TutelaTenant::query()
            ->with(['responsable', 'participante'])
            ->where(fn ($q) => $q->where('responsable_id', $persona->getKey())->orWhere('participante_id', $persona->getKey()))
            ->orderBy('id')
            ->get();

        return response()->json(['data' => [
            'activo' => $config->activo,
            'version' => (int) $config->version,
            'texto' => $config->texto(),
            'tiene_cuenta' => $persona->usuario_id !== null,
            'a_su_cargo' => $tutelas->where('responsable_id', $persona->getKey())
                ->map(fn (TutelaTenant $t): array => self::presentarTutela($t, $t->participante))->values()->all(),
            'responsables' => $tutelas->where('participante_id', $persona->getKey())
                ->map(fn (TutelaTenant $t): array => self::presentarTutela($t, $t->responsable))->values()->all(),
        ]]);
    }

    /** Pone a un participante a cargo de esta persona (queda pendiente de autorización). */
    public function vincular(Request $request): JsonResponse
    {
        $responsable = $this->personaDeRuta($request);
        $validado = $request->validate([
            'participante_id' => ['required', 'string'],
            'parentesco' => ['required', Rule::enum(Parentesco::class)],
        ]);
        $participante = $this->visible($request, PersonaTenant::query()->where('ulid', $validado['participante_id'])->firstOrFail());
        $tutela = $this->familias->vincular($responsable, $participante, Parentesco::from($validado['parentesco']), $this->actor($request));

        return response()->json(['data' => self::presentarTutela($tutela, $participante)], 201);
    }

    /**
     * El equipo registra que el responsable leyó y aceptó la autorización en el
     * mostrador (la versión que se le mostró).
     */
    public function autorizarEnMostrador(Request $request): JsonResponse
    {
        $tutela = $this->tutelaDeRuta($request);
        $validado = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $tutela = $this->familias->autorizar($tutela, (int) $validado['version'], TutelaTenant::VIA_MOSTRADOR, $this->actor($request), $request->ip());

        return response()->json(['data' => self::presentarTutela($tutela, $tutela->participante)]);
    }

    public function retirar(Request $request): JsonResponse
    {
        $tutela = $this->tutelaDeRuta($request);
        $validado = $request->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $tutela = $this->familias->retirar($tutela, $this->actor($request), $validado['motivo'] ?? null);

        return response()->json(['data' => self::presentarTutela($tutela, $tutela->participante)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentarConfiguracion(ConfiguracionFamiliasTenant $config): array
    {
        return [
            'activo' => $config->activo,
            'version' => (int) $config->version,
            // El propio (null = usa el base) y el que se acepta hoy.
            'texto_autorizacion' => $config->texto_autorizacion,
            'texto_vigente' => $config->texto(),
        ];
    }

    /**
     * El vínculo visto desde una de sus dos personas (`persona` es la otra).
     *
     * @return array<string, mixed>
     */
    public static function presentarTutela(TutelaTenant $tutela, PersonaTenant $persona): array
    {
        return [
            'id' => $tutela->ulid,
            'persona' => [
                'id' => $persona->ulid,
                'nombre' => $persona->nombreCompleto(),
                'tiene_cuenta' => $persona->usuario_id !== null,
                'fecha_nacimiento' => $persona->fecha_nacimiento?->toDateString(),
            ],
            'parentesco' => $tutela->parentesco->value,
            'estado' => $tutela->estado->value,
            'autorizacion' => $tutela->estado === EstadoTutela::Pendiente ? null : [
                'version' => $tutela->autorizacion_version,
                'aceptada_en' => $tutela->aceptada_en?->toIso8601String(),
                'via' => $tutela->aceptada_via,
            ],
            'retirada_en' => $tutela->retirada_en?->toIso8601String(),
        ];
    }

    private function personaDeRuta(Request $request): PersonaTenant
    {
        return $this->visible($request, PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail());
    }

    /** El vínculo, si quien pregunta puede ver a las dos personas (sede e instructor). */
    private function tutelaDeRuta(Request $request): TutelaTenant
    {
        $tutela = TutelaTenant::query()->with(['responsable', 'participante'])->where('ulid', (string) $request->route('tutela'))->firstOrFail();
        $this->visible($request, $tutela->responsable);
        $this->visible($request, $tutela->participante);

        return $tutela;
    }

    /** La persona, si quien pregunta puede verla (el mismo alcance que su ficha). */
    private function visible(Request $request, PersonaTenant $persona): PersonaTenant
    {
        $usuario = $this->actor($request);
        abort_unless(
            $usuario === null || $this->acceso->permiteSucursal($usuario, $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null),
            403,
        );
        abort_unless($this->alcance->puedeVer($persona, $usuario), 403);

        return $persona;
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
