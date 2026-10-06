<?php

declare(strict_types=1);

namespace App\Console\Commands\Demos;

use App\Modules\Tenancy\Acceso\MetodoAcceso;
use App\Modules\Tenancy\Application\AsistenciaTenant;
use App\Modules\Tenancy\Application\GenerarAgendaTenant;
use App\Modules\Tenancy\Application\InventarioTenant;
use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Application\OrdenesTenant;
use App\Modules\Tenancy\Application\PausarMembresiaTenant;
use App\Modules\Tenancy\Application\PuntoDeVentaTenant;
use App\Modules\Tenancy\Application\RegistrarAccesoTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Automatizacion\EventoAutomatizacion;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\SegmentoComunicacion;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Membresias\TipoVigencia;
use App\Modules\Tenancy\Membresias\VigenciaProducto;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\AsistenciaTenant as AsistenciaModelo;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\NivelTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
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
use App\Modules\Tenancy\Ordenes\TipoPromocion;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Recursos\ModoRecurso;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\SinDerechoDisponible;
use App\Modules\Tenancy\Reservas\QuienCancela;
use App\Modules\Tenancy\TipoCampo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Grecon Art House (demo): estudio de pole y exotic con los profesores, las clases,
 * el horario y los precios de prueba de `docs/DEMO_GRECON.md`. Solo lo que el
 * documento da: la agenda pública de octubre de 2026 (191 sesiones de 26 clases con
 * 11 profesores) y los planes de prueba (paquetes de 4, 8 y 12 clases hasta fin de
 * mes e Ilimitada, la única que incluye Open Training). Sin reglas que el sistema aún
 * no aplica (permanencia, inscripción y anualidad).
 *
 * Lo que el documento no da es inventado para que cada apartado tenga datos (ver
 * {@see ApartadosDemo}): lo que se vende en recepción con su inventario, la nómina por
 * clase, los niveles de pole, un rol propio, expedientes, el cuestionario de salud,
 * promociones, lealtad, comunicaciones, facturas, accesos con QR, una membresía en
 * pausa, una devolución y una solicitud de privacidad.
 *
 * El horario es el semanal que publica el estudio (44 clases a la semana). Octubre
 * queda exactamente como la agenda publicada: suplencias, clases sin profesor
 * publicado, semanas sin cierta clase y la sesión suspendida. Los meses anteriores
 * repiten el horario semanal.
 *
 * Constantino Escobar es el dueño, da clases y también toma clases con otros
 * profesores: una sola cuenta con los tres roles (entra con el que elija, ADR 0055)
 * y su ficha de alumno con su plan.
 *
 * Los 80 miembros son inventados: cada uno con su estilo (pole por nivel, exotic o
 * mixto), su horario y su plan. Reservan, cancelan (y entra quien esperaba), se
 * marca su asistencia, compran su plan al empezar el mes o cuando se les acaba, y
 * algunos se van. Al final quedan clases apartadas para las próximas dos semanas,
 * pagos por cobrar y algunos adeudos.
 */
final class DemoGrecon extends DemoBase
{
    use ApartadosDemo;

    /** La agenda pública de octubre (instantánea). */
    private const AGENDA = 'demo/grecon-octubre-2026.json';

    /**
     * Planes de prueba del documento, todos hasta fin de mes:
     * clave => [nombre, tipo, precio (centavos), créditos (null = ilimitado), clases por semana].
     */
    private const PLANES = [
        'p4' => ['Paquete 4 clases', TipoProducto::Paquete, 80000, 4000, 1],
        'p8' => ['Paquete 8 clases', TipoProducto::Paquete, 140000, 8000, 2],
        'p12' => ['Paquete 12 clases', TipoProducto::Paquete, 180000, 12000, 3],
        'ilimitada' => ['Ilimitada', TipoProducto::Membresia, 220000, null, 4],
    ];

    /** Descripción de muestra de cada categoría (el documento solo da los nombres). */
    private const DESCRIPCIONES = [
        'Pole' => 'Técnica de pole por nivel: giros, subidas, figuras y fuerza, con calentamiento y estiramiento.',
        'Exotic' => 'Exotic pole con tacones: floorwork, transiciones y coreografía, cuidando la fluidez.',
        'Flexibilidad' => 'Flexibilidad activa y pasiva para splits, espalda y hombros. Para todos los niveles.',
        'Danza' => 'Danza para trabajar ritmo, coordinación y expresión.',
        'Open Training' => 'Práctica libre en el estudio con un coach que supervisa. Incluida en la Ilimitada.',
    ];

    /** Cupo de prueba: clases regulares y Open Training. */
    private const CUPO = 8;

    private const CUPO_OPEN = 12;

    /** Los planes valen hasta fin de mes: en sus últimos días nadie compra uno. */
    private const SIN_COMPRA_ULTIMOS_DIAS = 7;

    /** El dueño: también da clases y toma clases como alumno. */
    private const DUENO = 'CONSTANTINO ESCOBAR';

    /** Las clases que toma el dueño con otros profesores (no chocan con las suyas). */
    private const CLASES_DEL_DUENO = ['ACRO MOVING', 'FLEXY LAB', 'JAZZ FUNK'];

    /** Cuántos miembros ya venían al empezar la historia y cuántos llegan durante ella. */
    private const ANTIGUOS = 62;

    private const NUEVOS = 18;

    /** Lo que se vende en recepción: [nombre, sku, precio (centavos), existencias iniciales]. */
    private const ARTICULOS = [
        ['Agua natural 600 ml', 'GR-AGU-01', 2500, 48],
        ['Bebida isotónica', 'GR-ISO-02', 3800, 24],
        ['Grip líquido para pole', 'GR-GRP-03', 39000, 10],
        ['Calcetas antiderrapantes', 'GR-CAL-04', 18000, 20],
        ['Rodilleras para pole', 'GR-ROD-05', 52000, 8],
        ['Shorts Grecon', 'GR-SHO-06', 45000, 12],
        ['Top deportivo Grecon', 'GR-TOP-07', 48000, 12],
    ];

    private const COMENTARIOS = [
        5 => ['Me encantó la clase, súper bien explicada.', 'Por fin me salió el combo.', 'Excelente ambiente, muy seguro.', 'La mejor clase de la semana.', 'Muy buena corrección de técnica.'],
        4 => ['Muy buena, aunque el grupo estaba lleno.', 'Me gustó mucho, quisiera más calentamiento.'],
        3 => ['Bien, pero empezó unos minutos tarde.'],
    ];

    private SucursalTenant $sede;

    private Usuario $dueno;

