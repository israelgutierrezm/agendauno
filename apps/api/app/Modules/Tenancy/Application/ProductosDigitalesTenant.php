<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use Illuminate\Support\Collection;

/**
 * ¿Un plan es 100 % contenido digital? (reglas 3.1.1 y 3.1.3 de Apple; ADR 0233) Las
 * apps de las tiendas no lo venden; la web, sí. Un plan mixto (también da clases
 * presenciales) sí se vende en la app: su valor principal es presencial.
 *
 * Es digital si solo da clases en línea (todas las que cubre tienen enlace) o si no da
 * reservas y sí videos. Una cita en línea no cuenta: es un servicio entre dos personas.
 *
 * La instancia recuerda el catálogo de ofertas: se usa una por petición, nunca entre
 * negocios.
 */
final class ProductosDigitalesTenant
{
    /** @var Collection<int, OfertaTenant>|null */
    private ?Collection $vigentes = null;

    public function esDigital(ProductoTenant $producto): bool
    {
        $daReservas = $producto->ilimitado
            || (int) $producto->creditos_incluidos > 0
            || (int) $producto->unidades_por_ciclo > 0;
        if (! $daReservas) {
            return $this->daVideos($producto);
        }
        $cubiertas = $this->cubiertas($producto);

        return $cubiertas->isNotEmpty() && $cubiertas->every(self::esClaseEnLinea(...));
    }

    private static function esClaseEnLinea(OfertaTenant $oferta): bool
    {
        return $oferta->modalidad === ModalidadOfertaTenant::Grupal
            && trim((string) $oferta->enlace_en_linea) !== '';
    }

    private function daVideos(ProductoTenant $producto): bool
    {
        return $producto->videos()->where('publicado', true)->exists();
    }

    /**
     * Las ofertas vigentes a las que aplica: las suyas o, sin ninguna, todas las de su
     * actividad (sin actividad, todas).
     *
     * @return Collection<int, OfertaTenant>
     */
    private function cubiertas(ProductoTenant $producto): Collection
    {
        $propias = $producto->ofertas->filter(static fn (OfertaTenant $o): bool => ! $o->archivada());
        if ($producto->ofertas->isNotEmpty()) {
            return $propias->values();
        }
        $this->vigentes ??= OfertaTenant::query()->vigentes()->get();

        return $producto->actividad_id === null
            ? $this->vigentes
            : $this->vigentes->where('actividad_id', $producto->actividad_id)->values();
    }
}
