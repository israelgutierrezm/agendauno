<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reseñas: tras asistir, el alumno califica la clase o cita (1 a 5 y un comentario
| opcional). Una por reserva. El negocio puede ocultar un comentario del público.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('resenas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('reserva_id')->unique()->constrained('reservas')->cascadeOnDelete();
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->foreignId('oferta_id')->nullable()->constrained('ofertas')->nullOnDelete();
            $tabla->foreignId('instructor_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->unsignedTinyInteger('calificacion');
            $tabla->text('comentario')->nullable();
            $tabla->boolean('visible')->default(true);
            $tabla->timestamps();

            $tabla->index(['instructor_id', 'calificacion']);
            $tabla->index(['oferta_id', 'calificacion']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('resenas');
    }
};
