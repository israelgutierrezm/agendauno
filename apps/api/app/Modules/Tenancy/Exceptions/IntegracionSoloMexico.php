<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * Una plataforma de bienestar solo se ofrece en los países donde opera (los decide el
 * superadmin, México por omisión; ADR 0202): fuera de ahí no se configura ni valida
 * visitas. El código conserva su nombre de cuando solo era México.
 */
class IntegracionSoloMexico extends TenancyException
{
    public function codigo(): string
    {
        return 'INTEGRATION_ONLY_MEXICO';
    }
}
