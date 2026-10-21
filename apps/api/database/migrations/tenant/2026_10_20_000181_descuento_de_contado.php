<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Precio por forma de pago (ADR 0191): el descuento por pagar de contado en caja
| (efectivo o transferencia) que se aplicó a la orden. Va aparte del de la promoción
| para que no se aplique dos veces y se vea en la venta.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $t): void {
            $t->unsignedBigInteger('descuento_contado_minor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $t): void {
            $t->dropColumn('descuento_contado_minor');
        });
    }
};
