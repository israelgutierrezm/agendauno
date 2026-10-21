<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Presupuestos en línea: el cliente lo ve, lo acepta o lo rechaza y lo paga desde el
| enlace de su correo o desde la app. `enlace_hash`: la huella del token del enlace (el
| token solo va en el correo; uno nuevo deja sin servir el anterior). `respondido_por`:
| quién lo respondió (`cliente` en línea o `negocio`).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('presupuestos', function (Blueprint $t): void {
            $t->char('enlace_hash', 64)->nullable();
            $t->string('respondido_por', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('presupuestos', function (Blueprint $t): void {
            $t->dropColumn(['enlace_hash', 'respondido_por']);
        });
    }
};
