<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\Exceptions\PrimeraVezNoDisponible;
use Illuminate\Database\Eloquent\Builder;

/**
 * La clase de prueba (plan de mejoras B03; decisión del dueño 2026-10-10): un producto
 * «para tu primera vez» solo lo compra, una sola vez, quien **nunca vino** al negocio:
 * sin ningún plan antes (ni otra clase de prueba) y sin asistencias registradas. Se
 * valida en el servidor al crear la orden y otra vez antes de cobrarla; ocultar la
 * tarjeta no basta.
 */
class PrimeraVezTenant
{
    /** ¿Esta persona puede comprar un producto «para tu primera vez»? */
    public function elegible(PersonaTenant $persona): bool
    {
        if (AcuerdoTenant::query()->where('persona_id', $persona->getKey())->exists()) {
            return false;
        }

        return ! ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereHas('asistencia', fn (Builder $q) => $q->where('estado', EstadoAsistencia::Presente->value))
            ->exists();
    }

    /**
     * Lanza si la orden lleva un producto de primera vez que su beneficiario ya no puede
     * comprar (o más de uno).
     *
     * @throws PrimeraVezNoDisponible
     */
    public function exigirEnOrden(OrdenTenant $orden): void
    {
        $orden->loadMissing(['lineas.producto', 'lineas.beneficiario', 'persona']);
        foreach ($orden->lineas as $linea) {
            if ($linea->producto?->primera_vez !== true) {
                continue;
            }
            $quien = $linea->beneficiario ?? $orden->persona;
            if ($linea->cantidad > 1 || ! $quien instanceof PersonaTenant || ! $this->elegible($quien)) {
                throw new PrimeraVezNoDisponible(self::MENSAJE);
            }
        }
    }

    public const MENSAJE = 'Esta clase de prueba es solo para quien viene por primera vez, y una sola vez.';
}
