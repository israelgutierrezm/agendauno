<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): DURACIÓN por oferta (citas). Minutos que dura el
 * servicio cuando se agenda como cita (barbería/estética: un corte = 30 min). Es la
 * duración con la que se trocea la disponibilidad y se crea la sesión de la cita.
 * Nullable: solo las ofertas usadas como cita la necesitan; las clases grupales
 * siguen definiendo su duración al programar cada sesión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('duracion_minutos')->nullable()->after('precio_clase_minor');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->dropColumn('duracion_minutos');
        });
    }
};
