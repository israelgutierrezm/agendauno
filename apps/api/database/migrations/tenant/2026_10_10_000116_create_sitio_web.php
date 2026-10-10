<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): el sitio público del negocio (ADR 0114). `sitio_web` es el
 * borrador que edita el negocio (una fila); `publicaciones_sitio_web`, cada versión que
 * publicó (la última es la que ve el público). El contenido es la configuración de la
 * página (plantilla, secciones, textos, banners), no datos operativos: horarios, precios
 * y servicios salen siempre del sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('sitio_web', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->json('borrador')->nullable();
            $tabla->unsignedBigInteger('actualizado_por_usuario_id')->nullable();
            $tabla->timestamps();
        });

        Schema::connection('tenant')->create('publicaciones_sitio_web', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->json('contenido');
            $tabla->unsignedBigInteger('publicada_por_usuario_id')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('publicaciones_sitio_web');
        Schema::connection('tenant')->dropIfExists('sitio_web');
    }
};
