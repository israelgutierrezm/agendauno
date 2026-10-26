<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La descarga de todos los datos de un negocio (ADR 0213): la pide su dueño y se arma
| en cola (un CSV por tabla y sus archivos, en un ZIP del disco privado). Vive en el
| control plane para limpiar las vencidas en una sola consulta. `solicitada_por` es el
| ulid del usuario en la base del negocio; `correo`, a quién se avisa que está lista.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportaciones_negocio', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $t->string('solicitada_por', 26)->nullable();
            $t->string('correo')->nullable();
            $t->string('estado', 20)->default('en_proceso');
            $t->string('ruta')->nullable();
            $t->unsignedBigInteger('tamano_bytes')->nullable();
            $t->string('error', 500)->nullable();
            $t->timestamp('lista_en')->nullable();
            $t->timestamp('vence_en')->nullable();
            $t->timestamps();
            $t->index(['estudio_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportaciones_negocio');
    }
};
