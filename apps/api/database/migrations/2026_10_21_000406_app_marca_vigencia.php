<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La app con la marca del negocio (ADR 0188) se cobra por mes vencido: hace falta saber
| desde y hasta cuándo estuvo encendida, no solo si lo está el día en que se emite. Si
| no, encenderla a mitad de mes cobraba el mes anterior y apagarla dejaba sin cobrar el
| mes en que sí la tuvo.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $t): void {
            $t->date('app_marca_desde')->nullable();
            $t->date('app_marca_hasta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $t): void {
            $t->dropColumn(['app_marca_desde', 'app_marca_hasta']);
        });
    }
};
