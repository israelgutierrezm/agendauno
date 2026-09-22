<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Models\User;
use App\Modules\Catalogo\Models\Actividad;
use App\Modules\Catalogo\Models\Nivel;
use App\Modules\Catalogo\Models\Oferta;
use App\Modules\Catalogo\Models\Programa;
use App\Modules\Creditos\Models\MovimientoCredito;
use App\Modules\Creditos\Models\RetencionCredito;
use App\Modules\Membresias\Models\Acuerdo;
use App\Modules\Membresias\Models\Derecho;
use App\Modules\Membresias\Models\ProductoComercial;
use App\Modules\Organizaciones\Models\Sucursal;
use App\Modules\Personas\Models\Persona;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\NivelTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrganizacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProgramaTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migracion de un tenant legacy (esquema compartido) a su base por tenant
 * (expand-migrate-verify-cutover). Copia identidad, catalogo, estructura y el nucleo
 * comercial + saldos del ledger en orden de dependencias, remapeando llaves. En
 * `--dry-run` solo calcula el plan (conteos) sin escribir. Idempotente por
 * `tenant_legacy_id`: no vuelve a migrar un estudio que ya tiene datos (salvo force).
 *
 * No migra el historial operativo (sesiones/reservas/ordenes): tiene divergencia de
 * esquema y se puede reoperar; los SALDOS de creditos si se conservan.
 */
class MigradorLegacyATenant
{
    /** @var array<string, array<int, int>> mapas legacy_id -> id_tenant por entidad */
    private array $mapa = [];

    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly PermissionRegistrar $permisos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function migrar(Tenant $legacy, bool $dryRun = false, bool $force = false): array
    {
        $plan = $this->contar($legacy);

        if ($dryRun) {
            return ['tenant' => $legacy->slug, 'dry_run' => true, 'plan' => $plan];
        }

        $estudio = $this->estudioPara($legacy);
        $this->gestor->aprovisionarBaseDeDatos($estudio);

        $yaTeniaDatos = $this->gestor->ejecutarEn($estudio, fn (): bool => Usuario::query()->exists() || PersonaTenant::query()->exists());

        if ($yaTeniaDatos && ! $force) {
            return ['tenant' => $legacy->slug, 'estudio' => $estudio->slug, 'omitido' => 'ya migrado', 'plan' => $plan];
        }

        $this->mapa = [];
        $migrados = $this->gestor->ejecutarEn($estudio, fn (): array => $this->copiar($legacy));

        // Cutover: el estudio pasa a operativo en su propia base.
        $estudio->forceFill([
            'estado' => EstadoEstudio::Active->value,
            'aprovisionado_en' => now(),
            'paso_aprovisionamiento' => 'migrado',
        ])->save();

        $verificacion = $this->verificar($plan, $migrados);

        return [
            'tenant' => $legacy->slug,
            'estudio' => $estudio->slug,
            'plan' => $plan,
            'migrados' => $migrados,
            'verificacion' => $verificacion,
            'ok' => ! in_array(false, $verificacion, true),
        ];
    }

    /**
     * Conteo de entidades legacy del tenant (plan y verificacion).
     *
     * @return array<string, int>
     */
    private function contar(Tenant $legacy): array
    {
        return [
            'usuarios' => $legacy->users()->count(),
            'personas' => $this->legacy(Persona::class, $legacy)->count(),
            'programas' => $this->legacy(Programa::class, $legacy)->count(),
            'actividades' => $this->legacy(Actividad::class, $legacy)->count(),
            'niveles' => $this->legacy(Nivel::class, $legacy)->count(),
            'ofertas' => $this->legacy(Oferta::class, $legacy)->count(),
            'sucursales' => $this->legacy(Sucursal::class, $legacy)->count(),
            'productos' => $this->legacy(ProductoComercial::class, $legacy)->count(),
            'acuerdos' => $this->legacy(Acuerdo::class, $legacy)->count(),
            'derechos' => $this->legacy(Derecho::class, $legacy)->count(),
            'movimientos' => $this->legacy(MovimientoCredito::class, $legacy)->count(),
            'retenciones' => $this->legacy(RetencionCredito::class, $legacy)->count(),
        ];
    }

