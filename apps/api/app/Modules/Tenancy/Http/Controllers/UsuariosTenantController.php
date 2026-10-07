<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BajasTenant;
use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\Application\EnviarActivacionTenant;
use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AsignacionPersonalTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de usuarios tenant-local (personal y personas con acceso). El
 * propietario/admin invita personal con un rol; la cuenta se crea inactiva con un
 * token de activación de un solo uso (la contraseña la define el invitado al
 * activar, en `/app/{estudio}/activar`).
 *
 * Multi-rol: desde el apartado Usuarios se asignan varios roles a la misma cuenta
 * (p. ej. miembro y profesor). El rol `propietario` está protegido: siempre debe
 * existir al menos uno, solo un propietario puede conceder/quitar ese rol, y nadie
 * puede quitárselo a sí mismo. Opera sobre la BD del estudio resuelto.
 *
 * Baja lógica: dar de baja quita el acceso sin borrar (queda con quién y cuándo);
 * invitar de nuevo ese correo reactiva la misma cuenta.
 */
class UsuariosTenantController
{
    public function __construct(
        private readonly EnviarActivacionTenant $enviarActivacion,
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly BajasTenant $bajas,
        private readonly RegistrarAuditoria $auditoria,
        private readonly RolesTenant $roles,
    ) {}

    /**
     * Lista los instructores del estudio (con un rol de quien imparte, de sistema o
     * propio, ADR 0078) para poder asignarlos a sesiones. Solo ulid + nombre; nunca
     * datos sensibles.
     */
    public function instructores(Request $request): JsonResponse
    {
        $instructores = Usuario::query()
            ->profesionales()
            ->orderBy('name')
            ->get(['id', 'ulid', 'name', 'nombre', 'primer_apellido', 'foto_ruta']);

        $sucursales = $this->sucursalesDe(array_map('intval', $instructores->modelKeys()));

        // `?resumen=1` (tarjetas del equipo): su agenda de la semana, sus sedes y, para
        // quien ve a los clientes, sus reseñas. Nada de contacto: eso es privado.
        $resumenes = [];
        if ($request->boolean('resumen')) {
            $actor = $request->attributes->get('usuario_tenant');
            $resumenes = $this->resumenesDelEquipo(
                array_map('intval', $instructores->modelKeys()),
                $actor instanceof Usuario && $actor->puede('miembros.ver'),
            );
        }

        return response()->json([
            'data' => $instructores->map(static fn (Usuario $u): array => [
                'id' => $u->ulid,
                'nombre' => $u->name,
                // Para ubicarlo (tarjetas): primer nombre + apellido paterno y foto.
                'nombre_corto' => $u->nombreCorto(),
                'foto_url' => $u->fotoUrl(),
                // Dónde atiende (ulid de cada sucursal): la agenda de una sucursal muestra
                // solo a los suyos. Vacío = no tiene sucursal propia (aparece en todas).
                'sucursales' => $sucursales[$u->getKey()] ?? [],
                ...(isset($resumenes[$u->getKey()]) ? ['resumen' => $resumenes[$u->getKey()]] : []),
            ])->all(),
        ]);
    }

