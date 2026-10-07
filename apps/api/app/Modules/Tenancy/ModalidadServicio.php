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
 * Se guarda en el negocio (`estudios.modalidad`, ADR 0104) y es excluyente: el giro
 * solo da el valor inicial al registrarse y solo el superadmin la cambia, antes de
 * operar. El dominio decide por modalidad, nunca por `if ($industria === …)`.
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
    private const PERFILES_CITAS = ['barberia', 'estetica', 'salon', 'spa', 'salud', 'general_citas'];

    public static function paraPerfil(PerfilNegocio $perfil): self
    {
        return in_array($perfil->value, self::PERFILES_CITAS, true) ? self::Citas : self::Clases;
    }

    /**
     * Los giros de esta modalidad: los únicos que el negocio puede elegir (PUT /perfil).
     *
     * @return list<PerfilNegocio>
     */
    public function perfiles(): array
    {
        return array_values(array_filter(
            PerfilNegocio::cases(),
            fn (PerfilNegocio $perfil): bool => self::paraPerfil($perfil) === $this,
        ));
    }

    /**
     * El giro con que queda un negocio al que el superadmin le cambia la modalidad sin
     * elegir giro: el «otro negocio» de cada una, el de terminología más neutra.
     */
    public function perfilPredeterminado(): PerfilNegocio
    {
        return match ($this) {
            self::Clases => PerfilNegocio::General,
            self::Citas => PerfilNegocio::GeneralCitas,
        };
    }

    /**
     * Tipo de toda sesión de un negocio de esta modalidad: un negocio es solo de clases
     * o solo de citas, nunca de ambas (ADR 0104).
     */
    public function tipoSesion(): TipoSesionTenant
    {
        return match ($this) {
            self::Clases => TipoSesionTenant::Clase,
            self::Citas => TipoSesionTenant::Cita,
        };
    }

    /**
     * Lo que el negocio ofrece, para que la web y la app no lo deduzcan (ADR 0104).
     *
     * @return array{clases: bool, citas: bool}
     */
    public function capacidades(): array
    {
        return ['clases' => $this === self::Clases, 'citas' => $this === self::Citas];
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
