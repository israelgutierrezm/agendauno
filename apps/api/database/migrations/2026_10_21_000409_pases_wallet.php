<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Pases de la cartera que se actualizan solos (ADR 0195, ajuste). Van en la base de la
| plataforma: el servicio de Apple Wallet pregunta por dispositivo y por número de serie
| sin saber de qué negocio son.
|
| - `pases_wallet`: un pase por persona de un negocio, con la huella de lo que muestra
|   (plan, vigencia, saldo, código) y cuándo cambió; si se guardó en Google Wallet.
| - `dispositivos_pase_wallet`: los teléfonos que tienen el pase en Apple Wallet y su
|   token para avisarles que cambió.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pases_wallet', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $t->string('persona_ulid', 26);
            $t->string('serie', 80)->unique();
            $t->char('huella', 64)->nullable();
            $t->timestamp('cambio_en')->nullable();
            $t->boolean('en_google')->default(false);
            $t->timestamps();

            $t->unique(['estudio_id', 'persona_ulid']);
        });

        Schema::create('dispositivos_pase_wallet', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('pase_wallet_id')->constrained('pases_wallet')->cascadeOnDelete();
            $t->string('dispositivo', 128);
            $t->string('push_token', 255);
            $t->timestamps();

            $t->unique(['pase_wallet_id', 'dispositivo']);
            $t->index('dispositivo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_pase_wallet');
        Schema::dropIfExists('pases_wallet');
    }
};
