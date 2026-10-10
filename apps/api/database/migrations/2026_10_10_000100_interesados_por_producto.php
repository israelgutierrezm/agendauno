<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Interesados en un producto que aún no abre registros (ADR 0108): la landing de
| TurnoUno junta los datos de contacto de quien quiere usarlo cuando se lance. Una fila
| por correo y producto: si vuelve a escribir, se actualiza.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interesados', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->ulid('ulid')->unique();
            $tabla->string('producto', 20);
            $tabla->string('nombre', 120);
            $tabla->string('correo', 190);
            $tabla->string('telefono', 30)->nullable();
            $tabla->string('negocio', 120)->nullable();
            $tabla->string('giro', 40)->nullable();
            $tabla->string('ciudad', 120)->nullable();
            $tabla->string('mensaje', 500)->nullable();
            $tabla->timestamp('acepto_aviso_en');
            $tabla->timestamp('avisado_en')->nullable();
            $tabla->timestamps();

            $tabla->unique(['producto', 'correo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interesados');
    }
};
