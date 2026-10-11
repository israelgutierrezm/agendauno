<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\AutorizacionDesactualizada;
use App\Modules\Tenancy\Exceptions\FamiliaNoPermitida;
use App\Modules\Tenancy\Exceptions\ParticipanteNoAutorizado;
use App\Modules\Tenancy\Familias\EstadoTutela;
use App\Modules\Tenancy\Familias\Parentesco;
use App\Modules\Tenancy\Familias\TextoAutorizacion;
use App\Modules\Tenancy\Models\ConfiguracionFamiliasTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TutelaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ModuloNegocio;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Familias y responsables (plan de mejoras B05, ADR 0123). Un responsable (alumno con
 * cuenta) se hace cargo de participantes del negocio, que pueden no tener cuenta. Cada
 * participante conserva sus planes, créditos y reservas: nada se mezcla.
 *
 * - **Vincular** (el negocio): queda pendiente hasta que el responsable acepta la
 *   autorización. Solo alumnos vigentes; el responsable con cuenta; sin ciclos ni más
 *   participantes que `familias.max_participantes`.
 * - **Autorizar**: el responsable acepta la versión vigente del texto del negocio en su
 *   cuenta, o el equipo registra que la aceptó en el mostrador. Queda la constancia.
 * - **Retirar**: el responsable o el negocio. Desde ese momento no ve ni reserva por esa
 *   persona; lo ya hecho se conserva.
 *
 * Todo pasa por la bitácora. La función solo existe si el superadministrador encendió
 * el módulo en el negocio (apagado por omisión) y el negocio no la apagó.
 */
