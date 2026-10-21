<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Archivos en la bandeja (ADR 0200, ajuste del 2026-10-21). De lo que llega se guarda
| su referencia en Meta (el id del medio de WhatsApp o la dirección del adjunto de
| Instagram), su tipo y su nombre; el archivo se baja aparte (en cola) al disco
| privado del negocio. Lo que manda el equipo se guarda ahí también.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('mensajes_conversacion', function (Blueprint $t): void {
            $t->text('adjunto_ref')->nullable();
            $t->string('adjunto_mime', 120)->nullable();
            $t->string('adjunto_nombre', 255)->nullable();
            $t->string('archivo_ruta', 255)->nullable();
            $t->unsignedInteger('archivo_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('mensajes_conversacion', function (Blueprint $t): void {
            $t->dropColumn(['adjunto_ref', 'adjunto_mime', 'adjunto_nombre', 'archivo_ruta', 'archivo_bytes']);
        });
    }
};
