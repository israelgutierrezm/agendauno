<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Models\ConfiguracionPlataforma;

/**
 * Plataformas de bienestar corporativo con las que un estudio puede integrarse
 * para aceptar check-ins de sus usuarios (la plataforma cubre la clase).
 *
 * Fitpass (ADR 0197) no publica una API para validar visitas: recepción la valida en la
 * app de socios de Fitpass y la registra aquí a mano; ocupa su lugar y se concilia con
 * su reporte de pagos, como las demás.
 */
enum ProveedorPartner: string
{
    case Wellhub = 'wellhub';
    case TotalPass = 'totalpass';
    case Fitpass = 'fitpass';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    /** Su nombre, como lo escribe la plataforma. */
    public function nombre(): string
    {
        return match ($this) {
            self::Wellhub => 'Wellhub',
            self::TotalPass => 'TotalPass',
            self::Fitpass => 'Fitpass',
        };
    }

    /** Con qué se ofrece una clase en ella: el producto de Wellhub o el plan de TotalPass. */
    public function producto(): string
    {
        return match ($this) {
            self::Wellhub => 'producto',
            self::TotalPass, self::Fitpass => 'plan',
        };
    }

    /**
     * Las credenciales que pide cada una (las da el proveedor al negocio): Wellhub,
     * su token de API, su Gym ID y, si Wellhub le dio una propia, la llave secreta con
     * que firma sus avisos; TotalPass, su llave de API, el código de su gimnasio, el del
     * plan si tiene varios y la llave de su sede para el check-in automático (ADR 0148).
     *
     * @return list<string>
     */
    public function credenciales(): array
    {
        return match ($this) {
            self::Wellhub => ['api_key', 'gym_id', 'webhook_secret'],
            self::TotalPass => ['api_key', 'codigo_gimnasio', 'codigo_plan', 'place_api_key'],
            // Sin API: no pide llaves (ADR 0197).
            self::Fitpass => [],
        };
    }

    /**
     * Sin estas no valida un solo check-in: no se puede activar la integración sin
     * ellas. Wellhub, su token y su Gym ID; TotalPass, su llave y el código del gimnasio
     * (validar el token a mano) o la llave de su sede (check-in automático y reservas).
     * Cada grupo basta.
     *
     * @return list<list<string>>
     */
    public function credencialesObligatorias(): array
    {
        return match ($this) {
            // Con el token de AgendaUno como sistema integrado, basta su Gym ID.
            self::Wellhub => trim((string) config('agendauno.integraciones.wellhub_api_token')) !== ''
                ? [['api_key', 'gym_id'], ['gym_id']]
                : [['api_key', 'gym_id']],
            self::TotalPass => [['api_key', 'codigo_gimnasio'], ['place_api_key']],
            self::Fitpass => [],
        };
    }

    /** ¿Se ofrece en ese país? (los decide el superadmin, ADR 0202) */
    public function disponibleEn(string $pais): bool
    {
        return in_array(strtoupper($pais), ConfiguracionPlataforma::paisesBienestar()[$this->value] ?? [], true);
    }

    /** ¿Valida las visitas con su API? Fitpass, no: las valida recepción (ADR 0197). */
    public function conApi(): bool
    {
        return $this !== self::Fitpass;
    }
}
