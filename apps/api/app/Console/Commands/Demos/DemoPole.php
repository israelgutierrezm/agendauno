<?php

declare(strict_types=1);

namespace App\Console\Commands\Demos;

use App\Modules\Tenancy\Application\AsistenciaTenant;
use App\Modules\Tenancy\Application\GenerarAgendaTenant;
use App\Modules\Tenancy\Application\InventarioTenant;
use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Application\OrdenesTenant;
use App\Modules\Tenancy\Application\PuntoDeVentaTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Membresias\TipoVigencia;
use App\Modules\Tenancy\Membresias\VigenciaProducto;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\ExcepcionHorarioTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Nomina\TipoPago;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Recursos\ModoRecurso;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\SinDerechoDisponible;
use App\Modules\Tenancy\Reservas\QuienCancela;
use App\Modules\Tenancy\Support\RedesSociales;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Aura Pole Studio: una sede en Del Valle con sala de tubos y sala de flexibilidad,
 * la dueña (que también toma clases), administración, recepción, una coordinadora con
 * un rol propio limitado y cuatro instructoras. Seis clases (Pole Nivel 1 a 3, Pole
 * Heels, Flexibilidad y Acondicionamiento) en un horario semanal fijo, y los planes de
 * un estudio real: clase muestra, clase suelta, paquetes de 4, 8 y 12 clases al mes,
 * mensualidad ilimitada y clases extra.
 *
 * Las alumnas tienen su costumbre (días, horario, nivel y plan); las nuevas llegan con
 * clase muestra y casi dos de cada tres se quedan. Cada día de la historia reservan,
 * algunas cancelan (y entra quien estaba en lista de espera), se marca la asistencia,
 * compran su paquete cuando se les acaba (o se van) y suben de nivel con el tiempo.
 */
final class DemoPole extends DemoBase
{
    /** clave => [nombre, actividad, capacidad, descripción] */
    private const CLASES = [
        'n1' => ['Pole Nivel 1', 'Pole Fitness', 8, 'Tu primera clase de pole: giros básicos, trepa y fuerza de agarre. No necesitas experiencia.'],
        'n2' => ['Pole Nivel 2', 'Pole Fitness', 8, 'Combinaciones de giros, primeras inversiones y trabajo de flexibilidad.'],
        'n3' => ['Pole Nivel 3', 'Pole Fitness', 6, 'Inversiones controladas, figuras en el aire y transiciones fluidas.'],
        'heels' => ['Pole Heels', 'Pole Fitness', 8, 'Coreografía y floorwork con tacones: sensualidad, ritmo y confianza.'],
        'flexi' => ['Flexibilidad', 'Flexibilidad y fuerza', 12, 'Apertura de cadera, espalda y hombros para mejorar tus figuras.'],
        'acond' => ['Acondicionamiento', 'Flexibilidad y fuerza', 12, 'Fuerza de core, brazos y agarre: la base para avanzar en el tubo.'],
    ];

    /** Horario semanal: [clase, días, hora, instructora, sala]. */
    private const HORARIO = [
        ['acond', [1, 3, 5], '08:00', 'majo', 'flexi'],
        ['flexi', [2, 4], '08:00', 'majo', 'flexi'],
        ['n1', [1, 2, 3, 4, 5], '18:00', 'andrea', 'tubos'],
        ['n2', [1, 3, 5], '19:00', 'andrea', 'tubos'],
        ['flexi', [1, 3, 5], '19:00', 'majo', 'flexi'],
        ['heels', [2, 4], '19:00', 'fernanda', 'tubos'],
        ['acond', [2, 4], '19:00', 'majo', 'flexi'],
        ['n3', [1, 3, 5], '20:00', 'fernanda', 'tubos'],
        ['n2', [2, 4], '20:00', 'fernanda', 'tubos'],
        ['n1', [6], '10:00', 'paulina', 'tubos'],
        ['heels', [6], '11:00', 'paulina', 'tubos'],
        ['flexi', [6], '11:00', 'majo', 'flexi'],
        ['n2', [6], '12:00', 'andrea', 'tubos'],
    ];

