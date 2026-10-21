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
 * @phpstan-type Plan array{clave: string, tipo: string, nombre: string, precio_minor: int, clases: int|null, primera_vez: bool}
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
            'planes' => self::planes($perfil),
        ];
    }

    /**
     * El catálogo de arranque de un negocio de clases (comparativo del 2026-10-20): la
     * clase muestra para quien viene por primera vez, la clase suelta, los paquetes
     * típicos del giro (más grandes, más baratos por clase) y la mensualidad. Precios de
     * referencia de estudios reales en México; el dueño los ajusta.
     *
     * @return list<Plan>
     */
    private static function planes(PerfilNegocio $perfil): array
    {
        $muestra = static fn (string $nombre, int $pesos): array => ['clave' => 'muestra', 'tipo' => 'muestra', 'nombre' => $nombre, 'precio_minor' => $pesos * 100, 'clases' => 1, 'primera_vez' => true];
        $suelta = static fn (int $pesos): array => ['clave' => 'suelta', 'tipo' => 'suelta', 'nombre' => 'Clase suelta', 'precio_minor' => $pesos * 100, 'clases' => 1, 'primera_vez' => false];
        // El primer paquete conserva la clave `paquete`; los demás, `paquete_{n}`.
        $paquetes = static fn (array $porClases): array => array_map(
            static fn (int $clases, int $pesos, int $i): array => [
                'clave' => $i === 0 ? 'paquete' : "paquete_{$clases}",
                'tipo' => 'paquete',
                'nombre' => "Paquete {$clases} clases",
                'precio_minor' => $pesos * 100,
                'clases' => $clases,
                'primera_vez' => false,
            ],
            array_keys($porClases),
            array_values($porClases),
            array_keys(array_values($porClases)),
        );
        $mensual = static fn (int $pesos, string $nombre = 'Mensualidad ilimitada'): array => ['clave' => 'mensualidad', 'tipo' => 'mensualidad', 'nombre' => $nombre, 'precio_minor' => $pesos * 100, 'clases' => null, 'primera_vez' => false];

        return match ($perfil) {
            PerfilNegocio::Pole => [$muestra('Clase muestra', 200), $suelta(280), ...$paquetes([4 => 900, 8 => 1600, 12 => 2200]), $mensual(2600)],
            PerfilNegocio::Pilates => [$muestra('Clase muestra', 150), $suelta(350), ...$paquetes([5 => 1500, 10 => 2800, 20 => 5000]), $mensual(4000)],
            PerfilNegocio::Cycling => [$muestra('Primera rodada', 120), $suelta(250), ...$paquetes([5 => 1100, 10 => 2000, 20 => 3600]), $mensual(2900)],
            PerfilNegocio::Yoga => [$muestra('Clase muestra', 100), $suelta(200), ...$paquetes([8 => 1200, 12 => 1600]), $mensual(1500)],
            PerfilNegocio::Danza => [$muestra('Clase muestra', 100), $suelta(180), ...$paquetes([8 => 1100]), $mensual(1300, 'Mensualidad')],
            PerfilNegocio::Natacion => [$muestra('Clase muestra', 150), $suelta(250), ...$paquetes([8 => 1600]), $mensual(1800, 'Mensualidad')],
            PerfilNegocio::Gimnasio => [$muestra('Pase de un día', 150), ...$paquetes([10 => 1100]), $mensual(900, 'Mensualidad')],
            PerfilNegocio::Crossfit => [$muestra('Clase de prueba', 200), $suelta(250), ...$paquetes([12 => 2000]), $mensual(2200)],
            PerfilNegocio::Hyrox => [$muestra('Pase de un día', 400), ...$paquetes([8 => 2000, 16 => 3400]), $mensual(2800)],
            default => [$muestra('Clase muestra', 150), $suelta(200), ...$paquetes([8 => 1200]), $mensual(1500)],
        };
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
            PerfilNegocio::Cycling => [$c('Indoor cycling', 45, 20), $c('Cycling + fuerza', 50, 16)],
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
