<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Perfil público por sucursal y por servicio o clase:
| - la sucursal con su dirección escrita (para el mapa), su teléfono y WhatsApp, sus
|   propias redes (hay negocios con una cuenta por sede) y su horario de atención;
| - cada servicio o clase con una descripción para quien lo elige en línea.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('sucursales', function (Blueprint $tabla): void {
            $tabla->string('direccion', 255)->nullable();
            $tabla->string('telefono', 30)->nullable();
            $tabla->string('whatsapp', 30)->nullable();
            $tabla->json('redes')->nullable();
            // [{dia: 1..7, abre: "HH:MM", cierra: "HH:MM"}]; el día que falta, cerrado.
            $tabla->json('horario')->nullable();
        });

        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->text('descripcion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sucursales', function (Blueprint $tabla): void {
            $tabla->dropColumn(['direccion', 'telefono', 'whatsapp', 'redes', 'horario']);
        });
        Schema::connection('tenant')->table('ofertas', function (Blueprint $tabla): void {
            $tabla->dropColumn('descripcion');
        });
    }
};
