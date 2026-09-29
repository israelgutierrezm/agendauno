<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Foto y enlace de Google Maps de la sede (ADR 0064): al agendar, el cliente reconoce
| la sede por su foto y ve dónde es su cita antes de confirmar, para no llegar a otra.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('sucursales', function (Blueprint $tabla): void {
            $tabla->string('foto_ruta')->nullable();
            $tabla->string('mapa_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sucursales', function (Blueprint $tabla): void {
            $tabla->dropColumn(['foto_ruta', 'mapa_url']);
        });
    }
};
