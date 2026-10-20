<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Cuándo se enteró el superadmin de cada incidencia de cobro: si su aviso no sale, se
| reintenta (`agendauno:conciliar-pagos`). Las que ya existían ya avisaron al abrirse.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('incidencias_cobro', function (Blueprint $tabla): void {
            $tabla->timestamp('alertada_en')->nullable();
        });

        DB::connection('tenant')->table('incidencias_cobro')->update(['alertada_en' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('incidencias_cobro', function (Blueprint $tabla): void {
            $tabla->dropColumn('alertada_en');
        });
    }
};
