<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cómo cobrar lo nuestro (ADR 0188): referidos entre negocios (un mes gratis para los
| dos cuando el invitado paga su primer mes) y los servicios con precio publicado
| (arranque acompañado, app con la marca del negocio).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estudios', function (Blueprint $t): void {
            $t->string('codigo_referido', 16)->nullable()->unique();
            $t->foreignId('referido_por_id')->nullable()->constrained('estudios')->nullOnDelete();
        });

        Schema::create('beneficios_renta', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->foreignId('estudio_id')->constrained('estudios')->cascadeOnDelete();
            $t->string('tipo', 30);
            $t->string('motivo', 200);
            $t->foreignId('origen_estudio_id')->nullable()->constrained('estudios')->nullOnDelete();
            $t->foreignId('cargo_id')->nullable()->constrained('cargos_renta')->nullOnDelete();
            $t->timestamp('aplicado_en')->nullable();
            $t->timestamps();

            $t->unique(['estudio_id', 'tipo', 'origen_estudio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficios_renta');
        Schema::table('estudios', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('referido_por_id');
            $t->dropColumn('codigo_referido');
        });
    }
};
