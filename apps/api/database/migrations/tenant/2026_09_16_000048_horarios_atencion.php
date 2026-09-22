<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): HORARIO DE ATENCIÓN de un proveedor (citas, F-08). Define
 * las ventanas semanales en que un instructor/barbero atiende EN una sucursal (día ISO
 * 1-7 + hora local HH:MM). El motor de disponibilidad genera los huecos libres a partir
 * de estas ventanas, restando las clases/citas ya agendadas y los conflictos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('horarios_atencion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $tabla->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $tabla->unsignedTinyInteger('dia_semana'); // ISO 1 (lunes) .. 7 (domingo)
            $tabla->string('hora_inicio', 5); // HH:MM local (zona de la sucursal)
            $tabla->string('hora_fin', 5);
            $tabla->timestamps();

            $tabla->index(['instructor_id', 'sucursal_id', 'dia_semana']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('horarios_atencion');
    }
};
