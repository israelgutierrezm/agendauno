<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cuándo estuvo suspendido cada negocio (ADR 0231): la renta de un mes con suspensión se
| prorratea a los días operados. Una suspensión abierta no tiene `hasta`. Las de hoy se
| abren con su fecha.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspensiones_estudio', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $tabla->dateTime('desde');
            $tabla->dateTime('hasta')->nullable();
            $tabla->timestamps();
            $tabla->index(['estudio_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspensiones_estudio');
    }
};