    /** Planes: clave => [nombre, tipo, precio, créditos (null = ilimitado), vigencia, clases por semana] */
    private const PLANES = [
        'muestra' => ['Clase muestra', 15000, 1000, [TipoVigencia::Dias, 7], 1],
        'suelta' => ['Clase suelta', 25000, 1000, [TipoVigencia::Dias, 30], 1],
        'p4' => ['Paquete 4 clases', 85000, 4000, [TipoVigencia::Meses, 1], 1],
        'p8' => ['Paquete 8 clases', 150000, 8000, [TipoVigencia::Meses, 1], 2],
        'p12' => ['Paquete 12 clases', 198000, 12000, [TipoVigencia::Meses, 1], 3],
        'ilimitada' => ['Mensualidad ilimitada', 189000, null, [TipoVigencia::Meses, 1], 4],
    ];

    private const ARTICULOS = [
        ['Grip líquido', 'AU-GRP-01', 32000, 15],
        ['Rodilleras de pole', 'AU-ROD-02', 45000, 10],
        ['Shorts de pole', 'AU-SHO-03', 52000, 12],
        ['Top deportivo', 'AU-TOP-04', 48000, 12],
        ['Botella Aura', 'AU-BOT-05', 18000, 20],
    ];

    private const COMENTARIOS = [
        5 => ['Me encantó la clase, súper bien explicada.', 'Andrea es increíble, siempre corrige con paciencia.', 'Por fin salió mi primera inversión.', 'El ambiente es muy seguro y bonito.', 'La mejor clase de la semana.'],
        4 => ['Muy buena, aunque la sala estaba llena.', 'Me gustó mucho, quisiera más tiempo de estiramiento.'],
        3 => ['Bien, pero empezó unos minutos tarde.'],
    ];

    private SucursalTenant $sede;

    /** @var array<string, Usuario> */
    private array $equipo = [];

    /** @var array<string, OfertaTenant> */
    private array $clases = [];

    /** @var array<int, string> oferta_id => clave */
    private array $claveDeOferta = [];

    /** @var array<string, ProductoTenant> */
    private array $planes = [];

    private ProductoTenant $extras;

    /**
     * @var list<array{persona: PersonaTenant, plan: string, dias: list<int>, clases: list<string>, franja: string, desde: CarbonImmutable, deja: CarbonImmutable|null, nueva: bool, sube: CarbonImmutable|null, compra: CarbonImmutable|null}>
     */
    private array $alumnas = [];

    /** @var list<ArticuloTenant> */
    private array $articulos = [];

    protected function datos(): array
    {
        return [
            'nombre' => 'Aura Pole Studio',
            'slug' => 'aura-pole',
            'perfil' => PerfilNegocio::Pole,
            'contacto' => ['Mariana', 'Guzmán', 'Robles'],
            'email' => 'mariana@aurapole.test',
            'telefono' => '5587654321',
            'descripcion' => 'Estudio de pole y flexibilidad en Del Valle. Grupos pequeños, instructoras certificadas y un espacio seguro para empezar desde cero o perfeccionar tu técnica.',
            'instagram' => '@aurapolestudio',
            'color' => [109, 40, 217],
        ];
    }

    public function cuentas(): array
    {
        return [
            'mariana@aurapole.test' => 'Dueña (también es alumna: roles dueña y alumna)',
            'daniela@aurapole.test' => 'Administradora',
            'ximena@aurapole.test' => 'Recepción',
            'renata@aurapole.test' => 'Coordinadora (rol propio «Coordinación», permisos limitados)',
            'andrea@aurapole.test' => 'Instructora (Pole Nivel 1 y 2)',
            'sofia.herrera@correo.test' => 'Alumna con cuenta («Mi cuenta»)',
        ];
    }

    protected function sembrar(): void
    {
        $this->reloj($this->inicio->subDays(30), '10:00');
        $this->sedeYEquipo();
        $this->catalogoYPlanes();
        $this->horarioSemanal();
        $this->inventario();
        $this->darDeAltaAlumnas();

        for ($dia = $this->inicio; $dia->lessThanOrEqualTo($this->hoy); $dia = $dia->addDay()) {
            $this->unDia($dia);
        }
        $this->proximasSemanas();
    }

    // ---------------------------------------------------------------- estructura

