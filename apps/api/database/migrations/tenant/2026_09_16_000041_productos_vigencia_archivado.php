<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editor completo de membresías (Etapa 2): el producto gana VIGENCIA (días de validez
 * que fijan `valido_hasta` del derecho al venderse) y un estado ARCHIVADO (retirar de
 * venta sin borrar, conservando su historial de acuerdos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('vigencia_dias')->nullable()->after('creditos_incluidos');
            $tabla->boolean('archivado')->default(false)->after('vigencia_dias');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('productos_comerciales', function (Blueprint $tabla): void {
            $tabla->dropColumn(['vigencia_dias', 'archivado']);
        });
    }
};
