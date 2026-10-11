<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Familias;

/**
 * Qué es el responsable del participante (B05). Sin figuras de un solo país: el negocio
 * decide qué documento pide para acreditarlo.
 */
enum Parentesco: string
{
    case MadrePadre = 'madre_padre';
    case TutorLegal = 'tutor_legal';
    case Familiar = 'familiar';
    case Autorizado = 'autorizado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::MadrePadre => 'Madre o padre',
            self::TutorLegal => 'Tutor legal',
            self::Familiar => 'Otro familiar',
            self::Autorizado => 'Persona autorizada',
        };
    }
}