    private function sedeYEquipo(): void
    {
        $organizacion = OrganizacionTenant::query()->create(['nombre' => 'Aura Pole Studio']);
        $this->sede = $organizacion->sucursales()->create([
            'nombre' => 'Del Valle', 'zona_horaria' => $this->zona, 'region' => 'CDMX', 'moneda' => 'MXN', 'impuesto_tasa_bps' => 1600,
            'direccion' => 'Av. Félix Cuevas 340, Del Valle Sur, Benito Juárez, CDMX', 'whatsapp' => '5587654321',
            'latitud' => 19.3712, 'longitud' => -99.1690,
            'redes' => RedesSociales::normalizar(['instagram' => '@aurapolestudio']),
            'horario' => [
                ['dia' => 1, 'abre' => '07:30', 'cierra' => '21:30'], ['dia' => 2, 'abre' => '07:30', 'cierra' => '21:30'],
                ['dia' => 3, 'abre' => '07:30', 'cierra' => '21:30'], ['dia' => 4, 'abre' => '07:30', 'cierra' => '21:30'],
                ['dia' => 5, 'abre' => '07:30', 'cierra' => '21:30'], ['dia' => 6, 'abre' => '09:30', 'cierra' => '13:30'],
            ],
        ]);

        $duena = $this->usuario('mariana@aurapole.test', 'Mariana', 'Guzmán', ['propietario', 'miembro']);
        $this->equipo = [
            'mariana' => $duena,
            'daniela' => $this->usuario('daniela@aurapole.test', 'Daniela', 'Pérez', ['admin']),
            'ximena' => $this->usuario('ximena@aurapole.test', 'Ximena', 'Flores', ['recepcionista']),
            'andrea' => $this->usuario('andrea@aurapole.test', 'Andrea', 'Lozano', ['instructor']),
            'fernanda' => $this->usuario('fernanda@aurapole.test', 'Fernanda', 'Ríos', ['instructor']),
            'majo' => $this->usuario('majo@aurapole.test', 'María José', 'Treviño', ['instructor']),
            'paulina' => $this->usuario('paulina@aurapole.test', 'Paulina', 'Ortega', ['instructor']),
        ];
        $coordinacion = $this->rolPropio($duena, 'Coordinación', [
            'agenda.ver', 'reservas.ver', 'reservas.gestionar', 'asistencia.marcar', 'miembros.ver', 'catalogo.ver', 'sucursales.ver',
        ]);
        $this->equipo['renata'] = $this->usuario('renata@aurapole.test', 'Renata', 'Salazar', [$coordinacion ?? 'recepcionista']);

        foreach ([['andrea', TipoPago::PorClase, 35000], ['fernanda', TipoPago::PorClase, 38000], ['majo', TipoPago::PorHora, 30000], ['paulina', TipoPago::PorAsistente, 6000]] as [$clave, $tipo, $monto]) {
            EsquemaPagoTenant::query()->create([
                'usuario_id' => $this->equipo[$clave]->getKey(), 'tipo' => $tipo->value, 'monto_minor' => $monto, 'moneda' => 'MXN', 'activo' => true,
            ]);
        }
    }

    private function catalogoYPlanes(): void
    {
        $programas = [
            'Pole Fitness' => ProgramaTenant::query()->create(['slug' => 'pole', 'nombre' => 'Pole']),
            'Flexibilidad y fuerza' => ProgramaTenant::query()->create(['slug' => 'flexibilidad', 'nombre' => 'Flexibilidad y fuerza']),
        ];
        $actividades = [];
        foreach (self::CLASES as $clave => [$nombre, $actividad, $capacidad, $descripcion]) {
            $actividades[$actividad] ??= $programas[$actividad]->actividades()->create(['slug' => str($actividad)->slug()->value(), 'nombre' => $actividad]);
            $this->clases[$clave] = $actividades[$actividad]->ofertas()->create([
                'nombre' => $nombre, 'descripcion' => $descripcion, 'modalidad' => ModalidadOfertaTenant::Grupal->value,
                'capacidad' => $capacidad, 'politica_reserva' => PoliticaReservaTenant::Entitlement->value, 'duracion_minutos' => 60,
            ]);
            $this->claveDeOferta[(int) $this->clases[$clave]->getKey()] = $clave;
        }

        $membresias = app(MembresiasTenant::class);
        foreach (self::PLANES as $clave => [$nombre, $precio, $creditos, [$vigencia, $cantidad]]) {
            $tipo = match ($clave) {
                'muestra', 'suelta' => TipoProducto::SesionIndividual,
                'ilimitada' => TipoProducto::Membresia,
                default => TipoProducto::Paquete,
            };
            $this->planes[$clave] = $membresias->crearProducto(
                $nombre, $tipo, $precio, 'MXN', $creditos === null, $creditos,
                vigencia: new VigenciaProducto($vigencia, $cantidad),
                ofertaIds: $clave === 'muestra' ? [(int) $this->clases['n1']->getKey()] : [],
            );
        }
        $this->extras = $membresias->crearProducto('2 clases extra', TipoProducto::AddOn, 40000, 'MXN', false, 2000);
    }

