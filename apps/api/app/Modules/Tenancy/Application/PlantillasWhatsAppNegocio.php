<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaWhatsAppNegocio;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Las plantillas de WhatsApp de cada negocio (ADR 0190), para sus campañas.
 *
 * - El negocio escribe el texto con dos marcadores: {{persona_nombre}} (a quien le
 *   llega) y {{negocio}}, que es obligatorio: todo sale del número de la plataforma y
 *   quien lo recibe debe saber quién le escribe.
 * - Como pide Meta, ningún marcador abre ni cierra el texto y ninguno se repite. Para
 *   Meta se vuelven {{1}}, {{2}}… en orden, con un ejemplo cada uno.
 * - La plataforma la manda a aprobar en su cuenta de WhatsApp Business. Su estado sigue
 *   al de Meta: en revisión, aprobada, rechazada (con el motivo) o pausada.
 * - Rechazada se corrige y se vuelve a mandar con otro nombre (Meta no deja reusar uno
 *   rechazado). Archivada ya no se usa y se borra de Meta.
 * - Cada mensaje gasta del saldo de WhatsApp según su categoría: utilidad, uno;
 *   marketing, los que diga la plataforma (Meta los cobra más caros).
 */
class PlantillasWhatsAppNegocio
{
    public const MARCADORES = ['persona_nombre', 'negocio'];

    public const MAX_CUERPO = 1024;

    public function __construct(private readonly ClienteWhatsApp $whatsapp) {}

    /**
     * @return Collection<int, PlantillaWhatsAppNegocio>
     */
    public function delNegocio(Estudio $estudio): Collection
    {
        return PlantillaWhatsAppNegocio::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('estado', '!=', 'archivada')
            ->orderByDesc('id')
            ->get();
    }

    /** Una aprobada del negocio, por su id público; null si no la hay. */
    public function aprobada(Estudio $estudio, string $ulid): ?PlantillaWhatsAppNegocio
    {
        return PlantillaWhatsAppNegocio::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('ulid', $ulid)
            ->where('estado', 'aprobada')
            ->first();
    }

    /** ¿Este negocio puede escribir y mandar a aprobar plantillas? */
    public function disponible(Estudio $estudio): bool
    {
        return $this->whatsapp->activoPara($estudio) && $this->whatsapp->gestionaPlantillas();
    }

    /**
     * @param  array{titulo: string, categoria: string, cuerpo: string, pie?: string|null}  $datos
     */
    public function crear(Estudio $estudio, array $datos): PlantillaWhatsAppNegocio
    {
        $limpios = $this->validar($datos);

        return PlantillaWhatsAppNegocio::query()->create([
            ...$limpios,
            'estudio_id' => $estudio->getKey(),
            'estado' => 'borrador',
            'nombre_meta' => $this->nombreMeta($estudio),
        ]);
    }

    /**
     * Solo en borrador o rechazada; rechazada vuelve a borrador con otro nombre.
     *
     * @param  array{titulo: string, categoria: string, cuerpo: string, pie?: string|null}  $datos
     */
    public function actualizar(PlantillaWhatsAppNegocio $plantilla, array $datos): PlantillaWhatsAppNegocio
    {
        if (! $plantilla->editable()) {
            throw ValidationException::withMessages(['estado' => ['Ya se mandó a revisión: no se puede cambiar. Archívala y escribe otra.']]);
        }
        $estudio = $plantilla->estudio ?? throw new RuntimeException('La plantilla no tiene negocio.');
        $plantilla->fill([
            ...$this->validar($datos),
            'estado' => 'borrador',
            'motivo' => null,
            ...($plantilla->estado === 'rechazada' ? ['nombre_meta' => $this->nombreMeta($estudio), 'id_meta' => null] : []),
        ])->save();

        return $plantilla;
    }

    /** La manda a aprobar a Meta. */
    public function enviar(PlantillaWhatsAppNegocio $plantilla): PlantillaWhatsAppNegocio
    {
        $estudio = $plantilla->estudio ?? throw new RuntimeException('La plantilla no tiene negocio.');
        if ($plantilla->estado !== 'borrador') {
            throw ValidationException::withMessages(['estado' => ['Solo se manda a revisión un borrador.']]);
        }
        if (! $this->disponible($estudio)) {
            throw ValidationException::withMessages(['estado' => ['La plataforma aún no puede mandar plantillas a revisión.']]);
        }

        ['cuerpo' => $cuerpo, 'marcadores' => $marcadores] = self::paraMeta($plantilla->cuerpo);
        $ejemplos = array_map(fn (string $m): string => $m === 'negocio' ? (string) $estudio->nombre : 'Ana', $marcadores);
        try {
            $id = $this->whatsapp->crearPlantilla($plantilla->nombre_meta, $plantilla->categoria, $cuerpo, $ejemplos, $plantilla->pie);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['estado' => ['Meta no la recibió: '.$e->getMessage()]]);
        }

        $plantilla->forceFill(['estado' => 'en_revision', 'id_meta' => $id !== '' ? $id : null, 'enviada_en' => now()])->save();

