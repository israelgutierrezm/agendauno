<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Cómo atiende el negocio a su gente, a nivel tenant. Define la experiencia (agenda,
 * terminología, opciones visibles) y el motor de cobro del SaaS:
 * - `Clases`: sesiones con cupo (pilates, pole, yoga, gym, natación…) → cobro por
 *   alumno activo.
 * - `Citas`: atención 1 a 1 con un profesional (barbería, salón, spa, salud…) →
 *   cobro por profesional activo.
 *
 * Se deriva del perfil de negocio (la industria solo elige el default; el dominio
 * decide por modalidad, nunca por `if ($industria === …)`).
 */
enum ModalidadServicio: string
{
    case Clases = 'clases';
    case Citas = 'citas';

    /**
     * Perfiles cuyo servicio principal es la cita 1 a 1 con un profesional.
     *
     * @var list<string>
     */
    private const PERFILES_CITAS = ['barberia', 'estetica', 'salon', 'spa', 'salud'];

    public static function paraPerfil(PerfilNegocio $perfil): self
    {
        return in_array($perfil->value, self::PERFILES_CITAS, true) ? self::Citas : self::Clases;
    }

    /**
     * Qué se mide para cobrar el SaaS en esta modalidad.
     */
    public function metrica(): string
    {
        return match ($this) {
            self::Clases => 'alumnos_activos',
            self::Citas => 'profesionales_activos',
        };
    }
}
