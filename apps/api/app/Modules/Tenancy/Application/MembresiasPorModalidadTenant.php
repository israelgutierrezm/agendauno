<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\MembresiaNoDisponible;
use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\ProductoTenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los negocios de citas (TurnoUno) no venden membresías (ADR 0130): ni membresías ni
 * planes que se renuevan, ilimitados, con permanencia o con cuotas. Venden bonos de
 * sesiones. Lo decide el servidor al crear o editar un producto, al venderlo y al
 * ofrecerlo; los de clases siguen igual.
 */
class MembresiasPorModalidadTenant
{
    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /** ¿El negocio conectado es de citas? */
    public function enCitas(): bool
    {
        return $this->gestor->actual()?->modalidad() === ModalidadServicio::Citas;
    }

    /**
     * Un producto con estos atributos (su estado final al crearlo o editarlo) no se
     * admite en citas. Uno archivado sí: archivarlo es retirarlo.
     *
     * @param  array<string, mixed>  $atributos
     *
     * @throws MembresiaNoDisponible
     */
    public function exigirAtributos(array $atributos): void
    {
        if ($this->enCitas() && ! (bool) ($atributos['archivado'] ?? false) && ProductoTenant::esMembresiaSegun($atributos)) {
            throw new MembresiaNoDisponible;
        }
    }

    /**
     * @throws MembresiaNoDisponible
     */
    public function exigirQueSeVenda(ProductoTenant $producto): void
    {
        if ($this->enCitas() && $producto->esMembresia()) {
            throw new MembresiaNoDisponible("«{$producto->nombre}» es una membresía: los negocios de citas venden bonos de sesiones.");
        }
    }

    /**
     * Los productos que se ofrecen: en citas, sin las membresías.
     *
     * @param  Builder<ProductoTenant>  $consulta
     * @return Builder<ProductoTenant>
     */
    public function vendibles(Builder $consulta): Builder
    {
        if (! $this->enCitas()) {
            return $consulta;
        }

        return self::sinMembresias($consulta);
    }

    /**
     * @param  Builder<ProductoTenant>  $consulta
     * @return Builder<ProductoTenant>
     */
    public static function sinMembresias(Builder $consulta): Builder
    {
        return $consulta
            ->where('tipo', '!=', TipoProducto::Membresia->value)
            ->where(fn (Builder $q) => $q->whereNull('politica_reset')->orWhere('politica_reset', PoliticaReset::Ninguno->value))
            ->where('ilimitado', false)
            ->where(fn (Builder $q) => $q->whereNull('permanencia_meses')->orWhere('permanencia_meses', 0))
            ->where('cobra_inscripcion', false)
            ->where('cobra_anualidad', false);
    }

    /**
     * Las membresías (o lo que funciona como tal): lo contrario de {@see self::sinMembresias()}.
     *
     * @param  Builder<ProductoTenant>  $consulta
     * @return Builder<ProductoTenant>
     */
    public static function soloMembresias(Builder $consulta): Builder
    {
        return $consulta->where(fn (Builder $q) => $q
            ->where('tipo', TipoProducto::Membresia->value)
            ->orWhere(fn (Builder $r) => $r->whereNotNull('politica_reset')->where('politica_reset', '!=', PoliticaReset::Ninguno->value))
            ->orWhere('ilimitado', true)
            ->orWhere('permanencia_meses', '>', 0)
            ->orWhere('cobra_inscripcion', true)
            ->orWhere('cobra_anualidad', true));
    }
}
