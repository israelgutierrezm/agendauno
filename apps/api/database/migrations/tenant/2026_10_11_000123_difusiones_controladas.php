<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Difusiones que se controlan (plan de mejoras B08): una campaña se pausa, se reanuda
 * o se cancela sin tocar los avisos de operación (recordatorios, pagos), y la misma
 * campaña no se manda dos veces el mismo día (su huella).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('difusiones', function (Blueprint $tabla): void {
            // enviando | pausada | cancelada
            $tabla->string('estado', 12)->default('enviando');
            $tabla->timestamp('pausada_en')->nullable();
            $tabla->timestamp('cancelada_en')->nullable();
            $tabla->string('huella', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('difusiones', function (Blueprint $tabla): void {
            $tabla->dropIndex(['huella']);
            $tabla->dropColumn(['estado', 'pausada_en', 'cancelada_en', 'huella']);
        });
    }
};