    /** El horario semanal como clases recurrentes, de inicio de la historia a tres semanas. */
    private function horarioSemanal(): void
    {
        $salas = [
            'tubos' => RecursoTenant::query()->create(['sucursal_id' => $this->sede->getKey(), 'nombre' => 'Sala de tubos', 'tipo' => 'Sala', 'modo' => ModoRecurso::Unidad->value, 'capacidad' => 1, 'activo' => true]),
            'flexi' => RecursoTenant::query()->create(['sucursal_id' => $this->sede->getKey(), 'nombre' => 'Sala de flexibilidad', 'tipo' => 'Sala', 'modo' => ModoRecurso::Unidad->value, 'capacidad' => 1, 'activo' => true]),
        ];
        // Cerrado en días feriados (antes de generar, para no crear esas clases).
        $hasta = $this->hoy->addDays(21);
        foreach (["{$this->hoy->year}-09-16" => 'Día de la Independencia', "{$this->hoy->year}-11-02" => 'Día de Muertos', "{$this->hoy->year}-12-25" => 'Navidad', "{$this->hoy->year}-01-01" => 'Año Nuevo'] as $fecha => $motivo) {
            if (CarbonImmutable::parse($fecha, $this->zona)->betweenIncluded($this->inicio, $hasta)) {
                ExcepcionHorarioTenant::query()->create(['fecha' => $fecha, 'motivo' => $motivo]);
            }
        }
        $generar = app(GenerarAgendaTenant::class);
        foreach (self::HORARIO as [$clase, $dias, $hora, $instructora, $sala]) {
            $plantilla = PlantillaHorarioTenant::query()->create([
                'oferta_id' => $this->clases[$clase]->getKey(), 'sucursal_id' => $this->sede->getKey(),
                'instructor_id' => $this->equipo[$instructora]->getKey(), 'recurso_id' => $salas[$sala]->getKey(),
                'dias_semana' => $dias, 'hora_local' => $hora, 'duracion_minutos' => 60,
                'capacidad' => self::CLASES[$clase][2], 'activo' => true, 'vigente_desde' => $this->inicio->toDateString(),
            ]);
            $resultado = $generar->ejecutar($plantilla, $this->inicio->toDateString(), $hasta->toDateString());
            $this->sumar('clases', $resultado->creadas);
        }
    }

    private function inventario(): void
    {
        $inventario = app(InventarioTenant::class);
        foreach (self::ARTICULOS as [$nombre, $sku, $precio, $stock]) {
            $articulo = ArticuloTenant::query()->create(['nombre' => $nombre, 'sku' => $sku, 'precio_minor' => $precio, 'moneda' => 'MXN', 'activo' => true]);
            $this->articulos[] = $articulo;
            $inventario->registrar((int) $articulo->getKey(), (int) $this->sede->getKey(), $stock, TipoMovimientoInventario::Entrada, 'Inventario inicial', $this->equipo['daniela']);
        }
    }

