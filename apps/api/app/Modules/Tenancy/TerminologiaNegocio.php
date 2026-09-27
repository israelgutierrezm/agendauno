<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use Illuminate\Validation\ValidationException;

/**
 * Cómo se llaman las cosas en cada negocio (ADR 0049): lo que se reserva (clase,
 * cita…), quien lo toma (alumno, cliente…) y quien lo imparte (instructor, barbero…).
 *
 * El perfil del giro trae los valores iniciales; el negocio (o el superadmin) puede
 * elegir otros, pero solo de estas listas: así la interfaz puede adaptar sus textos
 * sin romper la gramática (todo lo que se reserva es femenino, como "clase"; quien
 * imparte es masculino o de género común, como "instructor").
 */
final class TerminologiaNegocio
{
    /** Término => [singular => plural]. */
    public const OPCIONES = [
        'sesion' => [
            'Clase' => 'Clases', 'Cita' => 'Citas', 'Sesión' => 'Sesiones',
            'Lección' => 'Lecciones', 'Consulta' => 'Consultas',
        ],
        'miembro' => [
            'Alumno' => 'Alumnos', 'Alumna' => 'Alumnas', 'Cliente' => 'Clientes',
            'Paciente' => 'Pacientes', 'Miembro' => 'Miembros', 'Socio' => 'Socios',
        ],
        'instructor' => [
            'Instructor' => 'Instructores', 'Coach' => 'Coaches', 'Maestro' => 'Maestros',
            'Entrenador' => 'Entrenadores', 'Profesional' => 'Profesionales', 'Barbero' => 'Barberos',
            'Estilista' => 'Estilistas', 'Terapeuta' => 'Terapeutas', 'Especialista' => 'Especialistas',
        ],
    ];

    /** Plural de cada término en la terminología completa. */
    private const PLURALES = ['sesion' => 'sesiones', 'miembro' => 'miembros', 'instructor' => 'instructores'];

    /**
     * La terminología que usa el negocio: la del perfil con lo que el negocio eligió
     * encima, más los plurales.
     *
     * @param  array<string, string>  $perfil
     * @param  array<string, mixed>|null  $propia
     * @return array<string, string>
     */
    public static function completa(array $perfil, ?array $propia): array
    {
        $terminos = [];
        foreach (self::OPCIONES as $clave => $opciones) {
            $elegido = $propia[$clave] ?? null;
            $valor = is_string($elegido) && isset($opciones[$elegido]) ? $elegido : ($perfil[$clave] ?? array_key_first($opciones));
            $terminos[$clave] = $valor;
            $terminos[self::PLURALES[$clave]] = $opciones[$valor] ?? $valor.'s';
        }

        return $terminos;
    }

    /**
     * Valida lo que elige el negocio: solo términos conocidos y de sus listas; vacío o
     * null = vuelve al del perfil.
     *
     * @param  array<string, mixed>  $valores
     * @return array<string, string>
     */
    public static function validar(array $valores): array
    {
        $limpios = [];
        $errores = [];
        foreach ($valores as $clave => $valor) {
            if (! isset(self::OPCIONES[$clave])) {
                $errores[$clave] = ['Ese término no se puede cambiar.'];

                continue;
            }
            if ($valor === null || $valor === '') {
                continue;
            }
            if (! is_string($valor) || ! isset(self::OPCIONES[$clave][$valor])) {
                $errores[$clave] = ['Elige una de las opciones.'];

                continue;
            }
            $limpios[$clave] = $valor;
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $limpios;
    }

    /**
     * Opciones para la pantalla: por término, las elegibles.
     *
     * @return array<string, list<string>>
     */
    public static function opciones(): array
    {
        return array_map(static fn (array $o): array => array_keys($o), self::OPCIONES);
    }
}
