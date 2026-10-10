<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Legales\DocumentoLegal;
use App\Modules\Platform\Legales\DocumentosLegales;
use App\Modules\Tenancy\Application\VerificarRecaptcha;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Interesado;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lista de interesados de un producto que aún no abre registros (ADR 0108): la landing
 * de TurnoUno la llena (público, con captcha) y el superadmin la consulta para avisarles
 * al lanzarlo. Una fila por correo y producto: escribir otra vez actualiza los datos.
 */
class InteresadosController
{
    public function __construct(
        private readonly VerificarRecaptcha $recaptcha,
        private readonly DocumentosLegales $legales,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'producto' => ['required', Rule::enum(ProductoComercial::class)],
            'nombre' => ['required', 'string', 'max:120'],
            'correo' => ['required', 'email', 'max:190'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'negocio' => ['nullable', 'string', 'max:120'],
            'giro' => ['nullable', Rule::enum(PerfilNegocio::class)],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'mensaje' => ['nullable', 'string', 'max:500'],
            'acepta_aviso' => ['accepted'],
            // La versión del aviso que se leyó: si cambió mientras tanto, se pide revisarlo.
            'aviso_version' => ['nullable', 'integer', 'min:1'],
            'recaptcha_token' => ['nullable', 'string', 'max:4000'],
        ]);

        if (! $this->recaptcha->aprobado((string) ($validado['recaptcha_token'] ?? ''), $request->ip())) {
            throw ValidationException::withMessages([
                'recaptcha' => ['No pudimos verificar que no eres un robot. Recarga e inténtalo de nuevo.'],
            ]);
        }

        // Como el registro: en producción nadie deja sus datos sin un aviso de privacidad
        // publicado, y lo que se acepta es la versión que se leyó.
        $aviso = $this->legales->vigente(DocumentoLegal::AVISO);
        if ($aviso === null && app()->environment('production')) {
            throw ValidationException::withMessages([
                'acepta_aviso' => ['La lista abrirá cuando el aviso de privacidad esté publicado.'],
            ]);
        }
        $leida = $validado['aviso_version'] ?? null;
        if ($leida !== null && $aviso !== null && (int) $leida !== $aviso->version) {
            throw ValidationException::withMessages([
                'acepta_aviso' => ['El aviso de privacidad cambió mientras llenabas tus datos: revísalo y vuelve a aceptarlo.'],
            ]);
        }

        $producto = ProductoComercial::from((string) $validado['producto']);
        // El giro es de la modalidad de su producto: pilates no espera a TurnoUno.
        $giro = PerfilNegocio::tryFrom((string) ($validado['giro'] ?? ''));
        $suyo = $giro !== null ? ProductoComercial::deModalidad(ModalidadServicio::paraPerfil($giro)) : $producto;
        if ($suyo !== $producto) {
            throw ValidationException::withMessages([
                'giro' => ["Ese giro es de {$suyo->nombre()}, no de {$producto->nombre()}."],
            ]);
        }
        $correo = mb_strtolower(trim((string) $validado['correo']));
        $datos = [
            'nombre' => trim((string) $validado['nombre']),
            'telefono' => self::texto($validado['telefono'] ?? null),
            'negocio' => self::texto($validado['negocio'] ?? null),
            'giro' => self::texto($validado['giro'] ?? null),
            'ciudad' => self::texto($validado['ciudad'] ?? null),
            'mensaje' => self::texto($validado['mensaje'] ?? null),
            'acepto_aviso_en' => now(),
            'aviso_version' => $aviso?->version,
        ];

        $interesado = Interesado::query()->updateOrCreate(
            ['producto' => $producto->value, 'correo' => $correo],
            $datos,
        );
        Log::info('interesado.registrado', ['producto' => $producto->value, 'interesado' => $interesado->ulid]);

        // No dice si ya estaba: la respuesta es la misma para todos.
        return response()->json(['data' => ['registrado' => true]], 201);
    }

    /**
     * Para el superadmin: los interesados de un producto, los más recientes primero.
     */
    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'producto' => ['nullable', Rule::enum(ProductoComercial::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $pagina = Interesado::query()
            ->when(isset($validado['producto']), fn ($q) => $q->where('producto', $validado['producto']))
            ->orderByDesc('updated_at')
            ->paginate(50);

        return response()->json([
            'data' => collect($pagina->items())->map(fn (Interesado $i): array => [
                'id' => $i->ulid,
                'producto' => $i->producto->value,
                'nombre' => $i->nombre,
                'correo' => $i->correo,
                'telefono' => $i->telefono,
                'negocio' => $i->negocio,
                'giro' => $i->giro,
                'ciudad' => $i->ciudad,
                'mensaje' => $i->mensaje,
                'aviso_version' => $i->aviso_version,
                'registrado_en' => $i->created_at->toIso8601String(),
                'actualizado_en' => $i->updated_at->toIso8601String(),
            ])->all(),
            'meta' => [
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }

    private static function texto(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto !== '' ? $texto : null;
    }
}
