<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| WhatsApp negocio por negocio y respuesta automática (ADR 0083).
|
| - `estudios.whatsapp_habilitado`: el superadministrador activa los avisos por
|   WhatsApp de cada negocio a sus clientes. Apagado por omisión; el negocio no puede
|   activarlo.
| - `whatsapp_envios.telefono_huella`: HMAC del número al que se mandó, para saber de
|   qué negocio es el aviso al que alguien contesta sin guardar el número.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->boolean('whatsapp_habilitado')->default(false);
        });

        Schema::table('whatsapp_envios', function (Blueprint $tabla): void {
            $tabla->char('telefono_huella', 64)->nullable();
            $tabla->index(['telefono_huella', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_envios', function (Blueprint $tabla): void {
            $tabla->dropIndex(['telefono_huella', 'id']);
            $tabla->dropColumn('telefono_huella');
        });

        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->dropColumn('whatsapp_habilitado');
        });
    }
};