    private Usuario $recepcion;

    private Usuario $admin;

    /** @var list<ArticuloTenant> */
    private array $articulos = [];

    /** @var array<string, Usuario> nombre publicado => cuenta */
    private array $profes = [];

    /** @var array<string, OfertaTenant> nombre => clase */
    private array $clases = [];

    /** @var array<int, string> oferta_id => nombre */
    private array $nombreDeOferta = [];

    /** @var array<string, ProductoTenant> */
    private array $planes = [];

    /** @var array<int, true> miembros con su mes por pagar en recepción */
    private array $porPagar = [];

    /**
     * @var list<array{persona: PersonaTenant, plan: string, dias: list<int>, clases: list<string>, franja: string, desde: CarbonImmutable, deja: CarbonImmutable|null, nueva: bool, sube: CarbonImmutable|null, compra: CarbonImmutable|null}>
     */
    private array $alumnas = [];

    protected function datos(): array
    {
        return [
            'nombre' => 'Grecon Art House (demo)',
            'slug' => 'demo',
            'perfil' => PerfilNegocio::Pole,
            'contacto' => ['Constantino', 'Escobar', ''],
            'email' => 'constantino@grecon.test',
            'telefono' => '5500000000',
            'descripcion' => 'Demo con el horario y las clases publicadas por Grecon Art House en octubre de 2026: pole por niveles, exotic, flexibilidad, danza y Open Training. Precios, cuentas y miembros de prueba.',
            'instagram' => '@greconarthouse',
            'color' => [17, 17, 17],
            // Demo de un negocio real: solo con enlace, fuera del directorio.
            'privado' => true,
        ];
    }

    public function cuentas(): array
    {
        return [
            'constantino@grecon.test' => 'Constantino Escobar: dueño, instructor y alumno (elige con qué rol entrar)',
            'admin@grecon.test' => 'Administración: catálogo, agenda, miembros, ventas y configuración',
            'recepcion@grecon.test' => 'Recepción',
            'abril@grecon.test' => 'Instructora (Abril Von)',
            'valeria.rios@correo.test' => 'Miembro con Paquete 8 clases («Mi cuenta»)',
            'renata.soto@correo.test' => 'Miembro con Ilimitada, incluye Open Training («Mi cuenta»)',
        ];
    }

    protected function sembrar(): void
    {
        $this->reloj($this->inicio->subDays(30), '10:00');
        $agenda = $this->agenda();
        $this->sedeYEquipo($agenda);
        $this->catalogoYPlanes($agenda);
        $this->horarioSemanal($agenda);
        $this->inventario();
        $this->darDeAltaMiembros();

        for ($dia = $this->inicio; $dia->lessThanOrEqualTo($this->hoy); $dia = $dia->addDay()) {
            $this->unDia($dia);
        }
        $this->proximasSemanas();
        $this->porCobrar();
        $this->apartados();
    }

    /**
     * @return array{instructores: list<array{nombre: string}>, sesiones: list<array{fecha: string, actividad: string, instructor: string|null, hora_inicio: string, duracion_minutos: int, estado_publicado: string}>}
     */
    private function agenda(): array
    {
        /** @var array{instructores: list<array{nombre: string}>, sesiones: list<array{fecha: string, actividad: string, instructor: string|null, hora_inicio: string, duracion_minutos: int, estado_publicado: string}>} $datos */
        $datos = json_decode(File::get(database_path(self::AGENDA)), true, flags: JSON_THROW_ON_ERROR);

        return $datos;
    }

    // ---------------------------------------------------------------- estructura

    /**
     * @param  array{instructores: list<array{nombre: string}>}  $agenda
     */
    private function sedeYEquipo(array $agenda): void
    {
        $organizacion = OrganizacionTenant::query()->create(['nombre' => 'Grecon Art House']);
        // Una sede de prueba: la dirección real no está confirmada, no se inventa.
        $this->sede = $organizacion->sucursales()->create([
            'nombre' => 'Grecon Art House', 'zona_horaria' => $this->zona, 'region' => 'CDMX', 'moneda' => 'MXN', 'impuesto_tasa_bps' => 1600,
            'horario' => [
                ['dia' => 1, 'abre' => '10:00', 'cierra' => '21:30'], ['dia' => 2, 'abre' => '10:00', 'cierra' => '22:00'],
                ['dia' => 3, 'abre' => '10:00', 'cierra' => '22:00'], ['dia' => 4, 'abre' => '10:00', 'cierra' => '22:00'],
                ['dia' => 5, 'abre' => '11:00', 'cierra' => '20:30'], ['dia' => 6, 'abre' => '08:00', 'cierra' => '13:00'],
                ['dia' => 7, 'abre' => '08:00', 'cierra' => '12:00'],
            ],
        ]);

        // Los profesores con el nombre que publica el estudio; sus cuentas son de prueba.
        // El dueño es uno de ellos y además toma clases (ADR 0055: entra con un rol).
        foreach ($agenda['instructores'] as $profe) {
            $partes = explode(' ', mb_convert_case(mb_strtolower($profe['nombre']), MB_CASE_TITLE), 2);
            $correo = NombresDemo::ascii($partes[0]).'@grecon.test';
            $roles = $profe['nombre'] === self::DUENO ? ['propietario', 'instructor', 'miembro'] : ['instructor'];
            $this->profes[$profe['nombre']] = $this->usuario($correo, $partes[0], $partes[1] ?? '', $roles);
        }
        $this->dueno = $this->profes[self::DUENO];
        $this->admin = $this->usuario('admin@grecon.test', 'Administración', 'Grecon', ['admin']);
        $this->recepcion = $this->usuario('recepcion@grecon.test', 'Fernanda', 'Ruiz', ['recepcionista']);

        // Nómina: cada coach cobra por clase impartida (el dueño no se paga la suya).
        foreach ($this->profes as $nombre => $profe) {
            if ($nombre !== self::DUENO) {
                EsquemaPagoTenant::query()->create([
                    'usuario_id' => $profe->getKey(), 'tipo' => TipoPago::PorClase->value,
                    'monto_minor' => $this->uno([35000, 40000, 45000]), 'moneda' => 'MXN', 'activo' => true,
                ]);
            }
        }
        // Un rol propio para quien coordina la agenda (sin cobros ni configuración).
        $this->rolPropio($this->dueno, 'Coordinación', [
            'agenda.ver', 'agenda.gestionar', 'catalogo.ver', 'sucursales.ver', 'reservas.ver', 'reservas.gestionar', 'asistencia.marcar', 'miembros.ver', 'tareas.ver', 'tareas.gestionar',
        ]);
        // Los tubos y la sala, para saber con qué se cuenta.
        RecursoTenant::query()->create(['sucursal_id' => $this->sede->getKey(), 'nombre' => 'Tubos de pole', 'tipo' => 'Equipo', 'modo' => ModoRecurso::Pool->value, 'capacidad' => self::CUPO, 'activo' => true]);
        RecursoTenant::query()->create(['sucursal_id' => $this->sede->getKey(), 'nombre' => 'Sala principal', 'tipo' => 'Sala', 'modo' => ModoRecurso::Unidad->value, 'capacidad' => 1, 'activo' => true]);
    }

