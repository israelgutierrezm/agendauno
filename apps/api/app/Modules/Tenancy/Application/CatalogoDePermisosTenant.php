<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

/**
 * Catálogo de permisos y roles del data plane (por tenant). Los roles viven en la
 * BD del estudio: la misma persona puede tener roles distintos en dos estudios.
 * `propietario` (`['*']`) tiene todos los permisos.
 */
class CatalogoDePermisosTenant
{
    /**
     * @return array<string, list<string>>
     */
    public static function roles(): array
    {
        return [
            'propietario' => ['*'],
            'admin' => [
                'estudio.gestionar', 'miembros.gestionar', 'miembros.ver',
                'documentos.gestionar', 'documentos.subir',
                'formularios.gestionar', 'formularios.responder',
                'catalogo.ver', 'catalogo.gestionar',
                'organizaciones.ver', 'organizaciones.gestionar',
                'sucursales.ver', 'sucursales.gestionar',
                'agenda.ver', 'agenda.gestionar',
                'productos.ver', 'productos.gestionar',
                'membresias.gestionar', 'creditos.gestionar', 'derechos.ver',
                'reservas.ver', 'reservas.gestionar', 'asistencia.marcar', 'checkins.registrar',
                'ordenes.ver', 'ordenes.gestionar', 'pagos.reembolsar',
                'facturacion.ver', 'usuarios.invitar', 'usuarios.gestionar', 'auditoria.ver',
                'comunicaciones.gestionar', 'comunicaciones.ver',
                'automatizaciones.gestionar', 'tareas.ver', 'tareas.gestionar',
                'promociones.gestionar',
                'lealtad.ver', 'lealtad.gestionar',
                'inventario.ver', 'inventario.gestionar', 'pos.vender',
            ],
            'recepcionista' => [
                'miembros.gestionar', 'miembros.ver', 'documentos.subir', 'formularios.responder',
                'catalogo.ver', 'organizaciones.ver', 'sucursales.ver', 'agenda.ver',
                'productos.ver', 'membresias.gestionar', 'creditos.gestionar', 'derechos.ver',
                'reservas.ver', 'reservas.gestionar', 'asistencia.marcar', 'checkins.registrar',
                'ordenes.ver', 'ordenes.gestionar', 'comunicaciones.ver',
                'tareas.ver', 'tareas.gestionar',
                'lealtad.ver', 'lealtad.gestionar',
                'inventario.ver', 'inventario.gestionar', 'pos.vender',
            ],
            'instructor' => [
                'miembros.ver', 'documentos.subir', 'formularios.responder', 'catalogo.ver',
                'sucursales.ver', 'agenda.ver', 'derechos.ver',
                'reservas.ver', 'asistencia.marcar', 'checkins.registrar',
                'tareas.ver', 'tareas.gestionar',
            ],
            'miembro' => [
                'formularios.responder',
            ],
        ];
    }

