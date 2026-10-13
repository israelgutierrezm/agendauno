<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Http\Controllers\PagosTenantController;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * A quién de los clientes puede tocar quien hace la solicitud: alguien del equipo
 * acotado por sucursal (R19, ADR 0098) solo a los de sus sedes, y quien imparte solo a
 * sus clientes (ADR 0095). La misma regla que la ficha del miembro, para todo lo que
 * cuelga de una persona (su expediente, sus planes, sus créditos, sus compras): con el
 * ULID de algo de otra sede no se ve ni se opera nada.
 */
final class AlcancePersonaTenant
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly AlcanceClientesTenant $clientes,
    ) {}

    public function puedeVer(?Usuario $actor, ?PersonaTenant $persona): bool
    {
        if (! $actor instanceof Usuario || ! $persona instanceof PersonaTenant) {
            return true;
        }

        return $this->acceso->permiteSucursal($actor, $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null)
            && $this->clientes->puedeVer($persona, $actor);
    }

    public function exigirPersona(Request $request, ?PersonaTenant $persona): void
    {
        abort_unless($this->puedeVer(self::actor($request), $persona), 403, 'No puedes ver a esta persona.');
    }

    public function exigirAcuerdo(Request $request, AcuerdoTenant $acuerdo): void
    {
        $this->exigirPersona($request, $acuerdo->persona);
    }

    public function exigirDerecho(Request $request, DerechoTenant $derecho): void
    {
        $acuerdo = $derecho->acuerdo;
        if ($acuerdo instanceof AcuerdoTenant) {
            $this->exigirAcuerdo($request, $acuerdo);
        }
    }

    public function exigirRetencion(Request $request, RetencionCreditoTenant $retencion): void
    {
        $derecho = $retencion->derecho;
        if ($derecho instanceof DerechoTenant) {
            $this->exigirDerecho($request, $derecho);
        }
    }

    /**
     * Una compra se toca desde su sede (la de la orden; sin ella, la de quien compra),
     * como sus pagos ({@see PagosTenantController::pagoDeMiSede()}).
     */
    public function exigirOrden(Request $request, OrdenTenant $orden): void
    {
        $actor = self::actor($request);
        if (! $actor instanceof Usuario) {
            return;
        }
        $sucursal = $orden->sucursal_id ?? $orden->persona?->sucursal_id;
        abort_unless(
            $this->acceso->permiteSucursal($actor, $sucursal !== null ? (int) $sucursal : null),
            403,
            'Esta compra es de otra sucursal.',
        );
    }

    /**
     * Acota una consulta de algo que cuelga de una persona (`persona_id`) a las personas
     * que ve el actor: sus sedes y, si imparte, sus clientes.
     *
     * @param  Builder<*>  $consulta
     * @param  bool  $sinPersonaVisible  lo que no cuelga de nadie (`null`) lo ve todo el equipo
     */
    public function acotarPorPersona(Builder $consulta, ?Usuario $actor, string $columna = 'persona_id', bool $sinPersonaVisible = false): void
    {
        if (! $actor instanceof Usuario) {
            return;
        }
        $sedes = $this->acceso->sucursalesPermitidas($actor);
        $acotaClientes = $this->clientes->esAcotado($actor);
        if ($sedes === null && ! $acotaClientes) {
            return;
        }
        $personas = PersonaTenant::withTrashed()->select('id')
            ->when($sedes !== null, fn ($q) => $q->whereIn('sucursal_id', $sedes));
        if ($acotaClientes) {
            $this->clientes->acotar($personas, $actor);
        }
        $sinPersonaVisible
            ? $consulta->where(fn ($w) => $w->whereNull($columna)->orWhereIn($columna, $personas))
            : $consulta->whereIn($columna, $personas);
    }

    /**
     * La consulta acotada a quien hace la solicitud ({@see self::acotarPorPersona()}).
     *
     * @template TConsulta of Builder<*>
     *
     * @param  TConsulta  $consulta
     * @return TConsulta
     */
    public function acotada(Builder $consulta, Request $request, string $columna = 'persona_id', bool $sinPersonaVisible = false): Builder
    {
        $this->acotarPorPersona($consulta, self::actor($request), $columna, $sinPersonaVisible);

        return $consulta;
    }

    private static function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