    /**
     * Una clase por nombre publicado, agrupadas en categorías; y los planes.
     *
     * @param  array{sesiones: list<array{actividad: string, duracion_minutos: int}>}  $agenda
     */
    private function catalogoYPlanes(array $agenda): void
    {
        $programas = [];
        $actividades = [];
        foreach ($agenda['sesiones'] as $s) {
            $nombre = $s['actividad'];
            if (isset($this->clases[$nombre])) {
                continue;
            }
            $categoria = self::categoria($nombre);
            $programas[$categoria] ??= ProgramaTenant::query()->create(['slug' => str($categoria)->slug()->value(), 'nombre' => $categoria]);
            $actividades[$categoria] ??= $programas[$categoria]->actividades()->create(['slug' => str($categoria)->slug()->value(), 'nombre' => $categoria]);
            $this->clases[$nombre] = $actividades[$categoria]->ofertas()->create([
                'nombre' => $nombre, 'descripcion' => self::DESCRIPCIONES[$categoria] ?? null, 'modalidad' => ModalidadOfertaTenant::Grupal->value,
                'capacidad' => $categoria === 'Open Training' ? self::CUPO_OPEN : self::CUPO,
                'politica_reserva' => PoliticaReservaTenant::Entitlement->value, 'duracion_minutos' => $s['duracion_minutos'],
            ]);
            $this->nombreDeOferta[(int) $this->clases[$nombre]->getKey()] = $nombre;
        }
        $this->sumar('clases del catálogo', count($this->clases));
        // Los niveles de pole que usa el estudio (POLE LEVEL 1 a 3).
        $pole = $actividades['Pole'] ?? null;
        if ($pole instanceof ActividadTenant) {
            foreach (['Nivel 1 (principiante)', 'Nivel 2 (intermedio)', 'Nivel 3 (avanzado)'] as $orden => $nivel) {
                NivelTenant::query()->create(['actividad_id' => $pole->getKey(), 'nombre' => $nivel, 'orden' => $orden + 1]);
            }
        }

        // Los paquetes no incluyen Open Training (lista explícita: vacía serían todas).
        $sinOpen = [];
        foreach ($this->clases as $nombre => $oferta) {
            if (self::categoria($nombre) !== 'Open Training') {
                $sinOpen[] = (int) $oferta->getKey();
            }
        }
        $membresias = app(MembresiasTenant::class);
        foreach (self::PLANES as $clave => [$nombre, $tipo, $precio, $creditos]) {
            $this->planes[$clave] = $membresias->crearProducto(
                $nombre, $tipo, $precio, 'MXN', $creditos === null, $creditos,
                vigencia: new VigenciaProducto(TipoVigencia::FinDeMes, 1),
                ofertaIds: $clave === 'ilimitada' ? [] : $sinOpen,
            );
        }
    }

    /**
     * El horario semanal que publica el estudio como clases recurrentes (con el
     * profesor de casi todas las semanas), y octubre igual que la agenda publicada.
     *
     * @param  array{sesiones: list<array{fecha: string, actividad: string, instructor: string|null, hora_inicio: string, duracion_minutos: int, estado_publicado: string}>}  $agenda
     */
    private function horarioSemanal(array $agenda): void
    {
        /** @var array<string, array{dia: int, hora: string, clase: string, minutos: int, profes: array<string, int>}> $bloques */
        $bloques = [];
        foreach ($agenda['sesiones'] as $s) {
            $dia = CarbonImmutable::parse($s['fecha'], $this->zona)->isoWeekday();
            $clave = "{$dia}|{$s['hora_inicio']}|{$s['actividad']}";
            $bloques[$clave] ??= ['dia' => $dia, 'hora' => $s['hora_inicio'], 'clase' => $s['actividad'], 'minutos' => $s['duracion_minutos'], 'profes' => []];
            $profe = $s['instructor'] ?? '';
            $bloques[$clave]['profes'][$profe] = ($bloques[$clave]['profes'][$profe] ?? 0) + 1;
        }

        $hasta = $this->hoy->addDays(21);
        $generar = app(GenerarAgendaTenant::class);
        foreach ($bloques as $b) {
            arsort($b['profes']);
            $profe = (string) array_key_first($b['profes']);
            $plantilla = PlantillaHorarioTenant::query()->create([
                'oferta_id' => $this->clases[$b['clase']]->getKey(), 'sucursal_id' => $this->sede->getKey(),
                'instructor_id' => $profe === '' ? null : $this->profes[$profe]->getKey(),
                'dias_semana' => [$b['dia']], 'hora_local' => $b['hora'], 'duracion_minutos' => $b['minutos'],
                'capacidad' => (int) $this->clases[$b['clase']]->capacidad, 'activo' => true, 'vigente_desde' => $this->inicio->toDateString(),
            ]);
            $resultado = $generar->ejecutar($plantilla, $this->inicio->toDateString(), $hasta->toDateString());
            $this->sumar('clases en la agenda', $resultado->creadas);
        }

        $this->octubreComoSePublico($agenda, $hasta);
    }

