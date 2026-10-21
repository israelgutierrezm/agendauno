<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Avisos al momento de Google Calendar y Outlook (ADR 0199, ajuste del 2026-10-21): la
| suscripción de cada conexión a los cambios de su calendario principal. `aviso_id` es
| el canal de Google o la suscripción de Microsoft; `aviso_secreto` (cifrado) es lo que
| regresa en cada aviso para saber que es nuestro. Sin suscripción (o mientras se
| reintenta), sigue la pasada de cada 10 minutos.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('calendarios_externos', function (Blueprint $t): void {
            $t->string('aviso_id', 190)->nullable()->index();
            $t->string('aviso_recurso', 255)->nullable();
            $t->text('aviso_secreto')->nullable();
            $t->timestamp('aviso_vence_en')->nullable();
            $t->timestamp('aviso_reintento_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('calendarios_externos', function (Blueprint $t): void {
            $t->dropIndex(['aviso_id']);
            $t->dropColumn(['aviso_id', 'aviso_recurso', 'aviso_secreto', 'aviso_vence_en', 'aviso_reintento_en']);
        });
    }
};
