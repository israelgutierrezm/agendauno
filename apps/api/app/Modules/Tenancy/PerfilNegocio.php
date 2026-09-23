<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Perfil de negocio (industria) del estudio (R35). Un SOLO core configurable: el
 * perfil solo cambia DEFAULTS, TERMINOLOGIA y FEATURE-FLAGS; nunca hay forks ni
 * `if ($industria === ...)` en el dominio. El frontend adapta etiquetas/flags leyendo
 * la `configuracion()` que se expone en la sesion.
 */
enum PerfilNegocio: string
{
    case General = 'general';
    case Gimnasio = 'gimnasio';
    case Pilates = 'pilates';
    case Pole = 'pole';
    case Natacion = 'natacion';
    case Danza = 'danza';
    case Yoga = 'yoga';
    case Academia = 'academia';
    case Barberia = 'barberia';
    case Estetica = 'estetica';
    case Salon = 'salon';
    case Spa = 'spa';
    case Salud = 'salud';

    /**
     * Terminologia + feature-flags por defecto del perfil.
     *
     * @return array{terminologia: array<string, string>, flags: array<string, bool>}
     */
    public function configuracion(): array
    {
        return match ($this) {
            self::Natacion => [
                'terminologia' => ['sesion' => 'Lección', 'miembro' => 'Alumno', 'instructor' => 'Entrenador'],
                'flags' => ['grupos' => true, 'niveles' => true, 'acceso_abierto' => false],
            ],
            self::Danza, self::Academia => [
                'terminologia' => ['sesion' => 'Clase', 'miembro' => 'Alumno', 'instructor' => 'Maestro'],
                'flags' => ['grupos' => true, 'niveles' => true, 'acceso_abierto' => false],
            ],
            self::Pole => [
                'terminologia' => ['sesion' => 'Clase', 'miembro' => 'Alumna', 'instructor' => 'Coach'],
                'flags' => ['grupos' => false, 'niveles' => true, 'acceso_abierto' => false],
            ],
            self::Pilates, self::Yoga => [
                'terminologia' => ['sesion' => 'Sesión', 'miembro' => 'Alumno', 'instructor' => 'Instructor'],
                'flags' => ['grupos' => false, 'niveles' => false, 'acceso_abierto' => false],
            ],
            self::Gimnasio => [
                'terminologia' => ['sesion' => 'Clase', 'miembro' => 'Miembro', 'instructor' => 'Coach'],
                'flags' => ['grupos' => false, 'niveles' => false, 'acceso_abierto' => true],
            ],
            self::Barberia => [
                'terminologia' => ['sesion' => 'Cita', 'miembro' => 'Cliente', 'instructor' => 'Barbero'],
                'flags' => ['grupos' => false, 'niveles' => false, 'acceso_abierto' => false],
            ],
            self::Estetica, self::Salon => [
                'terminologia' => ['sesion' => 'Cita', 'miembro' => 'Cliente', 'instructor' => 'Profesional'],
                'flags' => ['grupos' => false, 'niveles' => false, 'acceso_abierto' => false],
            ],
            self::Spa => [
                'terminologia' => ['sesion' => 'Cita', 'miembro' => 'Cliente', 'instructor' => 'Terapeuta'],
                'flags' => ['grupos' => false, 'niveles' => false, 'acceso_abierto' => false],
            ],
            self::Salud => [
                'terminologia' => ['sesion' => 'Cita', 'miembro' => 'Paciente', 'instructor' => 'Profesional'],
                'flags' => ['grupos' => false, 'niveles' => false, 'acceso_abierto' => false],
            ],
            self::General => [
                'terminologia' => ['sesion' => 'Clase', 'miembro' => 'Miembro', 'instructor' => 'Instructor'],
                'flags' => ['grupos' => false, 'niveles' => false, 'acceso_abierto' => false],
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
