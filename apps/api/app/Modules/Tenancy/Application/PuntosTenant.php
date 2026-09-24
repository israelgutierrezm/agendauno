<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\PuntosInsuficientes;
use App\Modules\Tenancy\Lealtad\EstadoCanje;
use App\Modules\Tenancy\Lealtad\OrigenPuntos;
use App\Modules\Tenancy\Lealtad\TipoMovimientoPuntos;
use App\Modules\Tenancy\Models\CanjeLealtadTenant;
use App\Modules\Tenancy\Models\MovimientoPuntosTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\RecompensaLealtadTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de puntos de lealtad: acumular (por evento del outbox, idempotente), canjear
 * una recompensa (serializado por miembro, con guarda de saldo) y ajustar manualmente.
 * El saldo lo deriva {@see LibroPuntosTenant} del ledger.
 */
class PuntosTenant
{
    public function __construct(private readonly LibroPuntosTenant $libro) {}

    public function saldo(int $personaId): int
    {
        return $this->libro->saldo($personaId);
    }

    /**
     * Acumula puntos ganados por un evento de dominio. Idempotente por `evento_ulid`
     * (un evento = un asiento), para que el relay at-least-once no premie dos veces.
     */
    public function acumularPorEvento(
        int $personaId,
        int $puntos,
        OrigenPuntos $origen,
        string $eventoUlid,
        ?string $descripcion = null,
        ?string $referenciaTipo = null,
        ?string $referenciaId = null,
    ): void {
        if ($puntos <= 0) {
            return;
        }

        if (MovimientoPuntosTenant::query()->where('evento_ulid', $eventoUlid)->exists()) {
            return;
        }

        try {
            $this->libro->registrar(
                $personaId, TipoMovimientoPuntos::Acumulacion, $origen, $puntos,
                $descripcion, $referenciaTipo, $referenciaId, null, null, $eventoUlid,
            );
        } catch (QueryException $e) {
            // Entrega duplicada del relay: si ya hay un asiento para el evento, es la
            // colisión de idempotencia (unique evento_ulid) y se ignora; si no, es real.
            if (MovimientoPuntosTenant::query()->where('evento_ulid', $eventoUlid)->doesntExist()) {
                throw $e;
            }
        }
    }

    /**
     * Canjea una recompensa para un miembro: descuenta sus puntos y crea el canje
     * `pendiente`. Serializa por miembro (lockForUpdate sobre la persona) y valida saldo.
     */
    public function canjear(int $personaId, RecompensaLealtadTenant $recompensa, ?Usuario $actor): CanjeLealtadTenant
    {
        return DB::connection('tenant')->transaction(function () use ($personaId, $recompensa, $actor): CanjeLealtadTenant {
            PersonaTenant::query()->whereKey($personaId)->lockForUpdate()->firstOrFail();

            if ($this->libro->saldo($personaId) < $recompensa->costo_puntos) {
                throw new PuntosInsuficientes('Puntos insuficientes para canjear la recompensa.');
            }

            $this->libro->registrar(
                $personaId, TipoMovimientoPuntos::Canje, OrigenPuntos::Canje, -$recompensa->costo_puntos,
                'Canje: '.$recompensa->nombre, 'recompensa', $recompensa->ulid, $recompensa->getKey(), $actor,
            );

            return CanjeLealtadTenant::query()->create([
                'persona_id' => $personaId,
                'recompensa_id' => $recompensa->getKey(),
                'recompensa_nombre' => $recompensa->nombre,
                'puntos' => $recompensa->costo_puntos,
                'estado' => EstadoCanje::Pendiente->value,
                'actor_id' => $actor?->getKey(),
                'actor_nombre' => $actor?->name,
            ]);
        });
    }

    /**
     * Cancela un canje pendiente y devuelve los puntos al miembro (idempotente).
     */
    public function cancelarCanje(CanjeLealtadTenant $canje, ?Usuario $actor): CanjeLealtadTenant
    {
        return DB::connection('tenant')->transaction(function () use ($canje, $actor): CanjeLealtadTenant {
            $bloqueado = CanjeLealtadTenant::query()->whereKey($canje->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado !== EstadoCanje::Pendiente) {
                return $bloqueado;
            }

            $this->libro->registrar(
                $bloqueado->persona_id, TipoMovimientoPuntos::Reverso, OrigenPuntos::Canje, $bloqueado->puntos,
                'Canje cancelado: '.$bloqueado->recompensa_nombre, 'canje', $bloqueado->ulid, $bloqueado->recompensa_id, $actor,
            );
            $bloqueado->update(['estado' => EstadoCanje::Cancelado->value]);

            return $bloqueado;
        });
    }

    /**
     * Marca un canje pendiente como entregado (idempotente).
     */
    public function entregarCanje(CanjeLealtadTenant $canje): CanjeLealtadTenant
    {
        if ($canje->estado === EstadoCanje::Pendiente) {
            $canje->update(['estado' => EstadoCanje::Entregado->value, 'entregado_en' => Carbon::now()]);
        }

        return $canje;
    }

    /**
     * Ajuste manual (+/-) del staff. No permite dejar el saldo en negativo.
     */
    public function ajustar(int $personaId, int $puntos, ?string $descripcion, ?Usuario $actor): MovimientoPuntosTenant
    {
        return DB::connection('tenant')->transaction(function () use ($personaId, $puntos, $descripcion, $actor): MovimientoPuntosTenant {
            PersonaTenant::query()->whereKey($personaId)->lockForUpdate()->firstOrFail();

            if ($this->libro->saldo($personaId) + $puntos < 0) {
                throw new PuntosInsuficientes('El ajuste dejaría el saldo de puntos en negativo.');
            }

            return $this->libro->registrar(
                $personaId, TipoMovimientoPuntos::Ajuste, OrigenPuntos::Manual, $puntos, $descripcion, null, null, null, $actor,
            );
        });
    }
}
