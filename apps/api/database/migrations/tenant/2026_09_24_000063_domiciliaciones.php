<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Pago automático (domiciliación) de membresías: el alumno autoriza en la pasarela
| que cada renovación se cobre sola a su tarjeta. Solo se guardan referencias de la
| pasarela (cliente, método) y lo necesario para mostrar la tarjeta (marca, últimos
| 4, vencimiento); nunca datos de tarjeta.
|
| - `clientes_pasarela`: el cliente de cada persona en la cuenta de la pasarela del
|   negocio (a él se ligan sus tarjetas).
| - `domiciliaciones`: el cobro automático de una membresía (acuerdo) y con qué.
| - `ordenes.domiciliar`: al pagar esta compra se guarda la tarjeta para cobrar sola
|   la membresía cada periodo.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('clientes_pasarela', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->string('proveedor', 20);
            $tabla->string('cliente_externo', 120);
            $tabla->timestamps();

            $tabla->unique(['persona_id', 'proveedor']);
        });

        Schema::connection('tenant')->create('domiciliaciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('acuerdo_id')->constrained('acuerdos')->cascadeOnDelete();
            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->string('proveedor', 20);
            $tabla->string('estado', 20); // activa | cancelada
            $tabla->string('metodo_externo', 120)->nullable();
            $tabla->string('marca', 30)->nullable();
            $tabla->string('ultimos4', 4)->nullable();
            $tabla->unsignedTinyInteger('expira_mes')->nullable();
            $tabla->unsignedSmallInteger('expira_anio')->nullable();
            $tabla->string('ultimo_error', 255)->nullable();
            $tabla->timestamp('ultimo_error_en')->nullable();
            $tabla->timestamp('activada_en')->nullable();
            $tabla->timestamp('cancelada_en')->nullable();
            $tabla->timestamps();

            $tabla->index(['acuerdo_id', 'estado']);
            $tabla->index(['persona_id', 'proveedor', 'estado']);
        });

        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->boolean('domiciliar')->default(false);
        });

        Schema::connection('tenant')->table('pagos', function (Blueprint $tabla): void {
            // Cobro automático (sin el cliente presente) con la tarjeta domiciliada.
            $tabla->foreignId('domiciliacion_id')->nullable()->constrained('domiciliaciones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('pagos', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('domiciliacion_id');
        });
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->dropColumn('domiciliar');
        });
        Schema::connection('tenant')->dropIfExists('domiciliaciones');
        Schema::connection('tenant')->dropIfExists('clientes_pasarela');
    }
};
