<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\AprovisionarEstudio;
use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Application\ReprogramarTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Modules\Tenancy\Creditos\Exceptions\SaldoInsuficiente;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaException;
use App\Modules\Tenancy\TipoPersonaTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Los datos y las operaciones de `agendauno:verificar-concurrencia`: arma en el negocio
 * temporal lo que cada caso necesita (clases, alumnos con paquete, profesionales) y
 * ejecuta UNA operación real del dominio (reservar, agendar, cancelar, reprogramar) en
 * el proceso hijo, igual que lo haría una petición. Trabaja sobre la conexión `tenant`
 * ya apuntada al negocio.
 */
class EscenariosConcurrencia
{
    /** Días de anticipación: todo cae lejos del límite de cancelación (sin penalización). */
    private const DIAS_BASE = 3;

    /** @var array<int, ProductoTenant> paquete por número de clases */
    private array $paquetes = [];

    public function __construct(
        private readonly MembresiasTenant $membresias,
        private readonly ReservasTenant $reservas,
        private readonly AgendarCitaTenant $agendar,
        private readonly ReprogramarTenant $reprogramar,
        private readonly LibroMayorTenant $libro,
    ) {}

    /**
     * Sede, clase grupal, servicio de cita y dos profesionales.
     *
     * @return array{sucursal: int, clase: int, servicio: int, profesionales: list<int>}
     */
    public function prepararBase(): array
    {
        $organizacion = OrganizacionTenant::query()->create(['nombre' => 'Verificación']);
        $sucursal = $organizacion->sucursales()->create(['nombre' => 'Sede', 'zona_horaria' => 'America/Mexico_City']);

        $programa = ProgramaTenant::query()->create(['slug' => 'verificacion', 'nombre' => 'Verificación']);
        $actividad = $programa->actividades()->create(['slug' => 'verificacion', 'nombre' => 'Verificación']);
        $clase = $actividad->ofertas()->create([
            'nombre' => 'Clase grupal',
            'modalidad' => ModalidadOfertaTenant::Grupal->value,
            'capacidad' => 10,
        ]);
        $servicio = $actividad->ofertas()->create([
            'nombre' => 'Cita',
            'modalidad' => ModalidadOfertaTenant::Individual->value,
            'capacidad' => 1,
            'politica_reserva' => PoliticaReservaTenant::Pago->value,
            'precio_clase_minor' => 25000,
            'duracion_minutos' => 30,
        ]);

        $profesionales = [];
        foreach (['uno', 'dos'] as $n) {
            $profesionales[] = (int) Usuario::query()->create([
                'name' => "Profesional {$n}",
                'email' => "profesional-{$n}@verificacion.invalid",
                'rol' => 'instructor',
                'roles' => ['instructor'],
                'activo' => true,
                'password' => Str::random(40),
            ])->getKey();
        }

        return [
            'sucursal' => (int) $sucursal->getKey(),
            'clase' => (int) $clase->getKey(),
            'servicio' => (int) $servicio->getKey(),
            'profesionales' => $profesionales,
        ];
    }

    /**
     * Una fecha de la clase grupal, a `$dia` días de hoy (siempre futura).
     */
    public function sesion(int $claseId, int $sucursalId, int $capacidad, int $dia, int $hora = 19): SesionTenant
    {
        $inicia = CarbonImmutable::now('America/Mexico_City')->addDays(self::DIAS_BASE + $dia)->setTime($hora, 0)->utc();

        return SesionTenant::query()->create([
            'oferta_id' => $claseId,
            'sucursal_id' => $sucursalId,
            'inicia_en' => $inicia,
            'termina_en' => $inicia->addMinutes(60),
            'zona_horaria' => 'America/Mexico_City',
            'capacidad' => $capacidad,
            'estado' => EstadoSesionTenant::Programada->value,
            'tipo' => TipoSesionTenant::Clase->value,
        ]);
    }

