<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Comunicaciones\Push\CifradoWebPush;
use App\Modules\Tenancy\Comunicaciones\Push\ClienteWebPush;
use App\Modules\Tenancy\Models\SuscripcionPushWebTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Avisos en este navegador (push en la web, ADR 0225): la llave pública para suscribirse,
 * guardar la suscripción del navegador y quitarla. Una dirección es de un solo usuario
 * (si otro entra en ese navegador, pasa a él). Solo direcciones de los servicios de push
 * de los navegadores.
 */
class MiPushWebTenantController
{
    /** Navegadores por usuario: al pasar de ahí se olvida el más viejo. */
    private const MAXIMO = 10;

    public function __construct(private readonly ClienteWebPush $web) {}

    /** Si está disponible, su llave pública y si este navegador (`endpoint`) ya está. */
    public function mostrar(Request $request): JsonResponse
    {
        $endpoint = (string) $request->query('endpoint', '');

        return response()->json(['data' => [
            'disponible' => $this->web->configurado(),
            'llave_publica' => $this->web->configurado() ? $this->web->publica() : null,
            'activa' => $endpoint !== '' && SuscripcionPushWebTenant::query()
                ->where('endpoint_huella', SuscripcionPushWebTenant::huella($endpoint))
                ->where('usuario_id', $this->usuario($request)->getKey())
                ->exists(),
        ]]);
    }

    public function registrar(Request $request): JsonResponse
    {
        abort_unless($this->web->configurado(), 422, 'Los avisos en el navegador no están disponibles.');
        $validado = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:128'],
            'keys.auth' => ['required', 'string', 'max:64'],
        ]);
        $endpoint = (string) $validado['endpoint'];
        if (! ClienteWebPush::endpointValido($endpoint)
            || strlen(CifradoWebPush::deB64((string) $validado['keys']['p256dh'])) !== 65
            || strlen(CifradoWebPush::deB64((string) $validado['keys']['auth'])) < 16) {
            throw ValidationException::withMessages(['endpoint' => ['Este navegador no dio una suscripción válida.']]);
        }

        $usuario = $this->usuario($request);
        SuscripcionPushWebTenant::query()->updateOrCreate(
            ['endpoint_huella' => SuscripcionPushWebTenant::huella($endpoint)],
            [
                'usuario_id' => $usuario->getKey(),
                'endpoint' => $endpoint,
                'p256dh' => (string) $validado['keys']['p256dh'],
                'auth' => (string) $validado['keys']['auth'],
                'registrada_en' => Carbon::now(),
            ],
        );
        $sobran = SuscripcionPushWebTenant::query()->where('usuario_id', $usuario->getKey())
            ->orderByDesc('registrada_en')->orderByDesc('id')->skip(self::MAXIMO)->take(100)->pluck('id');
        if ($sobran->isNotEmpty()) {
            SuscripcionPushWebTenant::query()->whereKey($sobran->all())->delete();
        }

        return response()->json(['data' => ['activa' => true]], 201);
    }

    public function quitar(Request $request): JsonResponse
    {
        $validado = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        // Solo la suya: nadie apaga los avisos de otro usuario.
        SuscripcionPushWebTenant::query()
            ->where('endpoint_huella', SuscripcionPushWebTenant::huella((string) $validado['endpoint']))
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
