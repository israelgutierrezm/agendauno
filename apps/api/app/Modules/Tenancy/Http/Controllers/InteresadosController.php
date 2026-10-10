<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\VerificarRecaptcha;
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
    public function __construct(private readonly VerificarRecaptcha $recaptcha) {}

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
            'recaptcha_token' => ['nullable', 'string', 'max:4000'],
        ]);

        if (! $this->recaptcha->aprobado((string) ($validado['recaptcha_token'] ?? ''), $request->ip())) {
            throw ValidationException::withMessages([
                'recaptcha' => ['No pudimos verificar que no eres un robot. Recarga e inténtalo de nuevo.'],
            ]);
        }

        $producto = ProductoComercial::from((string) $validado['producto']);
        $correo = mb_strtolower(trim((string) $validado['correo']));
        $datos = [
            'nombre' => trim((string) $validado['nombre']),
            'telefono' => self::texto($validado['telefono'] ?? null),
            'negocio' => self::texto($validado['negocio'] ?? null),
            'giro' => self::texto($validado['giro'] ?? null),
            'ciudad' => self::texto($validado['ciudad'] ?? null),
            'mensaje' => self::texto($validado['mensaje'] ?? null),
            'acepto_aviso_en' => now(),
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
