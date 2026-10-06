<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\ArticuloTenant;
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
 */
class RegionNegocioTenant
{
    /** Dónde se guarda la moneda del negocio (número ISO 4217, entre sus parámetros). */
    public const CLAVE_MONEDA = 'negocio.moneda';

    public const MOTIVO_PASARELAS = 'Las pasarelas de pago en línea solo funcionan con pesos mexicanos (MXN).';

    public const MOTIVO_FACTURACION = 'La facturación a tus clientes solo funciona en pesos mexicanos (MXN) y para negocios en México.';

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

    /** ¿Está en México? (sin país registrado, se toma México). */
    public function enMexico(): bool
    {
        $pais = (string) ($this->gestor->actual()->pais ?? '');

        return $pais === '' || mb_strtoupper($pais) === 'MX';
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
     * Cambia la moneda del negocio. Solo antes de cobrar: lo ya creado (productos,
     * artículos, pagos del personal y compras sin pagar) pasa a la nueva moneda.
     */
    public function cambiarMoneda(string $codigo, ?Usuario $actor): void
    {
        $codigo = mb_strtoupper(trim($codigo));
        if (! CatalogoMonedas::existe($codigo)) {
            throw ValidationException::withMessages(['moneda' => ['Elige una moneda del catálogo.']]);
        }
        $actual = $this->moneda();
        if ($codigo === $actual) {
            return;
        }
        if ($this->hayCobros()) {
            throw ValidationException::withMessages(['moneda' => ["Ya hay cobros en {$actual}: la moneda se elige antes de empezar a cobrar."]]);
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
     * Cambia la zona horaria del negocio: la de sus reportes, cortes y días. Las
     * sucursales conservan la suya.
     */
    public function cambiarZona(string $zona, ?Usuario $actor): void
    {
        if (! in_array($zona, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['zona_horaria' => ['Elige una zona horaria de la lista.']]);
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
}
