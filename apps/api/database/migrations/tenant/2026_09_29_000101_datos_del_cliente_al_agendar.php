<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Más datos del cliente al agendar (ADR 0067): cómo conoció al negocio (en su ficha)
| y una nota para el negocio en la cita (alergias, preferencias, primera vez…).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->string('como_nos_conocio', 30)->nullable();
        });
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->string('nota_cliente', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropColumn('nota_cliente');
        });
        Schema::connection('tenant')->table('personas', function (Blueprint $tabla): void {
            $tabla->dropColumn('como_nos_conocio');
        });
    }
};
