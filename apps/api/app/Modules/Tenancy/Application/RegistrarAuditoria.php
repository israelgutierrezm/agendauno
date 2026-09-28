<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Registra un asiento de auditoria (append-only) de una operacion sensible en la BD
 * del tenant: actor, accion, entidad afectada, antes/despues, motivo, y la IP +
 * correlation-id del request actual. Se cablea en las operaciones que mueven dinero,
 * creditos, accesos o permisos. Ver docs/audits/agendauno-competitive-audit.md (R38).
 */
class RegistrarAuditoria
{
    /**
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     */
    public function registrar(
        ?Usuario $actor,
        string $accion,
        string $entidadTipo,
        ?string $entidadId = null,
        ?array $antes = null,
        ?array $despues = null,
        ?string $motivo = null,
    ): AuditoriaTenant {
        $request = request();

        return AuditoriaTenant::query()->create([
            'actor_id' => $actor?->getKey(),
            'actor_nombre' => $actor?->name,
            'accion' => $accion,
            'entidad_tipo' => $entidadTipo,
            'entidad_id' => $entidadId,
            'motivo' => $motivo,
            'antes' => $antes,
            'despues' => $despues,
            'ip' => $request->ip(),
            'correlation_id' => $request->header('X-Correlation-ID'),
        ]);
    }
}
