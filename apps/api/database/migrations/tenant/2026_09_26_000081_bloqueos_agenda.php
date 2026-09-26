<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Bloqueos de agenda (fase 2, punto 2.2): un intervalo en que un profesional (comida,
| vacaciones, ausencia), una sede (cierre) o una sala (mantenimiento) no está
| disponible. Quita ese intervalo del autoservicio y de nuevas reservas internas; no
| cancela lo que ya estaba agendado (se advierte al crearlo). Guarda motivo y quién
| lo puso; se elimina con baja lógica.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('bloqueos_agenda', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            // Exactamente uno de los tres: a quién o a qué se bloquea.
            $tabla->foreignId('instructor_id')->nullable()->constrained('users')->cascadeOnDelete();
            $tabla->foreignId('sucursal_id')->nullable()->constrained('sucursales')->cascadeOnDelete();
            $tabla->foreignId('recurso_id')->nullable()->constrained('recursos')->cascadeOnDelete();
            $tabla->timestamp('desde'); // UTC
            $tabla->timestamp('hasta'); // UTC
            $tabla->boolean('todo_el_dia')->default(false);
            $tabla->string('zona_horaria', 64);
            $tabla->string('motivo');
            $tabla->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
            $tabla->timestamps();
            $tabla->softDeletes();

            $tabla->index(['instructor_id', 'desde']);
            $tabla->index(['sucursal_id', 'desde']);
            $tabla->index(['recurso_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('bloqueos_agenda');
    }
};
