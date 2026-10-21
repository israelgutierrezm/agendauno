<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Valida la visita de un usuario de TotalPass con su API de uso
 * (`POST {base}/track_usages`, cabecera `x-api-key`; la base es
 * `https://api.totalpass.com/service/v1` y en pruebas `https://staging.totalpass.com/api/v1`).
 * El usuario muestra en su app el token del día, su CURP o su código de beneficiario y
 * el negocio lo valida (se consume al validarse). Con el código del gimnasio y, si tiene
 * varios planes, el del plan. Del identificador solo se guarda su huella. Sin conexión,
 * un error de su lado (5xx, 429) o la llave rechazada (401/403) dejan la visita no
 * verificable, nunca aprobada (M10).
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
        'inactive_employee' => 'La cuenta de TotalPass de esta persona no está activa.',
        'check_in_started_on_different_gym' => 'La persona hizo check-in para otro gimnasio en la app de TotalPass.',
        'gym_not_found' => 'TotalPass no encontró tu gimnasio: revisa su código en la integración.',
    ];

    public function __construct(private readonly FechasNegocioTenant $fechas) {}

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
        $tipo = self::tipo($token);

        $atributos = array_filter([
            'type' => $tipo,
            'identifier' => $tipo === 'token' ? $token : strtoupper($token),
            'service_provider_code' => $credenciales['codigo_gimnasio'] ?? null,
            'service_provider_plan_code' => $credenciales['codigo_plan'] ?? null,
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        try {
            $respuesta = Http::withHeaders(['x-api-key' => $llave])
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post(self::base().'/track_usages', [
                    'data' => ['attributes' => $atributos],
                ]);
        } catch (Throwable) {
            return ResultadoCheckin::noVerificable('No se pudo contactar a TotalPass: no respondió a tiempo o no hubo conexión.');
        }

        // Se guarda su huella, no el token ni la CURP. Una visita por persona y día.
        $referencia = 'totalpass:'.substr(hash('sha256', $tipo === 'token' ? $token : $tipo.':'.strtoupper($token)), 0, 40).':'.$this->fechas->hoy();
        $etiqueta = ClienteTotalPass::etiqueta($respuesta);
        // Ya usado aquí: es la misma visita (se valida una sola vez).
        if ($respuesta->successful() || $etiqueta === 'already_used') {
            return ResultadoCheckin::valido($referencia, null);
        }
        if (in_array($respuesta->status(), [401, 403], true)) {
            return ResultadoCheckin::noVerificable('TotalPass no aceptó la llave de API de la integración: revisa las credenciales.');
        }
        if ($respuesta->status() === 429 || $respuesta->serverError()) {
            return ResultadoCheckin::noVerificable('TotalPass no está respondiendo (error de su lado). Intenta de nuevo en unos minutos.');
        }

        return ResultadoCheckin::invalido(self::MOTIVOS[$etiqueta] ?? 'TotalPass no validó la visita.');
    }

    /**
     * Qué mostró la persona: su CURP (18 caracteres con su forma), su código de
     * beneficiario (8 letras y números) o el token del día (los demás).
     */
    private static function tipo(string $identificador): string
    {
        if (preg_match('/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/i', $identificador) === 1) {
            return 'curp';
        }

        return preg_match('/^[A-Z0-9]{8}$/i', $identificador) === 1 ? 'code' : 'token';
    }

    /** La base de su API de uso, con la versión (una base vieja sin ella se completa). */
    private static function base(): string
    {
        $base = rtrim((string) config('agendauno.integraciones.totalpass_url'), '/');

        return preg_match('#/v\d+$#', $base) === 1 ? $base : $base.'/service/v1';
    }
}