    /**
     * 75 alumnas que ya venían y 45 que llegan durante la historia (con clase muestra).
     * Más la dueña, que toma Heels, y Sofía, con cuenta para revisar «Mi cuenta».
     */
    private function darDeAltaAlumnas(): void
    {
        $dias = (int) $this->inicio->diffInDays($this->hoy);
        for ($i = 0; $i < 120; $i++) {
            $antigua = $i < 75;
            $alta = $antigua
                ? $this->inicio->subDays($this->azar(15, 500))->setTime($this->azar(9, 20), $this->azar(0, 59))
                : $this->inicio->addDays($this->azar(0, $dias - 3))->setTime($this->azar(9, 20), $this->azar(0, 59));
            $persona = $this->persona($this->uno(NombresDemo::MUJERES), $this->uno(NombresDemo::APELLIDOS), $this->uno(NombresDemo::APELLIDOS), $alta, (int) $this->sede->getKey(), $this->prob(80));
            $nivel = $antigua ? (int) $this->elegir([1 => 30, 2 => 45, 3 => 25]) : 1;
            $plan = (string) $this->elegir(['p4' => 25, 'p8' => 40, 'p12' => 15, 'ilimitada' => 20]);
            $this->alumnas[] = $this->costumbre($persona, $plan, $nivel, $antigua ? $this->inicio : $alta->startOfDay(), ! $antigua);
        }

        $duena = $this->persona('Mariana', 'Guzmán', 'Robles', $this->inicio->subDays(400), (int) $this->sede->getKey(), false, ['email' => 'mariana@aurapole.test', 'usuario_id' => $this->equipo['mariana']->getKey()]);
        $this->alumnas[] = [...$this->costumbre($duena, 'ilimitada', 3, $this->inicio, false), 'dias' => [2, 4], 'clases' => ['heels'], 'deja' => null];

        $sofia = $this->alumnas[0]['persona'];
        $sofia->update(['nombre' => 'Sofía', 'primer_apellido' => 'Herrera', 'segundo_apellido' => 'Campos', 'email' => 'sofia.herrera@correo.test']);
        $cuenta = $this->usuario('sofia.herrera@correo.test', 'Sofía', 'Herrera', ['miembro']);
        $sofia->update(['usuario_id' => $cuenta->getKey()]);
        $this->alumnas[0] = [...$this->alumnas[0], 'plan' => 'p8', 'deja' => null];
    }

    /**
     * Días, clases y horario de una alumna según su nivel y su plan.
     *
     * @return array{persona: PersonaTenant, plan: string, dias: list<int>, clases: list<string>, franja: string, desde: CarbonImmutable, deja: CarbonImmutable|null, nueva: bool, sube: CarbonImmutable|null, compra: CarbonImmutable|null}
     */
    private function costumbre(PersonaTenant $persona, string $plan, int $nivel, CarbonImmutable $desde, bool $nueva): array
    {
        $franja = (string) $this->elegir(['tarde' => 70, 'manana' => 18, 'sabado' => 12]);
        $clases = match ($nivel) {
            1 => ['n1', $this->uno(['flexi', 'acond'])],
            2 => ['n2', $this->uno(['heels', 'flexi', 'acond'])],
            default => ['n3', 'n2', $this->uno(['heels', 'flexi'])],
        };
        $porSemana = self::PLANES[$plan][4];
        $posibles = $franja === 'sabado' ? [6, 2, 4] : [1, 2, 3, 4, 5];
        $posibles = $this->mezclar($posibles);

        return [
            'persona' => $persona,
            'plan' => $plan,
            'dias' => array_slice($posibles, 0, min($porSemana, count($posibles))),
            'clases' => $clases,
            'franja' => $franja,
            'desde' => $desde,
            // Una de cada diez se va cuando se le acaba el plan (se decide al renovar).
            'deja' => null,
            'nueva' => $nueva,
            // Las de Nivel 1 suben a Nivel 2 tras unas semanas.
            'sube' => $nivel === 1 && $this->prob(55) ? $desde->addDays($this->azar(42, 70)) : null,
            'compra' => null,
        ];
    }

    // ---------------------------------------------------------------- un día

    private function unDia(CarbonImmutable $dia): void
    {
        $sesiones = SesionTenant::query()
            ->whereBetween('inicia_en', [$dia->startOfDay()->utc(), $dia->endOfDay()->utc()])
            ->where('estado', 'programada')
            ->orderBy('inicia_en')
            ->get();
        if ($sesiones->isEmpty()) {
            return;
        }
        $esHoy = $dia->equalTo($this->hoy);
        $ahora = $this->ahora;

        $orden = array_keys($this->alumnas);
        $orden = $this->mezclar($orden);
        foreach ($orden as $i) {
            $a = $this->alumnas[$i];
            if ($a['desde']->greaterThan($dia) || ($a['deja'] instanceof CarbonImmutable && $dia->greaterThanOrEqualTo($a['deja']))) {
                continue;
            }
            if ($a['sube'] instanceof CarbonImmutable && $dia->greaterThanOrEqualTo($a['sube'])) {
                $this->alumnas[$i]['clases'] = ['n2', ...array_values(array_diff($a['clases'], ['n1']))];
                $this->alumnas[$i]['sube'] = null;
                $a = $this->alumnas[$i];
            }
            // La nueva toma su clase muestra el día que llega (o el siguiente Nivel 1).
            if ($a['nueva']) {
                $sesion = $sesiones->first(fn (SesionTenant $s): bool => $this->claveDeOferta[(int) $s->oferta_id] === 'n1');
                if ($sesion instanceof SesionTenant) {
                    $this->claseMuestra($i, $sesion, $dia);
                }

                continue;
            }
            if (! in_array($dia->isoWeekday(), $a['dias'], true)) {
                continue;
            }
            $sesion = $this->claseDelDia($sesiones->all(), $a);
            if ($sesion instanceof SesionTenant) {
                $this->reservar($i, $sesion, $dia);
            }
        }

        foreach ($sesiones as $sesion) {
            $inicia = CarbonImmutable::instance($sesion->inicia_en)->setTimezone($this->zona);
            $this->cancelaciones($sesion, $inicia, $esHoy ? $ahora : null);
            if (! $esHoy || $inicia->addHour()->lessThanOrEqualTo($ahora)) {
                $this->asistencia($sesion, $inicia->addHour());
            }
        }
        $this->ventasDeMostrador($dia, $esHoy ? $ahora : null);
    }

