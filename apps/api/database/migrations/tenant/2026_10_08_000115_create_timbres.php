<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data plane (BD del tenant): los timbres para facturar (ADR 0107). Saldo con
 * movimientos: cada compra suma, cada factura timbrada resta. `saldo_timbres` es la
 * fila que se bloquea al mover el saldo (una sola) y lleva el disponible al día; los
 * movimientos son la historia (una compra o una factura se registra una sola vez).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('saldo_timbres', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->integer('disponibles')->default(0);
            $tabla->timestamps();
        });
        DB::connection('tenant')->table('saldo_timbres')->insert(['disponibles' => 0, 'created_at' => now(), 'updated_at' => now()]);

        Schema::connection('tenant')->create('movimientos_timbres', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('tipo', 20);
            $tabla->integer('cantidad');
            $tabla->integer('saldo_despues');
            $tabla->string('referencia', 64)->nullable();
            $tabla->string('detalle', 255)->nullable();
            $tabla->timestamps();

            $tabla->unique(['tipo', 'referencia']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('movimientos_timbres');
        Schema::connection('tenant')->dropIfExists('saldo_timbres');
    }
};
