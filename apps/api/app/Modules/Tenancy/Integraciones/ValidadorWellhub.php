<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Valida la visita de un usuario de Wellhub (antes Gympass) con su Access Control
 * API: `POST {base}/access/v1/validate {gympass_id}` con el token del negocio
 * (`Authorization: Bearer`) y su gimnasio (`X-Gym-Id`). El usuario hace check-in en
 * la app de Wellhub (vale 20 minutos) y el negocio lo valida con su Wellhub ID de 13
 * dígitos. Solo hay una visita por día (del negocio): ya validada, vale como la misma.
 * Sin conexión, un error de su lado (5xx, 429) o el token rechazado (401/403) no
 * dicen nada de la persona: la visita queda no verificable, nunca aprobada (M10).
 *
 * @see https://developers.wellhub.com/product/access-control-api/1.0/endpoints
 */
class ValidadorWellhub implements ValidadorPartner
{
    /** Lo que responde Wellhub cuando la visita no se puede validar. */
    private const MOTIVOS = [
        'checkin.validation.notfound' => 'La persona aún no hace check-in en la app de Wellhub.',
        'checkin.validation.cancelled' => 'La persona canceló su check-in en Wellhub.',
        'checkin.validation.expired' => 'El check-in venció: en Wellhub dura 20 minutos. Pide que lo haga de nuevo.',
    ];

    public function __construct(private readonly FechasNegocioTenant $fechas) {}

    public function nombre(): string
    {
        return 'wellhub';
    }

    public function validar(string $codigo, array $credenciales): ResultadoCheckin
    {
        $token = $credenciales['api_key'] ?? '';
        $gimnasio = $credenciales['gym_id'] ?? '';
        if ($token === '' || $gimnasio === '') {
            return ResultadoCheckin::invalido('Faltan el token y el Gym ID de Wellhub en la integración.');
        }
        $wellhubId = preg_replace('/\D/', '', $codigo) ?? '';
        if (strlen($wellhubId) !== 13) {
            return ResultadoCheckin::invalido('El Wellhub ID tiene 13 dígitos: está en el perfil de la persona en la app de Wellhub.');
        }

        try {
            $respuesta = Http::withToken($token)
                ->withHeaders(['X-Gym-Id' => $gimnasio])
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post(rtrim((string) config('agendauno.integraciones.wellhub_url'), '/').'/access/v1/validate', [
                    'gympass_id' => $wellhubId,
                ]);
        } catch (Throwable) {
            return ResultadoCheckin::noVerificable('No se pudo contactar a Wellhub: no respondió a tiempo o no hubo conexión.', $wellhubId);
        }

        // Una visita por día del negocio: la de hoy (o la que ya se validó hoy) es la misma.
        $referencia = 'wellhub:'.$gimnasio.':'.$wellhubId.':'.$this->fechas->hoy();
        if ($respuesta->successful() || self::clave($respuesta) === 'checkin.already.validated') {
            return ResultadoCheckin::valido($referencia, $wellhubId);
        }
        if (in_array($respuesta->status(), [401, 403], true)) {
            return ResultadoCheckin::noVerificable('Wellhub no aceptó el token o el Gym ID de la integración: revisa las credenciales.', $wellhubId);
        }
        if ($respuesta->status() === 429 || $respuesta->serverError()) {
            return ResultadoCheckin::noVerificable('Wellhub no está respondiendo (error de su lado). Intenta de nuevo en unos minutos.', $wellhubId);
        }

        return ResultadoCheckin::invalido(self::MOTIVOS[self::clave($respuesta)] ?? 'Wellhub no validó la visita.');
    }

    /** La `key` del primer error que responde Wellhub. */
    private static function clave(Response $respuesta): string
    {
        $errores = $respuesta->json('errors');

        return is_array($errores) && isset($errores[0]['key']) ? (string) $errores[0]['key'] : '';
    }
}
