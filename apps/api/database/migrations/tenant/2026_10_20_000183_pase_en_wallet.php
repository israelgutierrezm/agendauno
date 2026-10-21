<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Pase de entrada en Apple Wallet y Google Wallet (ADR 0195). El pase de la cartera no
| se renueva cada pocos minutos como el QR de la app: lleva un código propio de la
| persona, guardado cifrado (el mismo sirve para las dos carteras). Renovarlo deja sin
| efecto el anterior (p. ej. si perdió su teléfono).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $t): void {
            $t->text('pase_wallet_token')->nullable();
            $t->timestamp('pase_wallet_emitido_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $t): void {
            $t->dropColumn(['pase_wallet_token', 'pase_wallet_emitido_en']);
        });
    }
};
