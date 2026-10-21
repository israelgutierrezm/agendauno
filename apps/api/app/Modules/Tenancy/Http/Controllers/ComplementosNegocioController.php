<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Application\ComplementosRentaSaas;
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

    /** Servicios de pago único que se piden igual (ADR 0188). */
    private const SERVICIOS = [
        'arranque' => ['Arranque acompañado', 'Te dejamos listo: clases, paquetes, horario, pasarela de pago y tu sitio con plantilla.'],
        'arranque_migracion' => ['Arranque con migración', 'El arranque acompañado y la migración de tus clientes con sus saldos y membresías (hasta 2,000).'],
    ];

    public function __construct(
        private readonly AlertasPlataforma $alertas,
        private readonly ComplementosRentaSaas $complementos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json([
            'data' => [
                ...array_map(fn (ModuloNegocio $m): array => $this->presentar($estudio, $m), ModuloNegocio::cases()),
                ...array_map(fn (string $clave): array => $this->presentarServicio($estudio, $clave), array_keys(self::SERVICIOS)),
            ],
        ]);
    }

    public function solicitar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $clave = (string) $request->route('modulo');
        if (isset(self::SERVICIOS[$clave])) {
            return $this->solicitarServicio($request, $estudio, $clave);
        }
        $elegido = ModuloNegocio::tryFrom($clave) ?? abort(404);
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

        $precios = $this->complementos->precios();

        return [
            'clave' => $modulo->value,
            'tipo' => 'modulo',
            'nombre' => $modulo->etiqueta(),
            'descripcion' => $modulo->descripcion(),
            'activo' => $activo,
            'solicitado_en' => $solicitud?->ultima_en?->toIso8601String(),
            // Con precio publicado (ADR 0188); sin él, incluido.
            'precio' => $modulo === ModuloNegocio::AppConMarca ? [
                'alta_minor' => $precios['app_marca_alta_minor'],
                'mensual_minor' => $precios['app_marca_mensual_minor'],
                'moneda' => $precios['moneda'],
                'gratis' => false,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarServicio(Estudio $estudio, string $clave): array
    {
        $precios = $this->complementos->precios();
        $solicitud = AlertaPlataforma::query()->where('tipo', self::TIPO_ALERTA)->where('clave', "servicio:{$estudio->slug}:{$clave}")->first();

        return [
            'clave' => $clave,
            'tipo' => 'servicio',
            'nombre' => self::SERVICIOS[$clave][0],
            'descripcion' => self::SERVICIOS[$clave][1],
            'activo' => false,
            'solicitado_en' => $solicitud?->ultima_en?->toIso8601String(),
            'precio' => [
                'alta_minor' => $clave === 'arranque' ? $precios['arranque_minor'] : $precios['arranque_migracion_minor'],
                'mensual_minor' => null,
                'moneda' => $precios['moneda'],
                'gratis' => $this->complementos->arranqueGratis($estudio),
            ],
        ];
    }

    private function solicitarServicio(Request $request, Estudio $estudio, string $clave): JsonResponse
    {
        $usuario = $request->attributes->get('usuario_tenant');
        $quien = $usuario instanceof Usuario ? " (lo pidió {$usuario->name})" : '';
        $gratis = $this->complementos->arranqueGratis($estudio) ? ' Tiene plan anual: va sin costo.' : '';
        $this->alertas->registrar(
            self::TIPO_ALERTA,
            "servicio:{$estudio->slug}:{$clave}",
            "{$estudio->nombre} pide «".self::SERVICIOS[$clave][0]."»{$quien}.{$gratis} Contáctalo para agendar.",
            $estudio->slug,
        );

        return response()->json(['data' => $this->presentarServicio($estudio, $clave)], 201);
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