    /**
     * Las sucursales donde atiende cada profesional: las que tiene asignadas (puede
     * ser más de una) y aquellas donde tiene horario de atención.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function sucursalesDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $sucursales = [];
        $agregar = static function (int $usuario, ?string $sucursal) use (&$sucursales): void {
            if (is_string($sucursal) && ! in_array($sucursal, $sucursales[$usuario] ?? [], true)) {
                $sucursales[$usuario][] = $sucursal;
            }
        };
        foreach (AsignacionPersonalTenant::query()->whereIn('usuario_id', $ids)->with('sucursal:id,ulid')->get() as $asignacion) {
            $agregar((int) $asignacion->usuario_id, $asignacion->sucursal?->ulid);
        }
        foreach (HorarioAtencionTenant::query()->whereIn('instructor_id', $ids)->whereNotNull('sucursal_id')->with('sucursal:id,ulid')->get() as $horario) {
            $agregar((int) $horario->instructor_id, $horario->sucursal?->ulid);
        }

        return $sucursales;
    }

    /**
     * Por instructor: su próxima clase o cita, cuántas tiene en los próximos 7 días,
     * en qué sedes trabaja (por su agenda y su horario de atención) y, si se pide, el
     * promedio de sus reseñas. Unas cuantas consultas para todo el equipo.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function resumenesDelEquipo(array $ids, bool $conResenas): array
    {
        if ($ids === []) {
            return [];
        }

        $sesiones = SesionTenant::query()
            ->whereIn('instructor_id', $ids)
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->whereBetween('inicia_en', [now(), now()->addDays(7)])
            ->with(['oferta:id,nombre', 'sucursal:id,nombre'])
            ->orderBy('inicia_en')
            ->get();
        $horarios = HorarioAtencionTenant::query()
            ->whereIn('instructor_id', $ids)
            ->with('sucursal:id,nombre')
            ->get();
        $resenas = $conResenas
            ? ResenaTenant::query()
                ->whereIn('instructor_id', $ids)
                ->groupBy('instructor_id')
                ->selectRaw('instructor_id, AVG(calificacion) as promedio, COUNT(*) as total')
                ->get()
                ->keyBy('instructor_id')
            : collect();

        $resumenes = [];
        foreach ($ids as $id) {
            $suyas = $sesiones->where('instructor_id', $id);
            $proxima = $suyas->first();
            $sedes = $suyas->pluck('sucursal.nombre')
                ->merge($horarios->where('instructor_id', $id)->pluck('sucursal.nombre'))
                ->filter()->unique()->sort()->values()->all();
            $resena = $resenas->get($id);

            $resumenes[$id] = [
                'proxima' => $proxima instanceof SesionTenant ? [
                    'inicia_en' => $proxima->inicia_en->toIso8601String(),
                    'zona_horaria' => $proxima->zona_horaria,
                    'clase' => $proxima->oferta?->nombre,
                    'tipo' => $proxima->tipo->value,
                ] : null,
                'semana' => [
                    'clases' => $suyas->where('tipo', TipoSesionTenant::Clase)->count(),
                    'citas' => $suyas->where('tipo', TipoSesionTenant::Cita)->count(),
                ],
                'sedes' => $sedes,
                'resenas' => $resena instanceof ResenaTenant ? [
                    'promedio' => round((float) $resena->getAttribute('promedio'), 1),
                    'total' => (int) $resena->getAttribute('total'),
                ] : null,
            ];
        }

        return $resumenes;
    }

    /**
     * Perfil de un instructor para quien administra al equipo: quién es y la
     * persona a la que se cuelga su expediente (se crea la primera vez, sin
     * contar como alumno). Sin correo ni teléfono.
     */
    public function instructor(Request $request): JsonResponse
    {
        $usuario = Usuario::query()
            ->where('ulid', (string) $request->route('usuario'))
            ->profesionales()
            ->firstOrFail();

        $persona = $this->personas->asegurar($usuario, TipoPersonaTenant::Instructor);

        return response()->json(['data' => [
            'id' => $usuario->ulid,
            'nombre' => $usuario->name,
            'nombre_corto' => $usuario->nombreCorto(),
            'foto_url' => $usuario->fotoUrl(),
            'activo' => $usuario->activo,
            'desde' => $usuario->created_at?->toDateString(),
            'persona_id' => $persona->ulid,
        ]]);
    }

    /**
     * Todos los usuarios del estudio con sus roles, para el apartado Usuarios.
     */
    public function index(Request $request): JsonResponse
    {
        // `?estado=baja`: los dados de baja, con cuándo y quién.
        $deBaja = (string) $request->query('estado', '') === 'baja';
        $usuarios = ($deBaja ? Usuario::onlyTrashed() : Usuario::query())->orderBy('name')->get();
        $quienes = $this->nombresDe($usuarios->pluck('eliminado_por')->filter()->all());

        $roles = $this->roles->todos();

        return response()->json([
            'data' => $usuarios->map(fn (Usuario $u): array => $this->presentar($u, $quienes))->all(),
            'roles' => array_keys($roles),
            // Los propios traen su nombre; los de sistema se nombran en la app.
            'roles_detalle' => array_values(array_map(
                static fn (array $r): array => ['clave' => $r['clave'], 'nombre' => $r['nombre'], 'sistema' => $r['sistema']],
                $roles,
            )),
        ]);
    }

    /**
     * Baja lógica de un usuario del equipo: pierde el acceso (sesiones cerradas). A
     * un dueño solo lo da de baja un dueño, y a nadie con permisos que el actor no
     * tiene ({@see BajasTenant::exigirQueLeAlcance()}).
     */
    public function darDeBaja(Request $request): JsonResponse
    {
        $usuario = Usuario::query()->where('ulid', (string) $request->route('usuario'))->firstOrFail();
        $motivo = $request->validate(['motivo' => ['nullable', 'string', 'max:500']])['motivo'] ?? null;

        $this->bajas->darDeBajaUsuario($usuario, $this->actor($request), $motivo);

        return response()->json(['data' => $this->presentar($usuario->refresh(), $this->nombresDe([$usuario->eliminado_por]))]);
    }

