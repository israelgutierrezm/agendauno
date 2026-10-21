<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use Illuminate\Support\Collection;

/**
 * Avisos de coherencia del catálogo (antes de abrir; comparativo del 2026-10-20): los
 * errores que vimos en estudios reales y que nadie nota hasta que un cliente se queja.
 * No bloquean nada: se muestran al guardar un plan y en la lista de planes.
 *
 * - Un paquete más grande sale más caro por clase que uno más chico (del mismo alcance).
 * - El nombre o la descripción dicen «ilimitado», pero el plan tiene clases contadas.
 * - El nombre o la descripción dicen N clases y el plan da otras.
 * - La descripción habla de cancelación o inasistencia, pero la regla no penaliza nada.
 *
 * @phpstan-type Aviso array{codigo: string, producto_id: string, mensaje: string}
 */
class CoherenciaCatalogoTenant
{
    /**
     * Los avisos de todo el catálogo a la venta o, con `$producto`, solo los suyos.
     *
     * @return list<Aviso>
     */
    public function revisar(?ProductoTenant $producto = null): array
    {
        $activos = ProductoTenant::query()->where('archivado', false)->with('ofertas:id')->orderBy('id')->get();
        $avisos = [];
        foreach ($this->preciosQueSuben($activos) as $aviso) {
            $avisos[] = $aviso;
        }
        $penaliza = $this->reglaPenaliza();
        foreach ($activos as $p) {
            foreach ($this->delTexto($p, $penaliza) as $aviso) {
                $avisos[] = $aviso;
            }
        }

        if ($producto !== null) {
            $avisos = array_values(array_filter($avisos, static fn (array $a): bool => $a['producto_id'] === $producto->ulid));
        }

        return $avisos;
    }

    /**
     * Entre los planes con clases contadas del mismo alcance (actividad, sedes, clases),
     * el más grande no debería salir más caro por clase. La clase de prueba no cuenta:
     * es barata a propósito.
     *
     * @param  Collection<int, ProductoTenant>  $productos
     * @return list<Aviso>
     */
    private function preciosQueSuben(Collection $productos): array
    {
        $contados = $productos->filter(static fn (ProductoTenant $p): bool => ! $p->ilimitado
            && ! $p->primera_vez
            && (int) $p->creditos_incluidos >= 1000
            && (int) $p->precio_minor > 0);
        $avisos = [];
        foreach ($contados->groupBy(fn (ProductoTenant $p): string => self::alcance($p)) as $grupo) {
            $ordenados = $grupo->sortBy([['creditos_incluidos', 'asc'], ['precio_minor', 'asc']])->values();
            for ($i = 1; $i < $ordenados->count(); $i++) {
                /** @var ProductoTenant $grande */
                $grande = $ordenados[$i];
                // El más barato por clase entre los más chicos.
                $chico = $ordenados->slice(0, $i)
                    ->filter(static fn (ProductoTenant $p): bool => (int) $p->creditos_incluidos < (int) $grande->creditos_incluidos)
                    ->reduce(static fn (?ProductoTenant $min, ProductoTenant $p): ProductoTenant => $min === null || self::masCaroPorClase($min, $p) ? $p : $min);
                if (! $chico instanceof ProductoTenant || ! self::masCaroPorClase($grande, $chico)) {
                    continue;
                }
                $moneda = (string) $grande->moneda;
                $avisos[] = [
                    'codigo' => 'precio_por_clase_sube',
                    'producto_id' => (string) $grande->ulid,
                    'mensaje' => "«{$grande->nombre}» sale a ".DatosDeOrden::dinero(self::porClaseMinor($grande), $moneda)
                        .' por clase, más que «'.$chico->nombre.'» ('.DatosDeOrden::dinero(self::porClaseMinor($chico), $moneda)
                        .'). Un paquete más grande suele salir más barato por clase.',
                ];
            }
        }

        return $avisos;
    }

    /**
     * @return list<Aviso>
     */
    private function delTexto(ProductoTenant $p, bool $penaliza): array
    {
        $texto = mb_strtolower(trim($p->nombre.' '.($p->descripcion ?? '')));
        $avisos = [];
        $clases = intdiv((int) $p->creditos_incluidos, 1000);

        if (! $p->ilimitado && str_contains($texto, 'ilimitad')) {
            $avisos[] = [
                'codigo' => 'ilimitado_con_tope',
                'producto_id' => (string) $p->ulid,
                'mensaje' => "«{$p->nombre}» dice ilimitado, pero da {$clases} ".($clases === 1 ? 'clase' : 'clases').'. Márcalo como ilimitado o cambia el nombre.',
            ];
        } elseif (! $p->ilimitado && preg_match('/(\d{1,3})\s*(clases|clase|sesiones|sesión|sesion|créditos|creditos|visitas)\b/u', $texto, $m) === 1
            && (int) $m[1] !== $clases && (int) $p->creditos_incluidos % 1000 === 0) {
            $avisos[] = [
                'codigo' => 'clases_no_cuadran',
                'producto_id' => (string) $p->ulid,
                'mensaje' => "«{$p->nombre}» dice {$m[1]} {$m[2]}, pero da {$clases}. Corrige el nombre o las clases que incluye.",
            ];
        }

        if (! $penaliza && preg_match('/cancela|inasistencia|no asist|no llegas|pierdes|se pierde/u', $texto) === 1) {
            $avisos[] = [
                'codigo' => 'politica_apagada',
                'producto_id' => (string) $p->ulid,
                'mensaje' => "La descripción de «{$p->nombre}» habla de cancelaciones o inasistencias, pero tus reglas no penalizan nada. Actívalas en Reglas de la agenda o quita esa frase.",
            ];
        }

        return $avisos;
    }

    /** ¿La regla general (o alguna por actividad) penaliza cancelar tarde o no llegar? */
    private function reglaPenaliza(): bool
    {
        return PoliticaCancelacionTenant::query()
            ->where(fn ($q) => $q->where('penaliza_tarde', true)->orWhere('penaliza_no_show', true))
            ->exists();
    }

    private static function alcance(ProductoTenant $p): string
    {
        $sedes = is_array($p->sucursales_ids) ? $p->sucursales_ids : [];
        sort($sedes);
        $ofertas = $p->ofertas->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();

        return implode('|', [
            (string) $p->moneda,
            (string) ($p->actividad_id ?? ''),
            (string) ($p->sucursal_id ?? ''),
            implode(',', $sedes),
            implode(',', $ofertas),
        ]);
    }

    /** ¿`$a` sale más caro por clase que `$b`? (en enteros: precio_a × clases_b > precio_b × clases_a) */
    private static function masCaroPorClase(ProductoTenant $a, ProductoTenant $b): bool
    {
        return (int) $a->precio_minor * (int) $b->creditos_incluidos > (int) $b->precio_minor * (int) $a->creditos_incluidos;
    }

    private static function porClaseMinor(ProductoTenant $p): int
    {
        return intdiv((int) $p->precio_minor * 1000 + intdiv((int) $p->creditos_incluidos, 2), max(1, (int) $p->creditos_incluidos));
    }
}
