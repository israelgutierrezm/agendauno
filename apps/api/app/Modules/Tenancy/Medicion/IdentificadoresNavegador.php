<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Las preferencias de medición de quien convierte y los identificadores de su
 * navegador (ADR 0143), como llegan con un registro (`medicion`) y como se guardan en
 * su contexto. Solo se guarda lo que permitió:
 *
 * - con analítica: el `client_id` y la sesión de GA4;
 * - con publicidad: `fbp`, `fbc` (o el que se arma con el `fbclid` del último toque,
 *   como indica Meta), el agente del navegador y la IP;
 * - siempre: el id de visitante (aleatorio, por ámbito; con él se aplica después lo que
 *   cambie en sus preferencias) y la página, sin su query.
 *
 * Nada se inventa: un valor con otra forma se descarta.
 */
final class IdentificadoresNavegador
{
    public const COLUMNAS = [
        'visitante', 'analitica', 'publicidad', 'consentimiento_en', 'ga_client_id', 'ga_session_id',
        'fbp', 'fbc', 'agente', 'ip', 'url', 'capturado_en',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function reglas(string $campo = 'medicion'): array
    {
        return [
            $campo => ['nullable', 'array'],
            "{$campo}.visitante" => ['nullable', 'string', 'max:64'],
            "{$campo}.analitica" => ['nullable', 'boolean'],
            "{$campo}.publicidad" => ['nullable', 'boolean'],
            "{$campo}.ga_client_id" => ['nullable', 'string', 'max:64'],
            "{$campo}.ga_session_id" => ['nullable', 'string', 'max:32'],
            "{$campo}.fbp" => ['nullable', 'string', 'max:100'],
            "{$campo}.fbc" => ['nullable', 'string', 'max:600'],
            "{$campo}.url" => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Las columnas que se guardan, o null si no llegó nada de medición.
     *
     * @return array<string, mixed>|null
     */
    public static function desdeEntrada(mixed $medicion, mixed $atribucion, ?string $ip, ?string $agente, CarbonImmutable $ahora): ?array
    {
        if (! is_array($medicion)) {
            return null;
        }
        $analitica = ($medicion['analitica'] ?? false) === true;
        $publicidad = ($medicion['publicidad'] ?? false) === true;
        $texto = static fn (string $clave, string $patron): ?string => is_string($medicion[$clave] ?? null) && preg_match($patron, $medicion[$clave]) === 1
            ? $medicion[$clave]
            : null;

        $fbc = $publicidad ? ($texto('fbc', '/^fb\.\d\.\d{10,16}\.[A-Za-z0-9_-]{8,500}$/') ?? self::fbcDeClic($atribucion)) : null;

        return [
            'visitante' => self::visitante($medicion['visitante'] ?? null),
            'analitica' => $analitica,
            'publicidad' => $publicidad,
            'consentimiento_en' => $ahora,
            'ga_client_id' => $analitica ? $texto('ga_client_id', '/^\d{1,20}\.\d{1,20}$/') : null,
            'ga_session_id' => $analitica ? $texto('ga_session_id', '/^\d{1,20}$/') : null,
            'fbp' => $publicidad ? $texto('fbp', '/^fb\.\d\.\d{10,16}\.\d{1,25}$/') : null,
            'fbc' => $fbc,
            'agente' => $publicidad && $agente !== null && $agente !== '' ? Str::limit($agente, 400, '') : null,
            'ip' => $publicidad && $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            'url' => self::url($medicion['url'] ?? null),
            'capturado_en' => $ahora,
        ];
    }

    /** Id de visitante: 32 caracteres hexadecimales que genera el navegador. */
    public static function visitante(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^[a-f0-9]{32}$/', $valor) === 1 ? $valor : null;
    }

    /**
     * Lo que se borra al retirar una autorización (y lo que queda).
     *
     * @return array<string, mixed>
     */
    public static function alCambiar(bool $analitica, bool $publicidad, CarbonImmutable $ahora): array
    {
        return [
            'analitica' => $analitica,
            'publicidad' => $publicidad,
            'consentimiento_en' => $ahora,
            ...($analitica ? [] : ['ga_client_id' => null, 'ga_session_id' => null]),
            ...($publicidad ? [] : ['fbp' => null, 'fbc' => null, 'agente' => null, 'ip' => null]),
        ];
    }

    /**
     * Pasado el tiempo de retención, los identificadores del navegador se borran; las
     * preferencias y la campaña quedan.
     *
     * @return array<string, null>
     */
    public static function alVencer(): array
    {
        return ['ga_client_id' => null, 'ga_session_id' => null, 'fbp' => null, 'fbc' => null, 'agente' => null, 'ip' => null];
    }

    public static function contexto(Model $m): ContextoConversion
    {
        $capturado = $m->getAttribute('capturado_en');

        return new ContextoConversion(
            visitante: $m->getAttribute('visitante'),
            analitica: (bool) $m->getAttribute('analitica'),
            publicidad: (bool) $m->getAttribute('publicidad'),
            gaClientId: $m->getAttribute('ga_client_id'),
            gaSessionId: $m->getAttribute('ga_session_id'),
            fbp: $m->getAttribute('fbp'),
            fbc: $m->getAttribute('fbc'),
            agente: $m->getAttribute('agente'),
            ip: $m->getAttribute('ip'),
            url: $m->getAttribute('url'),
            capturadoEn: $capturado instanceof Carbon ? $capturado->toImmutable() : null,
        );
    }

    /** La página donde convirtió: esquema, host y ruta (sin query ni ancla). */
    private static function url(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $partes = parse_url($valor);
        if (! is_array($partes) || ! in_array($partes['scheme'] ?? '', ['http', 'https'], true)
            || Atribucion::host($partes['host'] ?? null) === null && ($partes['host'] ?? '') !== 'localhost') {
            return null;
        }
        $puerto = isset($partes['port']) ? ':'.$partes['port'] : '';

        return Str::limit("{$partes['scheme']}://{$partes['host']}{$puerto}".($partes['path'] ?? '/'), 300, '');
    }

    /**
     * El `fbc` que indica Meta cuando llegó con `fbclid` y no hay cookie: `fb.1.{ms en
     * que llegó}.{fbclid}`, con el `fbclid` tal cual.
     */
    private static function fbcDeClic(mixed $atribucion): ?string
    {
        $toque = is_array($atribucion) && is_array($atribucion['ultimo_toque'] ?? null) ? $atribucion['ultimo_toque'] : null;
        $fbclid = $toque['fbclid'] ?? null;
        $en = $toque['en'] ?? null;
        if (! is_string($fbclid) || preg_match('/^[A-Za-z0-9_-]{8,500}$/', $fbclid) !== 1 || ! is_string($en)) {
            return null;
        }
        try {
            $ms = CarbonImmutable::parse($en)->getTimestampMs();
        } catch (\Throwable) {
            return null;
        }

        return "fb.1.{$ms}.{$fbclid}";
    }
}
