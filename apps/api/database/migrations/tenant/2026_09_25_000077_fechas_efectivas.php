<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Fechas efectivas (fase 1, punto 1.7): cuándo se aprobó un pago (`aprobado_en`),
| distinta de cuándo se inició; así un pago iniciado ayer y confirmado hoy cuenta en
| el corte de hoy. Las devoluciones ya guardan `aplicado_en`.
|
| Lo anterior se llena con lo mejor que se sabe: la fecha en que se creó el registro
| (en caja coincide; en línea puede adelantarse unos minutos).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('pagos', function (Blueprint $tabla): void {
            $tabla->timestamp('aprobado_en')->nullable();
            $tabla->index('aprobado_en');
        });
        Schema::connection('tenant')->table('reembolsos', function (Blueprint $tabla): void {
            $tabla->index('aplicado_en');
        });

        DB::connection('tenant')->table('pagos')
            ->whereIn('estado', ['aprobado', 'parcialmente_reembolsado', 'reembolsado'])
            ->whereNull('aprobado_en')
            ->update(['aprobado_en' => DB::raw('created_at')]);
        DB::connection('tenant')->table('reembolsos')
            ->where('estado', 'aprobado')
            ->whereNull('aplicado_en')
            ->update(['aplicado_en' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reembolsos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['aplicado_en']);
        });
        Schema::connection('tenant')->table('pagos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['aprobado_en']);
            $tabla->dropColumn('aprobado_en');
        });
    }
};
