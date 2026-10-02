<?php

declare(strict_types=1);

namespace App\Console\Commands\Demos;

use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\AsistenciaTenant;
use App\Modules\Tenancy\Application\InventarioTenant;
use App\Modules\Tenancy\Application\OrdenesTenant;
use App\Modules\Tenancy\Application\PuntoDeVentaTenant;
use App\Modules\Tenancy\Application\ReprogramarTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\BloqueoAgendaTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\ExcepcionHorarioTenant;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Nomina\TipoPago;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\QuienCancela;
use App\Modules\Tenancy\Support\RedesSociales;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Barbería La Navaja: dos sucursales (Roma Norte y Del Valle), el dueño que también
 * corta, recepción, cuatro barberos con su horario y su comida, una cajera con un rol
 * propio y un contador que solo ve los pagos en línea. Cinco servicios con duración y
 * precio, productos de mostrador con inventario y unos 450 clientes que vuelven cada
 * dos a cinco semanas con su barbero de siempre, más los que llegan sin cita.
 *
 * Cada día de la historia: se agendan las visitas que tocan (algunas con días de
 * anticipación), se cancela o reprograma alguna, al terminar se marca la asistencia
 * y se cobra en caja (efectivo, tarjeta o transferencia), se venden productos y
 * algunos clientes dejan su reseña. Hacia adelante quedan dos semanas de citas.
 */
final class DemoBarberia extends DemoBase
{
    /** clave => [nombre, actividad, minutos, precio (centavos), descripción] */
    private const SERVICIOS = [
        'corte' => ['Corte de cabello', 'Cortes', 30, 25000, 'Corte a tijera o máquina, lavado y peinado. Incluye asesoría de estilo.'],
        'corte_barba' => ['Corte y barba', 'Cortes', 60, 38000, 'Corte completo y arreglo de barba con toalla caliente y navaja.'],
        'barba' => ['Arreglo de barba', 'Barba', 30, 18000, 'Perfilado, rebaje y aceite. Toalla caliente incluida.'],
        'afeitado' => ['Afeitado clásico', 'Barba', 45, 28000, 'Afeitado con navaja, espuma caliente y bálsamo.'],
        'infantil' => ['Corte infantil', 'Cortes', 30, 20000, 'Para niños hasta 12 años, con paciencia y buen ambiente.'],
    ];

    /** [nombre, sku, precio, stock inicial por sede] */
    private const ARTICULOS = [
        ['Pomada mate La Navaja', 'LN-POM-01', 28000, 18],
        ['Cera brillante', 'LN-CER-02', 23000, 14],
        ['Aceite para barba', 'LN-ACE-03', 26000, 16],
        ['Shampoo para barba', 'LN-SHA-04', 22000, 10],
        ['Bálsamo after shave', 'LN-BAL-05', 24000, 8],
        ['Peine de madera', 'LN-PEI-06', 12000, 20],
    ];

    private const COMENTARIOS = [
        5 => ['Excelente corte, como siempre.', 'Muy buen servicio y puntual.', 'El mejor degradado que me han hecho.', 'Atención de diez, regreso seguro.', 'Rápido y muy limpio.', 'Me encantó la toalla caliente.'],
        4 => ['Buen corte, tuve que esperar unos minutos.', 'Muy bien, aunque había mucha gente.', 'Buen servicio, el lugar muy agradable.'],
        3 => ['Bien, pero me lo dejaron un poco más corto de lo que pedí.'],
    ];

    private const NOTAS = ['Solo despuntar, por favor.', 'Degradado bajo.', 'Traigo foto de referencia.', 'Barba de candado.', 'Que no quede muy corto arriba.', 'Llego directo del trabajo, quizá 5 min tarde.'];

    /** @var array<string, OfertaTenant> */
    private array $servicios = [];

    /** @var array<string, SucursalTenant> */
    private array $sedes = [];

    /** @var array<string, Usuario> */
    private array $equipo = [];

    /**
     * Turno de cada barbero: [sede, días ISO, abre, cierra, comida (HH:MM) o null].
     *
     * @var array<int, list<array{0: string, 1: list<int>, 2: string, 3: string, 4: string|null}>>
     */
    private array $turnos = [];

    /**
     * Clientes con su costumbre: persona, barbero, servicio, cada cuántos días, franja
     * preferida, próxima visita y desde/hasta cuándo viene.
     *
     * @var list<array{persona: PersonaTenant, barbero: int, servicio: string, cada: int, franja: string, proxima: CarbonImmutable, deja: CarbonImmutable|null}>
     */
    private array $clientes = [];

    /** @var array<int, list<array{0: int, 1: int}>> ocupado por barbero en el día (minutos) */
    private array $ocupado = [];