    /**
     * Las fechas de la agenda publicada dentro de la historia quedan iguales a ella:
     * suplencias, clases sin profesor publicado, semanas sin cierta clase y la
     * sesión suspendida.
     *
     * @param  array{sesiones: list<array{fecha: string, actividad: string, instructor: string|null, hora_inicio: string, estado_publicado: string}>}  $agenda
     */
    private function octubreComoSePublico(array $agenda, CarbonImmutable $hasta): void
    {
        /** @var array<string, array<string, array{instructor: string|null, estado_publicado: string}>> $porFecha */
        $porFecha = [];
        foreach ($agenda['sesiones'] as $s) {
            $porFecha[$s['fecha']]["{$s['hora_inicio']}|{$s['actividad']}"] = $s;
        }

        foreach ($porFecha as $fecha => $publicadas) {
            $dia = CarbonImmutable::parse($fecha, $this->zona);
            if ($dia->lessThan($this->inicio) || $dia->greaterThan($hasta)) {
                continue;
            }
            $generadas = SesionTenant::query()
                ->whereBetween('inicia_en', [$dia->startOfDay()->utc(), $dia->endOfDay()->utc()])
                ->get();
            foreach ($generadas as $sesion) {
                $clave = CarbonImmutable::instance($sesion->inicia_en)->setTimezone($this->zona)->format('H:i').'|'.$this->nombreDeOferta[(int) $sesion->oferta_id];
                $publicada = $publicadas[$clave] ?? null;
                if ($publicada === null) {
                    // Esa semana no hubo esta clase.
                    $sesion->delete();
                    $this->sumar('semanas sin la clase');

                    continue;
                }
                $profe = $publicada['instructor'];
                $profeId = $profe === null ? null : $this->profes[$profe]->getKey();
                if ((int) $sesion->instructor_id !== (int) $profeId) {
                    $sesion->update(['instructor_id' => $profeId]);
                    $this->sumar($profe === null ? 'clases sin profesor publicado' : 'suplencias');
                }
                if ($publicada['estado_publicado'] !== 'programada') {
                    app(ReservasTenant::class)->cancelarSesion($sesion, $this->dueno);
                    $this->sumar('clases suspendidas');
                }
            }
        }
    }

    /** Pole, Exotic, Flexibilidad, Danza u Open Training, por su nombre publicado. */
    private static function categoria(string $clase): string
    {
        $nombre = mb_strtoupper($clase);

        return match (true) {
            str_contains($nombre, 'OPEN TRAINING') => 'Open Training',
            str_contains($nombre, 'FLEX') => 'Flexibilidad',
            str_contains($nombre, 'JAZZ') || str_contains($nombre, 'ACRO MOVING') => 'Danza',
            str_contains($nombre, 'EXO') || str_contains($nombre, 'HEELS') || str_contains($nombre, 'LOW FLOW') => 'Exotic',
            default => 'Pole',
        };
    }

    /** El nivel que dice el nombre (POLE LEVEL 1…3), si lo dice. */
    private static function nivel(string $clase): ?int
    {
        return preg_match('/LEVEL (\d)/i', $clase, $m) === 1 ? (int) $m[1] : null;
    }

    /** Lo que se vende en recepción, con sus existencias iniciales. */
    private function inventario(): void
    {
        $inventario = app(InventarioTenant::class);
        foreach (self::ARTICULOS as [$nombre, $sku, $precio, $stock]) {
            $articulo = ArticuloTenant::query()->create(['nombre' => $nombre, 'sku' => $sku, 'precio_minor' => $precio, 'moneda' => 'MXN', 'activo' => true]);
            $this->articulos[] = $articulo;
            $inventario->registrar((int) $articulo->getKey(), (int) $this->sede->getKey(), $stock, TipoMovimientoInventario::Entrada, 'Inventario inicial', $this->admin);
        }
    }

    // ---------------------------------------------------------------- miembros

    /**
     * Los que ya venían y los que llegan durante la historia, más dos con cuenta
     * para revisar «Mi cuenta» (una con paquete y una con Ilimitada) y el dueño
     * como alumno.
     */
    private function darDeAltaMiembros(): void
    {
        $dias = (int) $this->inicio->diffInDays($this->hoy);
        for ($i = 0; $i < self::ANTIGUOS + self::NUEVOS; $i++) {
            $antiguo = $i < self::ANTIGUOS;
            $alta = $antiguo
                ? $this->inicio->subDays($this->azar(20, 600))->setTime($this->azar(9, 20), $this->azar(0, 59))
                : $this->inicio->addDays($this->azar(0, max(0, $dias - 5)))->setTime($this->azar(10, 19), $this->azar(0, 59));
            $hombre = $this->prob(12);
            $persona = $this->persona(
                $this->uno($hombre ? NombresDemo::HOMBRES : NombresDemo::MUJERES), $this->uno(NombresDemo::APELLIDOS), $this->uno(NombresDemo::APELLIDOS),
                $alta, (int) $this->sede->getKey(), $this->prob(85), ['genero' => $hombre ? 'hombre' : 'mujer'],
            );
            $plan = (string) $this->elegir(['p4' => 22, 'p8' => 36, 'p12' => 17, 'ilimitada' => 25]);
            $this->alumnas[] = $this->costumbre($persona, $plan, $antiguo ? $this->inicio : $alta->startOfDay(), ! $antiguo);
        }

        $this->conCuenta(0, 'Valeria', 'Ríos', 'Peña', 'valeria.rios@correo.test', 'p8');
        $this->conCuenta(1, 'Renata', 'Soto', 'Lara', 'renata.soto@correo.test', 'ilimitada');

        // El dueño también es alumno: su ficha ligada a su cuenta, con Ilimitada.
        $dueno = $this->persona('Constantino', 'Escobar', '', $this->inicio->subDays(900), (int) $this->sede->getKey(), false, [
            'email' => 'constantino@grecon.test', 'usuario_id' => $this->dueno->getKey(), 'genero' => 'hombre',
        ]);
        $this->alumnas[] = [
            ...$this->costumbre($dueno, 'ilimitada', $this->inicio, false),
            'clases' => self::CLASES_DEL_DUENO, 'dias' => [1, 4, 5], 'franja' => 'tarde', 'sube' => null, 'deja' => null,
        ];
    }

    private function conCuenta(int $i, string $nombre, string $apellido1, string $apellido2, string $correo, string $plan): void
    {
        $persona = $this->alumnas[$i]['persona'];
        $persona->update(['nombre' => $nombre, 'primer_apellido' => $apellido1, 'segundo_apellido' => $apellido2, 'email' => $correo, 'genero' => 'mujer']);
        $cuenta = $this->usuario($correo, $nombre, $apellido1, ['miembro']);
        $persona->update(['usuario_id' => $cuenta->getKey()]);
        $this->alumnas[$i] = [...$this->costumbre($persona, $plan, $this->inicio, false), 'deja' => null];
    }

