<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El clima del Inicio del alumno o cliente (como el de Acadion).
 *
 * ── De dónde sale, en ese orden ────────────────────────────────────────────
 * 1. Si tiene una clase o cita próxima y su sucursal tiene coordenadas: el
 *    PRONÓSTICO para esa hora en esa sucursal ("para tu clase").
 * 2. Si no: el clima de AHORA según su IP pública (aproximado: la ciudad de su
 *    red). La IP viaja a un tercero (ip-api.com, solo HTTP): se consulta solo
 *    en este caso y nunca para IPs privadas.
 * 3. Si la IP no sirve (red privada, desarrollo, falla): el de ahora en la
 *    primera sucursal con coordenadas.
 *
 * ── Nunca rompe el Inicio ──────────────────────────────────────────────────
 * Es un adorno útil: si Open-Meteo o la geolocalización tardan o fallan, se
 * devuelve null y la tarjeta simplemente no lo muestra. Lo que falla no se
 * guarda en caché (el siguiente reintenta); lo que sale bien, media hora por
 * ubicación redondeada (todo un local comparte la consulta).
 */
class ClimaTenant
{
    private const MINUTOS_CACHE = 30;

    /** Corto a propósito: nada de esto vale hacer esperar a nadie. */
    private const SEGUNDOS_ESPERA = 4;

    /** Hasta dónde hay pronóstico por hora que valga la pena mostrar. */
    private const DIAS_PRONOSTICO = 14;

    /**
     * @return array<string, mixed>|null
     */
    public function paraMiembro(?PersonaTenant $persona, ?string $ip): ?array
    {
        $proxima = $persona === null ? null : $this->proximaSesion($persona);
        $sucursal = $proxima?->sucursal;

        if ($proxima !== null && $this->tieneUbicacion($sucursal)
            && $proxima->inicia_en->lessThanOrEqualTo(CarbonImmutable::now()->addDays(self::DIAS_PRONOSTICO))) {
            $pronostico = $this->pronostico((float) $sucursal->latitud, (float) $sucursal->longitud, CarbonImmutable::instance($proxima->inicia_en), (string) $proxima->zona_horaria);
            if ($pronostico !== null) {
                return [...$pronostico, 'tipo' => 'pronostico', 'lugar' => $sucursal->nombre, 'aproximado' => false];
            }
        }

        $porIp = $this->ubicacionPorIp($ip);
        if ($porIp !== null) {
            $ahora = $this->ahora($porIp['latitud'], $porIp['longitud']);
            if ($ahora !== null) {
                return [...$ahora, 'tipo' => 'ahora', 'lugar' => $porIp['ciudad'], 'aproximado' => true];
            }
        }

        $sede = $this->tieneUbicacion($sucursal) ? $sucursal
            : SucursalTenant::query()->whereNotNull('latitud')->whereNotNull('longitud')->orderBy('id')->first();
        if ($sede instanceof SucursalTenant) {
            $ahora = $this->ahora((float) $sede->latitud, (float) $sede->longitud);
            if ($ahora !== null) {
                return [...$ahora, 'tipo' => 'ahora', 'lugar' => $sede->nombre, 'aproximado' => false];
            }
        }

        return null;
    }

