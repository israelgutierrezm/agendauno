<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Models\User;
use App\Modules\Personas\Models\Persona;
use App\Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * Vincula un usuario con un tenant: crea la pertenencia (pivote `tenant_user`),
 * enlaza su Persona y le asigna roles tenant-wide.
 *
 * Ojo: esto NO es la "Membresía" comercial; es solo el acceso del usuario al tenant.
 */
class VincularUsuarioATenant
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @param  list<string>  $roles
     * @param  array{nombre?: string, apellidos?: string|null, email?: string|null}  $persona
     */
    public function ejecutar(Tenant $tenant, User $user, array $roles = [], array $persona = []): Persona
    {
        $tenant->users()->syncWithoutDetaching([$user->id => ['status' => 'active']]);

        $teamPrevio = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($tenant->id);

        try {
            if ($roles !== []) {
                $user->assignRole($roles);
            }
        } finally {
            $this->registrar->setPermissionsTeamId($teamPrevio);
        }

        return Persona::withoutGlobalScope('tenant')->updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            [
                'nombre' => $persona['nombre'] ?? $user->name,
                // Persona del control plane (legacy): su columna es `apellidos`; el
                // desglose (primer_apellido…) solo existe en la persona del tenant.
                'apellidos' => $persona['apellidos'] ?? null,
                'email' => $persona['email'] ?? $user->email,
            ],
        );
    }
}
