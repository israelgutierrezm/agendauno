<?php

declare(strict_types=1);

namespace App\Console\Commands\Demos;

/**
 * Nombres y apellidos comunes en México para poblar los demos con personas creíbles
 * (ninguna real: las combinaciones salen al azar con semilla fija).
 */
final class NombresDemo
{
    public const HOMBRES = [
        'José', 'Juan', 'Luis', 'Carlos', 'Jorge', 'Miguel', 'Alejandro', 'Fernando', 'Ricardo', 'Eduardo',
        'Roberto', 'Daniel', 'Javier', 'Sergio', 'Arturo', 'Raúl', 'Óscar', 'Héctor', 'Manuel', 'Francisco',
        'Diego', 'Andrés', 'Rodrigo', 'Emiliano', 'Santiago', 'Mateo', 'Sebastián', 'Gerardo', 'Alberto', 'Ángel',
        'Iván', 'Pablo', 'Martín', 'Hugo', 'Enrique', 'Rafael', 'Gustavo', 'Adrián', 'Mauricio', 'Bruno',
        'Leonardo', 'Esteban', 'Ernesto', 'Julio', 'Ramiro', 'Saúl', 'Omar', 'Israel', 'Tomás', 'Joaquín',
    ];

    public const MUJERES = [
        'María', 'Ana', 'Sofía', 'Valeria', 'Camila', 'Daniela', 'Fernanda', 'Mariana', 'Gabriela', 'Andrea',
        'Paola', 'Alejandra', 'Karla', 'Jimena', 'Regina', 'Renata', 'Lucía', 'Natalia', 'Ximena', 'Valentina',
        'Montserrat', 'Diana', 'Carolina', 'Paulina', 'Lorena', 'Mónica', 'Claudia', 'Verónica', 'Adriana', 'Itzel',
        'Abril', 'Frida', 'Aranza', 'Elena', 'Isabel', 'Rebeca', 'Tania', 'Brenda', 'Melissa', 'Nayeli',
        'Ivonne', 'Estefanía', 'Michelle', 'Vanessa', 'Ariadna', 'Lizbeth', 'Mayra', 'Yesenia', 'Pamela', 'Sara',
    ];

    public const APELLIDOS = [
        'Hernández', 'García', 'Martínez', 'López', 'González', 'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz',
        'Flores', 'Gómez', 'Morales', 'Vázquez', 'Reyes', 'Jiménez', 'Torres', 'Díaz', 'Gutiérrez', 'Ruiz',
        'Mendoza', 'Aguilar', 'Ortiz', 'Moreno', 'Castillo', 'Romero', 'Álvarez', 'Méndez', 'Chávez', 'Rivera',
        'Juárez', 'Ramos', 'Domínguez', 'Herrera', 'Medina', 'Castro', 'Vargas', 'Guzmán', 'Velázquez', 'Muñoz',
        'Rojas', 'Contreras', 'Salazar', 'Luna', 'Ortega', 'Guerrero', 'Estrada', 'Bautista', 'Cortés', 'Soto',
        'Delgado', 'Navarro', 'Ríos', 'Pineda', 'Cervantes', 'Lozano', 'Trejo', 'Solís', 'Ibarra', 'Valdez',
    ];

    /** Para los correos: sin acentos ni eñes. */
    public static function ascii(string $texto): string
    {
        return strtolower(strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', ' ' => '',
        ]));
    }
}
