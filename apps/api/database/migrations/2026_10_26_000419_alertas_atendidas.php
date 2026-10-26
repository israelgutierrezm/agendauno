<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Una alerta de la plataforma atendida (ADR 0216): cuándo y quién. Si vuelve a pasar,
| deja de estarlo. Las que piden algo a alguien (un módulo solicitado, un cobro doble)
| no se borran del historial mientras nadie las atienda.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alertas_plataforma', function (Blueprint $t): void {
            $t->timestamp('atendida_en')->nullable();
            $t->string('atendida_por', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alertas_plataforma', function (Blueprint $t): void {
            $t->dropColumn(['atendida_en', 'atendida_por']);
        });
    }
};
