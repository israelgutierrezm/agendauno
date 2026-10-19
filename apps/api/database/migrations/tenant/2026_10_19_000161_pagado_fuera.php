<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Lo que se pagó de una orden en otro sistema, antes de importarla (R14): se descuenta
| de lo que queda por cobrar y no es un ingreso de aquí (no hay pago). Queda el rastro:
| cuánto fue y, si fue todo, que la cita está pagada. Null = nada pagado afuera.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $t): void {
            $t->unsignedBigInteger('pagado_fuera_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $t): void {
            $t->dropColumn('pagado_fuera_minor');
        });
    }
};
