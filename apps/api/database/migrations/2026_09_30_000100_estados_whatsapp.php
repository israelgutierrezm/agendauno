<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Estados de entrega de WhatsApp (ADR 0074). Meta avisa por webhook si un mensaje se
| entregó, se leyó o falló, con el id que devolvió al enviarlo (wamid). Como el número
| es de toda la plataforma, `whatsapp_envios` (control plane) dice de qué negocio y de
| qué mensaje es cada wamid: un aviso del negocio a su cliente (`mensaje`, en la base
| del negocio) o un aviso de la plataforma al dueño (`aviso_dueno`).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_envios', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('wamid', 191)->unique();
            $tabla->foreignId('estudio_id')->nullable()->constrained('estudios')->cascadeOnDelete();
            $tabla->string('origen', 20); // mensaje | aviso_dueno
            $tabla->unsignedBigInteger('referencia_id');
            $tabla->string('estado', 12)->default('enviado'); // enviado | entregado | leido | fallido
            $tabla->string('error')->nullable();
            $tabla->timestamps();
        });

        Schema::table('avisos_duenos', function (Blueprint $tabla): void {
            $tabla->timestamp('entregado_en')->nullable();
            $tabla->timestamp('leido_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('avisos_duenos', function (Blueprint $tabla): void {
            $tabla->dropColumn(['entregado_en', 'leido_en']);
        });
        Schema::dropIfExists('whatsapp_envios');
    }
};
