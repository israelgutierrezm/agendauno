<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| País de cada negocio (ADR 0103): los que no lo tenían son de México (todos los
| negocios actuales lo son) y desde ahora todo negocio tiene uno, en mayúsculas.
| Ya no hay «sin país = México»: de él sale la lada de los celulares sin «+».
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('estudios')
            ->where(fn ($q) => $q->whereNull('pais')->orWhere('pais', ''))
            ->update(['pais' => 'MX']);
        DB::table('estudios')->update(['pais' => DB::raw('UPPER(pais)')]);

        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->string('pais', 2)->default('MX')->change();
        });
    }

    public function down(): void
    {
        Schema::table('estudios', function (Blueprint $tabla): void {
            $tabla->string('pais', 2)->nullable()->default(null)->change();
        });
    }
};
