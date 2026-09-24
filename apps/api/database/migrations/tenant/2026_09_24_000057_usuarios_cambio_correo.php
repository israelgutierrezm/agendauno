<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Cambio de correo: el correo nuevo queda pendiente hasta que su dueño confirma el
| enlace que le llega (hash del token y hasta cuándo sirve).
*/
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->string('email_nuevo')->nullable();
            $tabla->string('email_nuevo_token', 64)->nullable()->index();
            $tabla->timestamp('email_nuevo_expira_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('users', function (Blueprint $tabla): void {
            $tabla->dropIndex(['email_nuevo_token']);
            $tabla->dropColumn(['email_nuevo', 'email_nuevo_token', 'email_nuevo_expira_en']);
        });
    }
};
