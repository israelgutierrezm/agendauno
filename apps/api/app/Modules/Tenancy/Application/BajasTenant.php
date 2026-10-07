<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\BajaNoPermitida;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SolicitudPrivacidadTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\QuienCancela;
use Illuminate\Support\Facades\DB;

/**
 * Bajas lógicas y reactivaciones de alumnos/clientes y de usuarios del equipo.
 *
 * Una baja NO borra: la persona o el usuario quedan ocultos (`deleted_at`) con su
 * historial completo (compras, pagos, asistencias) y quién y cuándo los dio de baja.
 * Al dar de baja a un alumno se cierra lo vigente: sus reservas futuras, sus
 * membresías (dejan de renovarse y cobrarse) y sus pagos automáticos; y si su
 * cuenta es solo de alumno, también ella (sin sesiones abiertas).
 *
 * El correo es de la persona para siempre: si vuelve a registrarse (o el negocio la
 * da de alta con ese correo) se REACTIVA con su historial en lugar de crear otra.
 * La cancelación de datos por derechos ARCO es otra cosa ({@see BajaDePersonaTenant}):
 * anonimiza y no se puede reactivar.
 *
 * Todo queda en la bitácora con quién lo hizo.
 */
class BajasTenant
{
    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly AutenticacionTenant $auth,
        private readonly DomiciliacionesTenant $domiciliaciones,
        private readonly RegistrarAuditoria $auditoria,
        private readonly DeudaDeRenovacionTenant $deudas,
        private readonly RolesTenant $roles,
    ) {}

    /**
     * Da de baja a un alumno/cliente. Idempotente.
     */
    public function darDeBajaPersona(PersonaTenant $persona, ?Usuario $actor, ?string $motivo): void
    {
        if ($persona->trashed()) {
            return;
        }

        // Libera sus lugares en clases y citas por venir (sin penalizar créditos).
        ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [
                EstadoReserva::Confirmada->value, EstadoReserva::EnEspera->value,
                EstadoReserva::Ofrecida->value, EstadoReserva::PendientePago->value,
            ])
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', now()))
            ->get()
            ->each(fn (ReservaTenant $r) => $this->reservas->cancelar($r, QuienCancela::Negocio, $actor));

        $vigentes = AcuerdoTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoAcuerdo::Activo->value, EstadoAcuerdo::Suspendido->value, EstadoAcuerdo::Pausado->value])
            ->get();
        foreach ($vigentes as $acuerdo) {
            // Ya no se le cobra nada automáticamente.
            $this->domiciliaciones->desactivar($acuerdo);
        }

        DB::connection('tenant')->transaction(function () use ($persona, $actor, $motivo, $vigentes): void {
            AcuerdoTenant::query()
                ->whereKey($vigentes->modelKeys())
                ->update(['estado' => EstadoAcuerdo::Cancelado->value]);
            $this->deudas->anular($vigentes->modelKeys(), $actor);

            $usuario = $this->usuarioSoloAlumno($persona);
            if ($usuario instanceof Usuario) {
                $this->auth->revocarTodos($usuario);
                $usuario->forceFill(['eliminado_por' => $actor?->getKey()])->save();
                $usuario->delete();
            }

            $persona->forceFill(['eliminado_por' => $actor?->getKey()])->save();
            $persona->delete();

            $this->auditoria->registrar(
                $actor,
                'miembro.baja',
                'persona',
                (string) $persona->ulid,
                $this->datos($persona) + ['membresias_canceladas' => $vigentes->count()],
                null,
                $motivo,
            );
        });
    }

    /**
     * Reactiva a un alumno/cliente dado de baja (y su cuenta, si se dio de baja con
     * él). Vuelve con su historial; las membresías canceladas no regresan.
     */
    public function reactivarPersona(PersonaTenant $persona, ?Usuario $actor, ?string $motivo = null): void
    {
        $this->exigirNoAnonimizada($persona);
        if (! $persona->trashed()) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($persona, $actor, $motivo): void {
            $persona->restore();
            $persona->forceFill(['eliminado_por' => null])->save();

            $usuario = $persona->usuario_id !== null
                ? Usuario::withTrashed()->find($persona->usuario_id)
                : null;
            if ($usuario instanceof Usuario && $usuario->trashed()) {
                $usuario->restore();
                $usuario->forceFill(['eliminado_por' => null])->save();
            }

            $this->auditoria->registrar($actor, 'miembro.reactivado', 'persona', (string) $persona->ulid, null, $this->datos($persona), $motivo);
        });
    }

    /**
     * Da de baja a un usuario del equipo: pierde el acceso (sesiones cerradas). No
     * puede darse de baja a sí mismo ni dejar al negocio sin dueño, y debe alcanzarle
     * ({@see exigirQueLeAlcance()}).
     */
    public function darDeBajaUsuario(Usuario $usuario, Usuario $actor, ?string $motivo): void
    {
        if ($usuario->trashed()) {
            return;
        }
        if ((int) $usuario->getKey() === (int) $actor->getKey()) {
            throw new BajaNoPermitida('No puedes darte de baja a ti mismo.');
        }
        $this->exigirQueLeAlcance($usuario, $actor);
        if ($usuario->tieneRol('propietario') && $this->duenos() <= 1) {
            throw new BajaNoPermitida('El negocio debe quedarse con al menos un dueño.');
        }

        DB::connection('tenant')->transaction(function () use ($usuario, $actor, $motivo): void {
            $this->auth->revocarTodos($usuario);
            $usuario->forceFill(['eliminado_por' => $actor->getKey()])->save();
            $usuario->delete();

            $this->auditoria->registrar($actor, 'usuario.baja', 'usuario', (string) $usuario->ulid, [
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'roles' => $usuario->rolesEfectivos(),
            ], null, $motivo);
        });
    }

    /**
     * Nadie da de baja ni reactiva a alguien del equipo por encima de él (ADR 0057),
     * igual que al cambiar roles: a un dueño solo lo toca quien actúa como dueño (su
     * rol activo), y los permisos de los roles de la persona deben caber en los del
     * rol activo de quien actúa.
     */
    public function exigirQueLeAlcance(Usuario $usuario, Usuario $actor): void
    {
        if ($usuario->tieneRol('propietario') && ! $actor->actuaComo('propietario')) {
            throw new BajaNoPermitida('Solo un dueño puede dar de baja o reactivar a otro dueño.');
        }
        $propios = $this->roles->permisosDe($actor->rolesVigentes());
        if (! RolesTenant::cabenEn($this->roles->permisosDe($usuario->rolesEfectivos()), $propios)) {
            throw new BajaNoPermitida('No puedes dar de baja ni reactivar a alguien con permisos que tú no tienes.');
        }
    }

    /**
     * Reactiva a un usuario del equipo con sus roles de antes. Quien lo reactiva así
     * debe alcanzarle: el controlador lo exige antes con {@see exigirQueLeAlcance()}.
     * Al invitarlo de nuevo no hace falta: vuelve con el rol de la invitación (que se
     * revisa allá), no con los de antes.
     */
    public function reactivarUsuario(Usuario $usuario, ?Usuario $actor, ?string $motivo = null): void
    {
        if (! $usuario->trashed()) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($usuario, $actor, $motivo): void {
            $usuario->restore();
            $usuario->forceFill(['eliminado_por' => null])->save();

            $this->auditoria->registrar($actor, 'usuario.reactivado', 'usuario', (string) $usuario->ulid, null, [
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'roles' => $usuario->rolesEfectivos(),
            ], $motivo);
        });
    }

    /**
     * Quien pidió cancelar sus datos (ARCO) quedó anonimizado: no se reactiva; si
     * vuelve, es un registro nuevo.
     */
    private function exigirNoAnonimizada(PersonaTenant $persona): void
    {
        $cancelada = SolicitudPrivacidadTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('estado', SolicitudPrivacidadTenant::ATENDIDA)
            ->exists();
        if ($cancelada) {
            throw new BajaNoPermitida('Esta persona pidió cancelar sus datos (ARCO): no se puede reactivar; regístrala como nueva.');
        }
    }

    /**
     * La cuenta del alumno, si solo es de alumno (si también es del equipo, la
     * cuenta sigue: solo se da de baja su ficha de alumno).
     */
    private function usuarioSoloAlumno(PersonaTenant $persona): ?Usuario
    {
        $usuario = $persona->usuario_id !== null ? Usuario::query()->find($persona->usuario_id) : null;
        if (! $usuario instanceof Usuario) {
            return null;
        }

        return array_diff($usuario->rolesEfectivos(), ['miembro']) === [] ? $usuario : null;
    }

    private function duenos(): int
    {
        return Usuario::query()->get()->filter(static fn (Usuario $u): bool => $u->tieneRol('propietario'))->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(PersonaTenant $persona): array
    {
        return [
            'nombre' => $persona->nombreCompleto(),
            'email' => $persona->email,
            'celular' => $persona->celular,
        ];
    }
}
