<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Exceptions\SlugNoDisponible;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Crea el registro central de un estudio en estado `provisioning`. El slug se
 * reserva mediante el índice único (`estudios.slug`): dos registros concurrentes
 * con el mismo slug no pueden coexistir; el perdedor recibe SLUG_TAKEN. No crea
 * la BD del tenant (eso lo hace {@see AprovisionarEstudio}).
 *
 * @phpstan-type DatosRegistro array{nombre: string, slug: string, perfil_negocio?: string|null, contacto_nombre: string, contacto_segundo_nombre?: string|null, contacto_primer_apellido?: string|null, contacto_segundo_apellido?: string|null, contacto_email: string, contacto_whatsapp_pais?: string|null, contacto_telefono?: string|null, pais?: string|null, ciudad?: string|null, zona_horaria?: string|null}
 */
class RegistrarEstudio
{
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
                return $this->crear($datos, $slug.'-'.Str::lower(Str::random(4)));
            }

            throw new SlugNoDisponible('El slug ya está en uso.');
        }
    }

    /**
     * Deriva un slug único desde el nombre del estudio. En colisión agrega un sufijo
     * numérico; el índice único de `estudios.slug` cierra la carrera concurrente.
     */
    private function generarSlugUnico(string $nombre): string
    {
        $base = Str::slug($nombre);
        if ($base === '') {
            $base = 'estudio';
        }

        $candidato = $base;
        $intento = 1;
        while (Estudio::query()->where('slug', $candidato)->exists()) {
            $intento++;
            $candidato = $base.'-'.$intento;
        }

        return $candidato;
    }

    /**
     * @param  DatosRegistro  $datos
     */
    private function crear(array $datos, string $slug): Estudio
    {
        $driver = (string) config('agendauno.tenant_db_driver', 'sqlite');

        $dbDatabase = $driver === 'sqlite'
            ? $slug.'_'.Str::lower(Str::random(8)).'.sqlite'
            : 'tenant_'.str_replace('-', '_', $slug).'_'.Str::lower(Str::random(8));

        return Estudio::create([
            'nombre' => $datos['nombre'],
            'slug' => $slug,
            'perfil_negocio' => $datos['perfil_negocio'] ?? 'general',
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
            'contacto_whatsapp_pais' => $datos['contacto_whatsapp_pais'] ?? '52',
            'contacto_telefono' => $datos['contacto_telefono'] ?? null,
            'pais' => $datos['pais'] ?? null,
            'ciudad' => $datos['ciudad'] ?? null,
            'zona_horaria' => $datos['zona_horaria'] ?? 'America/Mexico_City',
            'db_driver' => $driver,
            'db_database' => $dbDatabase,
        ]);
    }
}