    /** @var list<ArticuloTenant> */
    private array $articulos = [];

    protected function datos(): array
    {
        return [
            'nombre' => 'Barbería La Navaja',
            'slug' => 'la-navaja',
            'perfil' => PerfilNegocio::Barberia,
            'contacto' => ['Ramón', 'Salgado', 'Ibarra'],
            'email' => 'ramon@lanavaja.test',
            'telefono' => '5521438765',
            'descripcion' => 'Barbería de barrio con oficio desde 2016: cortes clásicos y modernos, barba con toalla caliente y un buen café mientras esperas. En Roma Norte y Del Valle.',
            'instagram' => '@lanavaja.barberia',
            'color' => [24, 33, 54],
        ];
    }

    public function cuentas(): array
    {
        return [
            'ramon@lanavaja.test' => 'Dueño (también corta, rol de barbero)',
            'karla@lanavaja.test' => 'Administradora',
            'lupita@lanavaja.test' => 'Recepción en Roma Norte',
            'daniel@lanavaja.test' => 'Caja en Del Valle (rol propio «Caja»)',
            'hector@lanavaja.test' => 'Contador (rol propio «Pagos en línea»: solo pasarelas)',
            'tono@lanavaja.test' => 'Barbero (Roma Norte)',
            'ivan@lanavaja.test' => 'Barbero (Del Valle)',
            'jorge.pineda@correo.test' => 'Cliente con cuenta («Mi cuenta»)',
        ];
    }

    protected function sembrar(): void
    {
        $this->reloj($this->inicio->subDays(30), '10:00');
        $this->sedesYEquipo();
        $this->catalogo();
        $this->horarios();
        $this->bloqueos();
        $this->inventario();
        $this->darDeAltaClientes();

        for ($dia = $this->inicio; $dia->lessThanOrEqualTo($this->hoy); $dia = $dia->addDay()) {
            $this->unDia($dia);
        }
        $this->proximasSemanas();
    }

    // ---------------------------------------------------------------- estructura

    private function sedesYEquipo(): void
    {
        $organizacion = OrganizacionTenant::query()->create(['nombre' => 'Barbería La Navaja']);
        $sedes = [
            'roma' => ['Roma Norte', 'Colima 215, Roma Norte, Cuauhtémoc, CDMX', '5521438765', 19.4185, -99.1604],
            'valle' => ['Del Valle', 'Av. Coyoacán 1435, Del Valle Centro, Benito Juárez, CDMX', '5543217789', 19.3743, -99.1702],
        ];
        $horario = [
            ['dia' => 1, 'abre' => '10:00', 'cierra' => '20:00'], ['dia' => 2, 'abre' => '10:00', 'cierra' => '20:00'],
            ['dia' => 3, 'abre' => '10:00', 'cierra' => '20:00'], ['dia' => 4, 'abre' => '10:00', 'cierra' => '20:00'],
            ['dia' => 5, 'abre' => '10:00', 'cierra' => '20:00'], ['dia' => 6, 'abre' => '09:00', 'cierra' => '19:00'],
        ];
        foreach ($sedes as $clave => [$nombre, $direccion, $whatsapp, $lat, $lng]) {
            $this->sedes[$clave] = $organizacion->sucursales()->create([
                'nombre' => $nombre, 'zona_horaria' => $this->zona, 'region' => 'CDMX', 'moneda' => 'MXN',
                'impuesto_tasa_bps' => 1600, 'direccion' => $direccion, 'whatsapp' => $whatsapp,
                'latitud' => $lat, 'longitud' => $lng,
                'redes' => RedesSociales::normalizar(['instagram' => '@lanavaja.barberia']),
                'horario' => $clave === 'roma' ? [...$horario, ['dia' => 7, 'abre' => '10:00', 'cierra' => '15:00']] : $horario,
            ]);
        }

        $dueno = $this->usuario('ramon@lanavaja.test', 'Ramón', 'Salgado', ['propietario', 'instructor']);
        $this->equipo = [
            'ramon' => $dueno,
            'karla' => $this->usuario('karla@lanavaja.test', 'Karla', 'Domínguez', ['admin']),
            'lupita' => $this->usuario('lupita@lanavaja.test', 'Lupita', 'Hernández', ['recepcionista']),
            'tono' => $this->usuario('tono@lanavaja.test', 'Antonio', 'Vázquez', ['instructor']),
            'memo' => $this->usuario('memo@lanavaja.test', 'Guillermo', 'Castillo', ['instructor']),
            'ivan' => $this->usuario('ivan@lanavaja.test', 'Iván', 'Morales', ['instructor']),
            'chava' => $this->usuario('chava@lanavaja.test', 'Salvador', 'Ruiz', ['instructor']),
        ];
        $caja = $this->rolPropio($dueno, 'Caja', [
            'agenda.ver', 'reservas.ver', 'miembros.ver', 'derechos.ver', 'ordenes.ver', 'ordenes.gestionar',
            'productos.ver', 'pos.vender', 'inventario.ver', 'sucursales.ver',
        ]);
        $this->equipo['daniel'] = $this->usuario('daniel@lanavaja.test', 'Daniel', 'Ortiz', [$caja ?? 'recepcionista']);
        $contador = $this->rolPropio($dueno, 'Pagos en línea', ['pagos.configurar']);
        $this->equipo['hector'] = $this->usuario('hector@lanavaja.test', 'Héctor', 'Solís', [$contador ?? 'recepcionista']);

        // Turnos: [sede, días, abre, cierra, comida].
        $this->turnos = [
            (int) $this->equipo['tono']->getKey() => [['roma', [1, 2, 3, 4, 5, 6], '10:00', '19:00', '14:00']],
            (int) $this->equipo['memo']->getKey() => [['roma', [2, 3, 4, 5, 6], '11:00', '20:00', '15:00'], ['roma', [7], '10:00', '15:00', null]],
            (int) $this->equipo['ivan']->getKey() => [['valle', [1, 2, 3, 4, 5, 6], '10:00', '19:00', '14:00']],
            (int) $this->equipo['chava']->getKey() => [['valle', [2, 3, 4, 5, 6], '12:00', '20:00', '16:00'], ['roma', [1], '12:00', '20:00', '16:00']],
            (int) $dueno->getKey() => [['roma', [2, 4, 6], '10:00', '14:00', null]],
        ];

        // Nómina: por cliente atendido (lo usual en barbería).
        foreach (['tono' => 12000, 'memo' => 12000, 'ivan' => 11000, 'chava' => 11000] as $clave => $monto) {
            EsquemaPagoTenant::query()->create([
                'usuario_id' => $this->equipo[$clave]->getKey(), 'tipo' => TipoPago::PorAsistente->value,
                'monto_minor' => $monto, 'moneda' => 'MXN', 'activo' => true,
            ]);
        }
    }

