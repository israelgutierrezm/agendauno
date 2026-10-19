<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Importar citas de otro sistema (R14): el identificador estable del cliente en ese
| sistema (`personas.referencia_externa`) para reconocerlo sin correo ni celular y al
| reimportar, y el de la cita (`reservas.referencia_externa`) para distinguir un
| duplicado idéntico de un cambio que requiere decisión.
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        foreach (['personas', 'reservas'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->string('referencia_externa', 120)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['personas', 'reservas'] as $tabla) {
            Schema::connection('tenant')->table($tabla, function (Blueprint $t): void {
                $t->dropIndex(['referencia_externa']);
                $t->dropColumn('referencia_externa');
            });
        }
    }
};
