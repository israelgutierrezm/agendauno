<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Baja lógica de registros de catálogo y configuración (promociones, plantillas,
 * recursos, reglas, excepciones de horario, webhooks, asignaciones de sede):
 * quedan ocultos y dejan de usarse, pero no se borran; se guarda quién los eliminó
 * y la bitácora conserva qué eran (`{tipo}.eliminado`). Si se vuelven a crear con
 * su misma clave (p. ej. el código de una promoción), se restauran
 * (`{tipo}.restaurado`).
 */
class EliminacionesTenant
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /**
     * @param  array<string, mixed>  $antes  qué era (sin datos secretos)
     */
    public function eliminar(Model $registro, string $entidadTipo, array $antes): void
    {
        $actor = $this->actor();

        DB::connection('tenant')->transaction(function () use ($registro, $entidadTipo, $antes, $actor): void {
            $registro->forceFill(['eliminado_por' => $actor?->getKey()])->save();
            $registro->delete();

            $this->auditoria->registrar($actor, $entidadTipo.'.eliminado', $entidadTipo, (string) $registro->getAttribute('ulid'), $antes);
        });
    }

    /**
     * Un registro eliminado volvió a crearse con su misma clave: se restauró.
     *
     * @param  array<string, mixed>  $despues
     */
    public function restaurado(Model $registro, string $entidadTipo, array $despues): void
    {
        $registro->forceFill(['eliminado_por' => null])->save();
        $this->auditoria->registrar($this->actor(), $entidadTipo.'.restaurado', $entidadTipo, (string) $registro->getAttribute('ulid'), null, $despues);
    }

    private function actor(): ?Usuario
    {
        $actor = request()->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
