<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\AsistenciaTenant as MarcarAsistenciaTenant;
use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Application\OrdenesTenant;
use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Membresias\TipoVigencia;
use App\Modules\Tenancy\Membresias\VigenciaProducto;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\BloqueoAgendaTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\ExcepcionHorarioTenant;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Nomina\TipoPago;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\Reservas\Exceptions\SinDerechoDisponible;
use App\Modules\Tenancy\Reservas\QuienCancela;
use App\Modules\Tenancy\Support\RedesSociales;
use App\Modules\Tenancy\TipoPersonaTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * (Re)aprovisiona y siembra un estudio "demo" en el data plane por tenant para
 * revisión manual. Idempotente y reanudable: recrea la BD del tenant si fue
 * borrada (p. ej. la suite limpia storage/tenants entre pruebas y se lleva la BD
 * del estudio) y vuelve a dejar todo listo — dueño, instructor, alumnos con
 * créditos, catálogo, sucursal y clases próximas. NUNCA corre en producción.
 */
class SembrarEstudioDemo extends Command
{
    protected $signature = 'agendauno:sembrar-demo {--slug=demo} {--password=password}
        {--perfil= : Giro del negocio (p. ej. barberia); con uno de citas no se siembran clases grupales}
        {--nombre= : Nombre del negocio si se crea}';

    protected $description = 'Reaprovisiona y siembra el estudio demo (solo dev) para revisión manual';

    /** Días de historia que se siembran (ADR 0081). */
    private const DIAS_HISTORIA = 30;

    public function handle(GestorDeConexionTenant $gestor, RegistrarEstudio $registrar): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->warn('Omitido: no se siembran datos demo en producción.');