    private function proximaSesion(PersonaTenant $persona): ?SesionTenant
    {
        $reserva = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value])
            ->whereHas('sesion', fn ($q) => $q->where('estado', EstadoSesionTenant::Programada->value)->where('inicia_en', '>=', CarbonImmutable::now()))
            ->with('sesion.sucursal')
            ->orderBy(SesionTenant::query()->select('inicia_en')->whereColumn('sesiones.id', 'reservas.sesion_id'))
            ->first();

        return $reserva?->sesion;
    }

    private function tieneUbicacion(?SucursalTenant $sucursal): bool
    {
        return $sucursal !== null && $sucursal->latitud !== null && $sucursal->longitud !== null;
    }

    /**
     * El pronóstico para una hora (la de la clase o cita), en la zona de la sede.
     *
     * @return array<string, mixed>|null
     */
    private function pronostico(float $latitud, float $longitud, CarbonImmutable $momento, string $zona): ?array
    {
        $local = $momento->setTimezone($zona);
        $llave = sprintf('clima:pronostico:%.3f:%.3f:%s', $latitud, $longitud, $local->format('Y-m-d\TH'));

        return $this->recordar($llave, function () use ($latitud, $longitud, $local, $zona): ?array {
            $r = Http::timeout(self::SEGUNDOS_ESPERA)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $latitud,
                'longitude' => $longitud,
                'hourly' => 'temperature_2m,weather_code,precipitation_probability,is_day',
                'timezone' => $zona,
                'start_date' => $local->toDateString(),
                'end_date' => $local->toDateString(),
            ]);
            if (! $r->successful()) {
                return null;
            }
            $horas = (array) $r->json('hourly.time', []);
            $i = array_search($local->format('Y-m-d\TH:00'), $horas, true);
            if ($i === false) {
                return null;
            }

            return $this->presentar(
                (float) $r->json("hourly.temperature_2m.{$i}"),
                (int) $r->json("hourly.weather_code.{$i}"),
                (bool) $r->json("hourly.is_day.{$i}", true),
                $r->json("hourly.precipitation_probability.{$i}"),
            ) + ['para' => $local->toIso8601String()];
        });
    }

    /**
     * El clima de ahora en unas coordenadas.
     *
     * @return array<string, mixed>|null
     */
    private function ahora(float $latitud, float $longitud): ?array
    {
        $llave = sprintf('clima:ahora:%.3f:%.3f', $latitud, $longitud);

        return $this->recordar($llave, function () use ($latitud, $longitud): ?array {
            $r = Http::timeout(self::SEGUNDOS_ESPERA)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $latitud,
                'longitude' => $longitud,
                'current' => 'temperature_2m,weather_code,is_day',
                'timezone' => 'auto',
            ]);
            if (! $r->successful() || $r->json('current') === null) {
                return null;
            }

            return $this->presentar(
                (float) $r->json('current.temperature_2m'),
                (int) $r->json('current.weather_code'),
                (bool) $r->json('current.is_day', true),
                null,
            );
        });
    }

    /**
     * Dónde está su red, por la IP. Las IPs privadas ni se intentan.
     *
     * @return array{latitud: float, longitud: float, ciudad: string}|null
     */
    private function ubicacionPorIp(?string $ip): ?array
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        // La ubicación de una IP no se mueve como el clima: seis horas.
        $lugar = $this->recordar("clima:ip:{$ip}", function () use ($ip): ?array {
            $r = Http::timeout(self::SEGUNDOS_ESPERA)->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,city,lat,lon']);
            if (! $r->successful() || $r->json('status') !== 'success') {
                return null;
            }

            return [
                'latitud' => (float) $r->json('lat'),
                'longitud' => (float) $r->json('lon'),
                'ciudad' => (string) ($r->json('city') ?? ''),
            ];
        }, 6 * 60);

        return $lugar === null ? null : [
            'latitud' => (float) $lugar['latitud'],
            'longitud' => (float) $lugar['longitud'],
            'ciudad' => (string) $lugar['ciudad'],
        ];
    }

    /**
     * Lo que se pinta: temperatura redondeada, el estado en palabras y qué dibujo
     * lleva (la vista no tiene por qué saber qué es un código WMO).
     *
     * @return array{temperatura: int, condicion: string, icono: string, es_de_dia: bool, lluvia: int|null}
     */
    private function presentar(float $temperatura, int $codigo, bool $esDeDia, mixed $lluvia): array
    {
        [$icono, $condicion] = match (true) {
            $codigo === 0 => ['despejado', 'Despejado'],
            $codigo <= 2 => ['parcial', 'Parcialmente nublado'],
            $codigo === 3 => ['nublado', 'Nublado'],
            $codigo <= 48 => ['niebla', 'Neblina'],
            $codigo <= 57 => ['llovizna', 'Llovizna'],
            $codigo <= 67, $codigo >= 80 && $codigo <= 82 => ['lluvia', 'Lluvia'],
            $codigo <= 77, $codigo <= 86 => ['nieve', 'Nieve'],
            default => ['tormenta', 'Tormenta eléctrica'],
        };

        return [
            'temperatura' => (int) round($temperatura),
            'condicion' => $condicion,
            'icono' => $icono,
            'es_de_dia' => $esDeDia,
            'lluvia' => is_numeric($lluvia) ? (int) $lluvia : null,
        ];
    }

    /**
     * Trae y recuerda, salvo que falle (el fallo no se guarda: el siguiente reintenta).
     *
     * @param  \Closure(): (array<string, mixed>|null)  $traer
     * @return array<string, mixed>|null
     */
    private function recordar(string $llave, \Closure $traer, int $minutos = self::MINUTOS_CACHE): ?array
    {
        try {
            $guardado = Cache::remember($llave, now()->addMinutes($minutos), $traer);
            $valor = is_array($guardado) ? $guardado : null;
        } catch (\Throwable $e) {
            Log::info('No se pudo consultar el clima: '.$e->getMessage());
            $valor = null;
        }
        if ($valor === null) {
            Cache::forget($llave);
        }

        return $valor;
    }
}
