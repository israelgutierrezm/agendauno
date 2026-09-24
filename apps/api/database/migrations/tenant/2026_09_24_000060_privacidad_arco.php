<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Derechos ARCO del alumno frente al negocio (responsable de sus datos):
| - oposición a promociones (`personas.recibe_promociones`);
| - solicitudes de baja (cancelación) que el negocio atiende o rechaza.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->boolean('recibe_promociones')->default(true);
        });

        Schema::connection('tenant')->create('solicitudes_privacidad', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->string('tipo')->default('cancelacion');
            $tabla->string('estado')->default('pendiente'); // pendiente | atendida | rechazada
            $tabla->string('motivo', 500)->nullable();     // del alumno
            $tabla->string('respuesta', 500)->nullable();  // del negocio
            $tabla->foreignId('atendida_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->dateTime('atendida_en')->nullable();
            $tabla->timestamps();

            $tabla->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('solicitudes_privacidad');
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->dropColumn('recibe_promociones');
        });
    }
};
