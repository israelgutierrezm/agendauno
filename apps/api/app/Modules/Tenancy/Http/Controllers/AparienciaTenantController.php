<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\CatalogoTemas;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Apariencia del usuario (tema y ajustes de color), al estilo de Acadion. Es una
 * preferencia personal: no requiere permiso especial, cada quien la cambia sobre su
 * propia cuenta y se guarda en ella (viaja entre dispositivos).
 */
class AparienciaTenantController
{
    /**
     * Tema actual del usuario y los temas disponibles (con muestra para el selector).
     */
    public function show(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);

        return response()->json(['data' => [
            'actual' => CatalogoTemas::resolver($usuario->tema, $usuario->tema_personalizacion),
            'disponibles' => CatalogoTemas::disponibles(),
            'personalizables' => CatalogoTemas::PERSONALIZABLES,
        ]]);
    }

    /**
     * Elige un tema. Los ajustes propios eran del tema anterior: se descartan (si no,
     * arrastraría colores que no combinan con el nuevo).
     */
    public function elegir(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $validado = $request->validate([
            'tema' => ['required', 'string', function (string $campo, mixed $valor, \Closure $falla): void {
                if (! is_string($valor) || ! CatalogoTemas::existe($valor)) {
                    $falla('Ese tema no existe.');
                }
            }],
        ]);

        $usuario->forceFill(['tema' => $validado['tema'], 'tema_personalizacion' => null])->save();

        return $this->respuesta($usuario);
    }

    /**
     * Ajusta un color propio sobre el tema actual; sin valor, lo devuelve al del tema.
     */
    public function personalizar(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $validado = $request->validate([
            'token' => ['required', 'string', Rule::in(CatalogoTemas::PERSONALIZABLES)],
            'valor' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $actual = CatalogoTemas::resolver($usuario->tema, $usuario->tema_personalizacion);
        if (! $actual['permite_personalizar']) {
            throw ValidationException::withMessages([
                'token' => ['Este tema no admite ajustes personales.'],
            ]);
        }

        $propios = $actual['personalizacion'];
        if (($validado['valor'] ?? '') === '') {
            unset($propios[$validado['token']]);
        } else {
            $propios[$validado['token']] = strtoupper((string) $validado['valor']);
        }

        $usuario->forceFill(['tema' => $actual['clave'], 'tema_personalizacion' => $propios === [] ? null : $propios])->save();

        return $this->respuesta($usuario);
    }

    /**
     * Descarta los ajustes propios y vuelve al tema tal cual.
     */
    public function restablecer(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $usuario->forceFill(['tema_personalizacion' => null])->save();

        return $this->respuesta($usuario);
    }

    private function respuesta(Usuario $usuario): JsonResponse
    {
        return response()->json(['data' => CatalogoTemas::resolver($usuario->tema, $usuario->tema_personalizacion)]);
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}
