<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Calendarios\CalendariosExternosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Los avisos de Google Calendar y de Outlook de que cambió el calendario de quien
 * atiende (ADR 0199): `/webhooks/calendarios/{google|microsoft}/{negocio}`. Cada aviso
 * trae el canal o la suscripción y el secreto de esa conexión; si coinciden, se
 * sincroniza en cola y se contesta al momento (los dos piden respuesta rápida). Lo que
 * no es de ninguna conexión se ignora sin decir por qué.
 */
class WebhookCalendariosController
{
    public function __construct(
        private readonly CalendariosExternosTenant $calendarios,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * Google: sin cuerpo, todo en encabezados. El primero (`sync`) solo confirma que el
     * canal quedó abierto.
     */
    public function google(Request $request): Response
    {
        if ($request->header('X-Goog-Resource-State') !== 'sync') {
            $this->recibir((string) $request->route('estudio'), 'google', [[
                'id' => (string) $request->header('X-Goog-Channel-ID', ''),
                'secreto' => (string) $request->header('X-Goog-Channel-Token', ''),
            ]]);
        }

        return response('', 200);
    }

    /**
     * Microsoft: al crear la suscripción pide devolver su `validationToken` en texto; luego
     * manda los cambios en `value`, con el `clientState` de la suscripción.
     */
    public function microsoft(Request $request): Response
    {
        $validacion = $request->query('validationToken');
        if (is_string($validacion)) {
            return response($validacion, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
        }
        $avisos = [];
        foreach ((array) $request->json('value', []) as $cambio) {
            if (is_array($cambio)) {
                $avisos[] = ['id' => (string) ($cambio['subscriptionId'] ?? ''), 'secreto' => (string) ($cambio['clientState'] ?? '')];
            }
        }
        $this->recibir((string) $request->route('estudio'), 'microsoft', $avisos);

        return response('', 202);
    }

    /**
     * @param  list<array{id: string, secreto: string}>  $avisos
     */
    private function recibir(string $slug, string $proveedor, array $avisos): void
    {
        $estudio = Estudio::query()->where('slug', $slug)->first();
        if (! $estudio instanceof Estudio || $avisos === [] || ! $this->gestor->baseDeDatosExiste($estudio)) {
            return;
        }
        $this->gestor->ejecutarEn($estudio, function () use ($proveedor, $avisos): void {
            foreach ($avisos as $aviso) {
                $this->calendarios->avisoRecibido($proveedor, $aviso['id'], $aviso['secreto']);
            }
        });
    }
}