    /**
     * Un alumno (sin correo: nada le llega) con un paquete de `$creditos` clases.
     */
    public function alumno(int $creditos): PersonaTenant
    {
        $persona = PersonaTenant::query()->create([
            'nombre' => 'Alumno',
            'primer_apellido' => Str::upper(Str::random(6)),
            'tipo' => TipoPersonaTenant::Miembro->value,
            'activo' => true,
        ]);
        if ($creditos > 0) {
            $this->paquetes[$creditos] ??= $this->membresias->crearProducto("Paquete {$creditos}", TipoProducto::Paquete, 10000, 'MXN', false, $creditos * 1000);
            $this->membresias->venderProducto($persona, $this->paquetes[$creditos]);
        }

        return $persona;
    }

    /**
     * Inicio de un hueco de cita a `$dia` días, a la hora local dada.
     */
    public function hueco(int $dia, int $hora, int $minuto = 0): CarbonImmutable
    {
        return CarbonImmutable::now('America/Mexico_City')->addDays(self::DIAS_BASE + $dia)->setTime($hora, $minuto)->utc();
    }

    /**
     * Agenda una cita en el acto (para preparar un caso).
     */
    public function cita(int $servicioId, int $sucursalId, PersonaTenant $persona, int $profesionalId, CarbonImmutable $inicia): ReservaTenant
    {
        return $this->agendar->agendar(
            OfertaTenant::query()->findOrFail($servicioId),
            SucursalTenant::query()->findOrFail($sucursalId),
            $persona,
            $profesionalId,
            $inicia,
            30,
            porNegocio: true,
        );
    }