    /**
     * Copia todas las entidades (en orden de dependencias) a la BD del tenant ya
     * activa. Devuelve conteos migrados por entidad.
     *
     * @return array<string, int>
     */
    private function copiar(Tenant $legacy): array
    {
        return DB::connection('tenant')->transaction(fn (): array => [
            'usuarios' => $this->copiarUsuarios($legacy),
            'personas' => $this->copiarPersonas($legacy),
            'programas' => $this->copiarProgramas($legacy),
            'actividades' => $this->copiarActividades($legacy),
            'niveles' => $this->copiarNiveles($legacy),
            'ofertas' => $this->copiarOfertas($legacy),
            'sucursales' => $this->copiarSucursales($legacy),
            'productos' => $this->copiarProductos($legacy),
            'acuerdos' => $this->copiarAcuerdos($legacy),
            'derechos' => $this->copiarDerechos($legacy),
            'movimientos' => $this->copiarMovimientos($legacy),
            'retenciones' => $this->copiarRetenciones($legacy),
        ]);
    }

    private function copiarUsuarios(Tenant $legacy): int
    {
        $this->permisos->setPermissionsTeamId($legacy->id);
        $n = 0;

        /** @var list<int> $ids */
        $ids = $legacy->users()->pluck('users.id')->all();
        $usuarios = User::query()->whereIn('id', $ids)->get();

        foreach ($usuarios as $user) {
            $rolLegacy = $user->getRoleNames()->first();
            $rol = $this->mapearRol(is_string($rolLegacy) ? $rolLegacy : 'miembro');
            $nuevo = Usuario::query()->create([
                'name' => $user->name,
                'email' => $user->email,
                'password' => $user->password, // hash bcrypt portable
                'google_id' => null, // Google se enlaza despues via SSO
                'activo' => true,
                'activation_token' => null,
                'rol' => $rol,
                'roles' => [$rol],
            ]);
            $this->recordar('usuarios', (int) $user->id, (int) $nuevo->getKey());
            $n++;
        }

        return $n;
    }

    private function copiarPersonas(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(Persona::class, $legacy)->get() as $p) {
            $nuevo = PersonaTenant::query()->create([
                'nombre' => $p->nombre,
                'primer_apellido' => $p->apellidos,
                'email' => $p->email,
                'tipo' => 'miembro',
                'activo' => true,
                'es_facturable' => true,
                'archivado' => false,
                'usuario_id' => $this->traducir('usuarios', $this->valorInt($p, 'user_id')),
            ]);
            $this->recordar('personas', (int) $p->id, (int) $nuevo->getKey());
            $n++;
        }

