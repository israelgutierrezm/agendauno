<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Alertas de la plataforma (control plane): lo que falla en la operación (pagos,
| correos, respaldos, cola, errores) agrupado por tipo y origen, con cuántas veces
| pasó, para mandarlo por correo al superadmin en un resumen y no una por una.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('tipo', 60);
            // Qué agrupa: p. ej. el negocio, la clase de error o el trabajo.
            $tabla->string('clave', 191);
            $tabla->string('estudio', 100)->nullable();
            $tabla->string('mensaje', 500);
            $tabla->unsignedInteger('veces')->default(1);
            $tabla->timestamp('primera_en');
            $tabla->timestamp('ultima_en');
            // Falta avisar (nueva, o sigue pasando tras el tiempo de espera).
            $tabla->boolean('pendiente')->default(true);
            $tabla->timestamp('notificada_en')->nullable();
            $tabla->timestamps();

            $tabla->unique(['tipo', 'clave']);
            $tabla->index('pendiente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_plataforma');
    }
};
