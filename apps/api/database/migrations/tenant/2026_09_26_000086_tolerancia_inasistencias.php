<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Tolerancia de inasistencias (ADR 0043): cuántas faltas se toleran sin cobrar el
| crédito, y en cuántos días a la redonda se cuentan. La tolerancia ya existía en la
| política; faltaba la ventana y aplicarla. Cada asistencia guarda si cobró el crédito,
| así una corrección posterior sabe qué compensar.
|
| Lo anterior: la ventana queda vacía (aplica la de la plataforma) y el cobro de las
| asistencias ya registradas se deduce como antes.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('politicas_cancelacion', function (Blueprint $tabla): void {
            $tabla->unsignedSmallInteger('ventana_no_show_dias')->nullable();
        });
        Schema::connection('tenant')->table('asistencias', function (Blueprint $tabla): void {
            $tabla->boolean('credito_cobrado')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('asistencias', function (Blueprint $tabla): void {
            $tabla->dropColumn('credito_cobrado');
        });
        Schema::connection('tenant')->table('politicas_cancelacion', function (Blueprint $tabla): void {
            $tabla->dropColumn('ventana_no_show_dias');
        });
    }
};
