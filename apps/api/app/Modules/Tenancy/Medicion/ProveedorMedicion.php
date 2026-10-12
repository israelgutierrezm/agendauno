<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

/**
 * A dónde se manda la medición (ADR 0143): Google Analytics 4 (etiqueta gtag en el
 * navegador y Measurement Protocol en el servidor) o Meta (píxel en el navegador y
 * Conversions API en el servidor).
 */
enum ProveedorMedicion: string
{
    case Ga4 = 'ga4';
    case Meta = 'meta';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ga4 => 'Google Analytics 4',
            self::Meta => 'Meta (Facebook e Instagram)',
        };
    }

    /** El ID público: `G-XXXXXXX` (GA4) o el número del píxel o dataset (Meta). */
    public function identificadorValido(string $valor): bool
    {
        return match ($this) {
            self::Ga4 => preg_match('/^G-[A-Z0-9]{4,20}$/', $valor) === 1,
            self::Meta => preg_match('/^\d{6,20}$/', $valor) === 1,
        };
    }

    /** El secreto del servidor: el «API secret» de GA4 o el token de acceso de Meta. */
    public function secretoValido(string $valor): bool
    {
        return match ($this) {
            self::Ga4 => preg_match('/^[A-Za-z0-9_-]{8,100}$/', $valor) === 1,
            self::Meta => preg_match('/^[A-Za-z0-9_|-]{20,1000}$/', $valor) === 1,
        };
    }

    /**
     * Cuánto después de ocurrir se puede mandar un evento: GA4 lo acepta hasta 72 h
     * después (se manda dentro de 48 h para que se una a la sesión); Meta, hasta 7 días.
     */
    public function horasMaximas(): int
    {
        return match ($this) {
            self::Ga4 => 72,
            self::Meta => 24 * 7,
        };
    }
}
