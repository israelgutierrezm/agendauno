<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control plane: facturas (CFDI) que LA PLATAFORMA emite al dueño por la renta del
 * SaaS. AgendaUno es el emisor (llave FacturAPI de plataforma) y el estudio el receptor.
 * Se timbra al liquidarse un cargo de renta.
 *
 * - Dinero en unidades menores (nunca float); total = subtotal + IVA.
 * - unique(cargo_renta_id): un solo CFDI por cargo (timbrado idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas_plataforma', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $tabla->foreignId('cargo_renta_id')->unique()->constrained('cargos_renta')->cascadeOnDelete();
            $tabla->string('receptor_nombre');
            $tabla->string('receptor_rfc', 13);
            $tabla->string('receptor_email')->nullable();
            $tabla->string('receptor_regimen', 4)->nullable();
            $tabla->string('receptor_cp', 5);
            $tabla->string('uso_cfdi', 4);
            $tabla->char('moneda', 3)->default('MXN');
            $tabla->unsignedBigInteger('subtotal_minor');
            $tabla->unsignedBigInteger('impuesto_minor');
            $tabla->unsignedBigInteger('total_minor');
            $tabla->string('estado')->index(); // timbrada | error
            $tabla->string('facturapi_id')->nullable();
            $tabla->string('uuid')->nullable();
            $tabla->string('motivo_error')->nullable();
            $tabla->timestamp('timbrada_en')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas_plataforma');
    }
};