    /**
     * Reservas que ocupan lugar en una sesión.
     */
    public function ocupadas(int $sesionId): int
    {
        return ReservaTenant::query()
            ->where('sesion_id', $sesionId)
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::PendientePago->value])
            ->count();
    }

    /**
     * Citas programadas de un profesional que se enciman con [desde, hasta).
     */
    public function citasEncimadas(int $profesionalId, CarbonImmutable $desde, CarbonImmutable $hasta): int
    {
        return SesionTenant::query()
            ->where('instructor_id', $profesionalId)
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '<', $hasta)
            ->where('termina_en', '>', $desde)
            ->count();
    }

    /**
     * Revisa el libro de créditos de un alumno: ningún paquete en negativo y una
     * retención activa por cada reserva que ocupa lugar (ni de más ni de menos).
     *
     * @return list<string>
     */
    public function creditosCuadran(PersonaTenant $persona): array
    {
        $fallas = [];
        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->get();
        foreach ($derechos as $derecho) {
            $disponible = $this->libro->disponible($derecho);
            if ($disponible < 0) {
                $fallas[] = "el paquete quedó en negativo ({$disponible} unidades)";
            }
        }

        $retenciones = RetencionCreditoTenant::query()
            ->whereIn('derecho_id', $derechos->modelKeys())
            ->where('estado', EstadoRetencion::Activa->value)
            ->count();
        $conLugar = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value])
            ->whereNotNull('retencion_id')
            ->count();
        if ($retenciones !== $conLugar) {
            $fallas[] = "{$retenciones} crédito(s) apartado(s) para {$conLugar} reserva(s) con lugar";
        }

        return $fallas;
    }

    /**
     * Corre una operación real del dominio (en el proceso hijo). Devuelve si se hizo
     * y, si no, si fue un rechazo de negocio (esperado: cupo lleno, horario ocupado…)
     * o un error inesperado (lo que la verificación busca).
     *
     * @param  array<string, mixed>  $paso
     * @return array{hecho: bool, esperado: bool, detalle: string}
     */
    public function ejecutar(array $paso, callable $esperar): array
    {
        $accion = $this->preparar($paso);
        $esperar();

        try {
            return ['hecho' => true, 'esperado' => true, 'detalle' => $accion()];
        } catch (ReservaException|SaldoInsuficiente|ValidationException $e) {
            return ['hecho' => false, 'esperado' => true, 'detalle' => class_basename($e).': '.$e->getMessage()];
        } catch (Throwable $e) {
            return ['hecho' => false, 'esperado' => false, 'detalle' => class_basename($e).': '.Str::limit($e->getMessage(), 300)];
        }
    }

    /**
     * Carga lo necesario ANTES de la señal de salida, para que todos los procesos
     * choquen en la operación y no en el arranque.
     *
     * @param  array<string, mixed>  $paso
     * @return callable(): string
     */
    private function preparar(array $paso): callable
    {
        return match ($paso['accion']) {
            'reservar' => $this->prepararReservar((int) $paso['sesion'], (int) $paso['persona']),
            'agendar' => $this->prepararAgendar($paso),
            'cancelar' => $this->prepararCancelar((int) $paso['reserva']),
            'mover-clase' => $this->prepararMoverClase((int) $paso['reserva'], (int) $paso['sesion']),
            'mover-cita' => $this->prepararMoverCita((int) $paso['reserva'], (string) $paso['inicia']),
            'aprovisionar' => $this->prepararAprovisionar((int) $paso['estudio']),
            'migrar' => $this->prepararMigrar((int) $paso['estudio']),
            default => throw new InvalidArgumentException("Paso desconocido: {$paso['accion']}"),
        };
    }

    private function prepararReservar(int $sesionId, int $personaId): callable
    {
        $sesion = SesionTenant::query()->findOrFail($sesionId);
        $persona = PersonaTenant::query()->findOrFail($personaId);

        return fn (): string => $this->reservas->crear($sesion, $persona)->estado->value;
    }

    /**
     * @param  array<string, mixed>  $paso
     */
    private function prepararAgendar(array $paso): callable
    {
        $oferta = OfertaTenant::query()->findOrFail((int) $paso['servicio']);
        $sucursal = SucursalTenant::query()->findOrFail((int) $paso['sucursal']);
        $persona = PersonaTenant::query()->findOrFail((int) $paso['persona']);
        $inicia = CarbonImmutable::parse((string) $paso['inicia']);

        return fn (): string => $this->agendar
            ->agendar($oferta, $sucursal, $persona, (int) $paso['profesional'], $inicia, 30, porNegocio: true)
            ->estado->value;
    }

    private function prepararCancelar(int $reservaId): callable
    {
        $reserva = ReservaTenant::query()->findOrFail($reservaId);

        return fn (): string => $this->reservas->cancelar($reserva)->estado->value;
    }

    private function prepararMoverClase(int $reservaId, int $sesionId): callable
    {
        $reserva = ReservaTenant::query()->findOrFail($reservaId);
        $destino = SesionTenant::query()->findOrFail($sesionId);

        return fn (): string => $this->reprogramar->moverAClase($reserva, $destino, null)->estado->value;
    }

    private function prepararMoverCita(int $reservaId, string $inicia): callable
    {
        $reserva = ReservaTenant::query()->findOrFail($reservaId);
        $hora = CarbonImmutable::parse($inicia);

        return fn (): string => $this->reprogramar->moverCita($reserva, $hora, null, null)->estado->value;
    }

    private function prepararAprovisionar(int $estudioId): callable
    {
        $estudio = Estudio::query()->findOrFail($estudioId);

        return fn (): string => app(AprovisionarEstudio::class)->ejecutar($estudio)->estado->value;
    }

    /**
     * Lo mismo que hace `agendauno:migrar-estudios` con cada negocio.
     */
    private function prepararMigrar(int $estudioId): callable
    {
        $estudio = Estudio::query()->findOrFail($estudioId);

        return fn (): string => app(GestorDeConexionTenant::class)->migrar($estudio)['aplicadas'].' migración(es)';
    }

    /**
     * Apunta la conexión al negocio, para los pasos que operan dentro de él.
     */
    public function conectar(int $estudioId): void
    {
        app(GestorDeConexionTenant::class)->conectar(Estudio::query()->findOrFail($estudioId));
    }
}
