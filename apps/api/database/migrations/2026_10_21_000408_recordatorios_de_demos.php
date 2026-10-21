<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Recordatorios por correo de la demo agendada (ADR 0193): un día y una hora antes. Cada
| columna dice cuándo se mandó: se toma una sola vez aunque dos procesos revisen a la vez.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demos_agendadas', function (Blueprint $t): void {
            $t->timestamp('recordatorio_dia_en')->nullable();
            $t->timestamp('recordatorio_hora_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('demos_agendadas', function (Blueprint $t): void {
            $t->dropColumn(['recordatorio_dia_en', 'recordatorio_hora_en']);
        });
    }
};
