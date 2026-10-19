<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La comprobación de una conexión corresponde a su configuración vigente (R13): cuándo
| cambió por última vez lo que la define (sus llaves o su modo). Solo la evidencia
| posterior (un pago aprobado, un check-in validado, un aviso recibido) la comprueba; la
| anterior queda como historial. No se guarda ninguna llave ni su huella.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        foreach (['configuraciones_pasarela', 'integraciones'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->timestamp('configurada_en')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['configuraciones_pasarela', 'integraciones'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->dropColumn('configurada_en');
            });
        }
    }
};
