<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\AceptacionWaiverTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\WaiverTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Waivers / consentimientos versionados tenant-local (R27): publica versiones (cada
 * publicacion incrementa la version y sella su hash), resuelve la version vigente por
 * clave, calcula los pendientes de una persona (re-aceptacion al haber version nueva)
 * y registra aceptaciones selladas. Opera sobre la BD del tenant resuelto.
 */
class WaiversTenant
{
    /**
     * Clave reservada del aviso de privacidad del negocio para sus clientes: además
     * de firmarse en el portal como cualquier consentimiento, se publica en su página
     * y en el formulario para agendar sin cuenta.
     */
    public const AVISO_PRIVACIDAD = 'aviso-privacidad';

    /** La versión del aviso base (sin publicar) que se genera al dar de alta el negocio. */
    public const VERSION_BASE = 0;

    /** La versión vigente del aviso de privacidad del negocio, o null. */
    public function avisoPrivacidad(): ?WaiverTenant
    {
        return WaiverTenant::query()
            ->where('clave', self::AVISO_PRIVACIDAD)
            ->where('activo', true)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * El aviso base con los datos del negocio, para que lo revise y lo publique (ADR 0234).
     * Es la versión 0, sin activar: nadie lo ve ni lo firma; publicar crea la versión 1.
     */
    public function generarAvisoBase(Estudio $estudio): WaiverTenant
    {
        $citas = $estudio->modalidad() === ModalidadServicio::Citas;
        $producto = $estudio->producto();
        $texto = strtr((string) file_get_contents(resource_path('legales/aviso-negocio.txt')), [
            '{negocio}' => (string) $estudio->nombre,
            '{servicios}' => $citas ? 'citas' : 'clases',
            '{planes}' => $citas ? 'bonos' : 'membresías, paquetes',
            '[CORREO DE CONTACTO]' => (string) $estudio->contacto_email,
            'AgendaUno' => $producto->nombre(),
            'agendauno.mx' => $producto->dominio(),
        ]);

        return WaiverTenant::query()->firstOrCreate(
            ['clave' => self::AVISO_PRIVACIDAD, 'version' => self::VERSION_BASE],
            ['titulo' => 'Aviso de privacidad', 'contenido' => $texto, 'hash' => hash('sha256', $texto), 'activo' => false],
        );
    }

    /** El aviso base, mientras el negocio no publique uno. */
    public function avisoBase(): ?WaiverTenant
    {
        if ($this->avisoPrivacidad() !== null) {
            return null;
        }

        return WaiverTenant::query()
            ->where('clave', self::AVISO_PRIVACIDAD)
            ->where('version', self::VERSION_BASE)
            ->first();
    }

    public function publicar(string $clave, string $titulo, string $contenido): WaiverTenant
    {
        $ultima = (int) WaiverTenant::query()->where('clave', $clave)->max('version');

        return WaiverTenant::query()->create([
            'clave' => $clave,
            'titulo' => $titulo,
            'contenido' => $contenido,
            'version' => $ultima + 1,
            'hash' => hash('sha256', $contenido),
            'activo' => true,
        ]);
    }

    /**
     * La version vigente (mayor) y activa de cada clave.
     *
     * @return Collection<int, WaiverTenant>
     */
    public function vigentes(): Collection
    {
        return WaiverTenant::query()
            ->where('activo', true)
            ->orderByDesc('version')
            ->get()
            ->groupBy('clave')
            ->map(fn (Collection $porClave): WaiverTenant => $porClave->first())
            ->values();
    }

    /**
     * Waivers vigentes que la persona AUN no ha aceptado (incluye re-aceptacion: si se
     * publico una version nueva, su aceptacion previa era de otra version).
     *
     * @return Collection<int, WaiverTenant>
     */
    public function pendientesDe(PersonaTenant $persona): Collection
    {
        $aceptados = AceptacionWaiverTenant::query()
            ->where('persona_id', $persona->getKey())
            ->pluck('waiver_id')
            ->flip();

        return $this->vigentes()
            ->reject(fn (WaiverTenant $w): bool => $aceptados->has($w->getKey()))
            ->values();
    }

    public function aceptar(PersonaTenant $persona, WaiverTenant $waiver, ?string $ip): AceptacionWaiverTenant
    {
        return AceptacionWaiverTenant::query()->firstOrCreate(
            ['persona_id' => $persona->getKey(), 'waiver_id' => $waiver->getKey()],
            ['aceptado_en' => Carbon::now(), 'ip' => $ip, 'hash' => $waiver->hash],
        );
    }
}
