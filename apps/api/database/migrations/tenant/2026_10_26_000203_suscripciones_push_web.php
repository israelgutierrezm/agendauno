<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Push en la web (ADR 0225): cada navegador donde un usuario activó los avisos de este
| negocio. El servicio de push del navegador da su dirección (`endpoint`) y las llaves
| para cifrarle el mensaje (`p256dh`, `auth`). Una dirección es de un solo usuario.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('suscripciones_push_web', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $tabla->text('endpoint');
            // Huella del endpoint: única (el endpoint puede pasar del tamaño de un índice).
            $tabla->char('endpoint_huella', 64)->unique();
            $tabla->string('p256dh', 128);
            $tabla->string('auth', 64);
            $tabla->timestamp('registrada_en');
            $tabla->timestamps();

            $tabla->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('suscripciones_push_web');
    }
};
