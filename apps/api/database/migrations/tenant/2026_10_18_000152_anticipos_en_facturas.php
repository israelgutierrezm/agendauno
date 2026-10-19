<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Facturar anticipos (ADR 0163): una factura puede ser de un anticipo y, al facturar el
| resto («por remanente»), se relaciona con él (tipo 07). `anticipo_aplicado_en` es la
| factura que lo aplicó: un anticipo se aplica una sola vez.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->boolean('anticipo')->default(false);
            $tabla->unsignedBigInteger('anticipo_aplicado_en')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('facturas', function (Blueprint $tabla): void {
            $tabla->dropIndex(['anticipo_aplicado_en']);
            $tabla->dropColumn(['anticipo', 'anticipo_aplicado_en']);
        });
    }
};
