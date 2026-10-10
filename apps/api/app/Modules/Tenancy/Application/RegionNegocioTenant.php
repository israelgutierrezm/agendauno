<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\CatalogoPaises;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ParametroNegocioTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\CatalogoMonedas;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La región del negocio (ADR 0099): UNA moneda para todo el negocio (pesos mexicanos
 * si no elige otra) y su zona horaria (la de la Ciudad de México si no elige otra).
 * Las pasarelas de pago en línea y la facturación a sus clientes solo funcionan en
 * pesos mexicanos; la facturación, además, solo para negocios en México.
 *
 * Y su país (ADR 0103, ISO 3166-1 alfa-2): de él sale la lada con que se completan los
 * celulares capturados sin «+».
 */
class RegionNegocioTenant
{
    /** Dónde se guarda la moneda del negocio (número ISO 4217, entre sus parámetros). */
    public const CLAVE_MONEDA = 'negocio.moneda';

    public const MOTIVO_PASARELAS = 'Las pasarelas de pago en línea solo funcionan con pesos mexicanos (MXN).';

    public const MOTIVO_FACTURACION = 'La facturación a tus clientes solo funciona en pesos mexicanos (MXN) y para negocios en México.';

    public const MOTIVO_PAIS = 'El país ya no se puede cambiar desde aquí porque define cómo se te cobra; escríbenos para cambiarlo.';

    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly FechasNegocioTenant $fechas,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** La moneda del negocio (ISO 4217). */
    public function moneda(): string
    {
        try {
            $numero = ParametroNegocioTenant::query()->where('clave', self::CLAVE_MONEDA)->value('valor');
        } catch (QueryException) {
            // Negocio aún sin la tabla: la de siempre.
            $numero = null;
        }

        return $numero !== null ? CatalogoMonedas::codigoDe((int) $numero) : CatalogoMonedas::PREDETERMINADA;
    }

    /** La zona horaria del negocio (la de sus reportes, cortes y días). */
    public function zona(): string
    {
        return $this->fechas->zona();
    }

    /** ¿Trabaja en pesos mexicanos? (pasarelas en línea y facturación). */
    public function enPesos(): bool
    {
        return $this->moneda() === 'MXN';
    }

    /** El país del negocio (ISO 3166-1 alfa-2, en mayúsculas). */
    public function pais(): string
    {
        return CatalogoPaises::codigo($this->gestor->actual()?->pais);
    }

    /**
     * La lada del país del negocio (solo dígitos): la de los celulares que se capturan
     * sin «+». Con un país fuera del catálogo, la de la plataforma.
     */
    public function lada(): string
    {
        return CatalogoPaises::lada($this->pais()) ?? (string) config('agendauno.whatsapp.lada', '52');
    }

    /** ¿Está en México? */
    public function enMexico(): bool
    {
        return $this->pais() === 'MX';
    }

    /** ¿Puede facturar a sus clientes? Solo en pesos mexicanos y en México. */
    public function factura(): bool
    {
        return $this->enPesos() && $this->enMexico();
    }

    /**
     * ¿Ya cobró algo? Desde entonces la moneda ya no cambia: todo su dinero está en ella.
     */
    public function hayCobros(): bool
    {
        return PagoTenant::query()->exists()
            || VentaPosTenant::query()->exists()
            || OrdenTenant::query()->where('estado', EstadoOrden::Pagada->value)->exists();
    }

    /**
     * ¿Puede el dueño cambiar el país? Del país salen la moneda, el IVA y la factura de
     * su renta (ADR 0107): solo durante la prueba y antes de su primer cargo. Después lo
     * cambia el superadmin.
     */
    public function paisEditable(): bool
    {
        $estudio = $this->gestor->actual();
        if ($estudio === null) {
            return true;
        }
        $enPrueba = $estudio->trial_termina_en !== null
            && $estudio->trial_termina_en->toDateString() >= $this->fechas->hoy();

        return $enPrueba && ! CargoRenta::query()->where('estudio_id', $estudio->getKey())->exists();
    }

