<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\PaseAccesoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Vuelve a cifrar con la APP_KEY actual lo que siga cifrado con una de APP_PREVIOUS_KEYS,
 * en la plataforma y en cada negocio (ADR 0232; procedimiento en docs/DESPLIEGUE.md).
 * Las columnas salen de los modelos con cast `encrypted` (una nueva entra sola), más las
 * que se cifran a mano. Se puede repetir: lo que ya está con la llave actual no se toca.
 * No imprime ningún valor: solo cuentas y, de lo ilegible, tabla, columna y fila.
 */
class RotarAppKey extends Command
{
    /** Cifradas a mano con `Crypt::encryptString` ({@see PaseAccesoTenant}). */
    private const A_MANO = [
        ['conexion' => 'tenant', 'tabla' => 'personas', 'clave' => 'id', 'columna' => 'pase_wallet_token'],
    ];

    protected $signature = 'agendauno:rotar-app-key {--simular : Solo cuenta lo que cambiaría}';

    protected $description = 'Vuelve a cifrar con la APP_KEY actual lo cifrado con una llave anterior';

    /** @var list<string> */
    private array $ilegibles = [];

    public function handle(GestorDeConexionTenant $gestor): int
    {
        if (array_filter((array) config('app.previous_keys', [])) === []) {
            $this->error('Falta la llave anterior en APP_PREVIOUS_KEYS (y la nueva en APP_KEY): sin ella no se puede leer lo cifrado.');

            return self::FAILURE;
        }
        $simular = (bool) $this->option('simular');
        /** @var Encrypter $conTodas */
        $conTodas = app('encrypter');
        $actual = new Encrypter($conTodas->getKey(), (string) config('app.cipher'));

        $columnas = collect(self::columnasCifradas());
        $plataforma = $this->rotar($columnas->where('conexion', '!=', 'tenant')->all(), $conTodas, $actual, $simular);
        $this->line("Plataforma: {$plataforma['revisados']} valores revisados, {$plataforma['recifrados']} ".($simular ? 'por cifrar' : 'cifrados').'.');

        $delNegocio = $columnas->where('conexion', 'tenant')->all();
        $total = ['revisados' => 0, 'recifrados' => 0];
        $sinRevisar = [];
        foreach (Estudio::query()->orderBy('id')->get() as $estudio) {
            if (! $gestor->baseDeDatosExiste($estudio)) {
                continue;
            }
            $cuenta = $gestor->ejecutarAislado($estudio, fn (): array => $this->rotar($delNegocio, $conTodas, $actual, $simular, (string) $estudio->slug), null);
            if ($cuenta === null) {
                $sinRevisar[] = (string) $estudio->slug;

                continue;
            }
            $total['revisados'] += $cuenta['revisados'];
            $total['recifrados'] += $cuenta['recifrados'];
        }
        $this->line("Negocios: {$total['revisados']} valores revisados, {$total['recifrados']} ".($simular ? 'por cifrar' : 'cifrados').'.');

        foreach ($this->ilegibles as $ilegible) {
            $this->warn("Ilegible con estas llaves: {$ilegible}");
        }
        if ($sinRevisar !== []) {
            $this->warn('Negocios que no se pudieron revisar: '.implode(', ', $sinRevisar));
        }
        if ($this->ilegibles !== [] || $sinRevisar !== []) {
            $this->error('Quedó algo pendiente: no quites la llave anterior hasta resolverlo y repetir este comando.');

            return self::FAILURE;
        }
        $this->info($simular ? 'Simulación: no se cambió nada.' : 'Todo quedó con la llave actual.');

        return self::SUCCESS;
    }

    /**
     * Las columnas cifradas de los modelos (por su cast) y las cifradas a mano.
     *
     * @return list<array{conexion: string|null, tabla: string, clave: string, columna: string}>
     */
    public static function columnasCifradas(): array
    {
        $columnas = self::A_MANO;
        foreach (File::allFiles(app_path('Modules')) as $archivo) {
            if ($archivo->getExtension() !== 'php' || ! str_contains($archivo->getContents(), 'encrypted')) {
                continue;
            }
            $clase = 'App\\Modules\\'.str_replace('/', '\\', Str::beforeLast(str_replace('\\', '/', $archivo->getRelativePathname()), '.php'));
            if (! is_subclass_of($clase, Model::class) || (new ReflectionClass($clase))->isAbstract()) {
                continue;
            }
            /** @var Model $modelo */
            $modelo = new $clase;
            foreach ($modelo->getCasts() as $columna => $cast) {
                if (str_contains(strtolower($cast), 'encrypted')) {
                    $columnas[] = ['conexion' => $modelo->getConnectionName(), 'tabla' => $modelo->getTable(), 'clave' => $modelo->getKeyName(), 'columna' => (string) $columna];
                }
            }
        }

        return $columnas;
    }

    /**
     * @param  array<int, array{conexion: string|null, tabla: string, clave: string, columna: string}>  $columnas
     * @return array{revisados: int, recifrados: int}
     */
    private function rotar(array $columnas, Encrypter $conTodas, Encrypter $actual, bool $simular, string $donde = 'plataforma'): array
    {
        $cuenta = ['revisados' => 0, 'recifrados' => 0];
        foreach ($columnas as ['conexion' => $conexion, 'tabla' => $tabla, 'clave' => $clave, 'columna' => $columna]) {
            $esquema = Schema::connection($conexion);
            if (! $esquema->hasTable($tabla) || ! $esquema->hasColumn($tabla, $columna)) {
                continue;
            }
            DB::connection($conexion)->table($tabla)->select([$clave, $columna])->whereNotNull($columna)
                ->chunkById(200, function ($filas) use (&$cuenta, $conexion, $tabla, $clave, $columna, $conTodas, $actual, $simular, $donde): void {
                    foreach ($filas as $fila) {
                        $crudo = (string) $fila->{$columna};
                        if ($crudo === '') {
                            continue;
                        }
                        $cuenta['revisados']++;
                        if (self::abre($actual, $crudo) !== null) {
                            continue;
                        }
                        $plano = self::abre($conTodas, $crudo);
                        if ($plano === null) {
                            $this->ilegibles[] = "{$donde}: {$tabla}.{$columna}, fila {$fila->{$clave}}";

                            continue;
                        }
                        $cuenta['recifrados']++;
                        if (! $simular) {
                            // Si otro lo cambió mientras tanto, ya quedó con la llave actual.
                            DB::connection($conexion)->table($tabla)->where($clave, $fila->{$clave})->where($columna, $crudo)
                                ->update([$columna => $actual->encryptString($plano)]);
                        }
                    }
                }, $clave);
        }

        return $cuenta;
    }

    /** Lo descifrado tal cual (sin deserializar: se vuelve a cifrar byte por byte), o null. */
    private static function abre(Encrypter $llaves, string $crudo): ?string
    {
        try {
            return $llaves->decryptString($crudo);
        } catch (DecryptException) {
            return null;
        }
    }
}
