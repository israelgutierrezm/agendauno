<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): `personas.celular` — número de contacto del
 * cliente/alumno (recordatorios por WhatsApp/SMS). Útil sobre todo para citas guest de
 * barbería, donde el cliente deja nombre + celular en vez de correo. Nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->string('celular')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->dropColumn('celular');
        });
    }
};
