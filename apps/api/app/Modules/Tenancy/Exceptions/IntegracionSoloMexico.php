<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * Las plataformas de bienestar (Wellhub, TotalPass) solo operan con negocios en
 * México: fuera de ahí no se configuran ni validan visitas.
 */
class IntegracionSoloMexico extends TenancyException
{
    public function codigo(): string
    {
        return 'INTEGRATION_ONLY_MEXICO';
    }
}
