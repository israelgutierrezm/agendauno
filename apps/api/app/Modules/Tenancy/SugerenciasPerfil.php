<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Sugerencias de arranque por perfil de negocio (R35): con qué servicios o clases y
 * planes suele empezar un negocio de ese giro. Solo son valores iniciales que el dueño
 * ajusta en la configuración inicial (nombre, duración, precio o cupo); no cambian el
 * dominio ni hay lógica por giro.
 *
 * @phpstan-type Servicio array{nombre: string, duracion_minutos: int, precio_minor: int}
 * @phpstan-type Clase array{nombre: string, duracion_minutos: int, capacidad: int}
 * @phpstan-type Plan array{clave: string, nombre: string, precio_minor: int, clases: int|null}
 */
final class SugerenciasPerfil
{
    /**
     * @return array{servicios: list<Servicio>, clases: list<Clase>, planes: list<Plan>}
     */
    public static function para(PerfilNegocio $perfil): array
    {
        return [
            'servicios' => self::servicios($perfil),
            'clases' => self::clases($perfil),
            // Los planes de un negocio de clases (clase suelta, paquete y mensualidad).
            'planes' => [
                ['clave' => 'suelta', 'nombre' => 'Clase suelta', 'precio_minor' => 20000, 'clases' => 1],
                ['clave' => 'paquete', 'nombre' => 'Paquete 8 clases', 'precio_minor' => 120000, 'clases' => 8],
                ['clave' => 'mensualidad', 'nombre' => 'Mensualidad ilimitada', 'precio_minor' => 150000, 'clases' => null],
            ],
        ];
    }

    /**
     * @return list<Servicio>
     */
    private static function servicios(PerfilNegocio $perfil): array
    {
        $s = static fn (string $nombre, int $minutos, int $pesos): array => ['nombre' => $nombre, 'duracion_minutos' => $minutos, 'precio_minor' => $pesos * 100];

        return match ($perfil) {
            PerfilNegocio::Barberia => [$s('Corte de cabello', 30, 250), $s('Arreglo de barba', 30, 180), $s('Corte y barba', 60, 380)],
            PerfilNegocio::Salon => [$s('Corte de dama', 45, 350), $s('Tinte', 120, 900), $s('Peinado', 60, 450)],
            PerfilNegocio::Estetica => [$s('Limpieza facial', 60, 650), $s('Diseño de cejas', 30, 200), $s('Lifting de pestañas', 60, 600)],
            PerfilNegocio::Spa => [$s('Masaje relajante', 60, 800), $s('Masaje descontracturante', 60, 900), $s('Facial hidratante', 50, 700)],
            PerfilNegocio::Salud => [$s('Consulta', 50, 700), $s('Consulta de seguimiento', 30, 500)],
            PerfilNegocio::GeneralCitas => [$s('Servicio estándar', 60, 500), $s('Servicio rápido', 30, 300)],
            default => [$s('Sesión individual', 60, 500)],
        };
    }

    /**
     * @return list<Clase>
     */
    private static function clases(PerfilNegocio $perfil): array
    {
        $c = static fn (string $nombre, int $minutos, int $cupo): array => ['nombre' => $nombre, 'duracion_minutos' => $minutos, 'capacidad' => $cupo];

        return match ($perfil) {
            PerfilNegocio::Pole => [$c('Pole Nivel 1', 60, 8), $c('Pole Nivel 2', 60, 8), $c('Flexibilidad', 60, 10)],
            PerfilNegocio::Pilates => [$c('Pilates Mat', 50, 12), $c('Pilates Reformer', 50, 6)],
            PerfilNegocio::Yoga => [$c('Hatha Yoga', 60, 15), $c('Vinyasa', 60, 15)],
            PerfilNegocio::Danza => [$c('Ballet infantil', 60, 12), $c('Jazz', 60, 15)],
            PerfilNegocio::Natacion => [$c('Natación niños', 45, 6), $c('Natación adultos', 45, 8)],
            PerfilNegocio::Gimnasio => [$c('Funcional', 50, 15), $c('Spinning', 45, 20)],
            PerfilNegocio::Crossfit => [$c('WOD', 60, 15), $c('Open Box', 60, 20)],
            PerfilNegocio::Hyrox => [$c('Entrenamiento HYROX', 60, 16), $c('Simulacro HYROX', 90, 12)],
            default => [$c('Clase grupal', 60, 10)],
        };
    }
}
