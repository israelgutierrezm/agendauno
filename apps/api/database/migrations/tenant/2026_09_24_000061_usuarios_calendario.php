<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Calendario personal (iCal): enlace privado para suscribirse desde Google Calendar,
| Apple u Outlook. El token se guarda cifrado (para volver a mostrar el enlace) y su
| hash sirve para encontrar a la persona al leer el calendario.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->text('calendario_token')->nullable();
            $tabla->string('calendario_token_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropIndex(['calendario_token_hash']);
            $tabla->dropColumn(['calendario_token', 'calendario_token_hash']);
        });
    }
};
