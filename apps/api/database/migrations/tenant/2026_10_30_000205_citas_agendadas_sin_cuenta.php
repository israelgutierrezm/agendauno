<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Las citas agendadas desde la página pública, sin cuenta (ADR 0230): escribir un correo
| no prueba que sea suyo, así que su límite de citas por pagar se cuenta aparte del de la
| ficha. Lo que ya existe queda como agendado con cuenta.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->boolean('sin_cuenta')->default(false)->after('sin_avisos');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropColumn('sin_cuenta');
        });
    }
};
