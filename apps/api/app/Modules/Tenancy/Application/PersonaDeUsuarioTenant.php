<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;

/**
 * La persona operativa de un usuario (a la que se cuelgan documentos, formularios
 * y consentimientos). Se enlaza por `usuario_id`; la primera vez, por correo con una
 * persona aún sin cuenta.
 */
class PersonaDeUsuarioTenant
{
    public function buscar(Usuario $usuario): ?PersonaTenant
    {
        $persona = PersonaTenant::query()->where('usuario_id', $usuario->getKey())->first();
        if ($persona instanceof PersonaTenant) {
            return $persona;
        }

        // Enlace diferido: una persona sin usuario con el mismo correo.
        $porCorreo = PersonaTenant::query()
            ->whereNull('usuario_id')
            ->where('email', $usuario->email)
            ->first();
        $porCorreo?->update(['usuario_id' => $usuario->getKey()]);

        return $porCorreo;
    }

    /**
     * Como `buscar`, pero si no existe la crea (p. ej. el expediente de un
     * instructor que nunca tuvo ficha). No es facturable: no cuenta como alumno.
     */
    public function asegurar(Usuario $usuario, TipoPersonaTenant $tipo): PersonaTenant
    {
        return $this->buscar($usuario) ?? PersonaTenant::query()->create([
            'usuario_id' => $usuario->getKey(),
            'tipo' => $tipo->value,
            'nombre' => $usuario->nombre ?? $usuario->name,
            'primer_apellido' => $usuario->primer_apellido,
            'segundo_apellido' => $usuario->segundo_apellido,
            'email' => $usuario->email,
            'activo' => true,
            'es_facturable' => false,
            'archivado' => false,
        ]);
    }
}
