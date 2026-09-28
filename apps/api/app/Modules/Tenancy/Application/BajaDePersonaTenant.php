<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AceptacionWaiverTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Documento;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\RespuestaFormulario;
use App\Modules\Tenancy\Models\SolicitudPrivacidadTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\QuienCancela;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Derecho de cancelación (ARCO): el alumno pide la baja de sus datos y el negocio
 * (responsable) la atiende o la rechaza con motivo.
 *
 * Al atenderla se ANONIMIZA a la persona (no se borra): se quitan nombre, correo y
 * celular, se borran sus documentos, respuestas de formularios y mensajes, se
 * cancelan sus reservas futuras y membresías, y su cuenta queda inservible. Se
 * conservan, ya sin datos que la identifiquen, los registros que la ley obliga a
 * guardar (compras, pagos, facturas) y la operación (asistencias, accesos, firmas
 * de consentimiento sin IP).
 */
class BajaDePersonaTenant
{
    private const NOMBRE_ANONIMO = 'Persona dada de baja';

    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly AutenticacionTenant $auth,
        private readonly RegistrarEventoTenant $eventos,
        private readonly RegistrarAuditoria $auditoria,
        private readonly DomiciliacionesTenant $domiciliaciones,
        private readonly DeudaDeRenovacionTenant $deudas,
    ) {}

    public function solicitar(PersonaTenant $persona, ?string $motivo): SolicitudPrivacidadTenant
    {
        $abierta = SolicitudPrivacidadTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('estado', SolicitudPrivacidadTenant::PENDIENTE)
            ->first();
        if ($abierta instanceof SolicitudPrivacidadTenant) {
            return $abierta;
        }

        return DB::connection('tenant')->transaction(function () use ($persona, $motivo): SolicitudPrivacidadTenant {
            $solicitud = SolicitudPrivacidadTenant::query()->create([
                'persona_id' => $persona->getKey(),
                'tipo' => 'cancelacion',
                'estado' => SolicitudPrivacidadTenant::PENDIENTE,
                'motivo' => $motivo,
            ]);
            $this->eventos->registrar('privacidad.baja_solicitada', 'persona', (string) $persona->ulid, [
                'persona_id' => (string) $persona->ulid,
                'solicitud' => (string) $solicitud->ulid,
                // Para el aviso al equipo: dónde atenderla en el panel.
                'enlace_panel' => rtrim((string) config('agendauno.url_app'), '/').'/privacidad',
            ]);

            return $solicitud;
        });
    }

    public function rechazar(SolicitudPrivacidadTenant $solicitud, string $respuesta, ?Usuario $actor): SolicitudPrivacidadTenant
    {
        $this->exigirPendiente($solicitud);
        $solicitud->update([
            'estado' => SolicitudPrivacidadTenant::RECHAZADA,
            'respuesta' => $respuesta,
            'atendida_por' => $actor?->getKey(),
            'atendida_en' => now(),
        ]);
        $this->auditoria->registrar($actor, 'privacidad.baja_rechazada', 'solicitud_privacidad', (string) $solicitud->ulid, null, null, $respuesta);

        return $solicitud;
    }

    public function atender(SolicitudPrivacidadTenant $solicitud, ?Usuario $actor): SolicitudPrivacidadTenant
    {
        $this->exigirPendiente($solicitud);
        $persona = $solicitud->persona;
        if (! $persona instanceof PersonaTenant) {
            throw ValidationException::withMessages(['solicitud' => ['La persona ya no existe.']]);
        }

        // Reservas futuras: se cancelan (libera los lugares).
        ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::EnEspera->value, EstadoReserva::Ofrecida->value, EstadoReserva::PendientePago->value])
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', now()))
            ->get()
            ->each(fn (ReservaTenant $r) => $this->reservas->cancelar($r, QuienCancela::Negocio, $actor));

        $archivos = [];
        DB::connection('tenant')->transaction(function () use ($persona, $solicitud, $actor, &$archivos): void {
            AcuerdoTenant::query()
                ->where('persona_id', $persona->getKey())
                ->where('estado', '!=', EstadoAcuerdo::Cancelado->value)
                ->update(['estado' => EstadoAcuerdo::Cancelado->value]);
            $this->deudas->anular(AcuerdoTenant::query()->where('persona_id', $persona->getKey())->pluck('id')->all(), $actor);

            $documentos = Documento::query()->where('persona_id', $persona->getKey())->get();
            $archivos = $documentos->pluck('ruta')->filter()->all();
            Documento::query()->whereKey($documentos->modelKeys())->delete();
            RespuestaFormulario::query()->where('persona_id', $persona->getKey())->delete();
            MensajeTenant::query()->where('persona_id', $persona->getKey())->delete();
            AceptacionWaiverTenant::query()->where('persona_id', $persona->getKey())->update(['ip' => null]);

            $usuario = $persona->usuario_id !== null ? Usuario::query()->find($persona->usuario_id) : null;
            if ($usuario instanceof Usuario) {
                if (is_string($usuario->foto_ruta) && $usuario->foto_ruta !== '') {
                    Storage::disk('public')->delete($usuario->foto_ruta);
                }
                $usuario->forceFill([
                    'email' => 'baja-'.$usuario->ulid.'@baja.invalid',
                    'name' => self::NOMBRE_ANONIMO,
                    'nombre' => null,
                    'primer_apellido' => null,
                    'segundo_apellido' => null,
                    'password' => null,
                    'google_id' => null,
                    'foto_ruta' => null,
                    'activo' => false,
                    'activation_token' => null,
                    'reset_token' => null,
                    'email_nuevo' => null,
                    'email_nuevo_token' => null,
                ])->save();
                $this->auth->revocarTodos($usuario);
                $usuario->forceFill(['eliminado_por' => $actor?->getKey()])->save();
                $usuario->delete();
            }

            $persona->forceFill([
                'nombre' => self::NOMBRE_ANONIMO,
                'segundo_nombre' => null,
                'primer_apellido' => null,
                'segundo_apellido' => null,
                'email' => null,
                'celular' => null,
                'activo' => false,
                'archivado' => true,
                'es_facturable' => false,
                'recibe_promociones' => false,
                'usuario_id' => null,
                'eliminado_por' => $actor?->getKey(),
            ])->save();
            $persona->delete();

            $solicitud->update([
                'estado' => SolicitudPrivacidadTenant::ATENDIDA,
                'atendida_por' => $actor?->getKey(),
                'atendida_en' => now(),
            ]);
            $this->auditoria->registrar($actor, 'privacidad.baja_atendida', 'persona', (string) $persona->ulid);
        });

        // Sin membresías vigentes no hay nada que cobrar: se desligan sus tarjetas.
        $this->domiciliaciones->desactivarDePersona($persona);

        // Los archivos se borran ya fuera de la transacción (no se pueden deshacer).
        foreach ($archivos as $ruta) {
            Storage::disk('local')->delete((string) $ruta);
        }

        return $solicitud->refresh();
    }

    private function exigirPendiente(SolicitudPrivacidadTenant $solicitud): void
    {
        if ($solicitud->estado !== SolicitudPrivacidadTenant::PENDIENTE) {
            throw ValidationException::withMessages(['solicitud' => ['Esta solicitud ya fue atendida.']]);
        }
    }
}
