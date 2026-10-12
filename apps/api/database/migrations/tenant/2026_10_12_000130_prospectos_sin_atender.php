<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Un prospecto que su responsable no atiende (ADR 0138): desde cuándo se marcó «sin
| atender». Se limpia al atenderlo (nota, contacto, cambio de estado, seguimiento) o al
| reasignarlo.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('prospectos', function (Blueprint $tabla): void {
            $tabla->timestamp('sin_atender_desde')->nullable();
            $tabla->index(['estado', 'sin_atender_desde']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('prospectos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['estado', 'sin_atender_desde']);
            $tabla->dropColumn('sin_atender_desde');
        });
    }
};
