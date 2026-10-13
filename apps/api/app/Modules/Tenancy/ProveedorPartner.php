<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Plataformas de bienestar corporativo con las que un estudio puede integrarse
 * para aceptar check-ins de sus usuarios (la plataforma cubre la clase).
 */
enum ProveedorPartner: string
{
    case Wellhub = 'wellhub';
    case TotalPass = 'totalpass';

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
        };
    }

    /** Con qué se ofrece una clase en ella: el producto de Wellhub o el plan de TotalPass. */
    public function producto(): string
    {
        return match ($this) {
            self::Wellhub => 'producto',
            self::TotalPass => 'plan',
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
        };
    }

    /**
     * Sin estas no valida un solo check-in: no se puede activar la integración sin
     * ellas. Wellhub, su token y su Gym ID; TotalPass, su llave y el código del gimnasio.
     *
     * @return list<string>
     */
    public function credencialesObligatorias(): array
    {
        return match ($this) {
            self::Wellhub => ['api_key', 'gym_id'],
            self::TotalPass => ['api_key', 'codigo_gimnasio'],
        };
    }
}
