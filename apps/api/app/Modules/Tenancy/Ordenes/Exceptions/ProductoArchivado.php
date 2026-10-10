<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes\Exceptions;

/**
 * Un producto archivado ya no se vende (plan de mejoras, B03): ni desde la cuenta del
 * cliente con un enlace viejo, ni en caja. Lo que ya se tiene se sigue renovando.
 */
class ProductoArchivado extends OrdenException
{
    public function codigo(): string
    {
        return 'PRODUCT_ARCHIVED';
    }
}
