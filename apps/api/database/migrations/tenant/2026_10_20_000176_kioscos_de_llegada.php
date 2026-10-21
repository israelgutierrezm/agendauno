<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Kiosco de llegada (ADR 0183): una tableta en recepción donde el cliente muestra su
| pase y queda su llegada. Cada tableta tiene su propia llave (solo se guarda su hash),
| su sede y se puede dar de baja sin tocar a las demás.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('kioscos', function (Blueprint $t): void {
            $t->id();
            $t->ulid('ulid')->unique();
            $t->string('nombre', 80);
            $t->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $t->char('hash', 64)->unique();
            $t->string('prefijo', 12);
            $t->unsignedBigInteger('creado_por')->nullable();
            $t->timestamp('ultimo_uso_en')->nullable();
            $t->timestamp('revocado_en')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('kioscos');
    }
};
