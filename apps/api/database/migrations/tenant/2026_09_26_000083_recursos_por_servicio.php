<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Recursos por servicio (fase 2, punto 2.4): qué cabinas, consultorios, sillones o
| equipos puede usar un servicio. Una cita toma uno libre de su sede; un servicio
| sin recursos configurados no pide ninguno.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('oferta_recursos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('oferta_id')->constrained('ofertas')->cascadeOnDelete();
            $tabla->foreignId('recurso_id')->constrained('recursos')->cascadeOnDelete();
            $tabla->timestamps();

            $tabla->unique(['oferta_id', 'recurso_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('oferta_recursos');
    }
};