    private function catalogo(): void
    {
        $programa = ProgramaTenant::query()->create(['slug' => 'barberia', 'nombre' => 'Barbería']);
        $actividades = [];
        foreach (self::SERVICIOS as $clave => [$nombre, $actividad, $minutos, $precio, $descripcion]) {
            $actividades[$actividad] ??= $programa->actividades()->create(['slug' => strtolower($actividad), 'nombre' => $actividad]);
            $this->servicios[$clave] = $actividades[$actividad]->ofertas()->create([
                'nombre' => $nombre, 'descripcion' => $descripcion,
                'modalidad' => ModalidadOfertaTenant::Individual->value, 'capacidad' => 1,
                'politica_reserva' => PoliticaReservaTenant::Pago->value,
                'precio_clase_minor' => $precio, 'duracion_minutos' => $minutos,
            ]);
        }
    }

    private function horarios(): void
    {
        foreach ($this->turnos as $barbero => $turnos) {
            foreach ($turnos as [$sede, $dias, $abre, $cierra]) {
                foreach ($dias as $dia) {
                    HorarioAtencionTenant::query()->create([
                        'instructor_id' => $barbero, 'sucursal_id' => $this->sedes[$sede]->getKey(),
                        'dia_semana' => $dia, 'hora_inicio' => $abre, 'hora_fin' => $cierra,
                    ]);
                }
            }
        }
    }

    /**
     * La comida de cada barbero (toda la historia y las próximas semanas), las
     * vacaciones de Iván, una cita médica de Toño y los días feriados cerrados.
     */
    private function bloqueos(): void
    {
        $fin = $this->hoy->addDays(21);
        for ($dia = $this->inicio; $dia->lessThanOrEqualTo($fin); $dia = $dia->addDay()) {
            foreach ($this->turnos as $barbero => $turnos) {
                foreach ($turnos as [$sede, $dias, , , $comida]) {
                    if ($comida === null || ! in_array($dia->isoWeekday(), $dias, true)) {
                        continue;
                    }
                    $this->bloquear($barbero, $dia, $comida, 60, 'Comida');
                }
            }
        }
        $vacaciones = $this->inicio->addDays(intdiv((int) $this->inicio->diffInDays($this->hoy), 2))->startOfWeek();
        BloqueoAgendaTenant::query()->create([
            'instructor_id' => $this->equipo['ivan']->getKey(),
            'desde' => $vacaciones->utc(), 'hasta' => $vacaciones->addDays(6)->endOfDay()->utc(),
            'todo_el_dia' => true, 'zona_horaria' => $this->zona, 'motivo' => 'Vacaciones',
        ]);
        $this->bloquear((int) $this->equipo['tono']->getKey(), $this->hoy->addDays(3), '10:00', 120, 'Cita médica');

        foreach (["{$this->hoy->year}-09-16" => 'Día de la Independencia', "{$this->hoy->year}-11-02" => 'Día de Muertos', "{$this->hoy->year}-12-25" => 'Navidad', "{$this->hoy->year}-01-01" => 'Año Nuevo'] as $fecha => $motivo) {
            $dia = CarbonImmutable::parse($fecha, $this->zona);
            if ($dia->betweenIncluded($this->inicio, $fin)) {
                ExcepcionHorarioTenant::query()->create(['fecha' => $fecha, 'motivo' => $motivo]);
            }
        }
    }

