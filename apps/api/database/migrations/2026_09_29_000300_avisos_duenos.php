<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Avisos de la plataforma a los dueños (ADR 0071): prueba por terminar, renta lista,
| renta vencida y pago recibido. Uno por negocio, tipo, referencia (la fecha de fin de
| la prueba o el cargo) y canal: nunca se manda dos veces. Guarda el texto ya armado,
| su estado y sus intentos, como la bandeja de salida de un negocio.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_duenos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $tabla->string('tipo', 40);
            $tabla->string('referencia', 40);
            $tabla->string('canal', 10); // email | whatsapp
            $tabla->string('destinatario');
            $tabla->string('asunto');
            $tabla->text('cuerpo');
            $tabla->json('parametros')->nullable(); // WhatsApp: {plantilla, valores}
            $tabla->string('estado', 12)->default('encolado'); // encolado | enviado | fallido | descartado
            $tabla->unsignedTinyInteger('intentos')->default(0);
            $tabla->string('ultimo_error')->nullable();
            $tabla->timestamp('enviado_en')->nullable();
            $tabla->timestamps();

            $tabla->unique(['estudio_id', 'tipo', 'referencia', 'canal']);
            $tabla->index(['estado', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_duenos');
    }
};
