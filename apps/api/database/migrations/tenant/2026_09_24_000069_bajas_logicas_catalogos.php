<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Baja lógica de catálogos y configuración: lo que antes se borraba de verdad
| (promociones, plantillas, recursos, reglas, excepciones de horario, webhooks,
| asignaciones de sede) queda oculto con quién lo eliminó; la bitácora guarda qué era.
| Si se vuelve a crear con su misma clave, se restaura.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('promociones', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
        });

        Schema::connection('tenant')->table('plantillas_mensaje', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
        });

        Schema::connection('tenant')->table('plantillas_horario', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
        });

        Schema::connection('tenant')->table('recursos', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
        });

        Schema::connection('tenant')->table('reglas_automatizacion', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
        });

        Schema::connection('tenant')->table('reglas_capacidad_canal', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
        });

        Schema::connection('tenant')->table('excepciones_horario', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
        });

        Schema::connection('tenant')->table('webhooks_salientes', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
        });

        Schema::connection('tenant')->table('asignaciones_personal', function (Blueprint $tabla): void {
            $tabla->softDeletes();
            $tabla->unsignedBigInteger('eliminado_por')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('promociones', function (Blueprint $tabla): void {
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });

        Schema::connection('tenant')->table('plantillas_mensaje', function (Blueprint $tabla): void {
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });

        Schema::connection('tenant')->table('plantillas_horario', function (Blueprint $tabla): void {
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });

        Schema::connection('tenant')->table('recursos', function (Blueprint $tabla): void {
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });

        Schema::connection('tenant')->table('reglas_automatizacion', function (Blueprint $tabla): void {
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });

        Schema::connection('tenant')->table('reglas_capacidad_canal', function (Blueprint $tabla): void {
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });

        Schema::connection('tenant')->table('excepciones_horario', function (Blueprint $tabla): void {
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });

        Schema::connection('tenant')->table('webhooks_salientes', function (Blueprint $tabla): void {
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });

        Schema::connection('tenant')->table('asignaciones_personal', function (Blueprint $tabla): void {
            $tabla->dropColumn(['deleted_at', 'eliminado_por']);
        });
    }
};