        return $plantilla;
    }

    /** Pregunta a Meta cómo va. Sin respuesta, queda como estaba. */
    public function sincronizar(PlantillaWhatsAppNegocio $plantilla): PlantillaWhatsAppNegocio
    {
        if (! in_array($plantilla->estado, ['en_revision', 'aprobada', 'pausada'], true) || ! $this->whatsapp->gestionaPlantillas()) {
            return $plantilla;
        }
        $meta = $this->whatsapp->estadoPlantilla($plantilla->nombre_meta);
        if ($meta === null) {
            return $plantilla;
        }
        [$estado, $motivo] = match ($meta['estado']) {
            'APPROVED' => ['aprobada', null],
            'REJECTED' => ['rechazada', $meta['motivo'] ?? 'Meta no la aprobó.'],
            'DISABLED' => ['rechazada', 'Meta la desactivó por su calidad.'],
            'PAUSED' => ['pausada', 'Meta la pausó por su calidad; se reanuda sola.'],
            default => [$plantilla->estado, $plantilla->motivo],
        };
        $plantilla->forceFill(['estado' => $estado, 'motivo' => $motivo, 'revisada_en' => now()])->save();

        return $plantilla;
    }

    /** Ya no se usa: se archiva y, si llegó a Meta, se borra de allá. */
    public function archivar(PlantillaWhatsAppNegocio $plantilla): void
    {
        $enMeta = $plantilla->estado !== 'borrador';
        $plantilla->forceFill(['estado' => 'archivada'])->save();
        if ($enMeta && $this->whatsapp->gestionaPlantillas()) {
            try {
                $this->whatsapp->eliminarPlantilla($plantilla->nombre_meta);
            } catch (RuntimeException $e) {
                report($e);
            }
        }
    }

    /**
     * Los valores de sus marcadores para una persona, en el orden de Meta.
     *
     * @return list<string>
     */
    public function valores(PlantillaWhatsAppNegocio $plantilla, Estudio $estudio, PersonaTenant $persona): array
    {
        $nombre = trim((string) $persona->nombre);

        return array_map(
            static fn (string $m): string => $m === 'negocio' ? (string) $estudio->nombre : ($nombre !== '' ? $nombre : 'hola'),
            self::paraMeta($plantilla->cuerpo)['marcadores'],
        );
    }

    /**
     * El texto con {{1}}, {{2}}… y qué marcador es cada uno.
     *
     * @return array{cuerpo: string, marcadores: list<string>}
     */
    public static function paraMeta(string $cuerpo): array
    {
        $marcadores = [];
        $convertido = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use (&$marcadores): string {
            $marcadores[] = $m[1];

            return '{{'.count($marcadores).'}}';
        }, $cuerpo);

        return ['cuerpo' => $convertido, 'marcadores' => $marcadores];
    }

    /**
     * @param  array{titulo: string, categoria: string, cuerpo: string, pie?: string|null}  $datos
     * @return array{titulo: string, categoria: string, cuerpo: string, pie: string|null}
     */
    private function validar(array $datos): array
    {
        $cuerpo = trim(str_replace("\r\n", "\n", $datos['cuerpo']));
        $errores = [];
        preg_match_all('/\{\{\s*([^}]*)\s*\}\}/', $cuerpo, $encontrados);
        $usados = array_map('trim', $encontrados[1]);
        $ajenos = array_diff($usados, self::MARCADORES);
        if ($ajenos !== []) {
            $errores[] = 'Solo se pueden usar {{persona_nombre}} y {{negocio}}.';
        }
        if (! in_array('negocio', $usados, true)) {
            $errores[] = 'Incluye {{negocio}}: quien lo recibe debe saber quién le escribe.';
        }
        if (count($usados) !== count(array_unique($usados))) {
            $errores[] = 'Cada marcador va una sola vez.';
        }
        if (preg_match('/^\{\{|\}\}[.!?…]?$/u', $cuerpo) === 1) {
            $errores[] = 'El texto no puede empezar ni terminar con un marcador.';
        }
        if (mb_strlen($cuerpo) > self::MAX_CUERPO) {
            $errores[] = 'Caben hasta '.self::MAX_CUERPO.' caracteres.';
        }
        if ($errores !== []) {
            throw ValidationException::withMessages(['cuerpo' => $errores]);
        }
        $pie = trim((string) ($datos['pie'] ?? ''));
        if (str_contains($pie, '{{')) {
            throw ValidationException::withMessages(['pie' => ['El pie no lleva marcadores.']]);
        }

        return [
            'titulo' => trim($datos['titulo']),
            'categoria' => $datos['categoria'],
            'cuerpo' => $cuerpo,
            'pie' => $pie !== '' ? $pie : null,
        ];
    }

    /** Nombre en Meta: minúsculas, números y guion bajo, único y sin reusar. */
    private function nombreMeta(Estudio $estudio): string
    {
        return 'n'.$estudio->getKey().'_'.strtolower((string) Str::ulid());
    }
}
