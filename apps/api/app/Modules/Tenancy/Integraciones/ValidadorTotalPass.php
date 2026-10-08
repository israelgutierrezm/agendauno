<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Valida la visita de un usuario de TotalPass con su API de uso del token
 * (`POST {base}/service/v1/track_usages`, cabecera `x-api-key`): el usuario muestra
 * el token del día en su app y el negocio lo valida (se consume al validarse). Con
 * el código del gimnasio y, si tiene varios planes, el del plan.
 *
 * @see https://dev.totalpass.com/reference/post_track-usages-1
 */
class ValidadorTotalPass implements ValidadorPartner
{
    /** Lo que responde TotalPass (`label`) cuando el token no se puede usar. */
    private const MOTIVOS = [
        'check_in_not_found' => 'La persona aún no hace check-in en la app de TotalPass.',
        'not_exists' => 'Ese token de TotalPass no existe. Revisa que esté bien escrito.',
        'already_used_other_gym' => 'Ese token ya se usó en otro gimnasio.',
        'invalid_gym' => 'El código de gimnasio de la integración no es válido en TotalPass.',
        'gym_with_more_than_one_plan' => 'Tu gimnasio tiene varios planes en TotalPass: captura el código del plan en la integración.',
        'suspended_employee' => 'La cuenta de TotalPass de esta persona está suspendida.',
    ];

    public function nombre(): string
    {
        return 'totalpass';
    }

    public function validar(string $codigo, array $credenciales): ResultadoCheckin
    {
        $llave = $credenciales['api_key'] ?? '';
        if ($llave === '') {
            return ResultadoCheckin::invalido('Falta la llave de API de TotalPass en la integración.');
        }
        $token = trim($codigo);
        if ($token === '') {
            return ResultadoCheckin::invalido('Captura el token de TotalPass que muestra la persona en su app.');
        }

        $atributos = array_filter([
            'type' => 'token',
            'identifier' => $token,
            'service_provider_code' => $credenciales['codigo_gimnasio'] ?? null,
            'service_provider_plan_code' => $credenciales['codigo_plan'] ?? null,
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        try {
            $respuesta = Http::withHeaders(['x-api-key' => $llave])
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post(rtrim((string) config('agendauno.integraciones.totalpass_url'), '/').'/service/v1/track_usages', [
                    'data' => ['attributes' => $atributos],
                ]);
        } catch (Throwable) {
            return ResultadoCheckin::invalido('No se pudo contactar a TotalPass. Intenta de nuevo.');
        }

        $referencia = 'totalpass:'.$token.':'.now()->toDateString();
        $etiqueta = (string) ($respuesta->json('errors.0.label') ?? $respuesta->json('label') ?? '');
        // Ya usado aquí: es la misma visita (se valida una sola vez).
        if ($respuesta->successful() || $etiqueta === 'already_used') {
            return ResultadoCheckin::valido($referencia, null);
        }
        if (in_array($respuesta->status(), [401, 403], true)) {
            return ResultadoCheckin::invalido('TotalPass no aceptó la llave de API de la integración.');
        }

        return ResultadoCheckin::invalido(self::MOTIVOS[$etiqueta] ?? 'TotalPass no validó la visita.');
    }
}