    /**
     * Regla de validación: si se manda una moneda, debe ser la del negocio (sin
     * importar mayúsculas). Un negocio trabaja con una sola.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public function reglaMoneda(): Closure
    {
        $moneda = $this->moneda();

        return static function (string $atributo, mixed $valor, Closure $falla) use ($moneda): void {
            if (! is_string($valor) || mb_strtoupper($valor) !== $moneda) {
                $falla("El negocio trabaja en {$moneda}: no se aceptan otras monedas.");
            }
        };
    }

    /**
     * Revisa un cambio de región completo antes de aplicar nada: si algo no se puede, no
     * cambia ninguno (país, moneda y zona se mandan juntos).
     *
     * @param  array<string, mixed>  $cambios  `pais`, `moneda` y `zona_horaria` (los que se manden)
     *
     * @throws ValidationException
     */
    public function validarCambios(array $cambios): void
    {
        $errores = array_filter([
            'pais' => isset($cambios['pais']) ? $this->errorPais((string) $cambios['pais']) : null,
            'moneda' => isset($cambios['moneda']) ? $this->errorMoneda((string) $cambios['moneda']) : null,
            'zona_horaria' => isset($cambios['zona_horaria']) ? $this->errorZona((string) $cambios['zona_horaria']) : null,
        ]);
        if ($errores !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $error): array => [$error], $errores));
        }
    }

    /**
     * Cambia la moneda del negocio. Solo antes de cobrar: lo ya creado (productos,
     * artículos, pagos del personal y compras sin pagar) pasa a la nueva moneda.
     */
    public function cambiarMoneda(string $codigo, ?Usuario $actor): void
    {
        $error = $this->errorMoneda($codigo);
        if ($error !== null) {
            throw ValidationException::withMessages(['moneda' => [$error]]);
        }
        $codigo = mb_strtoupper(trim($codigo));
        $actual = $this->moneda();
        if ($codigo === $actual) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($codigo, $actor): void {
            ParametroNegocioTenant::query()->updateOrCreate(
                ['clave' => self::CLAVE_MONEDA],
                ['valor' => CatalogoMonedas::numero($codigo), 'actualizado_por' => $actor?->getKey()],
            );
            ProductoTenant::query()->update(['moneda' => $codigo]);
            ArticuloTenant::query()->update(['moneda' => $codigo]);
            EsquemaPagoTenant::query()->update(['moneda' => $codigo]);
            OrdenTenant::query()->where('estado', EstadoOrden::Pendiente->value)->update(['moneda' => $codigo]);
        });
        $this->auditoria->registrar($actor, 'negocio.moneda', 'estudio', null, ['moneda' => $actual], ['moneda' => $codigo]);
    }

    /**
     * Cambia el país del negocio (uno del catálogo). Con él cambia la lada de los
     * celulares sin «+»; fuera de México no hay facturación a sus clientes.
     */
    public function cambiarPais(string $codigo, ?Usuario $actor): void
    {
        $error = $this->errorPais($codigo);
        if ($error !== null) {
            throw ValidationException::withMessages(['pais' => [$error]]);
        }
        $codigo = CatalogoPaises::codigo($codigo);
        $estudio = $this->gestor->actual();
        if ($estudio === null) {
            return;
        }
        $antes = CatalogoPaises::codigo($estudio->pais);
        if ($antes === $codigo) {
            return;
        }
        $estudio->forceFill(['pais' => $codigo])->save();
        $this->auditoria->registrar($actor, 'negocio.pais', 'estudio', null, ['pais' => $antes], ['pais' => $codigo]);
    }

    /**
     * Cambia la zona horaria del negocio: la de sus reportes, cortes y días. Las
     * sucursales conservan la suya.
     */
    public function cambiarZona(string $zona, ?Usuario $actor): void
    {
        $error = $this->errorZona($zona);
        if ($error !== null) {
            throw ValidationException::withMessages(['zona_horaria' => [$error]]);
        }
        $estudio = $this->gestor->actual();
        if ($estudio === null) {
            return;
        }
        $antes = (string) $estudio->zona_horaria;
        if ($antes === $zona) {
            return;
        }
        $estudio->forceFill(['zona_horaria' => $zona])->save();
        $this->auditoria->registrar($actor, 'negocio.zona_horaria', 'estudio', null, ['zona_horaria' => $antes], ['zona_horaria' => $zona]);
    }

    /** El país debe ser del catálogo y, si es otro, aún debe poder cambiarlo el dueño. */
    private function errorPais(string $codigo): ?string
    {
        if (! CatalogoPaises::existe($codigo)) {
            return 'Elige un país de la lista.';
        }

        return CatalogoPaises::codigo($codigo) !== $this->pais() && ! $this->paisEditable() ? self::MOTIVO_PAIS : null;
    }

    /** La moneda debe ser del catálogo y, si es otra, el negocio aún no debe haber cobrado. */
    private function errorMoneda(string $codigo): ?string
    {
        $codigo = mb_strtoupper(trim($codigo));
        if (! CatalogoMonedas::existe($codigo)) {
            return 'Elige una moneda del catálogo.';
        }
        $actual = $this->moneda();

        return $codigo !== $actual && $this->hayCobros()
            ? "Ya hay cobros en {$actual}: la moneda se elige antes de empezar a cobrar."
            : null;
    }

    private function errorZona(string $zona): ?string
    {
        return in_array($zona, timezone_identifiers_list(), true) ? null : 'Elige una zona horaria de la lista.';
    }
}
