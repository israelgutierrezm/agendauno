<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El cliente de una persona en la cuenta de la pasarela del negocio (p. ej. el
 * `cus_…` de Stripe): a él se ligan las tarjetas que autoriza para pagos automáticos.
 */
class ClientePasarelaTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'clientes_pasarela';

    protected $fillable = ['persona_id', 'proveedor', 'cliente_externo'];
}
