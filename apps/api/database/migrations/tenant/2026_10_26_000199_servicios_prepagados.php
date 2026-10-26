<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Servicios pagados por adelantado (ADR 0218): el concepto de una orden que es un
| servicio del catálogo (un presupuesto convertido) dice cuál, y la cita que se agenda
| con ese pago apunta a ese concepto. Así la cita no se cobra de nuevo y cada concepto
| se usa hasta su cantidad.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineas_orden', function (Blueprint $t): void {
            $t->foreignId('oferta_id')->nullable()->constrained('ofertas')->nullOnDelete();
        });
        Schema::table('reservas', function (Blueprint $t): void {
            $t->foreignId('linea_orden_id')->nullable()->constrained('lineas_orden')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('linea_orden_id');
        });
        Schema::table('lineas_orden', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('oferta_id');
        });
    }
};
