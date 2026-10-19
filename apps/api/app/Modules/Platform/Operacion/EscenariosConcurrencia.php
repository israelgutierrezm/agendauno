<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\AprovisionarEstudio;
use App\Modules\Tenancy\Application\BajasDeGrupoTenant;
use App\Modules\Tenancy\Application\CobroDeCitaTenant;
use App\Modules\Tenancy\Application\InscribirEnGrupoTenant;
use App\Modules\Tenancy\Application\InventarioTenant;
use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Application\PuntoDeVentaTenant;
use App\Modules\Tenancy\Application\ReprogramarTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Modules\Tenancy\Creditos\Exceptions\SaldoInsuficiente;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Inventario\Exceptions\InventarioException;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\GrupoTenant;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\InscripcionGrupoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
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
        private readonly InventarioTenant $inventario,
        private readonly PuntoDeVentaTenant $mostrador,
        private readonly CobroDeCitaTenant $cobro,
        private readonly InscribirEnGrupoTenant $inscribir,
        private readonly BajasDeGrupoTenant $bajas,
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
     * Un producto del mostrador con `$stock` piezas en la sede.
     */
    public function articulo(int $sucursalId, int $stock): ArticuloTenant
    {
        $articulo = ArticuloTenant::query()->create([
            'nombre' => 'Agua '.Str::lower(Str::random(6)), 'precio_minor' => 2500, 'moneda' => 'MXN', 'activo' => true,
        ]);
        $this->inventario->registrar((int) $articulo->getKey(), $sucursalId, $stock, TipoMovimientoInventario::Entrada);

        return $articulo;
    }

    /** Las piezas de un producto en la sede (suma del ledger). */
    public function stock(int $articuloId, int $sucursalId): int
    {
        return $this->inventario->stock($articuloId, $sucursalId);
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
     * Un grupo (curso) con `$fechas` fechas diarias de la clase desde `$dia`, a la
     * `$hora`: la serie de su horario.
     */
    public function grupo(int $claseId, int $sucursalId, int $dia, int $fechas, int $hora): GrupoTenant
    {
        $plantilla = PlantillaHorarioTenant::query()->create([
            'oferta_id' => $claseId,
            'sucursal_id' => $sucursalId,
            'dias_semana' => [1, 2, 3, 4, 5, 6, 7],
            'hora_local' => sprintf('%02d:00', $hora),
            'duracion_minutos' => 60,
            'capacidad' => 10,
            'activo' => true,
            'vigente_desde' => CarbonImmutable::now('America/Mexico_City')->toDateString(),
        ]);
        foreach (range(0, $fechas - 1) as $k) {
            $this->sesion($claseId, $sucursalId, 10, $dia + $k, $hora)->update(['serie_id' => $plantilla->getKey()]);
        }

        return GrupoTenant::query()->create(['nombre' => 'Grupo '.Str::random(6), 'plantilla_id' => $plantilla->getKey(), 'activo' => true]);
    }

    /** Inscribe a la persona en el grupo (con sus reservas de todas sus fechas). */
    public function inscribir(GrupoTenant $grupo, PersonaTenant $persona): InscripcionGrupoTenant
    {
        return $this->inscribir->inscribir($grupo, $persona)['inscripcion'];
    }

    /** El día del negocio a `$dia` días (como lo cuentan {@see self::sesion()} y {@see self::grupo()}). */
    public function dia(int $dia): string
    {
        return CarbonImmutable::now('America/Mexico_City')->addDays(self::DIAS_BASE + $dia)->toDateString();
    }

    /** Reservas que siguen en pie de la persona en las fechas del grupo. */
    public function reservasEnGrupo(PersonaTenant $persona, GrupoTenant $grupo): int
    {
        return ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::EnEspera->value, EstadoReserva::Ofrecida->value, EstadoReserva::PendientePago->value])
            ->whereHas('sesion', fn ($q) => $q->where('serie_id', $grupo->plantilla_id))
            ->count();
    }

    /** Si la persona sigue inscrita (activa) en el grupo. */
    public function inscritaEn(PersonaTenant $persona, GrupoTenant $grupo): bool
    {
        return InscripcionGrupoTenant::query()->where('grupo_id', $grupo->getKey())->where('persona_id', $persona->getKey())->where('activo', true)->exists();
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
     * Los dos profesionales atienden en la sede toda la semana (para las citas que
     * agenda el cliente, que deben caer en su horario).
     *
     * @param  list<int>  $profesionales
     */
    public function abrirAtencion(int $sucursalId, array $profesionales): void
    {
        foreach ($profesionales as $profesional) {
            foreach (range(1, 7) as $dia) {
                HorarioAtencionTenant::query()->firstOrCreate([
                    'instructor_id' => $profesional, 'sucursal_id' => $sucursalId, 'dia_semana' => $dia,
                ], ['hora_inicio' => '07:00', 'hora_fin' => '22:00']);
            }
        }
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
     * Los comprobantes de un intento de cobro y los pagos de la orden de la cita.
     *
     * @return array{ventas: int, pagos: int}
     */
    public function cobrosDeCita(int $reservaId, string $clave): array
    {
        $orden = ReservaTenant::query()->findOrFail($reservaId)->orden_id;

        return [
            'ventas' => VentaPosTenant::query()->where('clave_intento', $clave)->count(),
            'pagos' => PagoTenant::query()->where('orden_id', $orden)->count(),
        ];
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
        } catch (ReservaException|SaldoInsuficiente|InventarioException|ValidationException $e) {
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
            'agendar-cualquiera' => $this->prepararAgendarConCualquiera($paso),
            'cancelar' => $this->prepararCancelar((int) $paso['reserva']),
            'mover-clase' => $this->prepararMoverClase((int) $paso['reserva'], (int) $paso['sesion']),
            'mover-cita' => $this->prepararMoverCita((int) $paso['reserva'], (string) $paso['inicia']),
            'ajustar-inventario' => $this->prepararAjustarInventario((int) $paso['articulo'], (int) $paso['sucursal'], (int) $paso['delta']),
            'vender-mostrador' => $this->prepararVenderMostrador((int) $paso['articulo'], (int) $paso['sucursal'], (int) $paso['cantidad']),
            'vender-carrito' => $this->prepararVenderCarrito(array_map(intval(...), (array) $paso['articulos']), (int) $paso['sucursal']),
            'cobrar-cita' => $this->prepararCobrarCita((int) $paso['reserva'], (string) $paso['clave']),
            'cambiar-grupo' => $this->prepararCambiarGrupo((int) $paso['inscripcion'], (int) $paso['destino'], (string) $paso['desde']),
            'aprovisionar' => $this->prepararAprovisionar((int) $paso['estudio']),
            'migrar' => $this->prepararMigrar((int) $paso['estudio']),
            default => throw new InvalidArgumentException("Paso desconocido: {$paso['accion']}"),
        };
    }

    /**
     * El cobro de solo el servicio de una cita, en efectivo, con la clave del intento
     * (como el botón «Cobrar» del panel). Devuelve el comprobante.
     */
    private function prepararCobrarCita(int $reservaId, string $clave): callable
    {
        $reserva = ReservaTenant::query()->findOrFail($reservaId);

        return fn (): string => (string) $this->cobro->cobrar($reserva, 'efectivo', [], 0, 100000, null, $clave, null)['venta']?->ulid;
    }

    /** El cambio de grupo de una persona desde un día (confirmado aunque sea parcial). */
    private function prepararCambiarGrupo(int $inscripcionId, int $destinoId, string $desde): callable
    {
        $inscripcion = InscripcionGrupoTenant::query()->findOrFail($inscripcionId);
        $destino = GrupoTenant::query()->findOrFail($destinoId);

        return fn (): string => $this->bajas->cambiar($inscripcion, $destino, $desde, null, parcial: true)['aplicado'] ? 'aplicado' : 'sin aplicar';
    }

    /** Un ajuste a mano del inventario (con el artículo bloqueado, como en el panel). */
    private function prepararAjustarInventario(int $articuloId, int $sucursalId, int $delta): callable
    {
        return fn (): string => (string) $this->inventario->ajustar($articuloId, $sucursalId, $delta, TipoMovimientoInventario::Ajuste)->cantidad;
    }

    /**
     * Una venta del mostrador con varios artículos (una pieza de cada uno), en el orden
     * dado.
     *
     * @param  list<int>  $articulos
     */
    private function prepararVenderCarrito(array $articulos, int $sucursalId): callable
    {
        $items = array_map(static fn (int $id): array => ['articulo' => ArticuloTenant::query()->findOrFail($id), 'cantidad' => 1], $articulos);
        $sucursal = SucursalTenant::query()->findOrFail($sucursalId);

        return fn (): string => (string) $this->mostrador->vender($sucursal, $items, 'efectivo')->total_minor;
    }

    /** Una venta del mostrador. */
    private function prepararVenderMostrador(int $articuloId, int $sucursalId, int $cantidad): callable
    {
        $articulo = ArticuloTenant::query()->findOrFail($articuloId);
        $sucursal = SucursalTenant::query()->findOrFail($sucursalId);

        return fn (): string => (string) $this->mostrador->vender($sucursal, [['articulo' => $articulo, 'cantidad' => $cantidad]], 'efectivo')->total_minor;
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

    /**
     * Como la cliente en línea: sin elegir profesional.
     *
     * @param  array<string, mixed>  $paso
     */
    private function prepararAgendarConCualquiera(array $paso): callable
    {
        $oferta = OfertaTenant::query()->findOrFail((int) $paso['servicio']);
        $sucursal = SucursalTenant::query()->findOrFail((int) $paso['sucursal']);
        $persona = PersonaTenant::query()->findOrFail((int) $paso['persona']);
        $inicia = CarbonImmutable::parse((string) $paso['inicia']);

        return fn (): string => $this->agendar
            ->agendarConCualquiera($oferta, $sucursal, $persona, $inicia, 30)
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
