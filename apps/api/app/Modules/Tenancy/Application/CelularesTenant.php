<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Models\PersonaTenant;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Los celulares de las personas del negocio se comparan como número, no como texto
 * (ADR 0103): en un negocio de México «5512345678», «55 1234 5678» y «+52 5512345678»
 * son el mismo. Lo guardado no se reescribe: se compara normalizado con la lada del
 * negocio ({@see TelefonoWhatsApp::normalizar}). Un número que no se puede normalizar
 * se compara tal cual.
 */
class CelularesTenant
{
    public function __construct(private readonly RegionNegocioTenant $region) {}

    /**
     * La persona de `$consulta` que tiene ese celular, o null.
     *
     * @param  Builder<PersonaTenant>  $consulta
     */
    public function buscar(Builder $consulta, string $celular): ?PersonaTenant
    {
        return $this->todas($consulta, $celular)->first();
    }

    /**
     * Todas las personas de `$consulta` con ese celular (en el orden en que se dieron de
     * alta): con más de una, el celular no basta para saber quién es (R14).
     *
     * @param  Builder<PersonaTenant>  $consulta
     * @return Collection<int, PersonaTenant>
     */
    public function todas(Builder $consulta, string $celular): Collection
    {
        $celular = trim($celular);
        if ($celular === '') {
            return new Collection;
        }
        $lada = $this->region->lada();
        $numero = TelefonoWhatsApp::normalizar($celular, $lada);
        if ($numero === null) {
            return $consulta->where('celular', $celular)->orderBy('id')->get();
        }

        // Solo las fichas con sus últimos 7 dígitos en orden (con lo que sea entre ellos:
        // espacios, guiones, la lada…); de esas, las que son el mismo número.
        $patron = '%'.implode('%', str_split(substr($numero, -7))).'%';

        return $consulta
            ->where(fn (Builder $q) => $q->where('celular', $celular)->orWhere('celular', 'like', $patron))
            ->orderBy('id')
            ->get()
            ->filter(static fn (PersonaTenant $persona): bool => trim((string) $persona->celular) === $celular
                || TelefonoWhatsApp::normalizar($persona->celular, $lada) === $numero)
            ->values();
    }

    /** La persona dada de baja que tiene ese celular, o null. */
    public function dadaDeBaja(string $celular): ?PersonaTenant
    {
        return $this->buscar(PersonaTenant::onlyTrashed(), $celular);
    }

    /**
     * Regla de validación: el celular no es de otra persona del negocio (`$ignorar` es
     * la propia). Con `$conBajas`, tampoco de una dada de baja.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public function reglaUnico(string $mensaje, ?int $ignorar = null, bool $conBajas = false): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falla) use ($mensaje, $ignorar, $conBajas): void {
            if (! is_string($valor) || trim($valor) === '') {
                return;
            }
            $consulta = $conBajas ? PersonaTenant::withTrashed() : PersonaTenant::query();
            if ($ignorar !== null) {
                $consulta->whereKeyNot($ignorar);
            }
            if ($this->buscar($consulta, $valor) !== null) {
                $falla($mensaje);
            }
        };
    }
}
