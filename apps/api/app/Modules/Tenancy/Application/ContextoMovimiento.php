<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Contexto auditable de un asiento del ledger: DE DONDE nace (origen), a QUE entidad
 * se refiere (reserva/acuerdo/…), QUIEN lo provocó (actor) y datos extra (metadata).
 * Se adjunta al registrar un {@see MovimientoCreditoTenant}
 * para que cada movimiento de crédito quede trazable. Todos los campos son opcionales:
 * los flujos del sistema (ciclos) no tienen actor; una concesión puede no tener
 * referencia.
 */
final readonly class ContextoMovimiento
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public ?OrigenMovimiento $origen = null,
        public ?string $referenciaTipo = null,
        public ?string $referenciaId = null,
        public ?Usuario $actor = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Contexto de un movimiento provocado por una acción sobre una entidad concreta
     * (p. ej. una reserva o un acuerdo), opcionalmente por un actor identificado.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function para(
        OrigenMovimiento $origen,
        ?string $referenciaTipo = null,
        ?string $referenciaId = null,
        ?Usuario $actor = null,
        ?array $metadata = null,
    ): self {
        return new self($origen, $referenciaTipo, $referenciaId, $actor, $metadata);
    }
}
