<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Monitoreo de errores (ADR 0080): cada error de la API, la web o la app, agrupado
| por su huella (origen, tipo y lugar), con su traza sin argumentos, el contexto de
| la última vez (datos sensibles tachados), cuántas veces pasó y en qué versiones, y
| su estado para el superadmin: abierto, resuelto o ignorado.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('errores_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('huella', 64)->unique();
            // api | web | app
            $tabla->string('origen', 8);
            $tabla->string('tipo', 191);
            $tabla->string('mensaje', 500);
            $tabla->string('lugar', 255)->nullable();
            $tabla->text('traza')->nullable();
            $tabla->json('contexto')->nullable();
            $tabla->unsignedInteger('veces')->default(1);
            $tabla->timestamp('primera_en');
            $tabla->timestamp('ultima_en');
            $tabla->string('version_primera', 40)->nullable();
            $tabla->string('version_ultima', 40)->nullable();
            // abierto | resuelto | ignorado
            $tabla->string('estado', 10)->default('abierto');
            $tabla->timestamp('resuelto_en')->nullable();
            // La última versión en que se vio al resolverlo: si vuelve en otra, se reabre.
            $tabla->string('version_resuelto', 40)->nullable();
            // Veces que volvió después de marcarse resuelto.
            $tabla->unsignedInteger('regresiones')->default(0);
            $tabla->timestamps();

            $tabla->index(['estado', 'ultima_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('errores_plataforma');
    }
};
