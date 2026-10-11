<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento y medición (plan de mejoras M09, C06, C05 y C08):
 *
 * - Una tarea de seguimiento dice por qué existe (`motivo`: primera clase, plan por vencer,
 *   plan vencido, días sin asistir, compró y no reservó) y con qué terminó el contacto
 *   (`resultado`). Así no se crean dos por el mismo motivo y se mide qué pasó después.
 * - Las visitas a la página del negocio se cuentan por día, una por visitante, sin guardar
 *   quién fue (ni IP ni navegador): solo el número, para el embudo del negocio.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('tareas', function (Blueprint $tabla): void {
            $tabla->string('motivo', 30)->nullable();
            $tabla->string('resultado', 30)->nullable();
            $tabla->timestamp('contactado_en')->nullable();
            $tabla->index(['persona_id', 'motivo']);
        });
        Schema::connection('tenant')->create('visitas_pagina', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->date('fecha')->unique();
            $tabla->unsignedInteger('visitas')->default(0);
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('visitas_pagina');
        Schema::connection('tenant')->table('tareas', function (Blueprint $tabla): void {
            $tabla->dropIndex(['persona_id', 'motivo']);
            $tabla->dropColumn(['motivo', 'resultado', 'contactado_en']);
        });
    }
};