    /**
     * Su estilo (pole por nivel, exotic o mixto), su horario y sus días según el plan.
     *
     * @return array{persona: PersonaTenant, plan: string, dias: list<int>, clases: list<string>, franja: string, desde: CarbonImmutable, deja: CarbonImmutable|null, nueva: bool, sube: CarbonImmutable|null, compra: CarbonImmutable|null}
     */
    private function costumbre(PersonaTenant $persona, string $plan, CarbonImmutable $desde, bool $nueva): array
    {
        $estilo = (string) $this->elegir(['pole' => 45, 'exotic' => 30, 'mixto' => 25]);
        $nivel = $nueva ? 1 : (int) $this->elegir([1 => 35, 2 => 40, 3 => 25]);
        $clases = [];
        foreach (array_keys($this->clases) as $nombre) {
            $categoria = self::categoria($nombre);
            $suNivel = self::nivel($nombre);
            $leToca = match ($categoria) {
                'Pole' => $estilo !== 'exotic' && ($suNivel === null || $suNivel === $nivel),
                'Exotic' => $estilo !== 'pole',
                'Flexibilidad', 'Danza' => $estilo === 'mixto' || $this->prob(30),
                default => $plan === 'ilimitada',
            };
            if ($leToca) {
                $clases[] = $nombre;
            }
        }
        $franja = (string) $this->elegir(['tarde' => 62, 'manana' => 22, 'finde' => 16]);
        $porSemana = self::PLANES[$plan][4];
        $posibles = $this->mezclar($franja === 'finde' ? [6, 7, 2, 4] : [1, 2, 3, 4, 5]);

        return [
            'persona' => $persona,
            'plan' => $plan,
            'dias' => array_slice($posibles, 0, min($porSemana, count($posibles))),
            'clases' => $clases,
            'franja' => $franja,
            'desde' => $desde,
            'deja' => null,
            'nueva' => $nueva,
            // Quien toma POLE LEVEL 1 pasa a LEVEL 2 tras unas semanas.
            'sube' => $estilo !== 'exotic' && $nivel === 1 && $this->prob(55) ? $desde->addDays($this->azar(42, 75)) : null,
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

        foreach ($this->mezclar(array_keys($this->alumnas)) as $i) {
            $a = $this->alumnas[$i];
            if ($a['desde']->greaterThan($dia) || ($a['deja'] instanceof CarbonImmutable && $dia->greaterThanOrEqualTo($a['deja']))) {
                continue;
            }
            if ($a['sube'] instanceof CarbonImmutable && $dia->greaterThanOrEqualTo($a['sube'])) {
                $this->alumnas[$i]['clases'] = array_map(static fn (string $c): string => $c === 'POLE LEVEL 1' ? 'POLE LEVEL 2' : $c, $a['clases']);
                $this->alumnas[$i]['sube'] = null;
                $a = $this->alumnas[$i];
            }
            // Quien llega toma su primera clase el día que se inscribe.
            if (! $a['nueva'] && ! in_array($dia->isoWeekday(), $a['dias'], true)) {
                continue;
            }
            $sesion = $this->claseDelDia($sesiones->all(), $a);
            if ($sesion instanceof SesionTenant) {
                $this->reservar($i, $sesion);
            }
        }

        foreach ($sesiones as $sesion) {
            $inicia = CarbonImmutable::instance($sesion->inicia_en)->setTimezone($this->zona);
            $termina = CarbonImmutable::instance($sesion->termina_en)->setTimezone($this->zona);
            $this->cancelaciones($sesion, $inicia, $esHoy ? $this->ahora : null);
            if (! $esHoy || $termina->lessThanOrEqualTo($this->ahora)) {
                $this->asistencia($sesion, $termina);
            }
        }
        $this->ventasDeMostrador($dia, $esHoy);
    }

    /**
     * La clase que toma hoy: de las suyas, en su horario si hay.
     *
     * @param  list<SesionTenant>  $sesiones
     * @param  array{clases: list<string>, franja: string}  $a
     */
    private function claseDelDia(array $sesiones, array $a): ?SesionTenant
    {
        $suyas = array_values(array_filter($sesiones, fn (SesionTenant $s): bool => in_array($this->nombreDeOferta[(int) $s->oferta_id], $a['clases'], true)));
        if ($suyas === []) {
            return null;
        }
        $enSuHorario = array_values(array_filter($suyas, function (SesionTenant $s) use ($a): bool {
            $inicia = CarbonImmutable::instance($s->inicia_en)->setTimezone($this->zona);

            return match ($a['franja']) {
                'manana' => $inicia->hour < 14,
                'tarde' => $inicia->hour >= 17,
                default => $inicia->isoWeekday() >= 6,
            };
        }));

        return $this->uno($enSuHorario !== [] ? $enSuHorario : $suyas);
    }

    /** Reserva con su plan; si no tiene (empezó el mes o se le acabó), compra en recepción. */
    private function reservar(int $i, SesionTenant $sesion): void
    {
        $a = $this->alumnas[$i];
        $inicia = CarbonImmutable::instance($sesion->inicia_en)->setTimezone($this->zona);
        $alta = CarbonImmutable::instance($a['persona']->created_at ?? $inicia)->setTimezone($this->zona);
        $antes = $a['nueva'] ? 1 : (int) $this->elegir([2 => 25, 6 => 25, 20 => 30, 44 => 20]);
        $momento = $inicia->subHours($antes);
        // Los planes valen hasta fin de mes: la clase de otro mes se aparta ya en ese mes.
        if (! $momento->isSameMonth($inicia)) {
            $momento = $inicia->subMinutes($this->azar(60, 180));
        }
        if ($momento->lessThan($alta)) {
            $momento = $alta->addMinutes(10);
        }
        if ($momento->greaterThanOrEqualTo($inicia)) {
            return;
        }
        $this->en($momento);
        try {
            app(ReservasTenant::class)->crear($sesion, $a['persona'], permitirEspera: true);
            $this->sumar('reservas');
            $this->alumnas[$i]['nueva'] = false;

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
            $this->alumnas[$i]['nueva'] = false;
        } catch (RuntimeException|ValidationException) {
            // Ya no hubo lugar ni lista de espera.
        }
    }

    /**
     * Sin plan vigente: casi todos compran otro (a veces cambian de plan). Quien
     * gastó su paquete a mitad de mes compra otro igual o uno más grande. Algunos
     * se van cuando termina su mes. Nadie compra un plan en la última semana del mes:
     * vale hasta fin de mes, así que espera al siguiente.
     */
    private function renovar(int $i, CarbonImmutable $momento): bool
    {
        $a = $this->alumnas[$i];
        $mismoMes = $a['compra'] instanceof CarbonImmutable && $a['compra']->isSameMonth($momento);
        if (! $mismoMes && $a['compra'] instanceof CarbonImmutable && $a['persona']->usuario_id === null && $this->prob($a['nueva'] ? 25 : 8)) {
            $this->alumnas[$i]['deja'] = $momento->startOfDay();
            $this->sumar('miembros que se fueron');

            return false;
        }
        if ($momento->daysInMonth - $momento->day < self::SIN_COMPRA_ULTIMOS_DIAS) {
            return false;
        }
        // Las cuentas para revisar «Mi cuenta» conservan el plan que dice su descripción.
        $conCuenta = $a['persona']->usuario_id !== null;
        if ($mismoMes) {
            // Se le acabaron las clases del mes: unos compran otro paquete (o uno más
            // grande); los demás esperan al mes siguiente.
            if (! $this->prob(40)) {
                return false;
            }
            if (! $conCuenta && in_array($a['plan'], ['p4', 'p8'], true) && $this->prob(50)) {
                $this->cambiarPlan($i, $a['plan'] === 'p4' ? 'p8' : 'p12');
            }
        } elseif (! $conCuenta && $this->prob(10)) {
            $this->cambiarPlan($i, (string) $this->elegir(['p4' => 20, 'p8' => 40, 'p12' => 20, 'ilimitada' => 20]));
        }
        $this->alumnas[$i]['compra'] = $momento;

        return $this->comprar($a['persona'], $this->planes[$this->alumnas[$i]['plan']], $momento);
    }

    /** Cambia de plan: Open Training entra o sale de sus clases con la Ilimitada. */
    private function cambiarPlan(int $i, string $plan): void
    {
        $open = array_keys(array_filter($this->clases, static fn (OfertaTenant $o, string $nombre): bool => self::categoria($nombre) === 'Open Training', ARRAY_FILTER_USE_BOTH));
        $clases = array_values(array_diff($this->alumnas[$i]['clases'], $open));
        $this->alumnas[$i]['clases'] = $plan === 'ilimitada' ? [...$clases, ...$open] : $clases;
        $this->alumnas[$i]['plan'] = $plan;
    }

    private function comprar(PersonaTenant $persona, ProductoTenant $producto, CarbonImmutable $momento): bool
    {
        $this->en($momento);
        try {
            $ordenes = app(OrdenesTenant::class);
            $orden = $ordenes->crear($persona, [['producto' => $producto, 'cantidad' => 1]], sucursalId: (int) $this->sede->getKey());
            $ordenes->liquidar($orden, (string) $this->elegir(['efectivo' => 30, 'manual' => 40, 'transferencia' => 30]), null, $this->recepcion);
            $this->sumar('ventas de planes');

            return true;
        } catch (RuntimeException|ValidationException) {
            return false;
        }
    }

    /**
     * Algunos cancelan a tiempo (la mayoría) o tarde; si había lista de espera, a la
     * primera se le ofrece el lugar y lo acepta.
     */
    private function cancelaciones(SesionTenant $sesion, CarbonImmutable $inicia, ?CarbonImmutable $ahora): void
    {
        $reservas = ReservaTenant::query()->where('sesion_id', $sesion->getKey())->where('estado', EstadoReserva::Confirmada->value)->get();
        foreach ($reservas as $reserva) {
            if (! $this->prob(9)) {
                continue;
            }
            $momento = $inicia->subHours($this->prob(80) ? $this->azar(8, 30) : $this->azar(1, 4));
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

    /** Al terminar la clase se pasa lista (el profesor o, sin profesor, recepción). */
    private function asistencia(SesionTenant $sesion, CarbonImmutable $termina): void
    {
        $this->en($termina);
        $quien = $sesion->instructor_id !== null ? Usuario::query()->find($sesion->instructor_id) : $this->recepcion;
        foreach (ReservaTenant::query()->where('sesion_id', $sesion->getKey())->where('estado', EstadoReserva::Confirmada->value)->get() as $reserva) {
            $vino = $this->prob(92);
            try {
                app(AsistenciaTenant::class)->marcar($reserva, $vino ? EstadoAsistencia::Presente : EstadoAsistencia::Ausente, $quien);
            } catch (RuntimeException|ValidationException) {
                continue;
            }
            $this->sumar($vino ? 'asistencias' : 'no asistieron');
            if ($vino && $sesion->instructor_id !== null && $this->prob(4)) {
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

    /**
     * En recepción se vende agua y bebidas (casi siempre), grip, calcetas y ropa del
     * estudio. Cuando algo se acaba, se compra al proveedor.
     */
    private function ventasDeMostrador(CarbonImmutable $dia, bool $esHoy): void
    {
        $pos = app(PuntoDeVentaTenant::class);
        $inventario = app(InventarioTenant::class);
        $finDeSemana = $dia->isoWeekday() >= 6;
        $ventas = $this->azar(0, $finDeSemana ? 2 : 4);
        for ($n = 0; $n < $ventas; $n++) {
            $hora = sprintf('%02d:%02d', $finDeSemana ? $this->azar(8, 11) : (int) $this->elegir([10 => 20, 12 => 10, 18 => 35, 19 => 25, 20 => 10]), $this->azar(0, 59));
            if ($esHoy && CarbonImmutable::parse($dia->toDateString().' '.$hora, $this->zona)->greaterThan($this->ahora)) {
                continue;
            }
            $cual = (int) $this->elegir([0 => 38, 1 => 18, 2 => 10, 3 => 12, 4 => 5, 5 => 9, 6 => 8]);
            $articulo = $this->articulos[$cual];
            $this->reloj($dia, $hora);
            if ($inventario->stock((int) $articulo->getKey(), (int) $this->sede->getKey()) < 2) {
                $inventario->registrar((int) $articulo->getKey(), (int) $this->sede->getKey(), $cual <= 1 ? 24 : 6, TipoMovimientoInventario::Entrada, 'Compra a proveedor', $this->admin);
            }
            try {
                $pos->vender($this->sede, [['articulo' => $articulo, 'cantidad' => $cual <= 1 && $this->prob(25) ? 2 : 1]], (string) $this->elegir(['efectivo' => 50, 'tarjeta' => 40, 'transferencia' => 10]), $this->recepcion);
                $this->sumar('ventas de mostrador');
            } catch (RuntimeException|ValidationException) {
                // Sin existencias: no se vende.
            }
        }
    }

    /**
     * Las próximas dos semanas: los miembros activos ya apartaron sus clases (menos
     * cuanto más lejos). Quien aún no paga su mes lo compra en línea antes de apartar.
     */
    private function proximasSemanas(): void
    {
        for ($dia = $this->hoy->addDay(); $dia->lessThanOrEqualTo($this->hoy->addDays(14)); $dia = $dia->addDay()) {
            $sesiones = SesionTenant::query()->whereBetween('inicia_en', [$dia->startOfDay()->utc(), $dia->endOfDay()->utc()])->where('estado', 'programada')->get()->all();
            $yaApartan = max(25, 95 - (int) $this->hoy->diffInDays($dia) * 5);
            foreach ($this->alumnas as $i => $a) {
                if ($a['nueva'] || $a['desde']->greaterThan($dia) || $a['deja'] instanceof CarbonImmutable
                    || ! in_array($dia->isoWeekday(), $a['dias'], true) || ! $this->prob($yaApartan)) {
                    continue;
                }
                $sesion = $this->claseDelDia($sesiones, $a);
                if (! $sesion instanceof SesionTenant) {
                    continue;
                }
                // Apartó en los últimos días, ya dentro del mes de la clase (su plan es del mes).
                $inicioDelMes = $dia->startOfMonth();
                if ($inicioDelMes->greaterThan($this->ahora)) {
                    continue;
                }
                $momento = $this->hace(60, (int) max(61, min(72 * 60, $inicioDelMes->diffInMinutes($this->ahora))), $a['persona']->created_at);
                try {
                    app(ReservasTenant::class)->crear($sesion, $a['persona'], permitirEspera: true);
                    $this->sumar('reservas próximas');
                } catch (SinDerechoDisponible) {
                    // Sin su plan del mes: la mayoría lo paga en línea antes de apartar;
                    // los demás lo piden y lo pagan al llegar (queda por cobrar).
                    if (! $this->prob(65)) {
                        $this->pedirSinPagar($i, $momento);

                        continue;
                    }
                    if ($this->renovar($i, $momento)) {
                        try {
                            app(ReservasTenant::class)->crear($sesion, $this->alumnas[$i]['persona'], permitirEspera: true);
                            $this->sumar('reservas próximas');
                        } catch (RuntimeException|ValidationException) {
                            // Ya no hubo lugar.
                        }
                    }
                } catch (RuntimeException|ValidationException) {
                    // Ya apartada o sin lugar.
                }
            }
        }
    }

    /** Pidió su plan del mes y lo paga al llegar: la orden queda por cobrar. */
    private function pedirSinPagar(int $i, CarbonImmutable $momento): void
    {
        $a = $this->alumnas[$i];
        if (isset($this->porPagar[$i]) || $a['persona']->usuario_id !== null) {
            return;
        }
        $this->en($momento);
        app(OrdenesTenant::class)->crear($a['persona'], [['producto' => $this->planes[$a['plan']], 'cantidad' => 1]], sucursalId: (int) $this->sede->getKey());
        $this->porPagar[$i] = true;
        $this->sumar('órdenes por cobrar');
    }

    /** Quien dejó de venir en las últimas semanas dejó pedido su siguiente mes sin pagarlo. */
    private function porCobrar(): void
    {
        $ordenes = app(OrdenesTenant::class);
        $adeudos = 0;
        foreach ($this->alumnas as $a) {
            $deja = $a['deja'];
            if (! $deja instanceof CarbonImmutable || $deja->lessThan($this->hoy->subDays(45)) || $adeudos >= 3 || ! $this->prob(50)) {
                continue;
            }
            $this->en($deja->setTime(18, 0));
            $ordenes->crear($a['persona'], [['producto' => $this->planes[$a['plan']], 'cantidad' => 1]], sucursalId: (int) $this->sede->getKey());
            $adeudos++;
        }
        $this->sumar('adeudos de quienes dejaron de venir', $adeudos);
    }

    // ---------------------------------------------------------------- apartados

    /** Lo que el documento no da (inventado): cada apartado con datos. */
    private function apartados(): void
    {
        $nueva = $this->alumnas[self::ANTIGUOS + self::NUEVOS - 1]['persona'];
        $this->tareasDelEquipo([
            ['Pedir grip líquido y calcetas al proveedor', 'Quedan pocas piezas en recepción.', 2, $this->recepcion, false],
            ['Revisar el tubo 6: rechina al girar', 'Avisar a mantenimiento si hay que cambiar el rodamiento.', -1, $this->admin, false],
            ['Publicar el horario del próximo mes', 'En la página de reservas y en Instagram.', 5, $this->admin, false],
            ['Llamar a las alumnas que no renovaron', 'Ofrecerles el Paquete 4 clases.', 1, $this->recepcion, false],
            ["Dar seguimiento a la primera clase de {$nueva->nombre}", 'Preguntarle cómo le fue y qué plan le conviene.', 0, $this->recepcion, false, $nueva],
            ['Preparar la coreografía del showcase', null, 12, $this->profes['ABRIL VON'], false],
            ['Limpiar los tubos con alcohol al cierre', null, -3, $this->recepcion, true],
            ['Corte de caja del fin de semana', null, -2, $this->admin, true],
            ['Actualizar las fotos de los coaches', null, -6, $this->admin, true],
            ['Mandar la lista de canciones de Exotic Choreo', null, -4, $this->profes['LEO ARELLANO'], true],
        ], $this->admin);

        $this->notasEnFichas([
            'Lesión en la muñeca izquierda: cuidar los apoyos en invertidas.',
            'Prefiere clases por la tarde, después de las 7.',
            'Viene con su amiga; quieren el mismo horario.',
            'Pidió factura de su paquete: ya mandó sus datos.',
            'Le interesa Flying Pole cuando abra cupo.',
            'Pagó por transferencia; el comprobante está en el correo.',
            'Quiere subir a Nivel 2 el próximo mes.',
            'Se le olvidan las calcetas: ofrecerle las de recepción.',
            'Tuvo una cirugía de rodilla hace dos años; ya tiene alta médica.',
            'Recomendó a dos amigas este mes.',
            'Avisó que viaja la última semana del mes.',
            'Prefiere que le escriban por WhatsApp, no por correo.',
        ], [$this->recepcion, $this->admin, $this->profes['ABRIL VON']]);

        $this->expedientes(
            [['Certificado médico', 'Que puede hacer actividad física de alto impacto.', true], ['Identificación oficial', 'Para el registro y las facturas.', false]],
            ['deslinde', 'Deslinde de responsabilidad', "Entiendo que el pole, el exotic y la flexibilidad son actividades físicas con riesgo de lesión.\n\nSigo las indicaciones de mi coach, aviso de cualquier lesión o condición médica y uso el equipo como me indican. Grecon Art House no se hace responsable de lesiones por no seguir las indicaciones."],
            $this->admin, 32, 88,
        );

        $this->formularioConRespuestas('Cuestionario de salud', 'Antes de tu primera clase, para cuidarte mejor.', [
            ['¿Tienes alguna lesión o cirugía reciente?', TipoCampo::Booleano, true],
            ['Cuéntanos de tu lesión, si tienes', TipoCampo::Textarea, false, ['Esguince de tobillo hace un año, ya recuperado.', 'Dolor lumbar de vez en cuando.', 'Operación de rodilla en 2024.', 'Tendinitis en el hombro derecho.']],
            ['Experiencia en pole', TipoCampo::Seleccion, true, ['Nunca he tomado', 'Menos de 6 meses', 'De 6 meses a 2 años', 'Más de 2 años']],
            ['¿Cómo te enteraste de Grecon?', TipoCampo::Seleccion, false, ['Instagram', 'Una amiga', 'Google', 'TikTok']],
            ['Contacto de emergencia', TipoCampo::Texto, true, ['Mamá: 55 1234 5678', 'Pareja: 55 8765 4321', 'Hermana: 55 2468 1357', 'Amiga: 55 9753 1864']],
        ], 46);

        $this->promociones([
            ['BIENVENIDA10', 'Tu primer plan con 10 % de descuento', TipoPromocion::Porcentaje, 1000, null, null, 14, null, true],
            ['AMIGA15', 'Si vienes con una amiga, 15 % en tu plan', TipoPromocion::Porcentaje, 1500, 80000, 40, 9, 60, true],
            ['PLAN200', '$200 menos en planes de $1,400 o más', TipoPromocion::MontoFijo, 20000, 140000, 30, 6, 25, true],
            ['VERANO20', 'Promoción de verano', TipoPromocion::Porcentaje, 2000, null, 50, 31, -40, false],
        ]);

        $this->lealtad(10, 1, [
            ['Clase extra', 'Una clase de cualquier nivel, este mes.', 1500],
            ['Calcetas antiderrapantes', 'Un par, en recepción.', 2500],
            ['Grip líquido', 'El de recepción.', 4000],
            ['Top Grecon', 'Edición del estudio.', 6000],
        ], $this->recepcion);

        $this->comunicaciones([
            [45, SegmentoComunicacion::Todos, CanalComunicacion::Email, 'Nuevo horario de clases', "Hola {{persona_nombre}}:\n\nYa está el nuevo horario, con dos clases de Exotic por la mañana. Aparta tu lugar desde tu cuenta.\n\nNos vemos en el estudio."],
            [26, SegmentoComunicacion::Primerizos, CanalComunicacion::Email, '¿Cómo te fue en tu primera clase?', "Hola {{persona_nombre}}:\n\nGracias por venir. Si quieres seguir, el Paquete 4 clases es la mejor forma de empezar. Cualquier duda, escríbenos."],
            [9, SegmentoComunicacion::PorVencer, CanalComunicacion::Email, 'Tu plan vence a fin de mes', "Hola {{persona_nombre}}:\n\nTu plan vence a fin de mes. Renueva desde tu cuenta para conservar tus horarios."],
            [3, SegmentoComunicacion::Todos, CanalComunicacion::Interno, 'Showcase de fin de año', 'El showcase será el 12 de diciembre. Pregunta en recepción cómo participar.'],
        ], [
            ['Lugar ofrecido de la lista de espera', EventoAutomatizacion::ReservaOfrecida, 'Avisar a {{persona_nombre}} que tiene un lugar', 'Si no lo acepta a tiempo, se ofrece a la siguiente.', 0],
            ['Membresía suspendida por falta de pago', EventoAutomatizacion::MembresiaSuspendida, 'Llamar a {{persona_nombre}} por su pago', null, 60],
        ]);

        // Datos fiscales de prueba del SAT (no son los del estudio).
        $this->facturas(['GRECON ART HOUSE DEMO', 'EKU9003173C9', '601', '26015'], 6, '86121600');

        $pago = PagoTenant::query()->where('estado', EstadoPago::Aprobado->value)->orderByDesc('id')->skip(6)->first();
        $this->devolucion($pago, null, 'Se cambió de ciudad antes de usar su paquete.', $this->admin);

        foreach ($this->alumnas as $a) {
            if ($a['deja'] instanceof CarbonImmutable && $a['persona']->usuario_id === null) {
                $this->solicitudDePrivacidad($a['persona'], 'Ya no voy a tomar clases; por favor borren mis datos.');
                break;
            }
        }

        $this->llaveDeApi('Sitio web (horario de clases)', ['agenda.ver']);
        $this->accesosConQr();
        $this->unaPausa();
    }

    /** La entrada con QR de quienes llegaron a clase en los últimos diez días. */
    private function accesosConQr(): void
    {
        $acceso = app(RegistrarAccesoTenant::class);
        $asistencias = AsistenciaModelo::query()->with('reserva.persona', 'reserva.sesion')
            ->where('estado', EstadoAsistencia::Presente->value)
            ->whereHas('reserva.sesion', fn ($q) => $q->where('inicia_en', '>=', $this->hoy->subDays(10)->utc()))
            ->orderBy('id')
            ->get();
        foreach ($asistencias as $asistencia) {
            $persona = $asistencia->reserva?->persona;
            $sesion = $asistencia->reserva?->sesion;
            if (! $persona instanceof PersonaTenant || ! $sesion instanceof SesionTenant || ! $this->prob(85)) {
                continue;
            }
            $llega = $this->en(CarbonImmutable::instance($sesion->inicia_en)->subMinutes($this->azar(3, 20)));
            $acceso->registrar($persona, MetodoAcceso::Qr, (int) $this->sede->getKey(), $llega);
            $this->sumar('accesos con QR');
        }
    }

    /** Una alumna con Ilimitada pausó su membresía por un viaje. */
    private function unaPausa(): void
    {
        $acuerdos = AcuerdoTenant::query()
            ->where('estado', EstadoAcuerdo::Activo->value)
            ->where('producto_comercial_id', $this->planes['ilimitada']->getKey())
            ->orderBy('id')
            ->get();
        foreach ($acuerdos as $acuerdo) {
            $conReservas = ReservaTenant::query()->where('persona_id', $acuerdo->persona_id)
                ->where('estado', EstadoReserva::Confirmada->value)
                ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>', $this->ahora->utc()))
                ->exists();
            if ($conReservas || PersonaTenant::query()->whereKey($acuerdo->persona_id)->value('usuario_id') !== null) {
                continue;
            }
            $this->en($this->ahora->subHours(4));
            try {
                app(PausarMembresiaTenant::class)->pausar($acuerdo, $this->hoy->addDays(10), 'Viaje de trabajo', $this->recepcion);
                $this->sumar('membresías en pausa');
            } catch (RuntimeException) {
                continue;
            }

            return;
        }
    }
}
