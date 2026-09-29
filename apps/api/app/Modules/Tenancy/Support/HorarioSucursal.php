<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use Illuminate\Validation\ValidationException;

/**
 * El horario de atención de una sucursal que ve el público: por día, a qué hora abre
 * y cierra (el día que no aparece, cerrado). Lo captura el negocio; si no lo ha
 * capturado y atiende con citas, se toma del horario de sus profesionales en esa
 * sucursal (lo más temprano que alguien empieza y lo más tarde que alguien termina).
 */
final class HorarioSucursal
{
    /**
     * @return array<string, list<string>>
     */
    public static function reglas(string $campo = 'horario'): array
    {
        return [
            $campo => ['nullable', 'array', 'max:7'],
            "{$campo}.*.dia" => ['required', 'integer', 'between:1,7', 'distinct'],
            "{$campo}.*.abre" => ['required', 'date_format:H:i'],
            "{$campo}.*.cierra" => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * Ordenado por día; null si queda vacío (sin horario capturado).
     *
     * @param  list<array<string, mixed>>|null  $dias
     * @return list<array{dia: int, abre: string, cierra: string}>|null
     *
     * @throws ValidationException
     */
    public static function normalizar(?array $dias, string $campo = 'horario'): ?array
    {
        $horario = [];
        foreach ($dias ?? [] as $i => $d) {
            $abre = (string) $d['abre'];
            $cierra = (string) $d['cierra'];
            if ($cierra <= $abre) {
                throw ValidationException::withMessages([
                    "{$campo}.{$i}.cierra" => ['La hora de cierre debe ser después de la de apertura.'],
                ]);
            }
            $horario[] = ['dia' => (int) $d['dia'], 'abre' => $abre, 'cierra' => $cierra];
        }
        usort($horario, static fn (array $a, array $b): int => $a['dia'] <=> $b['dia']);

        return $horario === [] ? null : $horario;
    }

    /**
     * El que se publica: el capturado o, si no hay, el de sus profesionales. Null si
     * no hay ni uno ni otro (no se muestra horario).
     *
     * @return list<array{dia: int, abre: string, cierra: string}>|null
     */
    public static function publico(SucursalTenant $sucursal): ?array
    {
        if (is_array($sucursal->horario) && $sucursal->horario !== []) {
            return self::normalizar($sucursal->horario);
        }

        $porDia = [];
        HorarioAtencionTenant::query()
            ->where('sucursal_id', $sucursal->getKey())
            ->get(['dia_semana', 'hora_inicio', 'hora_fin'])
            ->each(function (HorarioAtencionTenant $h) use (&$porDia): void {
                $dia = (int) $h->dia_semana;
                $actual = $porDia[$dia] ?? ['dia' => $dia, 'abre' => $h->hora_inicio, 'cierra' => $h->hora_fin];
                $porDia[$dia] = [
                    'dia' => $dia,
                    'abre' => min($actual['abre'], $h->hora_inicio),
                    'cierra' => max($actual['cierra'], $h->hora_fin),
                ];
            });
        ksort($porDia);

        return $porDia === [] ? null : array_values($porDia);
    }
}
