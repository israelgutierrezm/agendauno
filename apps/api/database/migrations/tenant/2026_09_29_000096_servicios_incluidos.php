<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Servicios que incluyen otros (ADR 0063): un paquete como «Limpieza dental completa»
| incluye limpieza, aplicación de flúor y diagnóstico de caries. Sigue siendo un
| servicio con su precio, su duración y una sola cita; esto dice qué incluye, en orden.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('oferta_incluidos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('oferta_id')->constrained('ofertas')->cascadeOnDelete();
            $tabla->foreignId('incluida_id')->constrained('ofertas')->cascadeOnDelete();
            $tabla->unsignedSmallInteger('posicion')->default(0);
            $tabla->timestamps();

            $tabla->unique(['oferta_id', 'incluida_id']);
            $tabla->index('incluida_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('oferta_incluidos');
    }
};
