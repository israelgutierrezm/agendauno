<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| WhatsApp por mensaje (ADR 0186): cuando la plataforma lo cobra, cada aviso por
| WhatsApp gasta un mensaje del saldo prepagado del negocio (paquetes, como los
| timbres). Saldo con movimientos, en la base del negocio.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('saldo_whatsapp', function (Blueprint $t): void {
            $t->id();
            $t->integer('disponibles')->default(0);
            $t->timestamps();
        });
        DB::connection('tenant')->table('saldo_whatsapp')->insert(['disponibles' => 0, 'created_at' => now(), 'updated_at' => now()]);

        Schema::connection('tenant')->create('movimientos_whatsapp', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('tipo', 20);
            $t->integer('cantidad');
            $t->integer('saldo_despues');
            $t->string('referencia', 64)->nullable();
            $t->string('detalle', 255)->nullable();
            $t->timestamps();

            $t->unique(['tipo', 'referencia']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('movimientos_whatsapp');
        Schema::connection('tenant')->dropIfExists('saldo_whatsapp');
    }
};