            return self::SUCCESS;
        }

        $slug = (string) $this->option('slug');
        $password = (string) $this->option('password');
        $instructorEmail = "beto@{$slug}.mx";
        $miembroEmail = "ana@{$slug}.mx";
        // Cuentas para probar cada rol (misma contraseña que el resto).
        $equipo = [
            'admin' => ["admin@{$slug}.mx", 'Alma Administradora'],
            'recepcionista' => ["recepcion@{$slug}.mx", 'Rita Recepción'],
            'profesional' => ["sofia@{$slug}.mx", 'Sofía Profesional'],
        ];

        // 1. Registro central del estudio (reusa el existente para conservar su BD).
        $perfil = $this->option('perfil');
        $estudio = Estudio::query()->where('slug', $slug)->first()
            ?? $registrar->ejecutar([
                'nombre' => is_string($this->option('nombre')) ? $this->option('nombre') : 'Estudio Demo',
                'slug' => $slug,
                'perfil_negocio' => is_string($perfil) ? $perfil : 'general',
                'contacto_nombre' => 'Dueño Demo',
                'contacto_email' => "demo@{$slug}.mx",
                'pais' => 'MX',
                'ciudad' => 'Ciudad de Mexico',
                'zona_horaria' => 'America/Mexico_City',
            ]);

        // El dueño es el contacto del registro central: así el login coincide con el
        // control plane (y con las credenciales que ya se compartieron para revisar).
        $ownerEmail = (string) $estudio->contacto_email;

        // 2. BD del tenant + esquema (recrea si fue borrada). Idempotente.
        $gestor->aprovisionarBaseDeDatos($estudio);

        // 3. Estado operativo + publicado en el directorio (para poder revisarlo).
        if (is_string($perfil)) {
            $estudio->update(['perfil_negocio' => PerfilNegocio::from($perfil)->value]);
        }
        $soloCitas = $estudio->refresh()->modalidad() === ModalidadServicio::Citas;
        $estudio->update([
            'estado' => EstadoEstudio::Trialing->value,
            'estado_facturacion' => EstadoFacturacion::Trial->value,
            'trial_inicia_en' => now()->toDateString(),
            'trial_termina_en' => now()->addDays(30)->toDateString(),
            'aprovisionado_en' => $estudio->aprovisionado_en ?? now(),
            'paso_aprovisionamiento' => null,
            'pais' => $estudio->pais ?? 'MX',
            'ciudad' => $estudio->ciudad ?? 'Ciudad de Mexico',
            'publicado' => true,
            'privado' => false,
        ]);

        // 3b. Logo de muestra (para ver el branding en la pantalla de acceso). Solo
        // si el estudio aun no tiene logo, para no pisar uno que el usuario haya subido.
        if ($estudio->logo_url === null) {
            $this->generarLogoDemo($estudio);
        }

        // 3c. Perfil público de muestra (su página y la de enlaces), sin pisar lo que
        // el usuario ya haya capturado.
        if ($estudio->descripcion === null) {
            $estudio->update([
                'descripcion' => $soloCitas
                    ? 'Barbería de barrio con oficio: cortes clásicos y modernos, barba con toalla caliente y un buen café mientras esperas.'
                    : 'Estudio de pole y flexibilidad para todos los niveles. Grupos pequeños, instructoras certificadas y un ambiente seguro para empezar o perfeccionar tu técnica.',
                'redes' => $estudio->redes ?? RedesSociales::normalizar([
                    'instagram' => '@'.str_replace('-', '_', $slug).'_demo',
                    'facebook' => str_replace('-', '', $slug).'demo',
                    'sitio_web' => 'agendauno.mx',
                ]),
            ]);
        }

        // 4. Datos operativos dentro de la BD del tenant.
        $historia = ['citas' => 0, 'clases' => 0];
        $gestor->ejecutarEn($estudio, function () use ($password, $ownerEmail, $instructorEmail, $miembroEmail, $equipo, $soloCitas, $slug, &$historia): void {
            $this->sembrarPersonal($password, $ownerEmail, $instructorEmail, $miembroEmail);
            $this->sembrarEquipo($password, $equipo);
            [$oferta, $sucursal] = $this->sembrarCatalogoYSucursal($soloCitas);
            $this->asignarSucursalDeCasa($sucursal);
            // Un negocio de citas no vende paquetes de clases ni tiene clases grupales.
            if (! $soloCitas && $oferta instanceof OfertaTenant) {
                $this->venderPack($miembroEmail);
                $this->sembrarPlanes($oferta, $miembroEmail);
                $this->sembrarClases($oferta, $sucursal, $instructorEmail);
            }
            $this->sembrarCitas([$instructorEmail, $equipo['profesional'][0]], $miembroEmail);
            $this->sembrarPerfilPublico($slug);
            $historia = $this->sembrarHistoria($soloCitas, [$instructorEmail, $equipo['profesional'][0]]);
        });
        if ($historia['citas'] + $historia['clases'] > 0) {
            $this->info("Historia del último mes: {$historia['citas']} citas y {$historia['clases']} clases.");
        }

        $this->componentInfo($estudio, $slug, $password, $ownerEmail, $instructorEmail, $miembroEmail, $equipo);

        return self::SUCCESS;
    }

    private function sembrarPersonal(string $password, string $ownerEmail, string $instructorEmail, string $miembroEmail): void
    {
        // Dueño (aprovisionar ya lo crea inactivo; aquí lo activamos con contraseña).
        Usuario::query()->updateOrCreate(
            ['email' => $ownerEmail],
            ['name' => 'Dueño Demo', 'rol' => 'propietario', 'roles' => ['propietario'], 'activo' => true, 'password' => $password, 'activation_token' => null],
        );

        Usuario::query()->updateOrCreate(
            ['email' => $instructorEmail],
            ['name' => 'Beto Instructor', 'rol' => 'instructor', 'roles' => ['instructor'], 'activo' => true, 'password' => $password, 'activation_token' => null],
        );

        $miembro = Usuario::query()->updateOrCreate(
            ['email' => $miembroEmail],
            ['name' => 'Ana Alumna', 'rol' => 'miembro', 'roles' => ['miembro'], 'activo' => true, 'password' => $password, 'activation_token' => null],
        );

        // Perfil de alumna de Ana, enlazado a su usuario para el autoservicio.
        PersonaTenant::query()->updateOrCreate(
            ['email' => $miembroEmail],
            ['nombre' => 'Ana', 'primer_apellido' => 'Alumna', 'tipo' => TipoPersonaTenant::Miembro->value,
                'activo' => true, 'es_facturable' => true, 'archivado' => false, 'usuario_id' => $miembro->getKey()],
        );

        // Varios alumnos más (sin acceso), para poblar el listado y ver el
        // buscador + la paginacion de la tabla.
        $alumnos = [
            ['Carla', 'Ruiz'], ['Diego', 'Mora'], ['Elena', 'Vega'], ['Fabian', 'Cortes'],
            ['Gabriela', 'Nunez'], ['Hector', 'Salas'], ['Ivonne', 'Rios'], ['Jorge', 'Lara'],
            ['Karla', 'Mena'], ['Luis', 'Prado'], ['Marina', 'Soto'], ['Nestor', 'Gil'],
            ['Olivia', 'Cano'], ['Pablo', 'Reyna'],
        ];
        foreach ($alumnos as [$nombre, $apellido]) {
            PersonaTenant::query()->firstOrCreate(
                ['email' => mb_strtolower($nombre).'@demo.mx'],
                ['nombre' => $nombre, 'primer_apellido' => $apellido, 'tipo' => TipoPersonaTenant::Miembro->value,
                    'activo' => true, 'es_facturable' => true, 'archivado' => false],
            );
        }
    }

    /**
     * Administradora, recepción y una profesional que solo atiende citas: una cuenta
     * activa por rol, para revisar qué ve y qué puede hacer cada quien.
     *
     * @param  array<string, array{0: string, 1: string}>  $equipo  rol => [correo, nombre]
     */
    private function sembrarEquipo(string $password, array $equipo): void
    {
        foreach ($equipo as $rol => [$email, $nombre]) {
            // La profesional de citas tiene el rol de instructor (cambia solo la etiqueta).
            $rolReal = $rol === 'profesional' ? 'instructor' : $rol;
            Usuario::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $nombre, 'rol' => $rolReal, 'roles' => [$rolReal], 'activo' => true, 'password' => $password, 'activation_token' => null],
            );
        }
    }

    /**
     * Sedes y, si el negocio da clases, la clase grupal de Pole.
     *
     * @return array{0: OfertaTenant|null, 1: SucursalTenant}
     */
    private function sembrarCatalogoYSucursal(bool $soloCitas): array
    {
        $oferta = null;
        if (! $soloCitas) {
            $programa = ProgramaTenant::query()->firstOrCreate(['slug' => 'pole'], ['nombre' => 'Pole']);
            $actividad = $programa->actividades()->firstOrCreate(['slug' => 'pole-sport'], ['nombre' => 'Pole Sport']);
            $oferta = $actividad->ofertas()->firstOrCreate(
                ['nombre' => 'Nivel 1'],
                ['modalidad' => ModalidadOfertaTenant::Grupal->value, 'capacidad' => 10],
            );
        }

        $organizacion = OrganizacionTenant::query()->firstOrCreate(['nombre' => 'AgendaUno Demo']);
        $sucursal = $organizacion->sucursales()->firstOrCreate(
            ['nombre' => 'Roma Norte'],
            ['zona_horaria' => 'America/Mexico_City'],
        );
        // Multi-sucursal (R18): la sucursal como unidad de negocio (moneda + IVA 16%).
        $sucursal->fill(['region' => 'Centro', 'moneda' => 'MXN', 'impuesto_tasa_bps' => 1600])->save();

        // Segunda sede: habilita revisar el alcance por sucursal (asignaciones de personal).
        $organizacion->sucursales()->firstOrCreate(
            ['nombre' => 'Condesa'],
            ['zona_horaria' => 'America/Mexico_City', 'region' => 'Centro', 'moneda' => 'MXN', 'impuesto_tasa_bps' => 1600],
        );

        return [$oferta, $sucursal];
    }

    /**
     * Perfil público de las sedes (dirección, WhatsApp, redes y horario) y descripción
     * de los servicios y clases, para ver completa la página pública y la de enlaces.
     * Solo rellena lo vacío.
     */
    private function sembrarPerfilPublico(string $slug): void
    {
        $sedes = [
            'Roma Norte' => ['Av. Álvaro Obregón 120, Roma Norte, Ciudad de México', '55 1234 5678', null],
            'Condesa' => ['Av. Tamaulipas 45, Condesa, Ciudad de México', '55 8765 4321', '@'.str_replace('-', '_', $slug).'_condesa'],
        ];
        $horario = [
            ['dia' => 1, 'abre' => '07:00', 'cierra' => '21:00'], ['dia' => 2, 'abre' => '07:00', 'cierra' => '21:00'],
            ['dia' => 3, 'abre' => '07:00', 'cierra' => '21:00'], ['dia' => 4, 'abre' => '07:00', 'cierra' => '21:00'],
            ['dia' => 5, 'abre' => '07:00', 'cierra' => '20:00'], ['dia' => 6, 'abre' => '09:00', 'cierra' => '14:00'],
        ];
        foreach ($sedes as $nombre => [$direccion, $whatsapp, $instagram]) {
            $sede = SucursalTenant::query()->where('nombre', $nombre)->first();
            if ($sede === null || $sede->direccion !== null) {
                continue;
            }
            $sede->fill([
                'direccion' => $direccion,
                'whatsapp' => $whatsapp,
                'redes' => $instagram !== null ? RedesSociales::normalizar(['instagram' => $instagram]) : null,
                'horario' => $horario,
            ])->save();
        }

        $descripciones = [
            'Nivel 1' => 'Tu primera clase de pole: giros básicos, trepa y fuerza de agarre. No necesitas experiencia.',
            'Nivel 2' => 'Combinaciones de giros, primeras inversiones y trabajo de flexibilidad.',
            'Nivel 3' => 'Inversiones controladas, figuras en el aire y transiciones fluidas.',
            'Nivel 4' => 'Figuras avanzadas, combos y coreografía. Requiere dominar inversiones.',
            'Corte de cabello' => 'Corte a tijera o máquina con lavado y peinado. Incluye asesoría de estilo.',
        ];
        foreach ($descripciones as $nombre => $texto) {
            OfertaTenant::query()->where('nombre', $nombre)->whereNull('descripcion')->update(['descripcion' => $texto]);
        }
    }

    /**
     * Asigna la sucursal de casa (home) a los alumnos que aun no la tienen (R18).
     */
    private function asignarSucursalDeCasa(SucursalTenant $sucursal): void
    {
        PersonaTenant::query()
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->whereNull('sucursal_id')
            ->update(['sucursal_id' => $sucursal->getKey()]);
    }

    private function venderPack(string $miembroEmail): void
    {
        $persona = PersonaTenant::query()->where('email', $miembroEmail)->first();
        if (! $persona instanceof PersonaTenant) {
            return;
        }

        // Idempotente: no revende si Ana ya tiene un acuerdo.
        if (AcuerdoTenant::query()->where('persona_id', $persona->getKey())->exists()) {
            return;
        }

        $pack = ProductoTenant::query()->where('nombre', 'Pack 8 clases')->first()
            ?? app(MembresiasTenant::class)->crearProducto('Pack 8 clases', TipoProducto::Paquete, 89900, 'MXN', false, 8000);

        app(MembresiasTenant::class)->venderProducto($persona, $pack);
    }

    /**
     * Planes de ejemplo (ADR 0050): un paquete que solo sirve para Nivel 1 a 3 y vence
     * al mes, una clase suelta, clases extra y una mensualidad ilimitada. A la alumna
     * con acceso le suma 2 clases extra, para que su corte las muestre. Idempotente.
     */
    private function sembrarPlanes(OfertaTenant $nivel1, string $miembroEmail): void
    {
        $actividad = $nivel1->actividad;
        if ($actividad === null) {
            return;
        }
        $niveles = [$nivel1];
        foreach (['Nivel 2', 'Nivel 3', 'Nivel 4'] as $nombre) {
            $niveles[] = $actividad->ofertas()->firstOrCreate(
                ['nombre' => $nombre],
                ['modalidad' => ModalidadOfertaTenant::Grupal->value, 'capacidad' => 10],
            );
        }

        $membresias = app(MembresiasTenant::class);
        $plan = fn (string $nombre, callable $crear): ProductoTenant => ProductoTenant::query()->where('nombre', $nombre)->first() ?? $crear($nombre);

        $plan('Paquete 4 clases (Nivel 1 a 3)', fn (string $n) => $membresias->crearProducto(
            $n, TipoProducto::Paquete, 60000, 'MXN', false, 4000,
            vigencia: new VigenciaProducto(TipoVigencia::Meses, 1),
            ofertaIds: array_map(fn (OfertaTenant $o): int => (int) $o->getKey(), array_slice($niveles, 0, 3)),
        ));
        $plan('Clase suelta', fn (string $n) => $membresias->crearProducto(
            $n, TipoProducto::SesionIndividual, 18000, 'MXN', false, 1000,
            vigencia: new VigenciaProducto(TipoVigencia::FinDeMes, 1),
        ));
        $extra = $plan('2 clases extra', fn (string $n) => $membresias->crearProducto($n, TipoProducto::AddOn, 25000, 'MXN', false, 2000));
        $plan('Mensualidad ilimitada', fn (string $n) => $membresias->crearProducto(
            $n, TipoProducto::Membresia, 129900, 'MXN', true, null, politicaReset: PoliticaReset::Calendario,
        ));

        $persona = PersonaTenant::query()->where('email', $miembroEmail)->first();
        $yaTieneExtras = $persona instanceof PersonaTenant && AcuerdoTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('producto_comercial_id', $extra->getKey())
            ->exists();
        if ($persona instanceof PersonaTenant && ! $yaTieneExtras && $membresias->paqueteParaExtras($persona) !== null) {
            $membresias->venderProducto($persona, $extra);
        }
    }

    private function sembrarClases(OfertaTenant $oferta, SucursalTenant $sucursal, string $instructorEmail): void
    {
        // No duplica clases si ya hay próximas programadas.
        $hayProximas = SesionTenant::query()
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>=', now())
            ->exists();
        if ($hayProximas) {
            return;
        }

        $instructor = Usuario::query()->where('email', $instructorEmail)->first();
        $zona = (string) $sucursal->zona_horaria;

        for ($dia = 1; $dia <= 3; $dia++) {
            $inicia = CarbonImmutable::now($zona)->addDays($dia)->setTime(19, 0)->utc();

            SesionTenant::query()->create([
                'oferta_id' => $oferta->getKey(),
                'sucursal_id' => $sucursal->getKey(),
                'instructor_id' => $instructor?->getKey(),
                'inicia_en' => $inicia,
                'termina_en' => $inicia->addMinutes(60),
                'zona_horaria' => $zona,
                'capacidad' => $oferta->capacidad,
                'estado' => EstadoSesionTenant::Programada->value,
            ]);
        }
    }

    /**
     * Servicio de CITA (barbería) + horario de atención de los profesionales, para
     * revisar el flujo de citas: elegir sede → profesional → hueco → agendar y pagar.
     * Idempotente (no duplica ni el servicio ni las ventanas de atención).
     *
     * @param  list<string>  $profesionales  correos de quienes atienden citas
     */
    private function sembrarCitas(array $profesionales, string $clienteEmail): void
    {

        // Servicio agendable como cita: pago-para-reservar, 30 min, MXN 250.
        $programa = ProgramaTenant::query()->firstOrCreate(['slug' => 'barberia'], ['nombre' => 'Barbería']);
        $actividad = $programa->actividades()->firstOrCreate(['slug' => 'cortes'], ['nombre' => 'Cortes']);
        $servicio = $actividad->ofertas()->firstOrCreate(
            ['nombre' => 'Corte de cabello'],
            [
                'modalidad' => ModalidadOfertaTenant::Individual->value,
                'capacidad' => 1,
                'politica_reserva' => PoliticaReservaTenant::Pago->value,
                'precio_clase_minor' => 25000,
                'duracion_minutos' => 30,
            ],
        );

        $this->horariosDeAtencion($profesionales);
        $this->agendarCitasDemo($servicio, $profesionales, $clienteEmail);
    }

    /**
     * Horarios de atención realistas: el primero (Beto) atiende en la sede principal
     * de lunes a viernes de 10:00 a 19:00 y el sábado hasta las 15:00; la segunda
     * (Sofía), de martes a sábado de 11:00 a 20:00 en la principal y los lunes en la
     * otra sede. Domingo cerrado. Reemplaza el horario de fábrica anterior (09:00–18:00
     * todos los días en todas las sedes); no toca uno capturado a mano.
     *
     * @param  list<string>  $profesionales
     */
    private function horariosDeAtencion(array $profesionales): void
    {
        $sedes = SucursalTenant::query()->orderBy('id')->get()->values();
        $principal = $sedes->first();
        $otra = $sedes->get(1) ?? $principal;
        if (! $principal instanceof SucursalTenant) {
            return;
        }
        $semana = [
            [[$principal, [1, 2, 3, 4, 5], '10:00', '19:00'], [$principal, [6], '10:00', '15:00']],
            [[$principal, [2, 3, 4, 5, 6], '11:00', '20:00'], [$otra, [1], '10:00', '18:00']],
        ];

        foreach (Usuario::query()->whereIn('email', $profesionales)->orderBy('id')->get()->values() as $i => $profesional) {
            $actuales = HorarioAtencionTenant::query()->where('instructor_id', $profesional->getKey())->get();
            $deFabrica = $actuales->isNotEmpty() && $actuales->every(
                static fn (HorarioAtencionTenant $h): bool => str_starts_with((string) $h->hora_inicio, '09:00') && str_starts_with((string) $h->hora_fin, '18:00'),
            ) && $actuales->count() === 7 * $sedes->count();
            if ($actuales->isNotEmpty() && ! $deFabrica) {
                continue;
            }
            HorarioAtencionTenant::query()->where('instructor_id', $profesional->getKey())->delete();
            foreach ($semana[$i % 2] as [$sede, $dias, $abre, $cierra]) {
                foreach ($dias as $dia) {
                    HorarioAtencionTenant::query()->create([
                        'instructor_id' => $profesional->getKey(), 'sucursal_id' => $sede->getKey(), 'dia_semana' => $dia,
                        'hora_inicio' => $abre, 'hora_fin' => $cierra,
                    ]);
                }
            }
        }
    }

    /**
     * Un mes de historia (ADR 0081), para que los reportes tengan qué mostrar:
     * - citas en el horario de cada profesional, más llenas hacia el fin de semana;
     * - si el negocio da clases, Nivel 1 (lun, mié, vie) y Nivel 2 (mar, jue) a las
     *   19:30, con alumnos que compran su paquete cuando se les acaba;
     * - cancelaciones antes de la hora, asistencias e inasistencias, y el cobro en
     *   caja de cada cita atendida;
     * - la comida de Beto (14:00–15:00 entre semana) y el 16 de septiembre cerrado;
     * - los esquemas de pago del equipo.
     *
     * Todo pasa por los servicios del dominio con el reloj en cada fecha, así que
     * órdenes, pagos, créditos y asistencia quedan como si hubiera ocurrido. Lo que
     * esos días dejaron por avisar ya pasó y no se envía. No se repite si ya hay
     * historia.
     *
     * @param  list<string>  $profesionales
     * @return array{citas: int, clases: int}
     */
    private function sembrarHistoria(bool $soloCitas, array $profesionales): array
    {
        $sucursal = SucursalTenant::query()->orderBy('id')->first();
        $servicio = OfertaTenant::query()->where('nombre', 'Corte de cabello')->first();
        if (! $sucursal instanceof SucursalTenant || ! $servicio instanceof OfertaTenant) {
            return ['citas' => 0, 'clases' => 0];
        }
        $zona = (string) $sucursal->zona_horaria;
        $hoy = CarbonImmutable::now($zona)->startOfDay();
        $inicio = $hoy->subDays(self::DIAS_HISTORIA);
        $pasadas = SesionTenant::query()->whereBetween('inicia_en', [$inicio->utc(), $hoy->utc()])->count();
        if ($pasadas >= 40) {
            return ['citas' => 0, 'clases' => 0];
        }

        $equipo = Usuario::query()->whereIn('email', $profesionales)->orderBy('id')->get()->values();
        $clientes = PersonaTenant::query()->where('tipo', TipoPersonaTenant::Miembro->value)->orderBy('id')->get()->values();
        if ($equipo->isEmpty() || $clientes->isEmpty()) {
            return ['citas' => 0, 'clases' => 0];
        }
        $this->esquemasDePago($equipo);
        $festivo = CarbonImmutable::parse($hoy->year.'-09-16', $zona);
        if ($festivo->betweenIncluded($inicio, $hoy->subDay())) {
            ExcepcionHorarioTenant::query()->firstOrCreate(['fecha' => $festivo->toDateString()], ['motivo' => 'Día de la Independencia']);
        }
        $clases = $soloCitas ? [] : [
            1 => 'Nivel 1', 3 => 'Nivel 1', 5 => 'Nivel 1', 2 => 'Nivel 2', 4 => 'Nivel 2',
        ];
        // Qué tanto se llena la agenda de citas cada día (lun..dom), en %.
        $llenado = $soloCitas
            ? [1 => 45, 2 => 50, 3 => 55, 4 => 60, 5 => 75, 6 => 85, 7 => 0]
            : [1 => 30, 2 => 35, 3 => 35, 4 => 40, 5 => 55, 6 => 65, 7 => 0];
        $azar = static fn (string $clave): int => crc32($clave) % 100;
        $pack = ProductoTenant::query()->where('nombre', 'Pack 8 clases')->first();
        $contador = ['citas' => 0, 'clases' => 0];

        try {
            for ($dia = $inicio; $dia->lessThan($hoy); $dia = $dia->addDay()) {
                $fecha = $dia->toDateString();
                if ($fecha === $festivo->toDateString()) {
                    continue;
                }
                Carbon::setTestNow($dia->setTime(7, 0)->utc());
                $this->comidaDeBeto($equipo->first(), $sucursal, $dia);
                /** @var list<array{0: ReservaTenant, 1: bool}> $delDia */
                $delDia = [];

                foreach (HorarioAtencionTenant::query()->whereIn('instructor_id', $equipo->pluck('id'))->where('dia_semana', $dia->isoWeekday())->get() as $ventana) {
                    $sede = SucursalTenant::query()->find($ventana->sucursal_id);
                    $cursor = CarbonImmutable::parse($fecha.' '.$ventana->hora_inicio, $zona);
                    $cierre = CarbonImmutable::parse($fecha.' '.$ventana->hora_fin, $zona);
                    for (; $cursor->addMinutes(30)->lessThanOrEqualTo($cierre); $cursor = $cursor->addMinutes(30)) {
                        $clave = $fecha.$ventana->instructor_id.$cursor->format('Hi');
                        if (! $sede instanceof SucursalTenant || $azar($clave) >= $llenado[$dia->isoWeekday()]) {
                            continue;
                        }
                        $persona = $clientes[crc32('cliente'.$clave) % $clientes->count()];
                        try {
                            $delDia[] = [app(AgendarCitaTenant::class)->agendar($servicio, $sede, $persona, (int) $ventana->instructor_id, $cursor->utc(), 30, porNegocio: true), true];
                            $contador['citas']++;
                        } catch (RuntimeException) {
                            // Hueco ocupado o bloqueado: se omite.
                        }
                    }
                }

                $nivel = $clases[$dia->isoWeekday()] ?? null;
                $oferta = $nivel !== null ? OfertaTenant::query()->where('nombre', $nivel)->first() : null;
                if ($oferta instanceof OfertaTenant && $pack instanceof ProductoTenant) {
                    $inicia = CarbonImmutable::parse($fecha.' 19:30', $zona)->utc();
                    $sesion = SesionTenant::query()->create([
                        'oferta_id' => $oferta->getKey(), 'sucursal_id' => $sucursal->getKey(), 'instructor_id' => $equipo->first()?->getKey(),
                        'inicia_en' => $inicia, 'termina_en' => $inicia->addMinutes(60), 'zona_horaria' => $zona,
                        'capacidad' => $oferta->capacidad ?? 10, 'estado' => EstadoSesionTenant::Programada->value,
                    ]);
                    $contador['clases']++;
                    $cuantos = 4 + $azar('clase'.$fecha) % 7;
                    for ($n = 0; $n < $cuantos; $n++) {
                        $reserva = $this->reservarClase($sesion, $clientes[($n + $dia->day) % $clientes->count()], $pack);
                        if ($reserva instanceof ReservaTenant) {
                            $delDia[] = [$reserva, false];
                        }
                    }
                }

                // Algunas se cancelan antes de la hora.
                $quedan = [];
                foreach ($delDia as [$reserva, $esCita]) {
                    if ($azar('cancela'.$reserva->getKey()) >= 4) {
                        $quedan[] = [$reserva, $esCita];

                        continue;
                    }
                    $esCita
                        ? app(ReservasTenant::class)->cancelarSesion($reserva->sesion()->firstOrFail())
                        : app(ReservasTenant::class)->cancelar($reserva, QuienCancela::Cliente);
                }

                // Al cierre del día: asistencia y cobro en caja de las citas atendidas.
                Carbon::setTestNow($dia->setTime(22, 0)->utc());
                foreach ($quedan as [$reserva, $esCita]) {
                    $vino = $azar('asiste'.$reserva->getKey()) < 91;
                    app(MarcarAsistenciaTenant::class)->marcar($reserva, $vino ? EstadoAsistencia::Presente : EstadoAsistencia::Ausente);
                    $orden = $esCita && $reserva->orden_id !== null ? OrdenTenant::query()->find($reserva->orden_id) : null;
                    if ($orden instanceof OrdenTenant && $vino) {
                        app(OrdenesTenant::class)->liquidar($orden, $azar('pago'.$reserva->getKey()) < 60 ? 'efectivo' : 'transferencia');
                    } elseif ($orden instanceof OrdenTenant) {
                        $orden->update(['estado' => EstadoOrden::Cancelada->value, 'cancelada_en' => now()]);
                    }
                }
            }
        } finally {
            Carbon::setTestNow();
        }

        // Lo que la historia dejó por avisar (confirmaciones, puntos…) ya pasó.
        EventoOutboxTenant::query()
            ->whereNull('publicado_en')
            ->where('ocurrido_en', '<', $hoy->utc())
            ->update(['publicado_en' => now()]);

        return $contador;
    }

    /**
     * Beto come de 14:00 a 15:00 entre semana.
     */
    private function comidaDeBeto(?Usuario $beto, SucursalTenant $sucursal, CarbonImmutable $dia): void
    {
        if (! $beto instanceof Usuario || $dia->isoWeekday() > 5) {
            return;
        }
        $zona = (string) $sucursal->zona_horaria;
        BloqueoAgendaTenant::query()->firstOrCreate(
            ['instructor_id' => $beto->getKey(), 'desde' => CarbonImmutable::parse($dia->toDateString().' 14:00', $zona)->utc()],
            ['hasta' => CarbonImmutable::parse($dia->toDateString().' 15:00', $zona)->utc(), 'todo_el_dia' => false, 'zona_horaria' => $zona, 'motivo' => 'Comida'],
        );
    }

    /**
     * Reserva la clase con sus créditos; si ya no tiene, compra su paquete en caja.
     */
    private function reservarClase(SesionTenant $sesion, PersonaTenant $persona, ProductoTenant $pack): ?ReservaTenant
    {
        $reservas = app(ReservasTenant::class);
        try {
            return $reservas->crear($sesion, $persona);
        } catch (SinDerechoDisponible) {
            $ordenes = app(OrdenesTenant::class);
            $ordenes->liquidar($ordenes->crear($persona, [['producto' => $pack, 'cantidad' => 1]], sucursalId: (int) $sesion->sucursal_id), 'efectivo');
        } catch (RuntimeException) {
            return null;
        }
        try {
            return $reservas->crear($sesion, $persona);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Beto cobra por hora y la profesional de citas por cliente atendido. No pisa uno
     * que ya exista.
     *
     * @param  Collection<int, Usuario>  $equipo
     */
    private function esquemasDePago(Collection $equipo): void
    {
        $esquemas = [[TipoPago::PorHora, 15000], [TipoPago::PorAsistente, 9000]];
        foreach ($equipo as $i => $profesional) {
            [$tipo, $monto] = $esquemas[$i % 2];
            EsquemaPagoTenant::query()->firstOrCreate(
                ['usuario_id' => $profesional->getKey()],
                ['tipo' => $tipo->value, 'monto_minor' => $monto, 'moneda' => 'MXN', 'activo' => true],
            );
        }
    }

    /**
     * Unas citas ya agendadas (mañana y pasado) con cada profesional, para que su
     * portal y el de la clienta no se vean vacíos. Las agenda el negocio (quedan por
     * cobrar en caja). Idempotente: no agenda a quien ya tiene citas próximas.
     *
     * @param  list<string>  $profesionales
     */
    private function agendarCitasDemo(OfertaTenant $servicio, array $profesionales, string $clienteEmail): void
    {
        $sucursal = SucursalTenant::query()->orderBy('id')->first();
        if (! $sucursal instanceof SucursalTenant) {
            return;
        }

        // La primera cita es de la clienta con acceso; las demás, de alumnos sin acceso.
        $correos = [$clienteEmail, 'carla@demo.mx', 'diego@demo.mx', 'elena@demo.mx', 'fabian@demo.mx', 'gabriela@demo.mx'];
        $personas = PersonaTenant::query()->whereIn('email', $correos)->get()->keyBy('email');
        $horarios = [[1, 10, 0], [1, 12, 30], [2, 16, 0]];
        $zona = (string) $sucursal->zona_horaria;
        $agendar = app(AgendarCitaTenant::class);
        $turno = 0;

        foreach (Usuario::query()->whereIn('email', $profesionales)->orderBy('id')->get() as $profesional) {
            $yaTiene = SesionTenant::query()
                ->where('instructor_id', $profesional->getKey())
                ->where('tipo', TipoSesionTenant::Cita->value)
                ->where('estado', EstadoSesionTenant::Programada->value)
                ->where('inicia_en', '>=', now())
                ->exists();
            if ($yaTiene) {
                continue;
            }

            foreach ($horarios as [$dias, $hora, $minuto]) {
                $persona = $personas->get($correos[$turno++ % count($correos)]);
                if (! $persona instanceof PersonaTenant) {
                    continue;
                }
                $inicia = CarbonImmutable::now($zona)->addDays($dias)->setTime($hora, $minuto)->utc();
                try {
                    $agendar->agendar($servicio, $sucursal, $persona, (int) $profesional->getKey(), $inicia, 30, porNegocio: true);
                } catch (SesionNoReservable) {
                    // Hueco ocupado (p. ej. por una clase): se omite.
                }
            }
        }
    }

    /**
     * Genera un logo PNG sencillo (anillo sobre fondo indigo) con GD y lo guarda en
     * el disco publico del estudio. Solo para el demo: da algo que mostrar en la
     * pantalla de acceso. Si GD no esta disponible, se omite sin fallar.
     */
    private function generarLogoDemo(Estudio $estudio): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return;
        }

        $tam = 256;
        $img = imagecreatetruecolor($tam, $tam);

        $indigo = (int) imagecolorallocate($img, 79, 70, 229);   // #4f46e5
        $blanco = (int) imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $tam, $tam, $indigo);

        // Anillo blanco + punto central (marca abstracta, sin depender de fuentes).
        imagefilledellipse($img, 128, 128, 156, 156, $blanco);
        imagefilledellipse($img, 128, 128, 92, 92, $indigo);
        imagefilledellipse($img, 128, 128, 40, 40, $blanco);

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        Storage::disk('public')->deleteDirectory('estudios/'.$estudio->getKey());
        $ruta = 'estudios/'.$estudio->getKey().'/logo.png';
        Storage::disk('public')->put($ruta, $png);

        $estudio->update(['logo_url' => Storage::disk('public')->url($ruta)]);
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $equipo
     */
    private function componentInfo(Estudio $estudio, string $slug, string $password, string $ownerEmail, string $instructorEmail, string $miembroEmail, array $equipo): void
    {
        $this->info("Estudio demo listo: {$estudio->nombre} (slug: {$slug})");
        $this->line('  Directorio:  publicado = '.($estudio->publicado ? 'si' : 'no'));
        $this->line("  App:         /app/{$slug}");
        $this->line('  Cuentas (contraseña: '.$password.'):');
        $this->line("    Dueño:          {$ownerEmail}");
        $this->line("    Administradora: {$equipo['admin'][0]}");
        $this->line("    Recepción:      {$equipo['recepcionista'][0]}");
        if ($estudio->modalidad() === ModalidadServicio::Citas) {
            $this->line("    Profesional:    {$instructorEmail} y {$equipo['profesional'][0]} (atienden citas)");
            $this->line("    Clienta:        {$miembroEmail}");
        } else {
            $this->line("    Instructor:     {$instructorEmail} (clases y citas)");
            $this->line("    Profesional:    {$equipo['profesional'][0]} (solo citas)");
            $this->line("    Alumna:         {$miembroEmail} (con paquete de clases)");
        }
    }
}
