<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Familias;

/**
 * El texto base de la autorización del responsable (B05), mientras el negocio no
 * redacte el suyo. Es un punto de partida funcional: su redacción y su validez legal
 * (sobre todo con menores) requieren revisión profesional del negocio.
 */
final class TextoAutorizacion
{
    public const BASE = 'Como responsable, autorizo a este negocio a que yo gestione las reservas, '
        .'los planes, los pagos y los documentos de la persona a mi cargo, y a que me envíe los avisos '
        .'sobre ella. Declaro que tengo la facultad para hacerlo. Sé que puedo retirar esta autorización '
        .'en cualquier momento desde mi cuenta o en el negocio; al retirarla dejo de ver y de hacer '
        .'reservas por esa persona.';

    /** La huella de un texto: identifica exactamente lo que se aceptó. */
    public static function huella(string $texto): string
    {
        return hash('sha256', $texto);
    }
}
