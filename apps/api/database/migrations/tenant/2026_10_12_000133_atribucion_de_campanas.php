<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Atribución de campañas del negocio (ADR 0142): de dónde llegó quien dejó una
| solicitud, compró o agendó, guardado con el registro (no solo en el navegador).
| - `contextos_medicion`: el primer origen y el último toque de campaña, y la campaña
|   que se acredita (la del último toque vigente; sin él, «sin atribución»).
| - `prospectos` y `ordenes` apuntan al suyo. La persona se anota para seguir su
|   recorrido: solicitud → cuenta → compra → reserva.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('contextos_medicion', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->unsignedBigInteger('persona_id')->nullable();
            $tabla->string('fuente', 100)->nullable();
            $tabla->string('medio', 100)->nullable();
            $tabla->string('campana', 100)->nullable();
            $tabla->string('referente', 120)->nullable();
            $tabla->json('primer_toque')->nullable();
            $tabla->json('ultimo_toque')->nullable();
            $tabla->timestamps();

            $tabla->index('persona_id');
            $tabla->index(['campana', 'fuente']);
        });

        Schema::connection('tenant')->table('prospectos', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('contexto_medicion_id')->nullable();
        });
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('contexto_medicion_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ordenes', function (Blueprint $tabla): void {
            $tabla->dropColumn('contexto_medicion_id');
        });
        Schema::connection('tenant')->table('prospectos', function (Blueprint $tabla): void {
            $tabla->dropColumn('contexto_medicion_id');
        });
        Schema::connection('tenant')->dropIfExists('contextos_medicion');
    }
};
