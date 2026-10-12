<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Captacion\EstadoProspecto;
use App\Modules\Tenancy\Captacion\MotivoCierreProspecto;
use App\Modules\Tenancy\Captacion\OrigenProspecto;
use App\Modules\Tenancy\Captacion\TipoEventoProspecto;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoProspectoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lo que el equipo hace con un prospecto (ADR 0117): darle seguimiento, asignarlo,
 * anotar, descartarlo con un motivo o convertirlo en miembro. Convertir usa el alta de
 * miembro y la invitación de siempre (registro cerrado, ADR 0093): crea o reactiva la
 * ficha y le manda la invitación; al activar su cuenta queda convertido. Nunca se enlaza
 * una cuenta existente solo porque coincide el correo: el equipo elige la ficha.
 */
class ProspectosTenant
{
    public function __construct(
        private readonly AltaDeMiembroTenant $altas,
        private readonly InvitarUsuarioTenant $invitaciones,
        private readonly RegistrarEventoTenant $eventos,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * Captura a mano (alguien llamó o llegó a recepción).
     *
     * @param  array{nombre: string, email?: string|null, telefono?: string|null, sucursal_id?: int|null}  $datos
     */
    public function crearManual(array $datos, Usuario $actor): ProspectoTenant
    {
        $prospecto = ProspectoTenant::query()->create([
            'nombre' => trim($datos['nombre']),
            'email' => isset($datos['email']) && $datos['email'] !== '' ? mb_strtolower(trim($datos['email'])) : null,
            'telefono' => CaptacionTenant::telefono($datos['telefono'] ?? null),
            'sucursal_id' => $datos['sucursal_id'] ?? null,
            'origen' => OrigenProspecto::Manual->value,
            'estado' => EstadoProspecto::Nuevo->value,
            'responsable_id' => $actor->getKey(),
        ]);
        $this->evento($prospecto, TipoEventoProspecto::Creado, $actor, ['manual' => true]);

        return $prospecto;
    }

    public function cambiarEstado(ProspectoTenant $prospecto, EstadoProspecto $estado, Usuario $actor, ?MotivoCierreProspecto $motivo = null, ?string $comentario = null): ProspectoTenant
    {
        if ($estado === EstadoProspecto::Convertido || $estado === EstadoProspecto::InvitacionEnviada) {
            throw ValidationException::withMessages(['estado' => ['Para convertirlo en miembro usa «Dar acceso».']]);
        }
        if ($prospecto->estado === EstadoProspecto::Convertido) {
            throw ValidationException::withMessages(['estado' => ['Ya es miembro: su seguimiento sigue en Miembros.']]);
        }
        if ($estado === EstadoProspecto::Descartado && $motivo === null) {
            throw ValidationException::withMessages(['motivo_cierre' => ['Elige por qué se descarta.']]);
        }

        $antes = $prospecto->estado;
        $prospecto->update([
            'estado' => $estado->value,
            'motivo_cierre' => $estado === EstadoProspecto::Descartado ? $motivo->value : null,
            'comentario_cierre' => $estado === EstadoProspecto::Descartado ? self::corto($comentario, 300) : null,
            // Pasar a seguimiento es el primer contacto.
            'primer_contacto_en' => $prospecto->primer_contacto_en ?? ($estado !== EstadoProspecto::Nuevo ? now() : null),
        ]);
        $this->evento($prospecto, TipoEventoProspecto::Estado, $actor, array_filter([
            'de' => $antes->value,
            'a' => $estado->value,
            'motivo' => $motivo?->value,
        ]));

        return $prospecto;
    }

    public function asignar(ProspectoTenant $prospecto, ?Usuario $responsable, Usuario $actor): ProspectoTenant
    {
        // En la historia queda de quién a quién (ADR 0138).
        $anterior = $prospecto->responsable_id === null ? null : Usuario::query()->whereKey($prospecto->responsable_id)->value('name');
        $prospecto->update(['responsable_id' => $responsable?->getKey()]);
        $this->evento($prospecto, TipoEventoProspecto::Asignado, $actor, array_filter([
            'responsable' => $responsable?->name,
            'anterior' => $anterior,
        ], static fn (mixed $v): bool => $v !== null));

        return $prospecto;
    }

    /**
     * Quiénes pueden atender prospectos: el equipo activo con permiso para gestionarlos.
     * Para elegir responsable (ADR 0138).
     *
     * @return Collection<int, Usuario>
     */
    public function responsables(): Collection
    {
        return Usuario::query()->where('activo', true)->orderBy('name')->get()
            ->filter(static fn (Usuario $u): bool => $u->puede('prospectos.gestionar'))
            ->values()
            ->toBase();
    }

    /**
     * El equipo le escribió o le llamó (WhatsApp, llamada o correo): queda en su historia
     * y cuenta como haberlo atendido (ADR 0138).
     */
    public function registrarContacto(ProspectoTenant $prospecto, string $canal, Usuario $actor): ProspectoTenant
    {
        $this->evento($prospecto, TipoEventoProspecto::Contacto, $actor, ['canal' => $canal]);
        if ($prospecto->primer_contacto_en === null) {
            $prospecto->update(['primer_contacto_en' => now()]);
        }

        return $prospecto;
    }

    public function programarSeguimiento(ProspectoTenant $prospecto, ?CarbonImmutable $cuando, Usuario $actor): ProspectoTenant
    {
        $prospecto->update(['proximo_seguimiento_en' => $cuando]);
        $this->evento($prospecto, TipoEventoProspecto::Seguimiento, $actor, ['cuando' => $cuando?->toIso8601String()]);

        return $prospecto;
    }

    public function anotar(ProspectoTenant $prospecto, string $texto, Usuario $actor): ProspectoTenant
    {
        $this->evento($prospecto, TipoEventoProspecto::Nota, $actor, ['texto' => self::corto($texto, 1000)]);
        // Anotar también cuenta como haberlo atendido.
        if ($prospecto->primer_contacto_en === null) {
            $prospecto->update(['primer_contacto_en' => now()]);
        }

        return $prospecto;
    }

    /**
     * Le da acceso: con una ficha que el equipo eligió o una nueva (las reglas del alta de
     * miembro), y si se pide, la invitación a su cuenta (rol miembro). Sin invitación
     * queda convertido de una vez (por ejemplo, alguien que se atiende en recepción).
     *
     * @return array{prospecto: ProspectoTenant, persona: PersonaTenant, token: string|null}
     */
    public function convertir(ProspectoTenant $prospecto, Estudio $estudio, Usuario $actor, ?PersonaTenant $existente, bool $invitar, bool $liberarCelular = false): array
    {
        if ($prospecto->estado === EstadoProspecto::Convertido) {
            throw ValidationException::withMessages(['estado' => ['Este prospecto ya es miembro.']]);
        }

        // Todo lo que puede impedirlo se revisa antes de crear nada (la invitación corre
        // en su propia conexión, fuera de una transacción de aquí).
        $email = $existente->email ?? $prospecto->email;
        if ($invitar) {
            if (! is_string($email) || $email === '') {
                throw ValidationException::withMessages(['email' => ['Para invitarlo necesita un correo.']]);
            }
            if ($existente?->usuario_id !== null || Usuario::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => ['Ya tiene cuenta con ese correo: elige su ficha y márcalo como miembro sin invitar.']]);
            }
        }
        // Una ficha con su correo ya existe: el equipo decide si es la misma persona.
        if (! $existente instanceof PersonaTenant && is_string($prospecto->email) && $prospecto->email !== ''
            && PersonaTenant::query()->where('email', $prospecto->email)->exists()) {
            throw ValidationException::withMessages(['persona_id' => ['Ya hay una ficha con ese correo: elígela para enlazarla.']]);
        }