    private function bloquear(int $barbero, CarbonImmutable $dia, string $hora, int $minutos, string $motivo): void
    {
        $desde = CarbonImmutable::parse($dia->toDateString().' '.$hora, $this->zona);
        BloqueoAgendaTenant::query()->create([
            'instructor_id' => $barbero, 'desde' => $desde->utc(), 'hasta' => $desde->addMinutes($minutos)->utc(),
            'todo_el_dia' => false, 'zona_horaria' => $this->zona, 'motivo' => $motivo,
        ]);
    }

    private function inventario(): void
    {
        $inventario = app(InventarioTenant::class);
        foreach (self::ARTICULOS as [$nombre, $sku, $precio, $stock]) {
            $articulo = ArticuloTenant::query()->create(['nombre' => $nombre, 'sku' => $sku, 'precio_minor' => $precio, 'moneda' => 'MXN', 'activo' => true]);
            $this->articulos[] = $articulo;
            foreach ($this->sedes as $sede) {
                $inventario->registrar((int) $articulo->getKey(), (int) $sede->getKey(), $stock, TipoMovimientoInventario::Entrada, 'Inventario inicial', $this->equipo['karla']);
            }
        }
    }

    /**
     * Unos 450 clientes: dos de cada tres ya venían antes de la historia; el resto
     * llega poco a poco. Uno de cada siete deja de venir en algún momento.
     */
    private function darDeAltaClientes(): void
    {
        $barberos = ['tono' => 30, 'memo' => 26, 'ivan' => 26, 'chava' => 18];
        $dias = (int) $this->inicio->diffInDays($this->hoy);
        for ($i = 0; $i < 450; $i++) {
            $esMujer = $this->prob(12);
            $nombre = $this->uno($esMujer ? NombresDemo::MUJERES : NombresDemo::HOMBRES);
            $antiguo = $this->prob(66);
            $alta = $antiguo
                ? $this->inicio->subDays($this->azar(20, 700))->setTime($this->azar(10, 19), $this->azar(0, 59))
                : $this->inicio->addDays($this->azar(0, $dias))->setTime($this->azar(10, 19), $this->azar(0, 59));
            $clave = (string) $this->elegir($barberos);
            $barbero = (int) $this->equipo[$clave]->getKey();
            $sede = in_array($clave, ['ivan', 'chava'], true) ? 'valle' : 'roma';
            $persona = $this->persona($nombre, $this->uno(NombresDemo::APELLIDOS), $this->uno(NombresDemo::APELLIDOS), $alta, (int) $this->sedes[$sede]->getKey(), $this->prob(65));
            $cada = $this->azar(14, 35);
            $this->clientes[] = [
                'persona' => $persona,
                'barbero' => $barbero,
                'servicio' => (string) $this->elegir($esMujer ? ['corte' => 100] : ['corte' => 52, 'corte_barba' => 26, 'barba' => 10, 'afeitado' => 5, 'infantil' => 7]),
                'cada' => $cada,
                'franja' => (string) $this->elegir(['manana' => 30, 'tarde' => 30, 'noche' => 40]),
                'proxima' => $antiguo ? $this->inicio->addDays($this->azar(0, $cada)) : $alta->startOfDay(),
                'deja' => $this->prob(14) ? $this->inicio->addDays($this->azar(15, $dias + 30)) : null,
            ];
        }

        // Un cliente con cuenta para revisar «Mi cuenta».
        $jorge = $this->clientes[0]['persona'];
        $jorge->update(['nombre' => 'Jorge', 'primer_apellido' => 'Pineda', 'segundo_apellido' => 'Luna', 'email' => 'jorge.pineda@correo.test']);
        $cuenta = $this->usuario('jorge.pineda@correo.test', 'Jorge', 'Pineda', ['miembro']);
        $jorge->update(['usuario_id' => $cuenta->getKey()]);
    }

