<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A quién se le vendió en el mostrador (ADR 0145):
| - `persona_id`: la persona del negocio que compró (alumno, instructor o alguien del
|   equipo); sin persona, fue venta a público en general;
| - `ticket_enviado_en`: cuándo se le mandó su ticket por correo.
|
| `propina_para_id` (ADR 0139) ya no se llena: la propina dejó de llevar «para quién».
| Se conserva por lo ya registrado.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('persona_id')->nullable();
            $tabla->timestamp('ticket_enviado_en')->nullable();

            $tabla->index(['persona_id', 'id']);
            $tabla->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('ventas_pos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['persona_id', 'id']);
            $tabla->dropIndex(['created_at']);
            $tabla->dropColumn(['persona_id', 'ticket_enviado_en']);
        });
    }
};
