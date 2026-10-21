<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

/**
 * Fitpass (ADR 0197 y 0202): no publica una API para validar visitas. Recepción revisa
 * la reserva en Fitpass Admin y la identificación oficial de la persona (lo piden sus
 * términos), y aquí registra el ID de la reservación: se acepta tal cual (la
 * responsabilidad es de quien la registra, que queda en la bitácora) y sirve de
 * referencia para no contarla dos veces y para conciliarla.
 */
class ValidadorFitpass implements ValidadorPartner
{
    public function nombre(): string
    {
        return 'fitpass';
    }

    public function validar(string $codigo, array $credenciales): ResultadoCheckin
    {
        $folio = strtoupper(trim((string) preg_replace('/\s+/', '', $codigo)));
        if ($folio === '' || strlen($folio) > 64 || preg_match('/^[A-Z0-9_\-]+$/', $folio) !== 1) {
            return ResultadoCheckin::invalido('Escribe el ID de la reservación que aparece en Fitpass Admin.');
        }

        return ResultadoCheckin::valido($folio);
    }
}
