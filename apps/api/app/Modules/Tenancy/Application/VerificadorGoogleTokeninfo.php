<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use Illuminate\Support\Facades\Http;

/**
 * Verificador de ID token de Google via el endpoint oficial `tokeninfo`: Google
 * valida firma y expiracion del token; aqui se confirma ademas que `aud` sea uno de
 * los clientes de AgendaUno (la web o la app móvil, Android/iOS), que `iss` sea de
 * Google y que el correo este verificado.
 * (Testeable con Http::fake, mismo patron que las pasarelas.)
 */
class VerificadorGoogleTokeninfo implements VerificadorGoogle
{
    private const ISS_VALIDOS = ['accounts.google.com', 'https://accounts.google.com'];

    public function verificar(string $credential): ?IdentidadGoogle
    {
        $clientes = $this->clientes();

        if ($credential === '' || $clientes === []) {
            return null;
        }

        $respuesta = Http::asJson()->acceptJson()->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $credential,
        ]);

        if (! $respuesta->successful()) {
            return null;
        }

        /** @var array<string, mixed> $datos */
        $datos = $respuesta->json();

        $aud = isset($datos['aud']) ? (string) $datos['aud'] : '';
        $iss = isset($datos['iss']) ? (string) $datos['iss'] : '';
        $sub = isset($datos['sub']) ? (string) $datos['sub'] : '';
        $email = isset($datos['email']) ? (string) $datos['email'] : '';
        $emailVerificado = isset($datos['email_verified'])
            && in_array($datos['email_verified'], [true, 'true', '1', 1], true);

        $audValido = array_filter($clientes, static fn (string $c): bool => hash_equals($c, $aud)) !== [];
        if (! $audValido || ! in_array($iss, self::ISS_VALIDOS, true)) {
            return null;
        }

        if ($sub === '' || $email === '' || ! $emailVerificado) {
            return null;
        }

        $nombre = isset($datos['name']) && $datos['name'] !== '' ? (string) $datos['name'] : null;

        return new IdentidadGoogle($sub, $email, $emailVerificado, $nombre);
    }

    /**
     * El cliente web y los de la app móvil configurados.
     *
     * @return list<string>
     */
    private function clientes(): array
    {
        $app = explode(',', (string) config('services.google.client_ids_app'));

        return array_values(array_filter(
            array_map('trim', [(string) config('services.google.client_id'), ...$app]),
            static fn (string $c): bool => $c !== '',
        ));
    }
}
