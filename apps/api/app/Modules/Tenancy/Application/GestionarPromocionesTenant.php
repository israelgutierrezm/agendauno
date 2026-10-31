<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PromocionTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Ordenes\Exceptions\PromocionInvalida;
use App\Modules\Tenancy\Reservas\EstadoReserva;

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
     * Aplica un código dentro de la transacción de la orden: bloquea la fila y revalida.
     * Devuelve la promoción y el descuento (minor). El uso se cuenta al pagarse la orden
     * ({@see FulfillmentTenant::cumplir()}): una compra que nadie paga no agota el
     * código. Debe llamarse dentro de una transacción de la conexión `tenant`.
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
        // Con tope de usos, también cuentan las compras con el código aún por pagar: las
        // de una reserva viva, sin caducidad (ya se le prometió ese precio; ADR 0231), y
        // las demás de las últimas horas (una compra abandonada deja de apartarlo).
        if ($promocion->usos_maximos !== null) {
            $apartados = OrdenTenant::query()
                ->where('promocion_id', $promocion->getKey())
                ->where('estado', EstadoOrden::Pendiente->value)
                ->where(fn ($q) => $q
                    ->where('created_at', '>=', now()->subHours(self::HORAS_APARTADO))
                    ->orWhereIn('id', ReservaTenant::query()->select('orden_id')->whereNotNull('orden_id')->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value]))
                    ->orWhereIn('id', ReservaTenant::query()->select('saldo_orden_id')->whereNotNull('saldo_orden_id')->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value])))
                ->count();
            if ((int) $promocion->usos + $apartados >= (int) $promocion->usos_maximos) {
                throw new PromocionInvalida('La promoción ya no tiene usos disponibles.');
            }
        }
        if ($promocion->monto_minimo_minor !== null && $subtotal < $promocion->monto_minimo_minor) {
            throw new PromocionInvalida('La compra no alcanza el mínimo para esta promoción.');
        }

        return $promocion;
    }

    /**
     * El tope opcional por persona (ADR 0231): sus compras pagadas con el código y las
     * que tiene por pagar, sin contar la orden que lo está usando.
     */
    public function exigirTopePorPersona(PromocionTenant $promocion, int $personaId, ?int $exceptoOrden = null): void
    {
        if ($promocion->usos_por_persona === null) {
            return;
        }
        $usos = OrdenTenant::query()
            ->where('promocion_id', $promocion->getKey())
            ->where('persona_id', $personaId)
            ->whereIn('estado', [EstadoOrden::Pendiente->value, EstadoOrden::Pagada->value])
            ->when($exceptoOrden !== null, static fn ($q) => $q->whereKeyNot($exceptoOrden))
            ->count();
        if ($usos >= $promocion->usos_por_persona) {
            throw new PromocionInvalida('Ya usaste esta promoción las veces que permite.');
        }
    }

    /** Cuánto tiempo aparta un uso una compra por pagar con el código. */
    private const HORAS_APARTADO = 24;

    private function normalizar(string $codigo): string
    {
        return mb_strtoupper(trim($codigo));
    }
}
