<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use App\Modules\Tenancy\Parametros\CatalogoParametros;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Borra los negocios cuyo dueño nunca activó su cuenta: el registro público crea una
 * base completa por alta, y las que nadie activa solo ocupan el servidor, alargan los
 * respaldos y las tareas por negocio, y apartan su slug.
 *
 * Se borra un negocio solo si TODO esto se cumple; ante la duda, se conserva:
 * - sigue en prueba o a medio aprovisionar (nunca uno activo, suspendido o cancelado);
 * - se registró hace más de N días (parámetro de la plataforma {@see CLAVE_DIAS});
 * - no tiene cargos de renta ni facturas de la plataforma;
 * - en su base: un solo usuario, que nunca activó su cuenta (sin contraseña, sin correo
 *   verificado, sin Google), sin sesiones, sin pagos, sin ventas y sin clientes. Sin
 *   base, solo si quedó a medio aprovisionar.
 *
 * Se borran sus respaldos (el slug queda libre y otro negocio con ese nombre no debe
 * verlos), su base y su registro (con lo que cuelga de él: mediciones y avisos; la
 * aceptación de los legales se conserva, sin el negocio).
 */
class LimpiezaDeAltasSinActivar
{
    /** Parámetro de la plataforma (CatalogoParametros): días para activar la cuenta. */
    public const CLAVE_DIAS = 'registro.dias_sin_activar';

    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly VolcadoBaseDatos $volcado,
        private readonly ParametrosTenant $parametros,
    ) {}

    /** Días que tiene el dueño para activar su cuenta: el parámetro de la plataforma. */
    public function dias(): int
    {
        return $this->parametros->entero(self::CLAVE_DIAS);
    }

    /** El plazo dentro de los límites del parámetro. */
    public static function acotar(int $dias): int
    {
        $definicion = CatalogoParametros::de(self::CLAVE_DIAS);

        return max($definicion->minimo, min($definicion->maximo, $dias));
    }

    /**
     * Con `$simular` solo dice qué borraría.
     *
     * @return array{borrados: list<string>, conservados: array<string, string>, errores: array<string, string>}
     */
    public function ejecutar(int $dias, bool $simular = false): array
    {
        $resultado = ['borrados' => [], 'conservados' => [], 'errores' => []];

        foreach ($this->candidatos(self::acotar($dias)) as $estudio) {
            $slug = (string) $estudio->slug;
            try {
                $motivo = $this->motivoParaConservar($estudio);
                if ($motivo !== null) {
                    $resultado['conservados'][$slug] = $motivo;

                    continue;
                }
                if (! $simular) {
                    $this->borrar($estudio);
                }
                $resultado['borrados'][] = $slug;
            } catch (Throwable $e) {
                $resultado['errores'][$slug] = $e->getMessage();
                Log::warning('No se pudo revisar o borrar un negocio sin activar.', ['slug' => $slug, 'error' => $e->getMessage()]);
            }
        }

        return $resultado;
    }

    /**
     * En prueba o a medio aprovisionar, registrados antes del plazo y sin nada de la
     * renta de la plataforma.
     *
     * @return Collection<int, Estudio>
     */
    private function candidatos(int $dias): Collection
    {
        return Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Provisioning->value, EstadoEstudio::Trialing->value])
            ->where('created_at', '<', Carbon::now()->subDays($dias))
            ->whereNotIn('id', CargoRenta::query()->select('estudio_id'))
            ->whereNotIn('id', FacturaPlataforma::query()->select('estudio_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Por qué NO se borra (null: se puede borrar). Lee la base del negocio sin
     * suponer su esquema completo: una alta pudo quedarse a medio migrar.
     */
    private function motivoParaConservar(Estudio $estudio): ?string
    {
        if (! $this->baseExiste($estudio)) {
            // A medio aprovisionar, la base pudo no llegar a crearse: nadie la usó. Si
            // ya estaba en prueba y no aparece, algo anda mal (conexión, disco): no se toca.
            return $estudio->estado === EstadoEstudio::Provisioning ? null : 'no se encontró su base';
        }

        return $this->gestor->ejecutarEn($estudio, static function (): ?string {
            $esquema = Schema::connection('tenant');
            $base = DB::connection('tenant');

            if ($esquema->hasTable('users')) {
                // Cuenta también a los dados de baja: el negocio tuvo equipo.
                if ($base->table('users')->count() > 1) {
                    return 'tiene más usuarios';
                }
                $activado = $base->table('users')->where(static function (Builder $q): void {
                    $q->where('activo', true)
                        ->orWhereNotNull('password')
                        ->orWhereNotNull('email_verified_at')
                        ->orWhereNotNull('google_id');
                })->exists();
                if ($activado) {
                    return 'el dueño activó su cuenta';
                }
            }

            foreach ([
                'personal_access_tokens' => 'alguien inició sesión',
                'pagos' => 'tiene pagos',
                'ordenes' => 'tiene ventas',
                'personas' => 'tiene clientes',
            ] as $tabla => $motivo) {
                if ($esquema->hasTable($tabla) && $base->table($tabla)->exists()) {
                    return $motivo;
                }
            }

            return null;
        });
    }

    private function baseExiste(Estudio $estudio): bool
    {
        if ($estudio->db_driver === 'sqlite') {
            return $this->gestor->baseDeDatosExiste($estudio);
        }

        return DB::selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [(string) $estudio->db_database]) !== null;
    }

    /**
     * Sus respaldos, su base y su registro, en ese orden. Si el registro no se pudo
     * borrar tras la base, la corrida lo reporta como error y en adelante el negocio
     * se conserva («no se encontró su base») hasta que la plataforma lo revise.
     */
    private function borrar(Estudio $estudio): void
    {
        $slug = (string) $estudio->slug;
        $nombre = (string) $estudio->db_database;

        $this->volcado->disco()->deleteDirectory($this->volcado->carpeta($slug));

        $this->gestor->desconectar();
        if ($estudio->db_driver === 'sqlite') {
            $ruta = (string) $this->gestor->configuracion($estudio)['database'];
            if ($nombre === '' || basename($ruta) !== $nombre) {
                throw new RuntimeException("El archivo «{$nombre}» no es el de una base de negocio: no se borra.");
            }
            foreach ([$ruta, $ruta.'-wal', $ruta.'-shm', $ruta.'-journal'] as $archivo) {
                if (File::exists($archivo)) {
                    File::delete($archivo);
                }
            }
        } else {
            // Solo una base con nombre de negocio (`tenant_…`, ver RegistrarEstudio).
            if (preg_match('/^tenant_[a-z0-9_]+$/', $nombre) !== 1 || strlen($nombre) > 64) {
                throw new RuntimeException("La base «{$nombre}» no tiene el nombre de una base de negocio: no se borra.");
            }
            DB::statement("DROP DATABASE IF EXISTS `{$nombre}`");
        }

        $registrado = $estudio->created_at?->toIso8601String();
        $estudio->delete();

        Log::info('Negocio sin activar borrado.', ['slug' => $slug, 'registrado' => $registrado, 'base' => $nombre]);
    }
}
