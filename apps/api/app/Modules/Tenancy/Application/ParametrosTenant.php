<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\ParametroNegocioTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Parametros\CatalogoParametros;
use App\Modules\Tenancy\Parametros\DefinicionParametro;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Los parámetros configurables (ADR 0042): el valor que aplica sale del negocio en
 * contexto, si lo ajustó; si no, de la plataforma (superadmin); y si tampoco, del
 * valor inicial del catálogo. Se leen una vez por negocio y por solicitud: lo leído
 * no se arrastra a la siguiente aunque la instancia se reutilice (controladores en
 * caché, servidores de larga vida).
 */
class ParametrosTenant
{
    private const CLAVE_PLATAFORMA = 'parametros';

    /** @var array<string, int>|null */
    private ?array $plataforma = null;

    /** @var array<int, array<string, int>> por negocio */
    private array $negocio = [];

    /** Solicitud en la que se leyó lo guardado arriba. */
    private ?int $solicitud = null;

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function entero(string $clave): int
    {
        $definicion = CatalogoParametros::de($clave);
        if ($definicion->porNegocio) {
            $delNegocio = $this->delNegocio()[$clave] ?? null;
            if ($delNegocio !== null) {
                return $delNegocio;
            }
        }

        return $this->dePlataforma()[$clave] ?? $definicion->defecto;
    }

    public function siNo(string $clave): bool
    {
        return $this->entero($clave) === 1;
    }

    /**
     * Para la pantalla del negocio: cada parámetro que puede ajustar, con el valor de
     * la plataforma (lo que aplica si no lo cambia) y el suyo (null = usa el otro).
     *
     * @return list<array<string, mixed>>
     */
    public function delNegocioParaEditar(): array
    {
        $propios = $this->delNegocio();
        $plataforma = $this->dePlataforma();

        return array_values(array_map(fn (DefinicionParametro $d): array => [
            ...$d->toArray(),
            'plataforma' => $plataforma[$d->clave] ?? $d->defecto,
            'valor' => $propios[$d->clave] ?? null,
        ], array_filter(CatalogoParametros::todos(), fn (DefinicionParametro $d): bool => $d->porNegocio)));
    }

    /**
     * Para el superadmin: todos, con su valor de plataforma (null = el inicial).
     *
     * @return list<array<string, mixed>>
     */
    public function deLaPlataformaParaEditar(): array
    {
        $plataforma = $this->dePlataforma();

        return array_values(array_map(fn (DefinicionParametro $d): array => [
            ...$d->toArray(),
            'defecto' => $d->defecto,
            'valor' => $plataforma[$d->clave] ?? null,
        ], CatalogoParametros::todos()));
    }

    /**
     * Guarda lo que el negocio ajustó (null = vuelve al de la plataforma).
     *
     * @param  array<string, mixed>  $valores
     */
    public function guardarDelNegocio(array $valores, ?Usuario $actor): void
    {
        foreach ($this->validar($valores, soloNegocio: true) as $clave => $valor) {
            if ($valor === null) {
                ParametroNegocioTenant::query()->where('clave', $clave)->delete();

                continue;
            }
            ParametroNegocioTenant::query()->updateOrCreate(['clave' => $clave], [
                'valor' => $valor,
                'actualizado_por' => $actor?->getKey(),
            ]);
        }
        $this->negocio = [];
    }

    /**
     * Guarda los valores de plataforma (null = vuelve al inicial).
     *
     * @param  array<string, mixed>  $valores
     */
    public function guardarDePlataforma(array $valores): void
    {
        $actuales = $this->dePlataforma();
        foreach ($this->validar($valores, soloNegocio: false) as $clave => $valor) {
            if ($valor === null) {
                unset($actuales[$clave]);
            } else {
                $actuales[$clave] = $valor;
            }
        }
        ConfiguracionPlataforma::establecer(self::CLAVE_PLATAFORMA, $actuales === [] ? null : (string) json_encode($actuales));
        $this->plataforma = null;
    }

    /**
     * @param  array<string, mixed>  $valores
     * @return array<string, int|null>
     */
    private function validar(array $valores, bool $soloNegocio): array
    {
        $catalogo = CatalogoParametros::todos();
        $limpios = [];
        $errores = [];
        foreach ($valores as $clave => $valor) {
            $definicion = $catalogo[$clave] ?? null;
            if (! $definicion instanceof DefinicionParametro || ($soloNegocio && ! $definicion->porNegocio)) {
                $errores[$clave] = ['Este dato no se puede ajustar aquí.'];

                continue;
            }
            if ($valor === null || $valor === '') {
                $limpios[$clave] = null;

                continue;
            }
            if (is_bool($valor)) {
                $valor = $valor ? 1 : 0;
            }
            if (! is_numeric($valor) || (int) $valor != $valor) {
                $errores[$clave] = ["{$definicion->etiqueta}: debe ser un número entero."];

                continue;
            }
            $entero = (int) $valor;
            if ($definicion->opciones !== [] && ! in_array($entero, $definicion->opciones, true)) {
                $errores[$clave] = ["{$definicion->etiqueta}: debe ser ".implode(' o ', $definicion->opciones).'.'];

                continue;
            }
            if ($entero < $definicion->minimo || $entero > $definicion->maximo) {
                $errores[$clave] = ["{$definicion->etiqueta}: debe estar entre {$definicion->minimo} y {$definicion->maximo}."];

                continue;
            }
            $limpios[$clave] = $entero;
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $limpios;
    }

    /**
     * @return array<string, int>
     */
    private function dePlataforma(): array
    {
        $this->olvidarSiEsOtraSolicitud();
        if ($this->plataforma === null) {
            try {
                $json = ConfiguracionPlataforma::obtener(self::CLAVE_PLATAFORMA);
                $datos = is_string($json) ? json_decode($json, true) : null;
                $this->plataforma = is_array($datos) ? array_map('intval', $datos) : [];
            } catch (QueryException) {
                // Sin la tabla (instalación nueva): aplican los valores iniciales.
                $this->plataforma = [];
            }
        }

        return $this->plataforma;
    }

    /**
     * @return array<string, int>
     */
    private function delNegocio(): array
    {
        $this->olvidarSiEsOtraSolicitud();
        $estudio = $this->gestor->actual();
        if ($estudio === null) {
            return [];
        }
        $id = (int) $estudio->getKey();
        if (! array_key_exists($id, $this->negocio)) {
            try {
                $this->negocio[$id] = ParametroNegocioTenant::query()->pluck('valor', 'clave')
                    ->map(fn ($v): int => (int) $v)->all();
            } catch (QueryException) {
                // Negocio aún sin la tabla: aplica lo de la plataforma.
                $this->negocio[$id] = [];
            }
        }

        return $this->negocio[$id];
    }

    private function olvidarSiEsOtraSolicitud(): void
    {
        $actual = spl_object_id(request());
        if ($this->solicitud !== $actual) {
            $this->solicitud = $actual;
            $this->plataforma = null;
            $this->negocio = [];
        }
    }
}
