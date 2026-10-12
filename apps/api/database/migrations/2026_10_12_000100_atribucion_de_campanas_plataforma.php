<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Atribución de campañas del SaaS (ADR 0142): de dónde llegó quien registró un negocio
| en AgendaUno o TurnoUno (su primer origen y su último toque), guardado con el negocio.
| Uno por negocio; la campaña acreditada es la del último toque vigente.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contextos_medicion_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('estudio_id')->unique()->constrained('estudios')->cascadeOnDelete();
            $tabla->string('producto', 20);
            $tabla->string('fuente', 100)->nullable();
            $tabla->string('medio', 100)->nullable();
            $tabla->string('campana', 100)->nullable();
            $tabla->string('referente', 120)->nullable();
            $tabla->json('primer_toque')->nullable();
            $tabla->json('ultimo_toque')->nullable();
            $tabla->timestamps();

            $tabla->index(['producto', 'campana']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contextos_medicion_plataforma');
    }
};
