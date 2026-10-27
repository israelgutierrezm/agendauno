<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

/**
 * Cómo se factura algo vendido, tal como estaba al venderse (ADR 0228): su IVA
 * (general, exento o tasa cero) y sus claves del SAT de producto o servicio y de unidad.
 * Se guarda en cada línea de la venta; la factura usa esta foto y no el catálogo de hoy,
 * que pudo cambiar después. Sin clave propia, la de siempre: un servicio `86121600`/`E48`,
 * un artículo del mostrador `01010101`/`H87`.
 */
final readonly class FotoFiscal
{
    /** Un servicio (clase, cita, plan, cuota): la clave y la unidad de siempre. */
    public const SERVICIO = ['clave_prod_serv' => '86121600', 'clave_unidad' => 'E48'];

    /** Un artículo del mostrador: «no existe en el catálogo» y pieza. */
    public const ARTICULO = ['clave_prod_serv' => '01010101', 'clave_unidad' => 'H87'];

    public function __construct(
        public TratamientoIva $iva,
        public string $claveProdServ,
        public string $claveUnidad,
    ) {}

    /** Un servicio, plan o cuota con el IVA y la clave del SAT de su catálogo (si tiene). */
    public static function deServicio(mixed $iva = null, mixed $claveSat = null): self
    {
        return self::con($iva, $claveSat, self::SERVICIO);
    }

    /** Un artículo del mostrador con el IVA y la clave del SAT de su catálogo (si tiene). */
    public static function deArticulo(mixed $iva = null, mixed $claveSat = null): self
    {
        return self::con($iva, $claveSat, self::ARTICULO);
    }

    /**
     * La foto guardada en una línea u orden, o null si es de antes de guardarla.
     *
     * @param  array<string, mixed>  $columnas
     */
    public static function guardada(array $columnas): ?self
    {
        $iva = $columnas['iva'] ?? null;
        $producto = $columnas['clave_prod_serv'] ?? null;
        $unidad = $columnas['clave_unidad'] ?? null;
        if (! is_string($iva) || $iva === '' || ! is_string($producto) || $producto === '' || ! is_string($unidad) || $unidad === '') {
            return null;
        }

        return new self(TratamientoIva::de($iva), $producto, $unidad);
    }

    /**
     * Las columnas con que se guarda.
     *
     * @return array{iva: string, clave_prod_serv: string, clave_unidad: string}
     */
    public function columnas(): array
    {
        return ['iva' => $this->iva->value, 'clave_prod_serv' => $this->claveProdServ, 'clave_unidad' => $this->claveUnidad];
    }

    /**
     * @param  array{clave_prod_serv: string, clave_unidad: string}  $porOmision
     */
    private static function con(mixed $iva, mixed $claveSat, array $porOmision): self
    {
        $clave = is_string($claveSat) && $claveSat !== '' ? $claveSat : $porOmision['clave_prod_serv'];

        return new self(TratamientoIva::de($iva), $clave, $porOmision['clave_unidad']);
    }
}
