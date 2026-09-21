<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): DIFUSIONES (comunicaciones segmentadas). Una difusión es
 * un envío PUNTUAL de asunto/cuerpo a un SEGMENTO dinámico (todos / por vencer /
 * vencidos / primerizos): genera un `mensaje` ENCOLADO por destinatario que reusa el
 * relay R28 para entregar (interno o email). El encabezado guarda el segmento, canal,
 * plantilla original y el total encolado. Sin `tenant_id`: aislamiento por base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('difusiones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('segmento');
            $tabla->string('canal');
            $tabla->string('asunto');
            $tabla->text('cuerpo');
            $tabla->unsignedInteger('total')->default(0);
            $tabla->dateTime('enviada_en')->nullable();
            $tabla->timestamps();
        });

        // Enlaza cada mensaje generado con su difusión (para ver destinatarios/estado).
        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->foreignId('difusion_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->dropColumn('difusion_id');
        });
        Schema::connection('tenant')->dropIfExists('difusiones');
    }
};
