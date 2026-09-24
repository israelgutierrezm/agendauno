<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PromocionTenant;
use App\Modules\Tenancy\Ordenes\Exceptions\PromocionInvalida;

/**
 * Promociones / cupones tenant-local (R22): alta y edición de códigos, y su aplicación
 * a una orden (validación de vigencia/mínimo/usos y conteo de usos concurrency-safe).
 */
class GestionarPromocionesTenant
{
    /**
     * Valida un código contra un subtotal SIN consumir uso (para preview del checkout).
     *
     * @return array{promocion: PromocionTenant, descuento: int}
     */
    public function previsualizar(string $codigo, int $subtotal): array
    {
        $encontrada = PromocionTenant::query()->where('codigo', $this->normalizar($codigo))->first();
        $promocion = $this->validar($encontrada, $subtotal);

        return ['promocion' => $promocion, 'descuento' => $promocion->descuentoPara($subtotal)];
    }

    /**
     * Aplica un código dentro de la transacción de la orden: bloquea la fila, revalida y
     * consume un uso. Devuelve la promoción y el descuento (minor). Debe llamarse dentro
     * de una transacción de la conexión `tenant`.
     *
     * @return array{promocion: PromocionTenant, descuento: int}
     */
    public function aplicarEnOrden(string $codigo, int $subtotal): array
    {
        $encontrada = PromocionTenant::query()
            ->where('codigo', $this->normalizar($codigo))
            ->lockForUpdate()
            ->first();

        $promocion = $this->validar($encontrada, $subtotal);
        $descuento = $promocion->descuentoPara($subtotal);
        $promocion->marcarUso();

        return ['promocion' => $promocion, 'descuento' => $descuento];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): PromocionTenant
    {
        $datos['codigo'] = $this->normalizar((string) $datos['codigo']);

        return PromocionTenant::query()->create($datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(PromocionTenant $promocion, array $datos): PromocionTenant
    {
        if (isset($datos['codigo'])) {
            $datos['codigo'] = $this->normalizar((string) $datos['codigo']);
        }
        $promocion->update($datos);

        return $promocion->refresh();
    }

    /**
     * Lanza si la promoción no aplica; si es válida, la devuelve.
     */
    private function validar(?PromocionTenant $promocion, int $subtotal): PromocionTenant
    {
        if ($promocion === null) {
            throw new PromocionInvalida('El código de promoción no existe.');
        }
        if (! $promocion->vigente()) {
            throw new PromocionInvalida('La promoción no está vigente.');
        }
        if ($promocion->monto_minimo_minor !== null && $subtotal < $promocion->monto_minimo_minor) {
            throw new PromocionInvalida('La compra no alcanza el mínimo para esta promoción.');
        }

        return $promocion;
    }

    private function normalizar(string $codigo): string
    {
        return mb_strtoupper(trim($codigo));
    }
}
