<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cortes de nómina: un periodo abierto se calcula al vuelo; al cerrarlo queda la foto
| de lo devengado por persona (con quién lo cerró), después se registra el pago con su
| referencia. Lo que se corrija después va en ajustes, aparte del corte.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('cortes_nomina', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->date('desde');
            $tabla->date('hasta');
            $tabla->string('estado', 12);
            $tabla->char('moneda', 3);
            $tabla->bigInteger('total_minor');
            $tabla->unsignedBigInteger('cerrado_por_id')->nullable();
            $tabla->string('cerrado_por_nombre')->nullable();
            $tabla->timestamp('cerrado_en');
            $tabla->unsignedBigInteger('pagado_por_id')->nullable();
            $tabla->string('pagado_por_nombre')->nullable();
            $tabla->timestamp('pagado_en')->nullable();
            $tabla->string('referencia_pago', 120)->nullable();
            $tabla->timestamps();

            $tabla->index(['desde', 'hasta']);
        });

        Schema::connection('tenant')->create('lineas_corte_nomina', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('corte_id')->constrained('cortes_nomina')->cascadeOnDelete();
            $tabla->unsignedBigInteger('usuario_id')->nullable();
            $tabla->string('usuario_nombre')->nullable();
            $tabla->string('tipo', 20);
            $tabla->bigInteger('monto_minor');
            $tabla->char('moneda', 3);
            $tabla->date('vigente_desde')->nullable();
            $tabla->decimal('unidades', 12, 3);
            $tabla->bigInteger('total_minor');
            $tabla->timestamps();

            $tabla->index('usuario_id');
        });

        Schema::connection('tenant')->create('ajustes_nomina', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->foreignId('corte_id')->constrained('cortes_nomina')->cascadeOnDelete();
            $tabla->unsignedBigInteger('usuario_id')->nullable();
            $tabla->string('usuario_nombre')->nullable();
            $tabla->bigInteger('monto_minor');
            $tabla->char('moneda', 3);
            $tabla->string('motivo', 250);
            $tabla->unsignedBigInteger('registrado_por_id')->nullable();
            $tabla->string('registrado_por_nombre')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('ajustes_nomina');
        Schema::connection('tenant')->dropIfExists('lineas_corte_nomina');
        Schema::connection('tenant')->dropIfExists('cortes_nomina');
    }
};
