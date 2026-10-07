<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\CatalogoPaises;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Exceptions\SlugNoDisponible;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\PerfilNegocio;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Crea el registro central de un estudio en estado `provisioning`. El slug se
 * reserva mediante el índice único (`estudios.slug`): dos registros concurrentes
 * con el mismo slug no pueden coexistir; el perdedor recibe SLUG_TAKEN. No crea
 * la BD del tenant (eso lo hace {@see AprovisionarEstudio}).
 *
 * Largos: el slug mide a lo más {@see LARGO_MAXIMO_SLUG} (también con su sufijo) y
 * el nombre de la base en MySQL nunca pasa de los 64 caracteres que admite: con un
 * nombre de negocio largo, el slug automático se recorta.
 *
 * @phpstan-type DatosRegistro array{nombre: string, slug: string, perfil_negocio?: string|null, contacto_nombre: string, contacto_segundo_nombre?: string|null, contacto_primer_apellido?: string|null, contacto_segundo_apellido?: string|null, contacto_email: string, contacto_whatsapp_pais?: string|null, contacto_telefono?: string|null, pais?: string|null, ciudad?: string|null, zona_horaria?: string|null}
 */
class RegistrarEstudio
{
    /** Largo máximo del slug: el manual se valida con él y el automático se recorta. */
    public const LARGO_MAXIMO_SLUG = 40;

    /** MySQL no admite nombres de base de más de 64 caracteres. */
    private const LARGO_MAXIMO_BASE = 64;

    /**
     * @param  DatosRegistro  $datos
     */
    public function ejecutar(array $datos): Estudio
    {
        // El enlace público (agendauno.mx/mi-estudio) se genera AUTOMÁTICAMENTE desde el
        // nombre cuando el registrante no captura un slug (lo normal). Si viene uno
        // (compatibilidad/avanzado), se respeta.
        $slugManual = Str::slug($datos['slug']);
        $autogenerado = $slugManual === '';
        $slug = $autogenerado ? $this->generarSlugUnico($datos['nombre']) : $slugManual;

        try {
            return $this->crear($datos, $slug);
        } catch (UniqueConstraintViolationException $e) {
            // Carrera concurrente sobre un slug autogenerado: reintenta con un sufijo
            // aleatorio (el registrante no lo eligió, no debe ver un error de "ocupado").
            if ($autogenerado) {
                $sufijo = '-'.Str::lower(Str::random(4));

                return $this->crear($datos, self::recortar($slug, self::LARGO_MAXIMO_SLUG - strlen($sufijo)).$sufijo);
            }

            throw new SlugNoDisponible('El slug ya está en uso.');
        }
    }

    /**
     * Deriva un slug único desde el nombre del estudio, recortado a
     * {@see LARGO_MAXIMO_SLUG}. En colisión agrega un sufijo numérico (recorta la base
     * para que quepa); el índice único de `estudios.slug` cierra la carrera concurrente.
     */
    private function generarSlugUnico(string $nombre): string
    {
        $base = self::recortar(Str::slug($nombre), self::LARGO_MAXIMO_SLUG);
        if ($base === '') {
            $base = 'estudio';
        }

        $candidato = $base;
        $intento = 1;
        while (Estudio::query()->where('slug', $candidato)->exists()) {
            $intento++;
            $sufijo = '-'.$intento;
            $candidato = self::recortar($base, self::LARGO_MAXIMO_SLUG - strlen($sufijo)).$sufijo;
        }

        return $candidato;
    }

    /** Los primeros `$largo` caracteres, sin guion (ni guion bajo) al final. */
    private static function recortar(string $slug, int $largo): string
    {
        return rtrim(substr($slug, 0, max(0, $largo)), '-_');
    }

    /**
     * Nombre de la base en MySQL: `tenant_`, el slug (recortado si hace falta) y un
     * sufijo aleatorio. Mide a lo más {@see LARGO_MAXIMO_BASE} venga de donde venga
     * el slug (registro, demos, verificación de concurrencia).
     */
    private static function nombreDeBase(string $slug): string
    {
        $prefijo = 'tenant_';
        $sufijo = '_'.Str::lower(Str::random(8));
        $parte = self::recortar(str_replace('-', '_', $slug), self::LARGO_MAXIMO_BASE - strlen($prefijo) - strlen($sufijo));

        return $prefijo.($parte === '' ? 'estudio' : $parte).$sufijo;
    }

    /**
     * @param  DatosRegistro  $datos
     */
    private function crear(array $datos, string $slug): Estudio
    {
        $driver = (string) config('agendauno.tenant_db_driver', 'sqlite');

        $dbDatabase = $driver === 'sqlite'
            ? $slug.'_'.Str::lower(Str::random(8)).'.sqlite'
            : self::nombreDeBase($slug);
        // Todo negocio tiene país (ADR 0103): México si no se dice otro.
        $pais = CatalogoPaises::codigo($datos['pais'] ?? null) ?: CatalogoPaises::PREDETERMINADO;
        $perfil = PerfilNegocio::from($datos['perfil_negocio'] ?? PerfilNegocio::General->value);

        $estudio = new Estudio([
            'nombre' => $datos['nombre'],
            'slug' => $slug,
            'perfil_negocio' => $perfil->value,
            'estado' => EstadoEstudio::Provisioning->value,
            'estado_facturacion' => EstadoFacturacion::Trial->value,
            // Por defecto el estudio aparece en el directorio en cuanto queda
            // operativo; el administrador puede optar por salirse (Configuracion).
            'publicado' => true,
            'privado' => false,
            'contacto_nombre' => $datos['contacto_nombre'],
            'contacto_segundo_nombre' => $datos['contacto_segundo_nombre'] ?? null,
            'contacto_primer_apellido' => $datos['contacto_primer_apellido'] ?? null,
            'contacto_segundo_apellido' => $datos['contacto_segundo_apellido'] ?? null,
            'contacto_email' => $datos['contacto_email'],
            // La lada del dueño: la que eligió o, si no, la de su país.
            'contacto_whatsapp_pais' => $datos['contacto_whatsapp_pais'] ?? CatalogoPaises::lada($pais) ?? '52',
            'contacto_telefono' => $datos['contacto_telefono'] ?? null,
            'pais' => $pais,
            'ciudad' => $datos['ciudad'] ?? null,
            'zona_horaria' => $datos['zona_horaria'] ?? 'America/Mexico_City',
            'db_driver' => $driver,
            'db_database' => $dbDatabase,
        ]);
        // Solo clases o solo citas (ADR 0104): el giro elegido da la modalidad, que
        // queda guardada; después solo la cambia el superadmin.
        $estudio->forceFill(['modalidad' => ModalidadServicio::paraPerfil($perfil)])->save();

        return $estudio;
    }
}
