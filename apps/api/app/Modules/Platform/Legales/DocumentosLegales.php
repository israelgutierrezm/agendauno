<?php

declare(strict_types=1);

namespace App\Modules\Platform\Legales;

use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Aviso de privacidad y términos de la plataforma: borrador y versiones publicadas.
 *
 * - El superadmin edita el BORRADOR (texto y datos del responsable). Guardar el
 *   borrador no cambia lo que ven los usuarios.
 * - PUBLICAR crea una versión nueva, inmutable y con fecha. En el aviso, los datos
 *   del responsable son obligatorios y reemplazan `{responsable}`, `{domicilio}`,
 *   `{contacto}` y `{area}` del texto. No se publica un texto que aún tenga
 *   marcadores del borrador entre corchetes (p. ej. `[NOMBRE COMPLETO…]`).
 * - Al registrarse, un negocio acepta las versiones vigentes y queda constancia
 *   (versión, fecha, IP y navegador).
 */
class DocumentosLegales
{
    /** Claves del borrador en la configuración de la plataforma. */
    private const BORRADOR = [
        DocumentoLegal::AVISO => 'aviso_privacidad',
        DocumentoLegal::TERMINOS => 'terminos',
    ];

    private const RESPONSABLE = 'aviso_responsable';

    /** Marcadores del borrador que aún no se llenan, p. ej. [NOMBRE COMPLETO…]. */
    private const MARCADOR = '/\[[A-ZÁÉÍÓÚÑÜ0-9 ,.;:()\/\-]{4,}\]/u';

    /**
     * @return array{aviso_privacidad: string, terminos: string, responsable: array{nombre: string, domicilio: string, contacto: string, area: string}}
     */
    public function borrador(): array
    {
        $responsable = json_decode((string) ConfiguracionPlataforma::obtener(self::RESPONSABLE), true);
        $responsable = is_array($responsable) ? $responsable : [];

        return [
            'aviso_privacidad' => (string) ConfiguracionPlataforma::obtener(self::BORRADOR[DocumentoLegal::AVISO]),
            'terminos' => (string) ConfiguracionPlataforma::obtener(self::BORRADOR[DocumentoLegal::TERMINOS]),
            'responsable' => [
                'nombre' => (string) ($responsable['nombre'] ?? ''),
                'domicilio' => (string) ($responsable['domicilio'] ?? ''),
                'contacto' => (string) ($responsable['contacto'] ?? ''),
                'area' => (string) ($responsable['area'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array{aviso_privacidad?: string|null, terminos?: string|null, responsable?: array<string, string|null>|null}  $datos
     */
    public function guardarBorrador(array $datos): void
    {
        if (array_key_exists('aviso_privacidad', $datos)) {
            ConfiguracionPlataforma::establecer(self::BORRADOR[DocumentoLegal::AVISO], $datos['aviso_privacidad']);
        }
        if (array_key_exists('terminos', $datos)) {
            ConfiguracionPlataforma::establecer(self::BORRADOR[DocumentoLegal::TERMINOS], $datos['terminos']);
        }
        if (array_key_exists('responsable', $datos)) {
            $r = $datos['responsable'] ?? [];
            ConfiguracionPlataforma::establecer(self::RESPONSABLE, (string) json_encode([
                'nombre' => trim((string) ($r['nombre'] ?? '')),
                'domicilio' => trim((string) ($r['domicilio'] ?? '')),
                'contacto' => trim((string) ($r['contacto'] ?? '')),
                'area' => trim((string) ($r['area'] ?? '')),
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Publica el borrador como la versión siguiente del documento.
     */
    public function publicar(string $tipo): DocumentoLegal
    {
        if (! array_key_exists($tipo, self::BORRADOR)) {
            throw ValidationException::withMessages(['tipo' => 'Documento desconocido.']);
        }
        $borrador = $this->borrador();
        $texto = trim($borrador[$tipo]);
        $responsable = null;

        if ($tipo === DocumentoLegal::AVISO) {
            $responsable = $borrador['responsable'];
            $faltan = [];
            if ($responsable['nombre'] === '') {
                $faltan[] = 'el nombre o razón social del responsable';
            }
            if ($responsable['domicilio'] === '') {
                $faltan[] = 'su domicilio';
            }
            if (filter_var($responsable['contacto'], FILTER_VALIDATE_EMAIL) === false) {
                $faltan[] = 'un correo válido para privacidad y derechos ARCO';
            }
            if ($faltan !== []) {
                throw ValidationException::withMessages(['responsable' => 'Para publicar el aviso falta '.implode(', ', $faltan).'.']);
            }
            $texto = strtr($texto, [
                '{responsable}' => $responsable['nombre'],
                '{domicilio}' => $responsable['domicilio'],
                '{contacto}' => $responsable['contacto'],
                '{area}' => $responsable['area'] !== '' ? $responsable['area'] : $responsable['nombre'],
            ]);
        }

        if ($texto === '') {
            throw ValidationException::withMessages([$tipo => 'El documento está vacío.']);
        }
        if (preg_match_all(self::MARCADOR, $texto, $m) > 0) {
            $ejemplos = implode(', ', array_slice(array_unique($m[0]), 0, 3));
            throw ValidationException::withMessages([$tipo => "Aún hay campos por llenar entre corchetes: {$ejemplos}."]);
        }

        return DB::transaction(function () use ($tipo, $texto, $responsable): DocumentoLegal {
            $version = (int) DocumentoLegal::query()->where('tipo', $tipo)->lockForUpdate()->max('version') + 1;

            return DocumentoLegal::query()->create([
                'tipo' => $tipo,
                'version' => $version,
                'contenido' => $texto,
                'responsable' => $responsable,
                'vigente_desde' => CarbonImmutable::now(),
            ]);
        });
    }

    /** La versión vigente (la más reciente publicada), o null si nunca se publicó. */
    public function vigente(string $tipo): ?DocumentoLegal
    {
        try {
            return DocumentoLegal::query()->where('tipo', $tipo)->orderByDesc('version')->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Deja constancia de que el negocio aceptó las versiones vigentes.
     */
    public function registrarAceptacion(Estudio $estudio, string $email, ?string $ip, ?string $navegador): void
    {
        foreach ([DocumentoLegal::AVISO, DocumentoLegal::TERMINOS] as $tipo) {
            $vigente = $this->vigente($tipo);
            if ($vigente === null) {
                continue;
            }
            DB::table('aceptaciones_legales')->insert([
                'estudio_id' => $estudio->getKey(),
                'email' => $email,
                'tipo' => $tipo,
                'version' => $vigente->version,
                'aceptado_en' => CarbonImmutable::now(),
                'ip' => $ip,
                'navegador' => $navegador !== null ? Str::limit($navegador, 250, '') : null,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
        }
    }
}
