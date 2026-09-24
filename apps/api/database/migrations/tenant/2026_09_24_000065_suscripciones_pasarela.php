<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Mercado Pago y OpenPay por negocio.
| - `domiciliaciones`: el pago automático como SUSCRIPCIÓN de la pasarela (Mercado
|   Pago `preapproval`, suscripción de OpenPay): su id y, en OpenPay, el cliente de
|   la membresía (a él se liga la tarjeta y sus cargos). Estado nuevo `pendiente`
|   mientras el cliente no la autoriza.
| - `configuraciones_pasarela.codigo_verificacion`: el código que OpenPay manda al
|   registrar el webhook, para que el dueño lo capture en su tablero.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('domiciliaciones', function (Blueprint $tabla): void {
            $tabla->string('cliente_externo', 120)->nullable();
            $tabla->string('suscripcion_externa', 120)->nullable()->index();
        });

        Schema::connection('tenant')->table('configuraciones_pasarela', function (Blueprint $tabla): void {
            $tabla->string('codigo_verificacion', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('configuraciones_pasarela', function (Blueprint $tabla): void {
            $tabla->dropColumn('codigo_verificacion');
        });
        Schema::connection('tenant')->table('domiciliaciones', function (Blueprint $tabla): void {
            $tabla->dropIndex(['suscripcion_externa']);
            $tabla->dropColumn(['cliente_externo', 'suscripcion_externa']);
        });
    }
};