        $persona = $existente ?? $this->altas->alta([
            'nombre' => $prospecto->nombre,
            'email' => $prospecto->email,
            'celular' => $prospecto->telefono,
        ], $actor, $prospecto->sucursal_id, $liberarCelular)['persona'];

        $token = $invitar
            ? $this->invitaciones->invitar($estudio, $actor, $persona->nombre, $email, 'miembro')['token']
            : null;

        $prospecto->update([
            'persona_id' => $persona->getKey(),
            'estado' => $invitar ? EstadoProspecto::InvitacionEnviada->value : EstadoProspecto::Convertido->value,
            'convertido_en' => $invitar ? null : now(),
            'primer_contacto_en' => $prospecto->primer_contacto_en ?? now(),
        ]);
        $this->evento($prospecto, $invitar ? TipoEventoProspecto::Invitado : TipoEventoProspecto::Convertido, $actor, [
            'persona' => (string) $persona->ulid,
            'ficha_existente' => $existente instanceof PersonaTenant,
        ]);
        if (! $invitar) {
            $this->convertido($prospecto);
        }
        $this->auditoria->registrar($actor, 'prospecto.convertido', 'prospecto', (string) $prospecto->ulid, null, [
            'persona' => (string) $persona->ulid,
            'invitado' => $invitar,
        ]);

        return ['prospecto' => $prospecto->refresh(), 'persona' => $persona, 'token' => $token];
    }

    /**
     * Al activar su cuenta, el prospecto invitado queda convertido (lo llama la activación).
     */
    public function alActivarCuenta(PersonaTenant $persona): void
    {
        ProspectoTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('estado', EstadoProspecto::InvitacionEnviada->value)
            ->get()
            ->each(function (ProspectoTenant $prospecto): void {
                $prospecto->update(['estado' => EstadoProspecto::Convertido->value, 'convertido_en' => now()]);
                $this->evento($prospecto, TipoEventoProspecto::Convertido, null, ['cuenta' => true]);
                $this->convertido($prospecto);
            });
    }

    /** Borra el prospecto y su historial (derecho de cancelación de sus datos). */
    public function eliminar(ProspectoTenant $prospecto, Usuario $actor): void
    {
        $this->auditoria->registrar($actor, 'prospecto.eliminado', 'prospecto', (string) $prospecto->ulid, null, null, 'Se borraron sus datos.');
        $prospecto->delete();
    }

    private function convertido(ProspectoTenant $prospecto): void
    {
        $this->eventos->registrar('prospecto.convertido', 'prospecto', (string) $prospecto->ulid, [
            'prospecto_id' => (string) $prospecto->ulid,
            'persona_id' => (string) $prospecto->persona?->ulid,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    private function evento(ProspectoTenant $prospecto, TipoEventoProspecto $tipo, ?Usuario $actor, array $detalle = []): void
    {
        EventoProspectoTenant::query()->create([
            'prospecto_id' => $prospecto->getKey(),
            'tipo' => $tipo->value,
            'detalle' => $detalle,
            'autor_id' => $actor?->getKey(),
        ]);
        // Atenderlo o reasignarlo le quita lo de «sin atender» (ADR 0138).
        if ($prospecto->sin_atender_desde !== null
            && ($tipo === TipoEventoProspecto::Asignado || in_array($tipo, TipoEventoProspecto::atencion(), true))) {
            $prospecto->update(['sin_atender_desde' => null]);
        }
    }

    private static function corto(?string $texto, int $largo): ?string
    {
        return is_string($texto) && trim($texto) !== '' ? mb_substr(trim($texto), 0, $largo) : null;
    }
}
