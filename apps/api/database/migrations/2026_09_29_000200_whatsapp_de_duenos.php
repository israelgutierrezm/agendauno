<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| WhatsApp con los dueños (ADR 0070). Al registrarse, el dueño puede confirmar su
| número con un código que le llega por WhatsApp y aceptar avisos de la plataforma.
|
| `verificaciones_whatsapp`: cada código enviado (solo su hash), sus intentos y, ya
| confirmado, el comprobante (hash) que se presenta al crear el negocio, de un solo uso.
| `estudios`: cuándo se verificó el WhatsApp del contacto y cuándo aceptó avisos.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verificaciones_whatsapp', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('telefono', 20); // solo dígitos, con lada
            $tabla->string('codigo_hash', 64);
            $tabla->unsignedTinyInteger('intentos')->default(0);
            $tabla->timestamp('expira_en');
            $tabla->timestamp('verificada_en')->nullable();
            $tabla->string('comprobante_hash', 64)->nullable()->unique();
            $tabla->timestamp('usada_en')->nullable();
            $tabla->string('ip', 45)->nullable();
            $tabla->timestamps();

            $tabla->index(['telefono', 'created_at']);
            $tabla->index('created_at');
        });

        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->timestamp('contacto_whatsapp_verificado_en')->nullable();
            $tabla->timestamp('contacto_whatsapp_aceptado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn(['contacto_whatsapp_verificado_en', 'contacto_whatsapp_aceptado_en']);
        });
        Schema::dropIfExists('verificaciones_whatsapp');
    }
};