    // ---------------------------------------------------------------- un día

    private function unDia(CarbonImmutable $dia): void
    {
        if (ExcepcionHorarioTenant::query()->whereDate('fecha', $dia->toDateString())->exists()) {
            return;
        }
        $esHoy = $dia->equalTo($this->hoy);
        $this->ocupado = [];
        $this->ocuparBloqueos($dia);

        /** @var list<array{0: ReservaTenant, 1: int|null}> $citas reserva y cliente (índice) */
        $citas = [];
        $orden = array_keys($this->clientes);
        shuffle($orden);
        foreach ($orden as $i) {
            $c = $this->clientes[$i];
            if ($c['proxima']->greaterThan($dia) || $c['persona']->created_at?->greaterThan($dia->endOfDay())
                || ($c['deja'] instanceof CarbonImmutable && $dia->greaterThan($c['deja']))) {
                continue;
            }
            // Entre semana, uno de cada tres lo deja para el viernes o el sábado.
            if ($dia->isoWeekday() <= 4 && $this->prob(30)) {
                $this->clientes[$i]['proxima'] = $dia->next(CarbonImmutable::FRIDAY)->addDays($this->azar(0, 1));

                continue;
            }
            $reserva = $this->agendarVisita($dia, $c);
            if ($reserva instanceof ReservaTenant) {
                $citas[] = [$reserva, $i];
                $this->clientes[$i]['proxima'] = $dia->addDays($c['cada'] + $this->azar(-3, 4));
            } else {
                $this->clientes[$i]['proxima'] = $dia->addDay();
            }
        }

        // Los que llegan sin cita (más los fines de semana).
        $sinCita = $this->azar(1, $dia->isoWeekday() >= 5 ? 6 : 3);
        for ($n = 0; $n < $sinCita; $n++) {
            $reserva = $this->llegaSinCita($dia, $esHoy);
            if ($reserva instanceof ReservaTenant) {
                $citas[] = [$reserva, null];
            }
        }

        $citas = $this->cancelarYReprogramar($dia, $citas);
        $this->atenderYCobrar($dia, $citas, $esHoy);
        $this->ventasDeMostrador($dia, $esHoy);
    }

    /** Bloqueos (comida, vacaciones) del día como tiempo ocupado de cada barbero. */
    private function ocuparBloqueos(CarbonImmutable $dia): void
    {
        $desde = $dia->startOfDay()->utc();
        $hasta = $dia->endOfDay()->utc();
        foreach (BloqueoAgendaTenant::query()->whereNotNull('instructor_id')->where('desde', '<=', $hasta)->where('hasta', '>=', $desde)->get() as $b) {
            $ini = $b->desde->setTimezone($this->zona);
            $fin = $b->hasta->setTimezone($this->zona);
            $this->ocupado[(int) $b->instructor_id][] = [
                $ini->lessThan($dia) ? 0 : $ini->hour * 60 + $ini->minute,
                $fin->greaterThan($dia->endOfDay()) ? 24 * 60 : $fin->hour * 60 + $fin->minute,
            ];
        }
    }

    /**
     * Agenda la visita de un cliente con su barbero (o con otro si el suyo no trabaja
     * ese día), en su franja preferida si se puede. Casi siempre la agendan con días
     * de anticipación (por WhatsApp o en recepción).
     *
     * @param  array{persona: PersonaTenant, barbero: int, servicio: string, cada: int, franja: string, proxima: CarbonImmutable, deja: CarbonImmutable|null}  $c
     */
    private function agendarVisita(CarbonImmutable $dia, array $c): ?ReservaTenant
    {
        $servicio = $this->servicios[$c['servicio']];
        $barberos = [$c['barbero'], ...array_values(array_diff(array_keys($this->turnos), [$c['barbero']]))];
        if ($this->prob(15)) {
            shuffle($barberos);
        }
        foreach ($barberos as $barbero) {
            foreach ($this->huecos($barbero, $dia, (int) $servicio->duracion_minutos, $c['franja']) as [$sede, $minuto]) {
                $anticipacion = (int) $this->elegir([0 => 30, 1 => 25, 2 => 15, 3 => 12, 5 => 10, 7 => 8]);
                $momento = $this->reloj($dia->subDays($anticipacion), $anticipacion === 0 ? '09:10' : $this->uno(['11:25', '13:40', '17:05', '20:30', '21:45']));
                // Nadie agenda antes de darse de alta: un cliente nuevo agenda al llegar.
                $alta = CarbonImmutable::instance($c['persona']->created_at ?? $momento);
                if ($alta->greaterThan($momento)) {
                    $this->en($alta->addMinutes(2));
                }
                $reserva = $this->agendar($servicio, $sede, $c['persona'], $barbero, $dia, $minuto);
                if ($reserva instanceof ReservaTenant) {
                    if ($c['servicio'] === 'infantil') {
                        $reserva->update(['asiste' => $this->uno(['Mateo', 'Santiago', 'Leonardo', 'Emiliano', 'Diego']).' (su hijo)']);
                    } elseif ($this->prob(8)) {
                        $reserva->update(['nota_cliente' => $this->uno(self::NOTAS)]);
                    }

                    return $reserva;
                }
            }
        }

        return null;
    }

