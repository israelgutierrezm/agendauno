<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Acceso\AccesoPlataforma;
use App\Modules\Platform\Acceso\AdministradorPlataforma;
use App\Modules\Tenancy\Exceptions\AccesoPlataformaInvalido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entrada del superadministrador (ADR 0126): correo, contraseña y el token de la
 * plataforma; luego el código que llega por correo abre la sesión. Salir la cierra.
 */
class AccesoPlataformaController
{
    public function __construct(private readonly AccesoPlataforma $acceso) {}

    public function entrar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
        ]);

        $inicio = $this->acceso->iniciar($datos['email'], $datos['password'], $datos['token'], $request->ip());
        if ($inicio === null) {
            throw new AccesoPlataformaInvalido('El correo, la contraseña o el token no coinciden.');
        }

        return response()->json(['data' => [
            'desafio' => $inicio['desafio']->ulid,
            'correo' => $inicio['correo'],
            'expira_en' => $inicio['desafio']->expira_en->toIso8601String(),
            // Fuera de producción el correo va al registro: el código se muestra aquí.
            'codigo' => app()->environment('production') ? null : $inicio['codigo'],
        ]]);
    }

    public function verificar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'desafio' => ['required', 'string', 'max:40'],
            'codigo' => ['required', 'string', 'digits:6'],
        ]);

        $sesion = $this->acceso->verificar($datos['desafio'], $datos['codigo'], $request->ip(), $request->userAgent());
        if ($sesion === null) {
            throw new AccesoPlataformaInvalido('El código no es válido o ya venció. Vuelve a entrar para recibir otro.');
        }

        return response()->json(['data' => [
            'token' => $sesion['token'],
            'expira_en' => $sesion['sesion']->expira_en->toIso8601String(),
            'administrador' => $this->presentar($sesion['administrador']),
        ]]);
    }

    public function yo(Request $request): JsonResponse
    {
        $administrador = $request->attributes->get('administrador_plataforma');

        return response()->json(['data' => [
            'administrador' => $administrador instanceof AdministradorPlataforma ? $this->presentar($administrador) : null,
        ]]);
    }

    public function salir(Request $request): JsonResponse
    {
        $this->acceso->salir((string) $request->bearerToken());

        return response()->json(['data' => ['cerrada' => true]]);
    }

    /**
     * @return array{nombre: string, email: string}
     */
    private function presentar(AdministradorPlataforma $administrador): array
    {
        return ['nombre' => $administrador->nombre, 'email' => $administrador->email];
    }
}