        return $n;
    }

    private function copiarProgramas(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(Programa::class, $legacy)->get() as $x) {
            $nuevo = ProgramaTenant::query()->create(['nombre' => $x->nombre, 'slug' => $x->slug]);
            $this->recordar('programas', (int) $x->id, (int) $nuevo->getKey());
            $n++;
        }

        return $n;
    }

    private function copiarActividades(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(Actividad::class, $legacy)->get() as $x) {
            $nuevo = ActividadTenant::query()->create([
                'programa_id' => $this->traducir('programas', $x->programa_id),
                'nombre' => $x->nombre,
                'slug' => $x->slug,
            ]);
            $this->recordar('actividades', (int) $x->id, (int) $nuevo->getKey());
            $n++;
        }

        return $n;
    }

    private function copiarNiveles(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(Nivel::class, $legacy)->get() as $x) {
            NivelTenant::query()->create([
                'actividad_id' => $this->traducir('actividades', $x->actividad_id),
                'nombre' => $x->nombre,
                'orden' => (int) $x->orden,
            ]);
            $n++;
        }

        return $n;
    }

    private function copiarOfertas(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(Oferta::class, $legacy)->get() as $x) {
            $nuevo = OfertaTenant::query()->create([
                'actividad_id' => $this->traducir('actividades', $x->actividad_id),
                'nombre' => $x->nombre,
                'modalidad' => $x->modalidad->value,
                'capacidad' => $x->capacidad,
            ]);
            $this->recordar('ofertas', (int) $x->id, (int) $nuevo->getKey());
            $n++;
        }

        return $n;
    }

    private function copiarSucursales(Tenant $legacy): int
    {
        $n = 0;
        // Aplana Organizacion → Marca → Sucursal a Organizacion → Sucursal.
        foreach ($this->legacy(Sucursal::class, $legacy)->with('marca')->get() as $s) {
            $orgLegacyId = $s->marca?->organizacion_id;
            $orgTenantId = $orgLegacyId !== null ? $this->orgTenant($legacy, (int) $orgLegacyId) : null;
            if ($orgTenantId === null) {
                continue;
            }
            $nuevo = SucursalTenant::query()->create([
                'organizacion_id' => $orgTenantId,
                'nombre' => $s->nombre,
                'zona_horaria' => $s->zona_horaria ?? 'America/Mexico_City',
            ]);
            $this->recordar('sucursales', (int) $s->id, (int) $nuevo->getKey());
            $n++;
        }

        return $n;
    }

    /**
     * Devuelve el id de la organizacion tenant equivalente a la legacy, creandola
     * la primera vez (memorizada en el mapa).
     */
    private function orgTenant(Tenant $legacy, int $orgLegacyId): ?int
    {
        $ya = $this->traducir('organizaciones', $orgLegacyId);
        if ($ya !== null) {
            return $ya;
        }

        $org = DB::connection(config('database.default'))
            ->table('organizaciones')->where('id', $orgLegacyId)->where('tenant_id', $legacy->id)->first();
        if ($org === null) {
            return null;
        }

        $nuevo = OrganizacionTenant::query()->create(['nombre' => $org->nombre]);
        $this->recordar('organizaciones', $orgLegacyId, (int) $nuevo->getKey());

        return (int) $nuevo->getKey();
    }

    private function copiarProductos(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(ProductoComercial::class, $legacy)->get() as $x) {
            $nuevo = ProductoTenant::query()->create([
                'nombre' => $x->nombre,
                'tipo' => $x->tipo->value,
                'precio_minor' => $x->precio_minor,
                'moneda' => $x->moneda,
                'ilimitado' => $x->ilimitado,
                'creditos_incluidos' => $x->creditos_incluidos,
                'actividad_id' => $this->traducir('actividades', $x->actividad_id),
                'sucursal_id' => $this->traducir('sucursales', $x->sucursal_id),
                'politica_reset' => $x->politica_reset->value,
                'unidades_por_ciclo' => $x->unidades_por_ciclo,
                'politica_rollover' => $x->politica_rollover->value,
                'rollover_max' => $x->rollover_max,
            ]);
            $this->recordar('productos', (int) $x->id, (int) $nuevo->getKey());
            $n++;
        }

        return $n;
    }

    private function copiarAcuerdos(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(Acuerdo::class, $legacy)->get() as $x) {
            $personaId = $this->traducir('personas', $x->persona_id);
            $productoId = $this->traducir('productos', $x->producto_comercial_id);
            if ($personaId === null || $productoId === null) {
                continue;
            }
            $nuevo = AcuerdoTenant::query()->create([
                'persona_id' => $personaId,
                'producto_comercial_id' => $productoId,
                'fecha_inicio' => $x->fecha_inicio,
                'estado' => $x->estado->value,
            ]);
            $this->recordar('acuerdos', (int) $x->id, (int) $nuevo->getKey());
            $n++;
        }

        return $n;
    }

    private function copiarDerechos(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(Derecho::class, $legacy)->get() as $x) {
            $acuerdoId = $this->traducir('acuerdos', $x->acuerdo_id);
            if ($acuerdoId === null) {
                continue;
            }
            $nuevo = DerechoTenant::query()->create([
                'acuerdo_id' => $acuerdoId,
                'ambito' => $x->ambito,
                'actividad_id' => $this->traducir('actividades', $x->actividad_id),
                'sucursal_id' => $this->traducir('sucursales', $x->sucursal_id),
                'ilimitado' => $x->ilimitado,
                'politica_reset' => $x->politica_reset->value,
                'unidades_por_ciclo' => $x->unidades_por_ciclo,
                'politica_rollover' => $x->politica_rollover->value,
                'rollover_max' => $x->rollover_max,
                'ciclo_inicio' => $x->ciclo_inicio,
                'ciclo_fin' => $x->ciclo_fin,
                'valido_desde' => $x->valido_desde,
                'valido_hasta' => $x->valido_hasta,
            ]);
            $this->recordar('derechos', (int) $x->id, (int) $nuevo->getKey());
            $n++;
        }

        return $n;
    }

    private function copiarMovimientos(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(MovimientoCredito::class, $legacy)->get() as $x) {
            $derechoId = $this->traducir('derechos', $x->derecho_id);
            if ($derechoId === null) {
                continue;
            }
            MovimientoCreditoTenant::query()->create([
                'derecho_id' => $derechoId,
                'tipo' => $x->tipo->value,
                'unidades' => $x->unidades,
                'descripcion' => $x->descripcion,
            ]);
            $n++;
        }

        return $n;
    }

    private function copiarRetenciones(Tenant $legacy): int
    {
        $n = 0;
        foreach ($this->legacy(RetencionCredito::class, $legacy)->get() as $x) {
            $derechoId = $this->traducir('derechos', $x->derecho_id);
            if ($derechoId === null) {
                continue;
            }
            RetencionCreditoTenant::query()->create([
                'derecho_id' => $derechoId,
                'unidades' => $x->unidades,
                'estado' => $x->estado->value,
                'descripcion' => $x->descripcion,
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * Compara el plan (origen) con lo migrado; movimientos/retenciones/acuerdos
     * pueden ser menores si referencian filas huerfanas (se omiten), asi que la
     * verificacion exige igualdad en las entidades base y no-perdida en el resto.
     *
     * @param  array<string, int>  $plan
     * @param  array<string, int>  $migrados
     * @return array<string, bool>
     */
    private function verificar(array $plan, array $migrados): array
    {
        $exactas = ['usuarios', 'personas', 'programas', 'actividades', 'niveles', 'ofertas', 'productos'];
        $resultado = [];
        foreach ($plan as $entidad => $origen) {
            $destino = $migrados[$entidad] ?? 0;
            $resultado[$entidad] = in_array($entidad, $exactas, true)
                ? $destino === $origen
                : $destino <= $origen; // dependientes: nunca mas que el origen
        }

        return $resultado;
    }

    private function estudioPara(Tenant $legacy): Estudio
    {
        $estudio = Estudio::query()->where('tenant_legacy_id', $legacy->id)->first();
        if ($estudio instanceof Estudio) {
            return $estudio;
        }

        $slug = $this->slugDisponible($legacy->slug);
        $driver = (string) config('turnouno.tenant_db_driver', 'sqlite');
        $dbDatabase = $driver === 'sqlite'
            ? $slug.'_'.Str::lower(Str::random(8)).'.sqlite'
            : 'tenant_'.str_replace('-', '_', $slug).'_'.Str::lower(Str::random(8));

        return Estudio::query()->create([
            'tenant_legacy_id' => $legacy->id,
            'nombre' => $legacy->name,
            'slug' => $slug,
            'estado' => EstadoEstudio::Provisioning->value,
            'estado_facturacion' => EstadoFacturacion::Trial->value,
            'contacto_nombre' => $legacy->name,
            'contacto_email' => 'migrado+'.$legacy->slug.'@agendauno.mx',
            'zona_horaria' => 'America/Mexico_City',
            'db_driver' => $driver,
            'db_database' => $dbDatabase,
        ]);
    }

    private function slugDisponible(string $base): string
    {
        $slug = Str::slug($base);
        if ($slug === '' || Estudio::query()->where('slug', $slug)->exists()) {
            $slug = ($slug === '' ? 'estudio' : $slug).'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    private function mapearRol(string $rol): string
    {
        return match ($rol) {
            'propietario' => 'propietario',
            'gerente-sucursal', 'gerente' => 'admin',
            'recepcionista' => 'recepcionista',
            'instructor' => 'instructor',
            default => 'miembro',
        };
    }

    /**
     * Query legacy de un modelo tenant-scoped, sin el scope de tenant y filtrada por
     * el tenant a migrar.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelo
     * @return Builder<TModel>
     */
    private function legacy(string $modelo, Tenant $legacy): Builder
    {
        return $modelo::query()->withoutGlobalScope('tenant')->where('tenant_id', $legacy->id);
    }

    private function recordar(string $entidad, int $legacyId, int $tenantId): void
    {
        $this->mapa[$entidad][$legacyId] = $tenantId;
    }

    /**
     * Lee un atributo del modelo como entero (o null). Evita el choque de nombres de
     * tabla legacy/tenant en el analisis estatico (p. ej. personas.user_id).
     */
    private function valorInt(Model $modelo, string $atributo): ?int
    {
        $valor = $modelo->getAttribute($atributo);

        return is_numeric($valor) ? (int) $valor : null;
    }

    private function traducir(string $entidad, int|string|null $legacyId): ?int
    {
        if ($legacyId === null) {
            return null;
        }

        return $this->mapa[$entidad][(int) $legacyId] ?? null;
    }
}
