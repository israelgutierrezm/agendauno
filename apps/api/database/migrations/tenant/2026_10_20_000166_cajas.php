<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Cierre y arqueo de caja (ADR 0175):
| - `turnos_caja`: la caja de una sucursal de su apertura (con su fondo) a su cierre.
|   `sucursal_abierta` vale la sucursal mientras el turno no está cerrado: el índice
|   único impide dos cajas sin cerrar en la misma sede.
| - `movimientos_caja`: el libro de cada turno. Cada peso de mostrador de la sede queda
|   aquí al moverse (cobros, ventas, propinas, devoluciones, anulaciones, correcciones,
|   retiros e ingresos), con signo. Lo cerrado no se toca: un ajuste posterior va al
|   turno abierto de ese momento.
| - `conteos_caja`: al cerrar, lo esperado, lo contado y la diferencia de cada forma de
|   pago. Se registra una sola vez.
| Los roles propios que cobran reciben `caja.operar`; los que ven la facturación, también
| `caja.supervisar` (nadie pierde lo que hacía cuando su sede empiece a usar la caja).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('turnos_caja', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->unsignedBigInteger('sucursal_id')->index();
            $tabla->unsignedBigInteger('sucursal_abierta')->nullable()->unique();
            $tabla->string('estado', 12)->default('abierta');
            $tabla->char('moneda', 3);
            $tabla->unsignedBigInteger('fondo_minor')->default(0);
            $tabla->string('nota_apertura', 300)->nullable();
            $tabla->unsignedBigInteger('abierta_por')->nullable();
            $tabla->timestamp('abierta_en');
            $tabla->unsignedBigInteger('contada_por')->nullable();
            $tabla->timestamp('contada_en')->nullable();
            $tabla->unsignedBigInteger('cerrada_por')->nullable();
            $tabla->timestamp('cerrada_en')->nullable();
            $tabla->bigInteger('diferencia_minor')->nullable();
            $tabla->string('motivo_diferencia', 500)->nullable();
            $tabla->boolean('requiere_revision')->default(false);
            $tabla->unsignedBigInteger('revisada_por')->nullable();
            $tabla->timestamp('revisada_en')->nullable();
            $tabla->string('nota_revision', 500)->nullable();
            $tabla->timestamps();

            $tabla->index(['sucursal_id', 'abierta_en']);
        });

        Schema::connection('tenant')->create('movimientos_caja', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->unsignedBigInteger('turno_caja_id');
            $tabla->string('tipo', 20);
            $tabla->string('metodo', 20);
            $tabla->bigInteger('monto_minor');
            $tabla->unsignedBigInteger('pago_id')->nullable()->index();
            $tabla->unsignedBigInteger('venta_pos_id')->nullable()->index();
            $tabla->unsignedBigInteger('reembolso_id')->nullable();
            $tabla->unsignedBigInteger('usuario_id')->nullable();
            $tabla->string('motivo', 300)->nullable();
            $tabla->timestamps();

            $tabla->index(['turno_caja_id', 'id']);
        });

        Schema::connection('tenant')->create('conteos_caja', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->unsignedBigInteger('turno_caja_id');
            $tabla->string('metodo', 20);
            $tabla->bigInteger('esperado_minor');
            $tabla->bigInteger('contado_minor');
            $tabla->bigInteger('diferencia_minor');
            $tabla->timestamps();

            $tabla->unique(['turno_caja_id', 'metodo']);
        });

        $db = DB::connection('tenant');
        foreach ($db->table('roles')->get(['id', 'permisos']) as $rol) {
            $permisos = json_decode((string) $rol->permisos, true);
            if (! is_array($permisos)) {
                continue;
            }
            $nuevos = $permisos;
            if (array_intersect(['ordenes.gestionar', 'pos.vender', 'facturacion.ver'], $permisos) !== []) {
                $nuevos[] = 'caja.operar';
            }
            if (in_array('facturacion.ver', $permisos, true)) {
                $nuevos[] = 'caja.supervisar';
            }
            if ($nuevos !== $permisos) {
                $db->table('roles')->where('id', $rol->id)->update(['permisos' => json_encode(array_values(array_unique($nuevos)))]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('conteos_caja');
        Schema::connection('tenant')->dropIfExists('movimientos_caja');
        Schema::connection('tenant')->dropIfExists('turnos_caja');
    }
};
