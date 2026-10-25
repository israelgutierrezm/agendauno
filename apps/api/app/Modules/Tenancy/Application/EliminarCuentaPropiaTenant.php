<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\BajaNoPermitida;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Eliminar la cuenta propia desde la web o la app (ADR 0211; lo piden Apple y Google
 * para cualquier rol). La cuenta deja de servir en el acto: se cierran sus sesiones y
 * queda dada de baja. Si además es cliente del negocio, queda pedida la baja de sus
 * datos (ARCO), que el negocio atiende en el plazo de la ley: anonimiza lo que la
 * identifica y conserva solo lo que la ley obliga a guardar (pagos y facturas).
 *
 * El único dueño no puede eliminar su cuenta mientras el negocio exista: antes da de
 * baja el negocio desde su suscripción.
 */
class EliminarCuentaPropiaTenant
{
    public const ELIMINADA = 'eliminada';

    public const SOLICITADA = 'solicitada';

    public function __construct(
        private readonly AutenticacionTenant $auth,
        private readonly BajaDePersonaTenant $bajaDePersona,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @return self::ELIMINADA|self::SOLICITADA
     */
    public function ejecutar(Usuario $usuario, ?string $motivo): string
    {
        if ($usuario->tieneRol('propietario') && $this->duenos() <= 1) {
            throw new BajaNoPermitida('Eres el dueño del negocio: para eliminar tu cuenta, primero da de baja el negocio desde Mi suscripción.');
        }

        return DB::connection('tenant')->transaction(function () use ($usuario, $motivo): string {
            $persona = PersonaTenant::query()->where('usuario_id', $usuario->getKey())->first();
            $estado = self::ELIMINADA;
            if ($persona instanceof PersonaTenant) {
                $this->bajaDePersona->solicitar($persona, $motivo);
                $estado = self::SOLICITADA;
            }

            $this->auth->revocarTodos($usuario);
            $usuario->forceFill(['eliminado_por' => $usuario->getKey()])->save();
            $usuario->delete();
            $this->auditoria->registrar($usuario, 'usuario.baja_propia', 'usuario', (string) $usuario->ulid, [
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'roles' => $usuario->rolesEfectivos(),
            ], null, $motivo);

            return $estado;
        });
    }

    private function duenos(): int
    {
        return Usuario::query()->get()->filter(static fn (Usuario $u): bool => $u->tieneRol('propietario'))->count();
    }
}