    /**
     * Todos los permisos del negocio, agrupados por área, para armar roles propios.
     * Cada permiso que se exige en una ruta debe estar aquí (lo revisa una prueba).
     *
     * @return array<string, list<string>>
     */
    public static function catalogo(): array
    {
        return [
            'agenda' => ['agenda.ver', 'agenda.gestionar', 'reservas.ver', 'reservas.gestionar', 'asistencia.marcar', 'checkins.registrar'],
            'clientes' => ['miembros.ver', 'miembros.gestionar', 'derechos.ver', 'documentos.subir', 'documentos.gestionar', 'formularios.responder', 'formularios.gestionar'],
            'membresias' => ['catalogo.ver', 'catalogo.gestionar', 'productos.ver', 'productos.gestionar', 'membresias.gestionar', 'creditos.gestionar', 'promociones.gestionar'],
            'cobros' => ['ordenes.ver', 'ordenes.gestionar', 'pagos.reembolsar', 'facturacion.ver'],
            'punto_venta' => ['pos.vender', 'inventario.ver', 'inventario.gestionar'],
            'equipo' => ['usuarios.invitar', 'usuarios.gestionar', 'roles.gestionar', 'tareas.ver', 'tareas.gestionar'],
            'marketing' => ['comunicaciones.ver', 'comunicaciones.gestionar', 'automatizaciones.gestionar', 'lealtad.ver', 'lealtad.gestionar'],
            'negocio' => ['estudio.gestionar', 'sucursales.ver', 'sucursales.gestionar', 'organizaciones.ver', 'organizaciones.gestionar', 'integraciones.configurar', 'pagos.configurar', 'auditoria.ver'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function permisosDelCatalogo(): array
    {
        return array_merge(...array_values(self::catalogo()));
    }

    /**
     * Lo que cada permiso necesita para servir en la app: las pantallas y formularios
     * que abre también leen otras listas (p. ej. «gestionar agenda» arma clases con el
     * catálogo y las sucursales). Un rol propio debe traerlos; no se agregan solos.
     * Completo: los requisitos de un requisito ya están en la lista.
     *
     * @return array<string, list<string>>
     */
    public static function requisitos(): array
    {
        return [
            'agenda.gestionar' => ['agenda.ver', 'catalogo.ver', 'sucursales.ver'],
            'reservas.ver' => ['agenda.ver'],
            'reservas.gestionar' => ['agenda.ver', 'reservas.ver', 'miembros.ver', 'catalogo.ver', 'sucursales.ver'],
            'asistencia.marcar' => ['agenda.ver', 'reservas.ver'],
            'checkins.registrar' => ['miembros.ver'],
            'miembros.gestionar' => ['miembros.ver'],
            'derechos.ver' => ['miembros.ver'],
            'documentos.subir' => ['miembros.ver'],
            'documentos.gestionar' => ['documentos.subir', 'miembros.ver'],
            'formularios.gestionar' => ['formularios.responder'],
            'catalogo.gestionar' => ['catalogo.ver', 'agenda.ver'],
            'productos.gestionar' => ['productos.ver', 'catalogo.ver', 'sucursales.ver'],
            'ordenes.gestionar' => ['ordenes.ver', 'productos.ver', 'miembros.ver', 'derechos.ver'],
            'pagos.reembolsar' => ['ordenes.ver'],
            'inventario.ver' => ['sucursales.ver'],
            'inventario.gestionar' => ['inventario.ver', 'sucursales.ver'],
            'pos.vender' => ['inventario.ver', 'sucursales.ver'],
            'lealtad.ver' => ['miembros.ver'],
            'lealtad.gestionar' => ['lealtad.ver', 'miembros.ver'],
            'comunicaciones.gestionar' => ['comunicaciones.ver'],
            'sucursales.gestionar' => ['sucursales.ver', 'organizaciones.ver'],
            'organizaciones.gestionar' => ['organizaciones.ver'],
            'usuarios.gestionar' => ['usuarios.invitar', 'sucursales.ver', 'miembros.ver'],
            'tareas.gestionar' => ['tareas.ver'],
        ];
    }

    /**
     * Los requisitos que le faltan a un conjunto de permisos, por permiso.
     *
     * @param  list<string>  $permisos
     * @return array<string, list<string>>
     */
    public static function requisitosFaltantes(array $permisos): array
    {
        $faltan = [];
        foreach (self::requisitos() as $permiso => $necesita) {
            if (in_array($permiso, $permisos, true) && ($ausentes = array_values(array_diff($necesita, $permisos))) !== []) {
                $faltan[$permiso] = $ausentes;
            }
        }

        return $faltan;
    }

    public static function puede(string $rol, string $permiso): bool
    {
        $permisos = self::roles()[$rol] ?? [];

        return in_array('*', $permisos, true) || in_array($permiso, $permisos, true);
    }

    /**
     * ¿Alguno de los roles concede el permiso? (unión de permisos multi-rol).
     *
     * @param  list<string>  $roles
     */
    public static function puedeAlguno(array $roles, string $permiso): bool
    {
        foreach ($roles as $rol) {
            if (self::puede($rol, $permiso)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unión de permisos de un conjunto de roles. Si alguno es total (`*`), devuelve `['*']`.
     *
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function permisosDe(array $roles): array
    {
        $union = [];
        foreach ($roles as $rol) {
            $permisos = self::roles()[$rol] ?? [];
            if (in_array('*', $permisos, true)) {
                return ['*'];
            }
            $union = array_merge($union, $permisos);
        }

        return array_values(array_unique($union));
    }

    /**
     * Roles que el estudio puede asignar al invitar personal (nunca `propietario`).
     *
     * @return list<string>
     */
    public static function rolesAsignables(): array
    {
        return ['admin', 'recepcionista', 'instructor', 'miembro'];
    }

    /**
     * Todos los roles del catálogo, incluido `propietario` (asignables desde el
     * apartado Usuarios; conceder/quitar `propietario` está protegido en el controlador).
     *
     * @return list<string>
     */
    public static function todosLosRoles(): array
    {
        return array_keys(self::roles());
    }

    /**
     * Scopes que puede tener una llave de API de integración (R40). De solo lectura
     * por ahora ("API keys initially"), acotados a datos operativos consultables.
     *
     * @return list<string>
     */
    public static function scopesApi(): array
    {
        return ['miembros.ver', 'agenda.ver', 'reservas.ver', 'derechos.ver', 'ordenes.ver'];
    }

    /**
     * Jerarquía de privilegio, del más alto al más bajo. Define el rol PRINCIPAL
     * cuando un usuario tiene varios.
     *
     * @return list<string>
     */
    public static function jerarquia(): array
    {
        return ['propietario', 'admin', 'recepcionista', 'instructor', 'miembro'];
    }

    /**
     * Qué parte de la app le toca a un rol: el negocio (`equipo`), el portal de quien
     * imparte (`instructor`) o la cuenta del alumno (`miembro`).
     */
    public static function faceta(string $rol): string
    {
        return match ($rol) {
            'instructor' => 'instructor',
            'miembro' => 'miembro',
            default => 'equipo',
        };
    }

    /**
     * Los roles con los que puede entrar, del más amplio al más acotado, con su
     * faceta (para elegir al entrar y cambiar después).
     *
     * @param  list<string>  $roles
     * @return list<array{clave: string, faceta: string}>
     */
    public static function rolesDisponibles(array $roles): array
    {
        $orden = array_flip(self::jerarquia());
        usort($roles, static fn (string $a, string $b): int => ($orden[$a] ?? PHP_INT_MAX) <=> ($orden[$b] ?? PHP_INT_MAX));

        return array_map(static fn (string $rol): array => ['clave' => $rol, 'faceta' => self::faceta($rol)], array_values(array_unique($roles)));
    }

    /**
     * Rol principal (el más privilegiado) de un conjunto de roles.
     *
     * @param  list<string>  $roles
     */
    public static function rolPrincipal(array $roles): string
    {
        foreach (self::jerarquia() as $rol) {
            if (in_array($rol, $roles, true)) {
                return $rol;
            }
        }

        return $roles[0] ?? 'miembro';
    }
}
