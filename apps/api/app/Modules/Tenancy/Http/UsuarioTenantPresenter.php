<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http;

use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\CatalogoTemas;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Representación JSON del usuario de la sesión (login, /yo y "Mi perfil"): quién
 * es, sus roles y permisos, su apariencia y su foto.
 */
class UsuarioTenantPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function datos(Usuario $usuario): array
    {
        $roles = $usuario->rolesEfectivos();
        $catalogo = app(RolesTenant::class);
        // Su ficha de cliente o alumno (si la tiene): de ahí sale su celular.
        $ficha = PersonaTenant::query()->where('usuario_id', $usuario->getKey())->first(['id', 'celular', 'fecha_nacimiento', 'genero']);

        return [
            'ulid' => $usuario->ulid,
            'nombre' => $usuario->name,
            // Partes del nombre (editables en "Mi perfil") y la forma corta para
            // ubicar a alguien: primer nombre + apellido paterno.
            'nombre_pila' => $usuario->nombre,
            'primer_apellido' => $usuario->primer_apellido,
            'segundo_apellido' => $usuario->segundo_apellido,
            'nombre_corto' => $usuario->nombreCorto(),
            'email' => $usuario->email,
            // Correo nuevo que espera confirmación por enlace (null si no hay o venció).
            'email_pendiente' => $usuario->email_nuevo_expira_en !== null && now()->lessThan($usuario->email_nuevo_expira_en)
                ? $usuario->email_nuevo
                : null,
            'foto_url' => $usuario->fotoUrl(),
            // Su celular, para avisos y WhatsApp (lo edita en «Mi perfil»). Sin ficha
            // (personal que no es cliente) no hay celular que editar.
            'tiene_ficha' => $ficha instanceof PersonaTenant,
            'celular' => $ficha?->celular,
            // Opcionales, también de su ficha: fecha de nacimiento y género.
            'fecha_nacimiento' => $ficha?->fecha_nacimiento?->toDateString(),
            'genero' => $ficha?->genero?->value,
            // ¿Ya tiene contraseña? (quien entra solo con Google aún no).
            'tiene_contrasena' => $usuario->password !== null && $usuario->password !== '',
            // ¿Conectó Google para entrar con él? (ADR 0093: se conecta desde el perfil).
            'google_conectado' => $usuario->google_id !== null,
            // El rol con el que trabaja en esta sesión, sus permisos y los roles con
            // los que puede entrar (quien tiene varios elige y cambia).
            'rol' => $usuario->rolActivo() ?? $catalogo->principal($roles),
            'roles' => $roles,
            'roles_disponibles' => $catalogo->disponibles($roles),
            'permisos' => $catalogo->permisosDe($usuario->rolesVigentes()),
            // Tema y colores propios: el front los aplica al entrar (ver /apariencia).
            'apariencia' => CatalogoTemas::resolver($usuario->tema, $usuario->tema_personalizacion, $usuario->fuente),
            // Sucursales que puede operar (todas o las que tiene asignadas): con más de
            // una, el panel ofrece elegir con cuál trabaja; con una, se usa esa.
            'sucursales' => self::sucursales($usuario),
            // Personal sin sucursal en un negocio con varias: no ve nada (ADR 0098).
            'sin_sucursal' => app(ResolverAccesoTenant::class)->sinSucursal($usuario),
        ];
    }

    /**
     * @return list<array{id: string, nombre: string, zona_horaria: string|null}>
     */
    private static function sucursales(Usuario $usuario): array
    {
        $permitidas = app(ResolverAccesoTenant::class)->sucursalesPermitidas($usuario);

        return SucursalTenant::query()
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')
            ->get(['id', 'ulid', 'nombre', 'zona_horaria', 'region'])
            ->map(static fn (SucursalTenant $s): array => [
                'id' => (string) $s->ulid,
                'nombre' => (string) $s->nombre,
                'zona_horaria' => $s->zona_horaria,
                // Su región o zona: distingue sedes con el mismo nombre (la barra lo dice).
                'region' => $s->region,
            ])
            ->values()
            ->all();
    }
}
