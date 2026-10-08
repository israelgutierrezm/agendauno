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

    /**
     * Las credenciales que pide cada una (las da el proveedor al negocio): Wellhub,
     * su token de API y su Gym ID; TotalPass, su llave de API, el código de su
     * gimnasio y, si tiene varios planes, el del plan.
     *
     * @return list<string>
     */
    public function credenciales(): array
    {
        return match ($this) {
            self::Wellhub => ['api_key', 'gym_id'],
            self::TotalPass => ['api_key', 'codigo_gimnasio', 'codigo_plan'],
        };
    }
}
