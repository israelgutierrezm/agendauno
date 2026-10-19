<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\BorradorSitioWebTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PublicacionSitioWebTenant;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Lleva lo que los negocios ya subieron al volumen del servidor (`public` y
 * `local/documentos`) a los discos configurados, normalmente S3 (ADR 0170), y cambia
 * las URL guardadas de las imágenes (logo, portada y sitio) a las nuevas.
 *
 * Copia, no borra: los originales se quedan para volver atrás. Se puede correr las
 * veces que haga falta: lo que ya está igual en el destino no se vuelve a copiar.
 */
class MoverArchivos extends Command
{
    protected $signature = 'agendauno:mover-archivos
        {--dry-run : Solo cuenta lo que copiaría y las URL que cambiaría}';

    protected $description = 'Copia los archivos subidos del volumen del servidor a los discos configurados (S3) y actualiza sus URL';

    public function handle(GestorDeConexionTenant $gestor): int
    {
        $simular = (bool) $this->option('dry-run');
        $fallidos = 0;
        $mueveImagenes = DiscosDeArchivos::nombrePublico() !== DiscosDeArchivos::LEGADO_PUBLICO;

        foreach ([
            'Imágenes' => [DiscosDeArchivos::LEGADO_PUBLICO, DiscosDeArchivos::nombrePublico(), ''],
            'Documentos' => [DiscosDeArchivos::LEGADO_PRIVADO, DiscosDeArchivos::nombrePrivado(), 'documentos'],
        ] as $tipo => [$origen, $destino, $carpeta]) {
            if ($origen === $destino) {
                $this->line("{$tipo}: ya se guardan en «{$destino}» (nada que mover).");

                continue;
            }
            ['copiados' => $copiados, 'iguales' => $iguales, 'fallidos' => $sinCopiar] = $this->copiar($origen, $destino, $carpeta, $simular);
            $verbo = $simular ? 'se copiarían' : 'copiados';
            $this->info("{$tipo} de «{$origen}» a «{$destino}»: {$copiados} {$verbo}, {$iguales} ya estaban.");
            foreach ($sinCopiar as $ruta) {
                $this->error("  No se pudo copiar {$ruta}");
            }
            $fallidos += count($sinCopiar);
        }

        if ($mueveImagenes) {
            $fallidos += $this->cambiarUrls($gestor, $simular);
        }

        if ($fallidos > 0) {
            $this->error("{$fallidos} archivo(s) o negocio(s) con error: vuelve a correrlo.");

            return self::FAILURE;
        }
        if (! $simular && $mueveImagenes) {
            $this->line('Los originales siguen en el volumen. Bórralos cuando compruebes que todo se ve bien.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{copiados: int, iguales: int, fallidos: list<string>}
     */
    private function copiar(string $origen, string $destino, string $carpeta, bool $simular): array
    {
        $de = DiscosDeArchivos::disco($origen);
        $a = DiscosDeArchivos::disco($destino);
        $resultado = ['copiados' => 0, 'iguales' => 0, 'fallidos' => []];
        foreach ($de->allFiles($carpeta) as $ruta) {
            if (basename($ruta) === '.gitignore') {
                continue;
            }
            if ($a->exists($ruta) && $a->size($ruta) === $de->size($ruta)) {
                $resultado['iguales']++;

                continue;
            }
            if ($simular) {
                $resultado['copiados']++;

                continue;
            }
            $flujo = $de->readStream($ruta);
            $copiado = is_resource($flujo) && $a->writeStream($ruta, $flujo);
            if (is_resource($flujo)) {
                fclose($flujo);
            }
            if ($copiado) {
                $resultado['copiados']++;
            } else {
                $resultado['fallidos'][] = $ruta;
            }
        }

        return $resultado;
    }

    /**
     * Las URL de las imágenes guardadas: el logo, la portada y el SEO del negocio (base
     * central) y el borrador y las versiones de su sitio (su base). Devuelve cuántos
     * negocios fallaron.
     */
    private function cambiarUrls(GestorDeConexionTenant $gestor, bool $simular): int
    {
        $viejo = DiscosDeArchivos::prefijoPublico(DiscosDeArchivos::LEGADO_PUBLICO);
        $nuevo = DiscosDeArchivos::prefijoPublico();
        $cambiados = 0;
        $fallidos = 0;

        Estudio::query()->chunkById(100, function (Collection $estudios) use ($gestor, $simular, $viejo, $nuevo, &$cambiados, &$fallidos): void {
            /** @var Collection<int, Estudio> $estudios */
            foreach ($estudios as $estudio) {
                try {
                    $cambio = $this->cambiarEn($estudio, ['logo_url', 'portada_url', 'seo_sitio'], $viejo, $nuevo, $simular);
                    if ($estudio->estado !== EstadoEstudio::Provisioning && $gestor->baseDeDatosExiste($estudio)) {
                        $cambio = $gestor->ejecutarEn($estudio, function () use ($viejo, $nuevo, $simular): bool {
                            $alguno = false;
                            foreach (BorradorSitioWebTenant::query()->get() as $borrador) {
                                $alguno = $this->cambiarEn($borrador, ['borrador'], $viejo, $nuevo, $simular) || $alguno;
                            }
                            foreach (PublicacionSitioWebTenant::query()->lazyById(50) as $publicacion) {
                                $alguno = $this->cambiarEn($publicacion, ['contenido'], $viejo, $nuevo, $simular) || $alguno;
                            }

                            return $alguno;
                        }) || $cambio;
                    }
                    $cambiados += $cambio ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                    $this->error("  {$estudio->slug}: {$e->getMessage()}");
                    $fallidos++;
                }
            }
        });

        $verbo = $simular ? 'cambiarían' : 'cambiaron';
        $this->info("URL de imágenes: {$viejo} → {$nuevo}. Se {$verbo} en {$cambiados} negocio(s).");

        return $fallidos;
    }

    /**
     * @param  list<string>  $atributos
     */
    private function cambiarEn(Model $modelo, array $atributos, string $viejo, string $nuevo, bool $simular): bool
    {
        foreach ($atributos as $atributo) {
            $modelo->setAttribute($atributo, self::cambiarPrefijo($modelo->getAttribute($atributo), $viejo, $nuevo));
        }
        if (! $modelo->isDirty($atributos)) {
            return false;
        }
        if (! $simular) {
            $modelo->save();
        }

        return true;
    }

    /** Cambia el prefijo en un texto o en todos los textos de un arreglo (JSON). */
    private static function cambiarPrefijo(mixed $valor, string $viejo, string $nuevo): mixed
    {
        if (is_string($valor) && str_starts_with($valor, $viejo)) {
            return $nuevo.substr($valor, strlen($viejo));
        }
        if (is_array($valor)) {
            return array_map(static fn (mixed $v): mixed => self::cambiarPrefijo($v, $viejo, $nuevo), $valor);
        }

        return $valor;
    }
}
