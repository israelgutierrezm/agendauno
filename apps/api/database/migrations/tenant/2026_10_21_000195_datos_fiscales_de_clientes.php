<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Los datos para facturarle a un cliente (receptor del CFDI): los guarda el cliente en su
| cuenta o el negocio en su ficha. Con `factura_automatica`, sus pagos de citas se
| facturan solos (el anticipo, y la cita con su CFDI de egreso que aplica el anticipo).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('datos_fiscales_personas', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('persona_id')->unique()->constrained('personas')->cascadeOnDelete();
            $t->string('rfc', 13);
            $t->string('nombre', 255);
            $t->string('codigo_postal', 5);
            $t->string('regimen_fiscal', 4);
            $t->string('uso_cfdi', 4);
            $t->string('email', 255)->nullable();
            $t->boolean('factura_automatica')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('datos_fiscales_personas');
    }
};
