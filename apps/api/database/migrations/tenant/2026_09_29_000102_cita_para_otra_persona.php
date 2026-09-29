<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Agendar para otra persona (ADR 0068): la cita es de quien agenda (paga y recibe los
| avisos) y guarda el nombre de quien asiste. No crea fichas ni familias (ADR 0059).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->string('asiste', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropColumn('asiste');
        });
    }
};
