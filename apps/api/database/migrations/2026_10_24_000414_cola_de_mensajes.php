<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cómo va el envío de mensajes de cada negocio (ADR 0205): cuántos quedan pendientes
| de avisos y de campañas, y desde cuándo espera el más antiguo. Lo anota cada pasada
| del envío en la cola; la verificación de producción y Operación lo leen sin entrar a
| la base de cada negocio.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cola_mensajes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            // `avisos` (operación y recordatorios) o `campanas` (difusiones).
            $t->string('tipo', 20);
            $t->unsignedInteger('pendientes')->default(0);
            $t->timestamp('mas_antiguo_en')->nullable();
            $t->timestamp('revisado_en');
            $t->unique(['estudio_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cola_mensajes');
    }
};
