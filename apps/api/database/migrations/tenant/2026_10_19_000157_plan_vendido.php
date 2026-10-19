<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Lo vendido no cambia si después se edita el catálogo (ADR 0166):
|
| - `lineas_orden.plan`: el plan como se vendió (créditos, vigencia, sedes, clases,
|   ciclos y permanencia). Una orden que se paga después concede eso, no lo que diga el
|   catálogo al pagarla.
| - `acuerdos.cobra_anualidad`: si su plan cobraba la anualidad al contratarlo. Null =
|   contratado antes; se lee del plan, como hasta ahora.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('lineas_orden', function (Blueprint $t): void {
            $t->json('plan')->nullable();
        });
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $t): void {
            $t->boolean('cobra_anualidad')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('lineas_orden', function (Blueprint $t): void {
            $t->dropColumn('plan');
        });
        Schema::connection('tenant')->table('acuerdos', function (Blueprint $t): void {
            $t->dropColumn('cobra_anualidad');
        });
    }
};
