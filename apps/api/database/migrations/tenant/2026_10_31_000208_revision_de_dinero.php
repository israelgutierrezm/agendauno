<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Revisión de dinero para producción (ADR 0231):
| - `pagos.checkout_guardado`: los datos del intento abierto (cifrados), para repetir la
|   misma respuesta a la misma llave.
| - `lineas_orden.veces_devueltas`: las veces de un servicio prepagado que ya se devolvieron.
| - `reservas.valor_servicio_minor`: el precio del servicio al reservar con bono (comisión).
| - `reservas.cambio_tardio`: se movió dentro del límite de cancelación.
| - `movimientos_caja.requiere_revision`: corrección de un cobro de un turno cerrado.
| - `reembolsos.referencia_externa` con índice: cada aviso la busca. No es única: la devolución ya
|   salió cuando se guarda, y un choque la dejaría sin registrar (el candado del pago ya evita duplicarla).
| - `promociones.usos_por_persona`: tope opcional por persona.
| - `ordenes.caduca_en`: una compra del portal sin pagar caduca sola.
| - `movimientos_inventario.clave_intento`: reintentar un ajuste no lo aplica dos veces.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $esquema = Schema::connection('tenant');
        $esquema->table('pagos', function (Blueprint $tabla): void {
            $tabla->text('checkout_guardado')->nullable();
        });
        $esquema->table('lineas_orden', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('veces_devueltas')->default(0);
        });
        $esquema->table('reservas', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('valor_servicio_minor')->nullable();
            $tabla->boolean('cambio_tardio')->default(false);
        });
        $esquema->table('movimientos_caja', function (Blueprint $tabla): void {
            $tabla->boolean('requiere_revision')->default(false);
        });
        $esquema->table('reembolsos', function (Blueprint $tabla): void {
            $tabla->index('referencia_externa', 'reembolsos_referencia_externa_index');
        });
        $esquema->table('promociones', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('usos_por_persona')->nullable();
        });
        $esquema->table('ordenes', function (Blueprint $tabla): void {
            $tabla->dateTime('caduca_en')->nullable()->index();
        });
        $esquema->table('movimientos_inventario', function (Blueprint $tabla): void {
            $tabla->string('clave_intento', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        $esquema = Schema::connection('tenant');
        $esquema->table('movimientos_inventario', function (Blueprint $tabla): void {
            $tabla->dropUnique(['clave_intento']);
            $tabla->dropColumn('clave_intento');
        });
        $esquema->table('ordenes', function (Blueprint $tabla): void {
            $tabla->dropIndex(['caduca_en']);
            $tabla->dropColumn('caduca_en');
        });
        $esquema->table('promociones', function (Blueprint $tabla): void {
            $tabla->dropColumn('usos_por_persona');
        });
        $esquema->table('reembolsos', function (Blueprint $tabla): void {
            $tabla->dropIndex('reembolsos_referencia_externa_index');
        });
        $esquema->table('movimientos_caja', function (Blueprint $tabla): void {
            $tabla->dropColumn('requiere_revision');
        });
        $esquema->table('reservas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['valor_servicio_minor', 'cambio_tardio']);
        });
        $esquema->table('lineas_orden', function (Blueprint $tabla): void {
            $tabla->dropColumn('veces_devueltas');
        });
        $esquema->table('pagos', function (Blueprint $tabla): void {
            $tabla->dropColumn('checkout_guardado');
        });
    }
};
