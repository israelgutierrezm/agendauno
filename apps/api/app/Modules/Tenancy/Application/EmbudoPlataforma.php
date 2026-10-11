<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ProductoComercial;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El embudo de la plataforma (plan de mejoras C08), separado del de cada negocio: de la
 * visita a la landing al primer pago de la renta, por producto. La cohorte son los
 * negocios que se registraron en el periodo, sin los demos; cada paso dice cuántos de
 * ellos llegaron (con su denominador) y la mediana de días hasta publicar y operar.
 */
class EmbudoPlataforma
{
    /**
     * Cuenta una visita a la landing de un producto: una por visitante y día (huella
     * pasajera en la caché), sin guardar quién.
     */
    public function registrarVisita(ProductoComercial $producto, ?string $ip, ?string $navegador): void
    {
        $dia = CarbonImmutable::now()->toDateString();
        $huella = hash('sha256', implode('|', [$producto->value, $dia, (string) $ip, (string) $navegador]));
        if (! Cache::add("visita-landing:{$huella}", 1, now()->addDay())) {
            return;
        }

        $sumar = fn (): int => DB::table('visitas_landing')
            ->where('fecha', $dia)->where('producto', $producto->value)
            ->update(['visitas' => DB::raw('visitas + 1'), 'updated_at' => now()]);
        if ($sumar() > 0) {
            return;
        }
        try {
            DB::table('visitas_landing')->insert([
                'fecha' => $dia, 'producto' => $producto->value, 'visitas' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $sumar();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calcular(string $desde, string $hasta): array
    {
        $inicio = CarbonImmutable::parse($desde)->startOfDay();
        $fin = CarbonImmutable::parse($hasta)->endOfDay();
        $cohorte = Estudio::query()
            ->where('es_demo', false)
            ->whereBetween('created_at', [$inicio, $fin])
            ->get();
        $pagaron = CargoRenta::query()
            ->whereIn('estudio_id', $cohorte->modelKeys())
            ->where('estado', EstadoCargoRenta::Pagado->value)
            ->distinct()
            ->pluck('estudio_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $filas = [];
        foreach (ProductoComercial::cases() as $producto) {
            $suyos = $cohorte->filter(static fn (Estudio $e): bool => $e->producto() === $producto)->values();
            $filas[] = [
                'producto' => $producto->value,
                'visitas_landing' => (int) DB::table('visitas_landing')
                    ->where('producto', $producto->value)
                    ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
                    ->sum('visitas'),
                'registros' => $suyos->count(),
                // Activó su cuenta (quedó aprovisionado).
                'verificados' => $suyos->filter(static fn (Estudio $e): bool => $e->aprovisionado_en !== null)->count(),
                'publicados' => $suyos->filter(static fn (Estudio $e): bool => $e->publicado_en !== null)->count(),
                'primera_operacion' => $suyos->filter(static fn (Estudio $e): bool => $e->primera_operacion_en !== null)->count(),
                'primer_pago' => $suyos->filter(static fn (Estudio $e): bool => in_array((int) $e->getKey(), $pagaron, true))->count(),
                'dias_a_publicar' => self::mediana($suyos->map(static fn (Estudio $e): ?float => $e->publicado_en !== null && $e->created_at !== null
                    ? (float) $e->created_at->diffInDays($e->publicado_en) : null)->filter(static fn (?float $d): bool => $d !== null)->values()->all()),
                'dias_a_operar' => self::mediana($suyos->map(static fn (Estudio $e): ?float => $e->primera_operacion_en !== null && $e->created_at !== null
                    ? (float) $e->created_at->diffInDays($e->primera_operacion_en) : null)->filter(static fn (?float $d): bool => $d !== null)->values()->all()),
            ];
        }

        return $filas;
    }

    /**
     * @param  list<float|null>  $valores
     */
    private static function mediana(array $valores): ?float
    {
        $valores = array_values(array_filter($valores, static fn (?float $v): bool => $v !== null));
        sort($valores);
        $n = count($valores);
        if ($n === 0) {
            return null;
        }
        $medio = intdiv($n, 2);

        return round($n % 2 === 1 ? $valores[$medio] : ($valores[$medio - 1] + $valores[$medio]) / 2, 1);
    }
}