    /**
     * La clase que toma hoy: de las suyas, en su horario si hay.
     *
     * @param  list<SesionTenant>  $sesiones
     * @param  array{clases: list<string>, franja: string}  $a
     */
    private function claseDelDia(array $sesiones, array $a): ?SesionTenant
    {
        $suyas = array_values(array_filter($sesiones, fn (SesionTenant $s): bool => in_array($this->claveDeOferta[(int) $s->oferta_id], $a['clases'], true)));
        if ($suyas === []) {
            return null;
        }
        $enSuHorario = array_values(array_filter($suyas, function (SesionTenant $s) use ($a): bool {
            $hora = CarbonImmutable::instance($s->inicia_en)->setTimezone($this->zona)->hour;

            return match ($a['franja']) {
                'manana' => $hora < 12,
                'tarde' => $hora >= 17,
                default => true,
            };
        }));

        return $this->uno($enSuHorario !== [] ? $enSuHorario : $suyas);
    }

    /** Reserva con su plan; si ya no tiene, renueva en recepción (o se va). */
    private function reservar(int $i, SesionTenant $sesion, CarbonImmutable $dia): void
    {
        $a = $this->alumnas[$i];
        $inicia = CarbonImmutable::instance($sesion->inicia_en)->setTimezone($this->zona);
        $momento = $this->en($inicia->subHours((int) $this->elegir([2 => 25, 6 => 25, 20 => 30, 44 => 20])));
        try {
            app(ReservasTenant::class)->crear($sesion, $a['persona'], permitirEspera: true);
            $this->sumar('reservas');

            return;
        } catch (SinDerechoDisponible) {
            if (! $this->renovar($i, $momento)) {
                return;
            }
        } catch (RuntimeException|ValidationException) {
            return;
        }
        try {
            app(ReservasTenant::class)->crear($sesion, $this->alumnas[$i]['persona'], permitirEspera: true);
            $this->sumar('reservas');
        } catch (RuntimeException|ValidationException) {
            // Ya no hubo lugar ni lista de espera.
        }
    }

    /**
     * Se le acabó el plan: casi todas compran otro igual (a veces cambian); si aún
     * tiene su paquete vigente y solo se le acabaron las clases, compra clases extra.
     * Una de cada diez se va.
     */
    private function renovar(int $i, CarbonImmutable $momento): bool
    {
        $a = $this->alumnas[$i];
        if ($a['compra'] instanceof CarbonImmutable && $a['compra']->diffInDays($momento) < 25 && in_array($a['plan'], ['p4', 'p8'], true) && $this->prob(60)) {
            return $this->comprar($a['persona'], $this->extras, $momento);
        }
        if ($a['compra'] instanceof CarbonImmutable && $a['persona']->usuario_id === null && $this->prob(9)) {
            $this->alumnas[$i]['deja'] = $momento->startOfDay();
            $this->sumar('alumnas que se fueron');

            return false;
        }
        if ($this->prob(10)) {
            $this->alumnas[$i]['plan'] = (string) $this->elegir(['p4' => 20, 'p8' => 40, 'p12' => 20, 'ilimitada' => 20]);
        }
        $this->alumnas[$i]['compra'] = $momento;

        return $this->comprar($a['persona'], $this->planes[$this->alumnas[$i]['plan']], $momento);
    }

