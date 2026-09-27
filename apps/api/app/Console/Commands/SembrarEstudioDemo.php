<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * (Re)aprovisiona y siembra un estudio "demo" en el data plane por tenant para
 * revisión manual. Idempotente y reanudable: recrea la BD del tenant si fue
 * borrada (p. ej. la suite limpia storage/tenants entre pruebas y se lleva la BD
 * del estudio) y vuelve a dejar todo listo — dueño, instructor, alumnos con
 * créditos, catálogo, sucursal y clases próximas. NUNCA corre en producción.
 */
class SembrarEstudioDemo extends Command
{
    protected $signature = 'turnouno:sembrar-demo {--slug=demo} {--password=password}
        {--perfil= : Giro del negocio (p. ej. barberia); con uno de citas no se siembran clases grupales}
        {--nombre= : Nombre del negocio si se crea}';

    protected $description = 'Reaprovisiona y siembra el estudio demo (solo dev) para revisión manual';

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

        // 4. Datos operativos dentro de la BD del tenant.
        $gestor->ejecutarEn($estudio, function () use ($password, $ownerEmail, $instructorEmail, $miembroEmail, $equipo, $soloCitas): void {
            $this->sembrarPersonal($password, $ownerEmail, $instructorEmail, $miembroEmail);
            $this->sembrarEquipo($password, $equipo);
            [$oferta, $sucursal] = $this->sembrarCatalogoYSucursal($soloCitas);
            $this->asignarSucursalDeCasa($sucursal);
            // Un negocio de citas no vende paquetes de clases ni tiene clases grupales.
            if (! $soloCitas && $oferta instanceof OfertaTenant) {
                $this->venderPack($miembroEmail);
                $this->sembrarClases($oferta, $sucursal, $instructorEmail);
            }
            $this->sembrarCitas([$instructorEmail, $equipo['profesional'][0]]);
        });

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
    private function sembrarCitas(array $profesionales): void
    {

        // Servicio agendable como cita: pago-para-reservar, 30 min, MXN 250.
        $programa = ProgramaTenant::query()->firstOrCreate(['slug' => 'barberia'], ['nombre' => 'Barbería']);
        $actividad = $programa->actividades()->firstOrCreate(['slug' => 'cortes'], ['nombre' => 'Cortes']);
        $actividad->ofertas()->firstOrCreate(
            ['nombre' => 'Corte de cabello'],
            [
                'modalidad' => ModalidadOfertaTenant::Individual->value,
                'capacidad' => 1,
                'politica_reserva' => PoliticaReservaTenant::Pago->value,
                'precio_clase_minor' => 25000,
                'duracion_minutos' => 30,
            ],
        );

        // Horario de atención 09:00–18:00 (lun–dom) de cada profesional en cada sucursal.
        foreach (Usuario::query()->whereIn('email', $profesionales)->get() as $profesional) {
            foreach (SucursalTenant::query()->get() as $sucursal) {
                for ($dia = 1; $dia <= 7; $dia++) {
                    HorarioAtencionTenant::query()->firstOrCreate(
                        ['instructor_id' => $profesional->getKey(), 'sucursal_id' => $sucursal->getKey(), 'dia_semana' => $dia],
                        ['hora_inicio' => '09:00', 'hora_fin' => '18:00'],
                    );
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
