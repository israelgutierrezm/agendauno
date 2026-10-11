<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuestos de los negocios de citas (ADR 0127):
 * - `presupuestos`: folio consecutivo del negocio, para un cliente o un contacto, con
 *   vigencia, impuestos congelados (incluidos o desglosados y su tasa), totales en
 *   centavos, estado y la orden de cobro en que se convirtió.
 * - `lineas_presupuesto`: cada concepto (servicio, producto, artículo o libre).
 * - `configuracion_presupuestos`: los impuestos, la vigencia y las notas por omisión
 *   del negocio, y el último folio.
 * - `lineas_orden.descripcion` y `articulo_id`: un cobro hecho de un presupuesto lleva
 *   conceptos libres y artículos (que salen del inventario al pagarse).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('configuracion_presupuestos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('impuestos', 12)->default('incluidos');
            $tabla->unsignedInteger('tasa_impuesto_bp')->default(1600);
            $tabla->unsignedSmallInteger('vigencia_dias')->default(15);
            $tabla->text('notas')->nullable();
            $tabla->unsignedInteger('ultimo_folio')->default(0);
            $tabla->timestamps();
        });
        Schema::connection('tenant')->create('presupuestos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->unsignedInteger('folio')->unique();
            $tabla->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $tabla->string('nombre_contacto')->nullable();
            $tabla->string('email_contacto')->nullable();
            $tabla->string('celular_contacto', 40)->nullable();
            $tabla->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $tabla->string('estado', 12)->default('borrador');
            $tabla->date('valido_hasta');
            $tabla->char('moneda', 3);
            $tabla->string('impuestos', 12);
            $tabla->unsignedInteger('tasa_impuesto_bp');
            $tabla->unsignedBigInteger('subtotal_minor')->default(0);
            $tabla->unsignedBigInteger('descuento_minor')->default(0);
            $tabla->unsignedBigInteger('impuesto_minor')->default(0);
            $tabla->unsignedBigInteger('total_minor')->default(0);
            $tabla->text('notas')->nullable();
            $tabla->timestamp('enviado_en')->nullable();
            $tabla->timestamp('respondido_en')->nullable();
            $tabla->string('motivo_rechazo', 500)->nullable();
            $tabla->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete();
            $tabla->unsignedBigInteger('creado_por_id')->nullable();
            $tabla->timestamps();

            $tabla->index(['estado', 'valido_hasta']);
        });
        Schema::connection('tenant')->create('lineas_presupuesto', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('presupuesto_id')->constrained('presupuestos')->cascadeOnDelete();
            $tabla->string('tipo', 12);
            $tabla->unsignedBigInteger('referencia_id')->nullable();
            $tabla->string('descripcion');
            $tabla->unsignedInteger('cantidad');
            $tabla->unsignedBigInteger('precio_unitario_minor');
            $tabla->unsignedBigInteger('descuento_minor')->default(0);
            $tabla->unsignedBigInteger('subtotal_minor');
            $tabla->unsignedSmallInteger('posicion')->default(1);
            $tabla->timestamps();
        });
        Schema::connection('tenant')->table('lineas_orden', function (Blueprint $tabla): void {
            $tabla->string('descripcion')->nullable();
            $tabla->unsignedBigInteger('articulo_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('lineas_orden', function (Blueprint $tabla): void {
            $tabla->dropColumn(['descripcion', 'articulo_id']);
        });
        Schema::connection('tenant')->dropIfExists('lineas_presupuesto');
        Schema::connection('tenant')->dropIfExists('presupuestos');
        Schema::connection('tenant')->dropIfExists('configuracion_presupuestos');
    }
};
