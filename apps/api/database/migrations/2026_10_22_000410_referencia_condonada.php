<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Un cargo de renta condonado con su página de pago de OpenPay abierta (OpenPay no deja
| cerrarla): se guarda la referencia de ese intento para vigilarlo. Si después se paga,
| la conciliación lo detecta y avisa del cobro doble aunque el aviso de OpenPay se pierda.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos_renta', function (Blueprint $t): void {
            $t->string('referencia_condonada', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cargos_renta', function (Blueprint $t): void {
            $t->dropColumn('referencia_condonada');
        });
    }
};
