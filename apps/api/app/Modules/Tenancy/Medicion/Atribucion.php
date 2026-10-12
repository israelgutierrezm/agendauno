<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * De dónde llegó quien hizo una solicitud, una compra, una cita o el registro de un
 * negocio (ADR 0142): su primer origen y su último toque de campaña, como los anotó el
 * navegador. Aquí se vuelve a validar todo (nada del navegador se cree tal cual) y se
 * aplica la ventana configurada: un toque más viejo no cuenta y, sin uno vigente, el
 * registro queda «sin atribución».
 *
 * Reglas propias, no las de Google ni las de Meta: la campaña que se acredita es la
 * del último toque vigente al momento del registro.
 */
final readonly class Atribucion
{
    /** Los campos de un toque y su largo máximo. */
    public const CAMPOS = [
        'fuente' => 100,
        'medio' => 100,
        'campana' => 100,
        'contenido' => 100,
        'termino' => 100,
    ];

    /**
     * @param  array<string, string>|null  $primerToque
     * @param  array<string, string>|null  $ultimoToque
     */
    private function __construct(
        public ?array $primerToque,
        public ?array $ultimoToque,
    ) {}

    /**
     * Reglas para validar la atribución que llega con una solicitud. Solo forma y largo:
     * el contenido se revisa en `desdeEntrada`, que descarta lo que no sirve sin hacer
     * fallar el registro (la medición nunca impide registrarse, comprar ni agendar).
     *
     * @return array<string, list<string>>
     */
    public static function reglas(string $campo = 'atribucion'): array
    {
        $reglas = [$campo => ['nullable', 'array:primer_toque,ultimo_toque']];
        foreach (['primer_toque', 'ultimo_toque'] as $toque) {
            $reglas["{$campo}.{$toque}"] = ['nullable', 'array'];
            foreach ([...array_keys(self::CAMPOS), 'referente', 'en'] as $clave) {
                $reglas["{$campo}.{$toque}.{$clave}"] = ['nullable', 'string', 'max:200'];
            }
            // Identificadores de clic: solo llegan con la autorización de publicidad
            // (ADR 0143) y solo se usan para armar el `fbc` de Meta.
            foreach (['gclid', 'fbclid'] as $clic) {
                $reglas["{$campo}.{$toque}.{$clic}"] = ['nullable', 'string', 'max:500'];
            }
        }

        return $reglas;
    }

    /**
     * La atribución que vale, o `null` si no llegó nada utilizable.
     *
     * @param  int  $diasPrimer  ventana del primer origen
     * @param  int  $diasUltimo  ventana del último toque
     */
    public static function desdeEntrada(mixed $entrada, CarbonImmutable $ahora, int $diasPrimer, int $diasUltimo): ?self
    {
        if (! is_array($entrada)) {
            return null;
        }
        $primer = self::toque($entrada['primer_toque'] ?? null, $ahora, $diasPrimer);
        $ultimo = self::toque($entrada['ultimo_toque'] ?? null, $ahora, $diasUltimo);

        return $primer === null && $ultimo === null ? null : new self($primer, $ultimo);
    }

    /**
     * Lo que se acredita: la campaña del último toque vigente. Sin él, nada («sin
     * atribución»), aunque haya primer origen.
     *
     * @return array{fuente: string|null, medio: string|null, campana: string|null, referente: string|null}
     */
    public function acreditada(): array
    {
        return [
            'fuente' => $this->ultimoToque['fuente'] ?? null,
            'medio' => $this->ultimoToque['medio'] ?? null,
            'campana' => $this->ultimoToque['campana'] ?? null,
            'referente' => $this->ultimoToque['referente'] ?? null,
        ];
    }

    /** Cuándo llegó el último toque (o el primero, si es el único). */
    public function capturadaEn(): ?CarbonImmutable
    {
        $en = $this->ultimoToque['en'] ?? $this->primerToque['en'] ?? null;

        return $en !== null ? CarbonImmutable::parse($en) : null;
    }

    /**
     * Un valor de campaña que se puede guardar: texto corto y común. Lo que parece un
     * dato personal (un correo, un teléfono, un número largo) o trae caracteres raros
     * se descarta.
     */
    public static function valorDeCampana(mixed $valor, int $maximo = 100): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $limpio = trim($valor);
        if ($limpio === '' || mb_strlen($limpio) > $maximo || str_contains($limpio, '@')) {
            return null;
        }
        if (preg_match('/\d{7,}/', (string) preg_replace('/[\s().+-]/', '', $limpio)) === 1) {
            return null;
        }

        return preg_match('/^[\p{L}\p{N} ._\-+:\/|()]+$/u', $limpio) === 1 ? $limpio : null;
    }

    /** El host de un sitio (sin ruta, usuario ni puerto). */
    public static function host(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $host = mb_strtolower(trim($valor));

        return preg_match('/^(?=.{1,120}$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $host) === 1
            ? $host
            : null;
    }

    /**
     * @return array<string, string>|null
     */
    private static function toque(mixed $entrada, CarbonImmutable $ahora, int $dias): ?array
    {
        if (! is_array($entrada) || ! is_string($entrada['en'] ?? null)) {
            return null;
        }
        try {
            $en = CarbonImmutable::parse($entrada['en'])->utc();
        } catch (Throwable) {
            return null;
        }
        // Del futuro (un reloj adelantado de más) o fuera de la ventana: no cuenta.
        if ($en->greaterThan($ahora->addMinutes(5)) || $en->lessThan($ahora->subDays($dias))) {
            return null;
        }

        $toque = [];
        foreach (self::CAMPOS as $campo => $maximo) {
            $valor = self::valorDeCampana($entrada[$campo] ?? null, $maximo);
            if ($valor !== null) {
                $toque[$campo] = $valor;
            }
        }
        $referente = self::host($entrada['referente'] ?? null);
        if ($referente !== null) {
            $toque['referente'] = $referente;
        }
        // Un toque es una campaña (fuente o campaña) o un sitio que trajo la visita.
        if (! isset($toque['fuente']) && ! isset($toque['campana']) && ! isset($toque['referente'])) {
            return null;
        }
        $toque['en'] = $en->toIso8601ZuluString();

        return $toque;
    }
}