class FamiliasTenant
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly RegistrarAuditoria $auditoria,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function configuracion(): ConfiguracionFamiliasTenant
    {
        return ConfiguracionFamiliasTenant::actual();
    }

    /**
     * ¿El negocio usa familias? El superadministrador encendió el módulo (ADR 0123) y el
     * negocio no lo apagó en su configuración.
     */
    public function activas(): bool
    {
        return $this->moduloEncendido() && $this->configuracion()->activo;
    }

    public function moduloEncendido(): bool
    {
        $estudio = $this->gestor->actual();

        return $estudio instanceof Estudio && $estudio->moduloHabilitado(ModuloNegocio::Familias);
    }

    /** Activa o apaga la función y guarda el texto; si el texto cambia, sube la versión. */
    public function guardarConfiguracion(bool $activo, ?string $texto, ?Usuario $actor): ConfiguracionFamiliasTenant
    {
        $config = ConfiguracionFamiliasTenant::query()->first() ?? new ConfiguracionFamiliasTenant(['version' => 1]);
        $antes = ['activo' => (bool) $config->activo, 'version' => (int) $config->version];
        $nuevo = trim((string) $texto) !== '' ? trim((string) $texto) : null;
        // Otro texto que aceptar (también el primero propio en lugar del base): otra versión.
        $cambioTexto = $nuevo !== ($config->texto_autorizacion !== null ? trim($config->texto_autorizacion) : null);

        $config->fill([
            'activo' => $activo,
            'texto_autorizacion' => $nuevo,
            'version' => $cambioTexto ? (int) $config->version + 1 : (int) $config->version,
            'actualizado_por' => $actor?->getKey(),
        ])->save();

        $this->auditoria->registrar($actor, 'familias.configuracion', 'configuracion_familias', null, $antes,
            ['activo' => $activo, 'version' => (int) $config->version]);

        return $config;
    }

    /**
     * El negocio pone a un participante a cargo de un responsable. Si ya hubo un vínculo
     * retirado entre ellos, vuelve a quedar pendiente de autorización.
     */
    public function vincular(PersonaTenant $responsable, PersonaTenant $participante, Parentesco $parentesco, ?Usuario $actor): TutelaTenant
    {
        if (! $this->activas()) {
            throw new FamiliaNoPermitida('Este negocio no tiene activadas las familias.', 'desactivado');
        }
        if ($responsable->usuario_id === null || ! $this->esAlumnoVigente($responsable)) {
            throw new FamiliaNoPermitida('El responsable debe ser un alumno con cuenta en el negocio.', 'sin_cuenta');
        }
        if ($responsable->is($participante) || ! $this->esAlumnoVigente($participante)) {
            throw new FamiliaNoPermitida('El participante debe ser otro alumno vigente del negocio.', 'participante_invalido');
        }

        return DB::connection('tenant')->transaction(function () use ($responsable, $participante, $parentesco, $actor): TutelaTenant {
            // El responsable bloqueado: dos altas a la vez no pasan el máximo.
            PersonaTenant::query()->whereKey($responsable->getKey())->lockForUpdate()->first();

            $inverso = TutelaTenant::query()
                ->where('responsable_id', $participante->getKey())
                ->where('participante_id', $responsable->getKey())
                ->where('estado', '!=', EstadoTutela::Retirada->value)
                ->exists();
            if ($inverso) {
                throw new FamiliaNoPermitida('Esa persona ya es responsable de quien quieres poner a su cargo.', 'ciclo');
            }

            $existente = TutelaTenant::query()
                ->where('responsable_id', $responsable->getKey())
                ->where('participante_id', $participante->getKey())
                ->first();
            if ($existente instanceof TutelaTenant && $existente->estado !== EstadoTutela::Retirada) {
                return $existente;
            }

            $vigentes = TutelaTenant::query()
                ->where('responsable_id', $responsable->getKey())
                ->where('estado', '!=', EstadoTutela::Retirada->value)
                ->count();
            $maximo = $this->parametros->entero('familias.max_participantes');
            if ($vigentes >= $maximo) {
                throw new FamiliaNoPermitida("Un responsable puede tener hasta {$maximo} personas a su cargo.", 'limite');
            }

            $datos = [
                'parentesco' => $parentesco->value,
                'estado' => EstadoTutela::Pendiente->value,
                'autorizacion_version' => null, 'autorizacion_huella' => null, 'autorizacion_texto' => null,
                'aceptada_en' => null, 'aceptada_via' => null, 'aceptada_ip' => null, 'aceptada_por' => null,
                'retirada_en' => null, 'retirada_por' => null, 'motivo_retiro' => null,
                'creada_por' => $actor?->getKey(),
            ];
            $tutela = $existente instanceof TutelaTenant
                ? tap($existente)->update($datos)
                : TutelaTenant::query()->create($datos + [
                    'responsable_id' => $responsable->getKey(),
                    'participante_id' => $participante->getKey(),
                ]);

            $this->auditoria->registrar($actor, 'tutela.creada', 'tutela', $tutela->ulid, null, [
                'responsable' => $responsable->ulid, 'participante' => $participante->ulid, 'parentesco' => $parentesco->value,
            ]);

            return $tutela->refresh();
        });
    }

    /**
     * El responsable acepta la autorización vigente (en su cuenta) o el equipo registra
     * que la aceptó en el mostrador. Debe ser la versión que se leyó; aceptarla otra vez
     * no cambia la constancia.
     */
    public function autorizar(TutelaTenant $tutela, int $version, string $via, ?Usuario $actor, ?string $ip): TutelaTenant
    {
        if (! $this->activas()) {
            throw new FamiliaNoPermitida('Este negocio no tiene activadas las familias.', 'desactivado');
        }

        return DB::connection('tenant')->transaction(function () use ($tutela, $version, $via, $actor, $ip): TutelaTenant {
            $tutela = TutelaTenant::query()->whereKey($tutela->getKey())->lockForUpdate()->firstOrFail();
            if ($tutela->estado === EstadoTutela::Activa) {
                return $tutela;
            }
            if ($tutela->estado !== EstadoTutela::Pendiente) {
                throw new FamiliaNoPermitida('Esta autorización se retiró; el negocio debe vincularlos de nuevo.', 'estado');
            }
            $config = $this->configuracion();
            if ($version !== (int) $config->version) {
                throw new AutorizacionDesactualizada((int) $config->version, $config->texto());
            }

            $texto = $config->texto();
            $tutela->update([
                'estado' => EstadoTutela::Activa->value,
                'autorizacion_version' => (int) $config->version,
                'autorizacion_huella' => TextoAutorizacion::huella($texto),
                'autorizacion_texto' => $texto,
                'aceptada_en' => Carbon::now(),
                'aceptada_via' => $via,
                'aceptada_ip' => $ip,
                'aceptada_por' => $actor?->getKey(),
            ]);
            $this->auditoria->registrar($actor, 'tutela.autorizada', 'tutela', $tutela->ulid, null,
                ['version' => (int) $config->version, 'via' => $via]);

            return $tutela->refresh();
        });
    }

    /** Retira la autorización: ya no ve ni reserva por esa persona. */
    public function retirar(TutelaTenant $tutela, ?Usuario $actor, ?string $motivo): TutelaTenant
    {
        if ($tutela->estado === EstadoTutela::Retirada) {
            return $tutela;
        }
        $antes = $tutela->estado->value;
        $tutela->update([
            'estado' => EstadoTutela::Retirada->value,
            'retirada_en' => Carbon::now(),
            'retirada_por' => $actor?->getKey(),
            'motivo_retiro' => $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null,
        ]);
        $this->auditoria->registrar($actor, 'tutela.retirada', 'tutela', $tutela->ulid,
            ['estado' => $antes], ['estado' => EstadoTutela::Retirada->value], $tutela->motivo_retiro);
        // Su tarjeta deja de pagar las membresías de esa persona (ADR 0129).
        app(DomiciliacionesTenant::class)->alRetirarTutela($tutela);

        return $tutela->refresh();
    }

    /**
     * Las personas a cargo de un responsable (sin las retiradas).
     *
     * @return Collection<int, TutelaTenant>
     */
    public function aCargoDe(PersonaTenant $responsable): Collection
    {
        return TutelaTenant::query()
            ->with('participante')
            ->where('responsable_id', $responsable->getKey())
            ->where('estado', '!=', EstadoTutela::Retirada->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * El participante al que una cuenta puede representar: a su cargo, con la
     * autorización vigente y aún alumno del negocio.
     */
    public function participanteAutorizado(PersonaTenant $responsable, string $participanteUlid): TutelaTenant
    {
        $tutela = $this->activas()
            ? TutelaTenant::query()
                ->with('participante')
                ->where('responsable_id', $responsable->getKey())
                ->where('estado', EstadoTutela::Activa->value)
                ->whereHas('participante', fn ($q) => $q->where('ulid', $participanteUlid))
                ->first()
            : null;
        if (! $tutela instanceof TutelaTenant || ! $this->esAlumnoVigente($tutela->participante)) {
            throw new ParticipanteNoAutorizado('No tienes autorización vigente para esa persona.');
        }

        return $tutela;
    }

    /**
     * Las personas por las que un responsable puede pagar (pago automático, ADR 0129): a
     * su cargo, con la autorización vigente y aún alumnos del negocio.
     *
     * @return Collection<int, PersonaTenant>
     */
    public function participantesAutorizados(PersonaTenant $responsable): Collection
    {
        if (! $this->activas()) {
            return new Collection;
        }

        return PersonaTenant::query()
            ->whereIn('id', TutelaTenant::query()
                ->where('responsable_id', $responsable->getKey())
                ->where('estado', EstadoTutela::Activa->value)
                ->select('participante_id'))
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('archivado', false)
            ->orderBy('id')
            ->get();
    }

    /**
     * Los responsables con autorización vigente de un participante (para sus avisos).
     *
     * @return Collection<int, PersonaTenant>
     */
    public function responsablesDe(PersonaTenant $participante): Collection
    {
        if (! $this->activas()) {
            return new Collection;
        }

        return PersonaTenant::query()
            ->whereIn('id', TutelaTenant::query()
                ->where('participante_id', $participante->getKey())
                ->where('estado', EstadoTutela::Activa->value)
                ->select('responsable_id'))
            ->whereNotNull('usuario_id')
            ->where('archivado', false)
            ->get();
    }

    private function esAlumnoVigente(PersonaTenant $persona): bool
    {
        return $persona->tipo === TipoPersonaTenant::Miembro && ! $persona->archivado;
    }
}
