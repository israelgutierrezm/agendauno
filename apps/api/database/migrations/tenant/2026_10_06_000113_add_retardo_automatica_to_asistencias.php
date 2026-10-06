<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistencia (ADR 0101): si llegó tarde (`retardo`, solo con «presente»: cuenta como
 * asistencia) y si la marcó el sistema al terminar la clase o cita sin registro
 * (`automatica`), para distinguirla de una que alguien marcó.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('asistencias', function (Blueprint $tabla): void {
            $tabla->boolean('retardo')->default(false)->after('estado');
            $tabla->boolean('automatica')->default(false)->after('retardo');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('asistencias', function (Blueprint $tabla): void {
            $tabla->dropColumn(['retardo', 'automatica']);
        });
    }
};