    private function comprar(PersonaTenant $persona, ProductoTenant $producto, CarbonImmutable $momento): bool
    {
        $momento = $this->en($momento);
        $cajera = $momento->isoWeekday() === 6 ? $this->equipo['daniela'] : $this->equipo['ximena'];
        try {
            $ordenes = app(OrdenesTenant::class);
            $orden = $ordenes->crear($persona, [['producto' => $producto, 'cantidad' => 1]], sucursalId: (int) $this->sede->getKey());
            $ordenes->liquidar($orden, (string) $this->elegir(['efectivo' => 35, 'manual' => 40, 'transferencia' => 25]), null, $cajera);
            $this->sumar('ventas de planes');

            return true;
        } catch (RuntimeException|ValidationException) {
            return false;
        }
    }

    /** Llega con clase muestra; casi dos de cada tres se quedan con un plan. */
    private function claseMuestra(int $i, SesionTenant $sesion, CarbonImmutable $dia): void
    {
        $a = $this->alumnas[$i];
        $inicia = CarbonImmutable::instance($sesion->inicia_en)->setTimezone($this->zona);
        // Paga su clase muestra al darse de alta; si llegó cuando la clase ya empezaba,
        // toma la siguiente.
        $momento = CarbonImmutable::instance($a['persona']->created_at ?? $inicia)->setTimezone($this->zona)->addMinutes(5);
        if ($momento->greaterThanOrEqualTo($inicia->subMinutes(30))) {
            return;
        }
        // La clase muestra se paga una vez; si la clase estaba llena, toma la siguiente.
        if ($a['compra'] === null) {
            if (! $this->comprar($a['persona'], $this->planes['muestra'], $momento)) {
                return;
            }
            $this->alumnas[$i]['compra'] = $momento;
        }
        try {
            app(ReservasTenant::class)->crear($sesion, $a['persona']);
            $this->sumar('clases muestra');
        } catch (RuntimeException|ValidationException) {
            // Clase llena: le tocará la siguiente (su clase muestra vale una semana).
            return;
        }
        $this->alumnas[$i]['nueva'] = false;
        if ($this->prob(64)) {
            // Se inscribe al salir de su clase muestra.
            $this->alumnas[$i]['desde'] = $dia->addDay();
            $this->alumnas[$i]['compra'] = $inicia->addMinutes(70);
            $this->comprar($a['persona'], $this->planes[$a['plan']], $inicia->addMinutes(70));
        } else {
            $this->alumnas[$i]['deja'] = $dia->addDay();
        }
    }

    /**
     * Algunas cancelan a tiempo (la mayoría) o tarde; si había lista de espera, a la
     * primera se le ofrece el lugar y lo acepta.
     */
    private function cancelaciones(SesionTenant $sesion, CarbonImmutable $inicia, ?CarbonImmutable $ahora): void
    {
        $reservas = ReservaTenant::query()->where('sesion_id', $sesion->getKey())->where('estado', EstadoReserva::Confirmada->value)->get();
        foreach ($reservas as $reserva) {
            if (! $this->prob(8)) {
                continue;
            }
            $momento = $inicia->subHours($this->prob(80) ? $this->azar(8, 30) : $this->azar(1, 3));
            // Nadie cancela antes de haber reservado.
            $reservada = CarbonImmutable::instance($reserva->created_at ?? $momento)->setTimezone($this->zona);
            if ($momento->lessThanOrEqualTo($reservada)) {
                $momento = $reservada->addMinutes($this->azar(10, 60));
            }
            if ($momento->greaterThanOrEqualTo($inicia) || ($ahora instanceof CarbonImmutable && $momento->greaterThan($ahora))) {
                continue;
            }
            $this->en($momento);
            try {
                app(ReservasTenant::class)->cancelar($reserva, QuienCancela::Cliente);
                $this->sumar('reservas canceladas');
            } catch (RuntimeException|ValidationException) {
                continue;
            }
            // Entró quien esperaba: acepta su lugar a los pocos minutos.
            $this->en($momento->addMinutes($this->azar(5, 40)));
            foreach (ReservaTenant::query()->where('sesion_id', $sesion->getKey())->where('estado', EstadoReserva::Ofrecida->value)->get() as $ofrecida) {
                try {
                    app(ReservasTenant::class)->aceptar($ofrecida);
                    $this->sumar('lugares de lista de espera');
                } catch (RuntimeException|ValidationException) {
                    // Expiró o ya no tenía saldo.
                }
            }
        }
    }

