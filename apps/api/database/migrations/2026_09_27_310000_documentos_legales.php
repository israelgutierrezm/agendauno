<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Documentos legales PUBLICADOS de la plataforma (aviso de privacidad y términos),
| versionados e inmutables: el borrador se edita aparte y al publicarse queda aquí
| una versión nueva con su fecha y los datos del responsable. Y qué versiones aceptó
| cada negocio al registrarse (evidencia: fecha, IP y navegador).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_legales', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('tipo', 40); // aviso_privacidad | terminos
            $tabla->unsignedInteger('version');
            $tabla->longText('contenido');
            // Del aviso: responsable, domicilio, contacto de privacidad y área.
            $tabla->json('responsable')->nullable();
            $tabla->timestamp('vigente_desde');
            $tabla->timestamps();

            $tabla->unique(['tipo', 'version']);
        });

        Schema::create('aceptaciones_legales', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('estudio_id')->nullable()->constrained('estudios')->nullOnDelete();
            $tabla->string('email');
            $tabla->string('tipo', 40);
            $tabla->unsignedInteger('version');
            $tabla->timestamp('aceptado_en');
            $tabla->string('ip', 45)->nullable();
            $tabla->string('navegador', 255)->nullable();
            $tabla->timestamps();

            $tabla->index(['tipo', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aceptaciones_legales');
        Schema::dropIfExists('documentos_legales');
    }
};
