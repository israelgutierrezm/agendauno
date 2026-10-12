<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Permanencia (B04) con «meses restantes» por porcentaje (ADR 0137): qué parte de las
| mensualidades que falten paga quien da de baja antes (p. ej. 50 %). En el plan y,
| congelado, en cada membresía vendida. Vacío = 100 %.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        foreach (['productos_comerciales', 'acuerdos'] as $tablaNombre) {
            Schema::connection('tenant')->table($tablaNombre, function (Blueprint $tabla): void {
                $tabla->unsignedTinyInteger('penalizacion_porcentaje')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['productos_comerciales', 'acuerdos'] as $tablaNombre) {
            Schema::connection('tenant')->table($tablaNombre, function (Blueprint $tabla): void {
                $tabla->dropColumn('penalizacion_porcentaje');
            });
        }
    }
};
