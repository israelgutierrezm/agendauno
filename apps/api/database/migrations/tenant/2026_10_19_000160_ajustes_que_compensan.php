<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Si un ajuste de nómina corrige una diferencia del cálculo (R09): solo esos se restan de
| lo que falta; un bono u otra corrección se muestra aparte y no cambia la diferencia.
| Null = anterior (se toma como corrección, como hasta ahora).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ajustes_nomina', function (Blueprint $t): void {
            $t->boolean('compensa_diferencia')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ajustes_nomina', function (Blueprint $t): void {
            $t->dropColumn('compensa_diferencia');
        });
    }
};
