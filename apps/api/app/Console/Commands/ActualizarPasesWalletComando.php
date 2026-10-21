<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ActualizarPasesWallet;
use Illuminate\Console\Command;

/**
 * Los pases de la cartera que cambiaron (plan, vigencia, saldo o código): avisa a Apple
 * Wallet y actualiza Google Wallet (ADR 0195).
 */
class ActualizarPasesWalletComando extends Command
{
    protected $signature = 'agendauno:actualizar-pases-wallet';

    protected $description = 'Avisa a Apple Wallet y actualiza Google Wallet cuando cambia lo que muestra un pase';

    public function handle(ActualizarPasesWallet $pases): int
    {
        $this->info('Pases que cambiaron: '.$pases->revisar().'.');

        return self::SUCCESS;
    }
}