    /**
     * Reactiva a un usuario dado de baja con sus roles de antes: solo si a quien lo
     * hace le alcanza (un dueño lo reactiva un dueño; nadie devuelve permisos que no
     * tiene).
     */
    public function reactivar(Request $request): JsonResponse
    {
        $usuario = Usuario::withTrashed()->where('ulid', (string) $request->route('usuario'))->firstOrFail();

        $actor = $this->actor($request);
        if ($usuario->trashed()) {
            $this->bajas->exigirQueLeAlcance($usuario, $actor);
        }
        $this->bajas->reactivarUsuario($usuario, $actor);

        return response()->json(['data' => $this->presentar($usuario->refresh())]);
    }

    public function invitar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'rol' => ['required', Rule::in([...CatalogoDePermisosTenant::rolesAsignables(), ...$this->clavesPropias()])],
            // Sede opcional: si viene, el usuario queda ACOTADO a ella desde el alta (R19).
            'sucursal_id' => ['nullable', 'string'],
        ]);
        $this->exigirQuePuedaDar($this->actor($request), [(string) $validado['rol']]);

        // Email único dentro de la BD del tenant (también el de alguien dado de baja:
        // ese correo es suyo y se reactiva su cuenta).
        $existente = Usuario::withTrashed()->where('email', $validado['email'])->first();
        if ($existente instanceof Usuario && ! $existente->trashed()) {
            throw ValidationException::withMessages(['email' => ['Ya existe un usuario con ese correo en este estudio.']]);
        }

        // Si se pide sede, se resuelve ANTES de crear el usuario (falla limpio si no existe).
        $sucursal = isset($validado['sucursal_id']) && $validado['sucursal_id'] !== ''
            ? SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail()
            : null;

        $datos = [
            'name' => $validado['nombre'],
            'email' => $validado['email'],
            'rol' => $validado['rol'],
            'roles' => [$validado['rol']],
            'activo' => false,
            'password' => null,
        ];
        $reactivado = $existente instanceof Usuario;
        if ($existente instanceof Usuario) {
            // Vuelve al equipo: misma cuenta (su historial), con el rol de ahora y una
            // invitación nueva para definir su contraseña.
            $this->bajas->reactivarUsuario($existente, $this->actor($request), 'Invitado de nuevo al equipo.');
            $existente->forceFill($datos)->save();
            $usuario = $existente;
        } else {
            $usuario = Usuario::query()->create($datos);
        }

        // Asignación de sede (acota al usuario a esa sucursal con su rol).
        if ($sucursal instanceof SucursalTenant) {
            AsignacionPersonalTenant::query()->create([
                'usuario_id' => $usuario->getKey(),
                'sucursal_id' => $sucursal->getKey(),
                'rol' => $validado['rol'],
            ]);
        }

        if (! $reactivado) {
            $this->auditoria->registrar($this->actor($request), 'usuario.invitado', 'usuario', (string) $usuario->ulid, null, [
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'rol' => $validado['rol'],
            ]);
        }

        // Genera el token y ENVÍA la invitación por correo.
        $token = $this->enviarActivacion->enviar($this->estudioDe($request), (string) $usuario->email);

        return response()->json(['data' => [
            'usuario' => $this->presentar($usuario),
            'reactivado' => $reactivado,
            'activacion' => app()->environment('production') ? null : ['email' => $usuario->email, 'token' => $token],
        ]], 201);
    }

    /**
     * Reenvía la invitación/activación a un usuario que aún no ha activado su cuenta.
     */
    public function reenviar(Request $request): JsonResponse
    {
        $usuario = Usuario::query()->where('ulid', (string) $request->route('usuario'))->firstOrFail();

        if ($usuario->activo) {
            throw ValidationException::withMessages(['email' => ['Este usuario ya activó su cuenta.']]);
        }

        $token = $this->enviarActivacion->enviar($this->estudioDe($request), (string) $usuario->email);

        return response()->json(['data' => [
            'usuario' => $this->presentar($usuario),
            'activacion' => app()->environment('production') ? null : ['email' => $usuario->email, 'token' => $token],
        ]]);
    }

    private function estudioDe(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }

    /**
     * Reasigna el conjunto de roles de un usuario. Protege el rol `propietario`.
     */
    public function actualizarRoles(Request $request): JsonResponse
    {
        $usuario = Usuario::query()->where('ulid', (string) $request->route('usuario'))->firstOrFail();

        $validado = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(array_keys($this->roles->todos()))],
        ]);

        /** @var list<string> $rolesNuevos */
        $rolesNuevos = array_values(array_unique($validado['roles']));

        $this->protegerPropietario($request, $usuario, $rolesNuevos);
        $antes = $usuario->rolesEfectivos();
        // Lo que se da o se quita (el de dueño tiene su propia regla, arriba).
        $cambios = array_diff([...array_diff($rolesNuevos, $antes), ...array_diff($antes, $rolesNuevos)], ['propietario']);
        $this->exigirQuePuedaDar($this->actor($request), array_values($cambios));

        $usuario->update([
            'roles' => $rolesNuevos,
            'rol' => $this->roles->principal($rolesNuevos),
        ]);
        if ($antes !== $rolesNuevos) {
            $this->auditoria->registrar($this->actor($request), 'usuario.roles', 'usuario', (string) $usuario->ulid,
                ['nombre' => $usuario->name, 'roles' => $antes],
                ['nombre' => $usuario->name, 'roles' => $rolesNuevos],
            );
        }

        return response()->json(['data' => $this->presentar($usuario->refresh())]);
    }

    /**
     * Reglas del rol `propietario`: siempre ≥1, solo un propietario lo concede/quita,
     * y nadie se lo quita a sí mismo.
     *
     * @param  list<string>  $rolesNuevos
     */
    private function protegerPropietario(Request $request, Usuario $usuario, array $rolesNuevos): void
    {
        $eraPropietario = $usuario->tieneRol('propietario');
        $seraPropietario = in_array('propietario', $rolesNuevos, true);

        if ($eraPropietario === $seraPropietario) {
            return; // No se toca el rol de dueño.
        }

        $actor = $request->attributes->get('usuario_tenant');
        // Quien actúa como dueño (su rol activo), no quien además lo es.
        if (! ($actor instanceof Usuario) || ! $actor->actuaComo('propietario')) {
            throw ValidationException::withMessages([
                'roles' => ['Solo un dueño puede conceder o quitar el rol de dueño.'],
            ]);
        }

        if ($eraPropietario && ! $seraPropietario) {
            if ((int) $actor->getKey() === (int) $usuario->getKey()) {
                throw ValidationException::withMessages([
                    'roles' => ['No puedes quitarte a ti mismo el rol de dueño.'],
                ]);
            }

            if ($this->contarPropietarios() <= 1) {
                throw ValidationException::withMessages([
                    'roles' => ['Debe existir al menos un dueño en el estudio.'],
                ]);
            }
        }
    }

    private function contarPropietarios(): int
    {
        return Usuario::query()->whereJsonContains('roles', 'propietario')->count();
    }

    /**
     * Nadie da (ni quita) un rol con permisos que no tiene en su rol activo.
     *
     * @param  list<string>  $roles
     */
    private function exigirQuePuedaDar(Usuario $actor, array $roles): void
    {
        $propios = $this->roles->permisosDe($actor->rolesVigentes());
        foreach ($roles as $rol) {
            if (! RolesTenant::cabenEn($this->roles->permisosDe([$rol]), $propios)) {
                throw ValidationException::withMessages([
                    'roles' => ['No puedes dar ni quitar un rol con permisos que tú no tienes.'],
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function clavesPropias(): array
    {
        return array_keys(array_filter($this->roles->todos(), static fn (array $r): bool => ! $r['sistema']));
    }

    private function actor(Request $request): Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless($actor instanceof Usuario, 401);

        return $actor;
    }

    /**
     * Nombres de quienes dieron de baja (aunque ya no estén en el equipo).
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function nombresDe(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        return $ids === [] ? [] : Usuario::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n): string => (string) $n)->all();
    }

    /**
     * @param  array<int, string>  $quienes  nombres de quienes dieron de baja
     * @return array<string, mixed>
     */
    private function presentar(Usuario $usuario, array $quienes = []): array
    {
        $roles = $usuario->rolesEfectivos();

        return [
            'id' => $usuario->ulid,
            'nombre' => $usuario->name,
            'nombre_corto' => $usuario->nombreCorto(),
            'foto_url' => $usuario->fotoUrl(),
            'email' => $usuario->email,
            'rol' => CatalogoDePermisosTenant::rolPrincipal($roles),
            'roles' => $roles,
            'activo' => (bool) $usuario->activo,
            'dado_de_baja_en' => $usuario->deleted_at?->toIso8601String(),
            'dado_de_baja_por' => $usuario->eliminado_por !== null ? ($quienes[(int) $usuario->eliminado_por] ?? null) : null,
        ];
    }
}
