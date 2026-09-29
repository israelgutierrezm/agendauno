<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Avisos por WhatsApp (ADR 0069). `personas.whatsapp_aceptado_en`: cuándo la persona
| aceptó recibir avisos por WhatsApp (Meta pide su consentimiento; sin él no se le
| manda nada). `mensajes.parametros`: con qué plantilla de Meta y qué valores se
| envía un aviso por WhatsApp.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->timestamp('whatsapp_aceptado_en')->nullable();
        });
        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->json('parametros')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->dropColumn('parametros');
        });
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->dropColumn('whatsapp_aceptado_en');
        });
    }
};
