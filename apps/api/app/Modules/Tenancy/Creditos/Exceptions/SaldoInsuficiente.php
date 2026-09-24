<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Creditos\Exceptions;

use RuntimeException;

/**
 * Se lanza al intentar consumir o retener más créditos de los disponibles.
 * Se traduce a un error 422 con código estable SALDO_INSUFICIENTE.
 */
class SaldoInsuficiente extends RuntimeException {}
