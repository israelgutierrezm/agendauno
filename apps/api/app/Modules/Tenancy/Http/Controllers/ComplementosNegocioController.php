<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ModuloNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Complementos que el negocio puede pedir (ADR 0140): los módulos que el
 * superadministrador enciende por negocio (p. ej. Familias). Apagados, no existen en
 * su panel; aquí se ofrecen como un plus, con lo que hacen, y se piden con un botón.
 * La solicitud le llega a la plataforma como alerta; quien la atiende lo enciende en la
 * ficha del negocio.
 */
class ComplementosNegocioController
{
    public const TIPO_ALERTA = 'modulo_solicitado';

    public function __construct(private readonly AlertasPlataforma $alertas) {}

    public function index(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json([
            'data' => array_map(fn (ModuloNegocio $m): array => $this->presentar($estudio, $m), ModuloNegocio::cases()),
        ]);
    }

    public function solicitar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $elegido = ModuloNegocio::tryFrom((string) $request->route('modulo')) ?? abort(404);
        if ($estudio->moduloHabilitado($elegido)) {
            throw ValidationException::withMessages(['modulo' => ['Ya lo tienes activo.']]);
        }

        $usuario = $request->attributes->get('usuario_tenant');
        $quien = $usuario instanceof Usuario ? " (lo pidió {$usuario->name})" : '';
        $this->alertas->registrar(
            self::TIPO_ALERTA,
            self::clave($estudio, $elegido),
            "{$estudio->nombre} pide activar «{$elegido->etiqueta()}»{$quien}. Se enciende en la ficha del negocio.",
            $estudio->slug,
        );

        return response()->json(['data' => $this->presentar($estudio, $elegido)], 201);
    }

    /**
     * @return array{clave: string, nombre: string, descripcion: string, activo: bool, solicitado_en: string|null}
     */
    private function presentar(Estudio $estudio, ModuloNegocio $modulo): array
    {
        $activo = $estudio->moduloHabilitado($modulo);
        $solicitud = $activo ? null : AlertaPlataforma::query()
            ->where('tipo', self::TIPO_ALERTA)
            ->where('clave', self::clave($estudio, $modulo))
            ->first();

        return [
            'clave' => $modulo->value,
            'nombre' => $modulo->etiqueta(),
            'descripcion' => $modulo->descripcion(),
            'activo' => $activo,
            'solicitado_en' => $solicitud?->ultima_en?->toIso8601String(),
        ];
    }

    private static function clave(Estudio $estudio, ModuloNegocio $modulo): string
    {
        return "modulo:{$estudio->slug}:{$modulo->value}";
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
