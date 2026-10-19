<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La fecha en que vence lo que renueva una orden de renovación (ADR 0166): la orden puede
| nacer días antes (con el aviso de renovación), y el embudo cuenta las renovaciones que
| tocaban en el periodo, no las órdenes creadas en él. Null = anterior; se usa la fecha
| de creación.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $t): void {
            $t->date('renueva_vence_el')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $t): void {
            $t->dropColumn('renueva_vence_el');
        });
    }
};
