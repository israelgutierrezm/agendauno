<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A qué clases o servicios aplica un plan (ADR 0050): p. ej. "sirve para Nivel 1, 2 y
| 3, no para Nivel 4". Sin filas, aplica a todas. Al vender se copia al derecho: lo
| ya comprado no cambia si después se edita el plan. Una clase ligada a un plan no se
| puede borrar: si se borrara, el plan quedaría "para todas" sin que nadie lo decidiera.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('producto_ofertas', function (Blueprint $tabla): void {
            $tabla->foreignId('producto_id')->constrained('productos_comerciales')->cascadeOnDelete();
            $tabla->foreignId('oferta_id')->constrained('ofertas')->restrictOnDelete();
            $tabla->primary(['producto_id', 'oferta_id']);
        });

        Schema::connection('tenant')->create('derecho_ofertas', function (Blueprint $tabla): void {
            $tabla->foreignId('derecho_id')->constrained('derechos')->cascadeOnDelete();
            $tabla->foreignId('oferta_id')->constrained('ofertas')->restrictOnDelete();
            $tabla->primary(['derecho_id', 'oferta_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('derecho_ofertas');
        Schema::connection('tenant')->dropIfExists('producto_ofertas');
    }
};
