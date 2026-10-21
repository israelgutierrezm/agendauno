<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| El dominio propio de un negocio (ADR 0189): su sitio en `reservas.minegocio.com` en
| lugar del subdominio. Uno por negocio; se sirve solo cuando su dueño probó que es
| suyo (registro TXT) y apunta a la plataforma (CNAME o A).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dominios_estudio', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('estudio_id')->unique()->constrained('estudios')->cascadeOnDelete();
            $t->string('dominio', 253)->unique();
            $t->string('token', 64);
            $t->timestamp('propiedad_verificada_en')->nullable();
            $t->timestamp('apunta_desde')->nullable();
            $t->timestamp('revisado_en')->nullable();
            $t->string('problema', 30)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dominios_estudio');
    }
};
