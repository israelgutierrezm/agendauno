<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Lealtad\OrigenPuntos;
use App\Modules\Tenancy\Lealtad\TipoMovimientoPuntos;
use App\Modules\Tenancy\Models\MovimientoPuntosTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Ledger de puntos de lealtad tenant-local: el saldo de un miembro es la SUMA de los
 * deltas `puntos` (nunca se guarda). `registrar()` sólo agrega un asiento; la
 * concurrencia (canje) la serializa {@see PuntosTenant}.
 */
class LibroPuntosTenant
{
    public function saldo(int $personaId): int
    {
        return (int) MovimientoPuntosTenant::query()->where('persona_id', $personaId)->sum('puntos');
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function registrar(
        int $personaId,
        TipoMovimientoPuntos $tipo,
        OrigenPuntos $origen,
        int $puntos,
        ?string $descripcion = null,
        ?string $referenciaTipo = null,
        ?string $referenciaId = null,
        ?int $recompensaId = null,
        ?Usuario $actor = null,
        ?string $eventoUlid = null,
        ?array $metadata = null,
    ): MovimientoPuntosTenant {
        $saldoPrevio = $this->saldo($personaId);

        return MovimientoPuntosTenant::query()->create([
            'persona_id' => $personaId,
            'tipo' => $tipo->value,
            'origen' => $origen->value,
            'puntos' => $puntos,
            'saldo_posterior' => $saldoPrevio + $puntos,
            'descripcion' => $descripcion,
            'referencia_tipo' => $referenciaTipo,
            'referencia_id' => $referenciaId,
            'recompensa_id' => $recompensaId,
            'actor_id' => $actor?->getKey(),
            'actor_nombre' => $actor?->name,
            'evento_ulid' => $eventoUlid,
            'metadata' => $metadata,
        ]);
    }
}