    private function asistencia(SesionTenant $sesion, CarbonImmutable $termina): void
    {
        $this->en($termina);
        $profe = Usuario::query()->find($sesion->instructor_id);
        foreach (ReservaTenant::query()->where('sesion_id', $sesion->getKey())->where('estado', EstadoReserva::Confirmada->value)->get() as $reserva) {
            $vino = $this->prob(93);
            try {
                app(AsistenciaTenant::class)->marcar($reserva, $vino ? EstadoAsistencia::Presente : EstadoAsistencia::Ausente, $profe);
            } catch (RuntimeException|ValidationException) {
                continue;
            }
            $this->sumar($vino ? 'asistencias' : 'no asistieron');
            if ($vino && $this->prob(4)) {
                $calificacion = (int) $this->elegir([5 => 80, 4 => 16, 3 => 4]);
                ResenaTenant::query()->create([
                    'reserva_id' => $reserva->getKey(), 'persona_id' => $reserva->persona_id, 'oferta_id' => $sesion->oferta_id,
                    'instructor_id' => $sesion->instructor_id, 'calificacion' => $calificacion,
                    'comentario' => $this->prob(75) ? $this->uno(self::COMENTARIOS[$calificacion]) : null, 'visible' => true,
                ]);
                $this->sumar('reseñas');
            }
        }
    }

    private function ventasDeMostrador(CarbonImmutable $dia, ?CarbonImmutable $ahora): void
    {
        $pos = app(PuntoDeVentaTenant::class);
        $inventario = app(InventarioTenant::class);
        $ventas = $this->azar(0, $dia->isoWeekday() === 6 ? 3 : 2);
        for ($n = 0; $n < $ventas; $n++) {
            $momento = CarbonImmutable::parse($dia->toDateString().' '.$this->uno(['08:55', '17:50', '18:55', '19:55', '21:05']), $this->zona);
            if ($dia->isoWeekday() === 6) {
                $momento = $momento->setTime($this->azar(10, 12), $this->azar(0, 59));
            }
            if ($ahora instanceof CarbonImmutable && $momento->greaterThan($ahora)) {
                continue;
            }
            $this->en($momento);
            $articulo = $this->articulos[(int) $this->elegir([0 => 40, 1 => 15, 2 => 15, 3 => 15, 4 => 15])];
            if ($inventario->stock((int) $articulo->getKey(), (int) $this->sede->getKey()) < 2) {
                $inventario->registrar((int) $articulo->getKey(), (int) $this->sede->getKey(), 10, TipoMovimientoInventario::Entrada, 'Compra a proveedor', $this->equipo['daniela']);
            }
            try {
                $pos->vender($this->sede, [['articulo' => $articulo, 'cantidad' => 1]], (string) $this->elegir(['efectivo' => 40, 'tarjeta' => 45, 'transferencia' => 15]), $dia->isoWeekday() === 6 ? $this->equipo['daniela'] : $this->equipo['ximena']);
                $this->sumar('ventas de mostrador');
            } catch (RuntimeException|ValidationException) {
                // Sin existencias.
            }
        }
    }

    /**
     * Las próximas dos semanas: las alumnas activas ya apartaron sus clases (menos
     * cuanto más lejos), con el reloj de hoy.
     */
    private function proximasSemanas(): void
    {
        for ($dia = $this->hoy->addDay(); $dia->lessThanOrEqualTo($this->hoy->addDays(14)); $dia = $dia->addDay()) {
            $sesiones = SesionTenant::query()->whereBetween('inicia_en', [$dia->startOfDay()->utc(), $dia->endOfDay()->utc()])->where('estado', 'programada')->get()->all();
            $yaApartan = max(25, 95 - (int) $this->hoy->diffInDays($dia) * 5);
            foreach ($this->alumnas as $a) {
                if ($a['nueva'] || $a['desde']->greaterThan($dia) || $a['deja'] instanceof CarbonImmutable
                    || ! in_array($dia->isoWeekday(), $a['dias'], true) || ! $this->prob($yaApartan)) {
                    continue;
                }
                $sesion = $this->claseDelDia($sesiones, $a);
                if (! $sesion instanceof SesionTenant) {
                    continue;
                }
                $this->hace(60, 72 * 60, $a['persona']->created_at);
                try {
                    app(ReservasTenant::class)->crear($sesion, $a['persona'], permitirEspera: true);
                    $this->sumar('reservas próximas');
                } catch (RuntimeException|ValidationException) {
                    // Sin saldo (renovará al venir) o ya apartada.
                }
            }
        }
    }
}
