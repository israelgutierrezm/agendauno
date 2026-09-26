<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Avisos sin repetir (cierre de la fase 1): el outbox entrega "al menos una vez", así
| que si un consumidor falla el evento se reintenta. Cada mensaje generado por un
| evento lleva su llave de envío (evento + plantilla + a quién) con índice único, y
| las entregas de webhook se buscan por (webhook, evento) antes de repetirse.
|
| Lo anterior queda sin llave (NULL): el índice único admite varios NULL.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->string('clave_envio', 120)->nullable()->unique();
        });
        Schema::connection('tenant')->table('entregas_webhook', function (Blueprint $tabla): void {
            $tabla->index(['webhook_id', 'evento_ulid']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('entregas_webhook', function (Blueprint $tabla): void {
            $tabla->dropIndex(['webhook_id', 'evento_ulid']);
        });
        Schema::connection('tenant')->table('mensajes', function (Blueprint $tabla): void {
            $tabla->dropUnique(['clave_envio']);
            $tabla->dropColumn('clave_envio');
        });
    }
};
