<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Hasta cuándo llegó un proceso programado en este negocio (ADR 0230): con eso retoma lo
| que le faltó si el programador o la cola estuvieron caídos. Primer uso: las
| inasistencias automáticas (`asistencia.no_asistio_al_terminar`).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('marcas_de_proceso', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('clave', 100)->unique();
            $tabla->dateTime('hasta');
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('marcas_de_proceso');
    }
};