    private function llegaSinCita(CarbonImmutable $dia, bool $esHoy): ?ReservaTenant
    {
        $barberos = array_keys($this->turnos);
        shuffle($barberos);
        $servicio = $this->servicios[(string) $this->elegir(['corte' => 60, 'barba' => 25, 'corte_barba' => 15])];
        foreach ($barberos as $barbero) {
            foreach ($this->huecos($barbero, $dia, (int) $servicio->duracion_minutos, (string) $this->elegir(['manana' => 25, 'tarde' => 40, 'noche' => 35])) as [$sede, $minuto]) {
                $llega = $dia->startOfDay()->addMinutes($minuto - 5);
                if ($esHoy && $llega->greaterThan($this->ahora)) {
                    continue;
                }
                $alta = $llega->subMinutes(2);
                $persona = $this->persona($this->uno(NombresDemo::HOMBRES), $this->uno(NombresDemo::APELLIDOS), $this->uno(NombresDemo::APELLIDOS), $alta, (int) $sede->getKey(), $this->prob(30), ['como_nos_conocio' => 'paso_por_aqui']);
                $this->reloj($dia, $llega->format('H:i'));
                $reserva = $this->agendar($servicio, $sede, $persona, $barbero, $dia, $minuto);
                if ($reserva instanceof ReservaTenant) {
                    // Si le gustó, vuelve.
                    if ($this->prob(45)) {
                        $this->clientes[] = [
                            'persona' => $persona, 'barbero' => $barbero, 'servicio' => 'corte', 'cada' => $this->azar(18, 35),
                            'franja' => 'tarde', 'proxima' => $dia->addDays($this->azar(18, 35)), 'deja' => null,
                        ];
                    }

                    return $reserva;
                }
            }
        }

        return null;
    }

    /**
     * Huecos libres del barbero ese día para el servicio: primero en la franja que
     * prefiere el cliente, luego el resto. Devuelve [sede, minuto del día].
     *
     * @return list<array{0: SucursalTenant, 1: int}>
     */
    private function huecos(int $barbero, CarbonImmutable $dia, int $duracion, string $franja): array
    {
        $preferidos = [];
        $otros = [];
        foreach ($this->turnos[$barbero] ?? [] as [$sede, $dias, $abre, $cierra]) {
            if (! in_array($dia->isoWeekday(), $dias, true)) {
                continue;
            }
            $desde = $this->minutos($abre);
            $hasta = $this->minutos($cierra);
            for ($m = $desde; $m + $duracion <= $hasta; $m += 30) {
                if ($this->chocaCon($barbero, $m, $m + $duracion)) {
                    continue;
                }
                $enFranja = match ($franja) {
                    'manana' => $m < 13 * 60,
                    'tarde' => $m >= 13 * 60 && $m < 17 * 60,
                    default => $m >= 17 * 60,
                };
                if ($enFranja) {
                    $preferidos[] = [$this->sedes[$sede], $m];
                } else {
                    $otros[] = [$this->sedes[$sede], $m];
                }
            }
        }
        shuffle($preferidos);
        shuffle($otros);

        return array_slice([...$preferidos, ...$otros], 0, 3);
    }

    private function chocaCon(int $barbero, int $desde, int $hasta): bool
    {
        foreach ($this->ocupado[$barbero] ?? [] as [$ini, $fin]) {
            if ($desde < $fin && $hasta > $ini) {
                return true;
            }
        }

        return false;
    }

    private function agendar(OfertaTenant $servicio, SucursalTenant $sede, PersonaTenant $persona, int $barbero, CarbonImmutable $dia, int $minuto): ?ReservaTenant
    {
        $inicia = $dia->startOfDay()->addMinutes($minuto);
        try {
            $reserva = app(AgendarCitaTenant::class)->agendar($servicio, $sede, $persona, $barbero, $inicia->utc(), (int) $servicio->duracion_minutos, porNegocio: true);
        } catch (RuntimeException|ValidationException) {
            return null;
        }
        $this->ocupado[$barbero][] = [$minuto, $minuto + (int) $servicio->duracion_minutos];
        $this->sumar('citas');

        return $reserva;
    }

