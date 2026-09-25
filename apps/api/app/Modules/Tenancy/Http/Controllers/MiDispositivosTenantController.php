<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\DispositivoPushTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * La app registra el teléfono (token de FCM) donde el usuario tiene sesión para
 * recibir notificaciones push, y lo quita al cerrar sesión. Un token es de un solo
 * usuario: si otro inicia sesión en ese teléfono, pasa a él.
 */
class MiDispositivosTenantController
{
    public function registrar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'plataforma' => ['required', Rule::in(['android', 'ios'])],
        ]);

        DispositivoPushTenant::query()->updateOrCreate(
            ['token' => $validado['token']],
            [
                'usuario_id' => $this->usuario($request)->getKey(),
                'plataforma' => $validado['plataforma'],
                'registrado_en' => Carbon::now(),
            ],
        );

        return response()->json(['data' => ['registrado' => true]], 201);
    }

    public function quitar(Request $request): JsonResponse
    {
        $validado = $request->validate(['token' => ['required', 'string', 'max:512']]);

        // Solo el suyo: nadie puede apagar las notificaciones de otro usuario.
        DispositivoPushTenant::query()
            ->where('token', $validado['token'])
            ->where('usuario_id', $this->usuario($request)->getKey())
            ->delete();

        return response()->json(status: 204);
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}