    /**
     * Uno de cada veinticinco cancela (casi siempre el cliente, a veces el negocio) y
     * uno de cada cincuenta cambia la hora.
     *
     * @param  list<array{0: ReservaTenant, 1: int|null}>  $citas
     * @return list<array{0: ReservaTenant, 1: int|null}>
     */
    private function cancelarYReprogramar(CarbonImmutable $dia, array $citas): array
    {
        $quedan = [];
        foreach ($citas as [$reserva, $cliente]) {
            if ($this->prob(4)) {
                $porCliente = $this->prob(80);
                // Cancela después de haber agendado y antes de la hora de su cita.
                $agendada = CarbonImmutable::instance($reserva->created_at ?? $dia)->setTimezone($this->zona);
                $inicio = $reserva->sesion()->first()?->inicia_en;
                $limite = $inicio !== null ? CarbonImmutable::instance($inicio)->setTimezone($this->zona) : $dia->endOfDay();
                $momento = $agendada->addMinutes($this->azar(20, max(21, (int) $agendada->diffInMinutes($limite) - 10)));
                if ($momento->greaterThanOrEqualTo($limite)) {
                    $quedan[] = [$reserva, $cliente];

                    continue;
                }
                $this->en($momento);
                try {
                    app(ReservasTenant::class)->cancelar($reserva, $porCliente ? QuienCancela::Cliente : QuienCancela::Negocio, $porCliente ? null : $this->equipo['lupita']);
                    $this->sumar('citas canceladas');
                } catch (RuntimeException|ValidationException) {
                    $quedan[] = [$reserva, $cliente];
                }
                if ($cliente !== null) {
                    $this->clientes[$cliente]['proxima'] = $dia->addDays($this->azar(2, 6));
                }

                continue;
            }
            if ($this->prob(2)) {
                $sesion = $reserva->sesion()->first();
                $inicio = $sesion?->inicia_en?->setTimezone($this->zona);
                if ($inicio !== null) {
                    $this->reloj($dia->subDay(), '19:30');
                    try {
                        app(ReprogramarTenant::class)->moverCita($reserva, CarbonImmutable::instance($inicio)->addHours(2), null, $this->equipo['lupita']);
                        $this->sumar('citas reprogramadas');
                    } catch (RuntimeException|ValidationException) {
                        // El nuevo horario no estaba libre: se queda donde estaba.
                    }
                }
            }
            $quedan[] = [$reserva->refresh(), $cliente];
        }

        return $quedan;
    }

    /**
     * Al terminar cada cita: llegó (casi siempre) y se cobra en caja; si no llegó, su
     * orden se cancela. De hoy, solo lo que ya terminó.
     *
     * @param  list<array{0: ReservaTenant, 1: int|null}>  $citas
     */
    private function atenderYCobrar(CarbonImmutable $dia, array $citas, bool $esHoy): void
    {
        $ahora = $this->ahora;
        foreach ($citas as [$reserva]) {
            $sesion = $reserva->sesion()->first();
            if ($sesion === null) {
                continue;
            }
            $termina = CarbonImmutable::instance($sesion->termina_en)->setTimezone($this->zona);
            if ($esHoy && $termina->greaterThan($ahora)) {
                continue;
            }
            $this->reloj($dia, $termina->format('H:i'));
            $vino = $this->prob(95);
            $cajero = $this->cajero($sesion->sucursal_id, $dia, (int) $sesion->instructor_id);
            try {
                app(AsistenciaTenant::class)->marcar($reserva, $vino ? EstadoAsistencia::Presente : EstadoAsistencia::Ausente, $cajero);
            } catch (RuntimeException|ValidationException) {
                continue;
            }
            $orden = $reserva->orden_id !== null ? OrdenTenant::query()->find($reserva->orden_id) : null;
            if (! $orden instanceof OrdenTenant) {
                continue;
            }
            if (! $vino) {
                $orden->update(['estado' => EstadoOrden::Cancelada->value, 'cancelada_en' => now()]);
                $this->sumar('no asistieron');

                continue;
            }
            $this->reloj($dia, $termina->addMinutes($this->azar(1, 6))->format('H:i'));
            app(OrdenesTenant::class)->liquidar($orden, (string) $this->elegir(['efectivo' => 48, 'manual' => 34, 'transferencia' => 18]), null, $cajero);
            $this->sumar('cobros');
            if ($this->prob(12)) {
                $calificacion = (int) $this->elegir([5 => 78, 4 => 18, 3 => 4]);
                ResenaTenant::query()->create([
                    'reserva_id' => $reserva->getKey(), 'persona_id' => $reserva->persona_id, 'oferta_id' => $sesion->oferta_id,
                    'instructor_id' => $sesion->instructor_id, 'calificacion' => $calificacion,
                    'comentario' => $this->prob(70) ? $this->uno(self::COMENTARIOS[$calificacion]) : null, 'visible' => true,
                ]);
                $this->sumar('reseñas');
            }
        }
    }

    /** Quién cobra: recepción en Roma (lun a sáb), caja en Del Valle (mar a sáb); si no, el barbero. */
    private function cajero(?int $sucursalId, CarbonImmutable $dia, int $barbero): Usuario
    {
        $dow = $dia->isoWeekday();
        if ($sucursalId === (int) $this->sedes['roma']->getKey() && $dow <= 6) {
            return $this->equipo['lupita'];
        }
        if ($sucursalId === (int) $this->sedes['valle']->getKey() && $dow >= 2 && $dow <= 6) {
            return $this->equipo['daniel'];
        }

        return Usuario::query()->findOrFail($barbero);
    }

    private function ventasDeMostrador(CarbonImmutable $dia, bool $esHoy): void
    {
        $pos = app(PuntoDeVentaTenant::class);
        $inventario = app(InventarioTenant::class);
        foreach ($this->sedes as $clave => $sede) {
            if ($clave === 'valle' && $dia->isoWeekday() === 7) {
                continue;
            }
            $ventas = $this->azar(0, $dia->isoWeekday() >= 5 ? 4 : 2);
            for ($n = 0; $n < $ventas; $n++) {
                $hora = sprintf('%02d:%02d', $this->azar(11, 19), $this->azar(0, 59));
                if ($esHoy && CarbonImmutable::parse($dia->toDateString().' '.$hora, $this->zona)->greaterThan($this->ahora)) {
                    continue;
                }
                $articulo = $this->articulos[(int) $this->elegir([0 => 30, 1 => 18, 2 => 22, 3 => 10, 4 => 8, 5 => 12])];
                $this->reloj($dia, $hora);
                $cajero = $this->cajero((int) $sede->getKey(), $dia, (int) $this->equipo['tono']->getKey());
                if ($inventario->stock((int) $articulo->getKey(), (int) $sede->getKey()) < 2) {
                    $inventario->registrar((int) $articulo->getKey(), (int) $sede->getKey(), 12, TipoMovimientoInventario::Entrada, 'Compra a proveedor', $this->equipo['karla']);
                }
                try {
                    $pos->vender($sede, [['articulo' => $articulo, 'cantidad' => $this->prob(85) ? 1 : 2]], (string) $this->elegir(['efectivo' => 55, 'tarjeta' => 35, 'transferencia' => 10]), $cajero);
                    $this->sumar('ventas de mostrador');
                } catch (RuntimeException|ValidationException) {
                    // Sin existencias: no se vende.
                }
            }
        }
    }

    /**
     * Las próximas dos semanas: las visitas que tocan, agendadas desde ya (quedan por
     * cobrar en caja). Hoy se agenda con el reloj real.
     */
    private function proximasSemanas(): void
    {
        for ($dia = $this->hoy->addDay(); $dia->lessThanOrEqualTo($this->hoy->addDays(14)); $dia = $dia->addDay()) {
            if (ExcepcionHorarioTenant::query()->whereDate('fecha', $dia->toDateString())->exists()) {
                continue;
            }
            $this->ocupado = [];
            $this->ocuparBloqueos($dia);
            // Cuanto más lejos, menos citas ya agendadas.
            $yaAgendan = max(15, 85 - (int) $this->hoy->diffInDays($dia) * 5);
            foreach ($this->clientes as $i => $c) {
                if ($c['proxima']->toDateString() !== $dia->toDateString()
                    || ($c['deja'] instanceof CarbonImmutable && $dia->greaterThan($c['deja'])) || ! $this->prob($yaAgendan)) {
                    continue;
                }
                $servicio = $this->servicios[$c['servicio']];
                foreach ($this->huecos($c['barbero'], $dia, (int) $servicio->duracion_minutos, $c['franja']) as [$sede, $minuto]) {
                    $this->hace(20, 4 * 24 * 60, $c['persona']->created_at);
                    if ($this->agendar($servicio, $sede, $c['persona'], $c['barbero'], $dia, $minuto) instanceof ReservaTenant) {
                        $this->clientes[$i]['proxima'] = $dia->addDays($c['cada']);
                        break;
                    }
                }
            }
        }
    }

    private function minutos(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }
}
