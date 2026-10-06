<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CobroDeCitasTenant;
use App\Modules\Tenancy\Application\OpcionesCitaTenant;
use App\Modules\Tenancy\Application\ResolverDerechoTenant;
use App\Modules\Tenancy\Application\WhatsAppTenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

it('expone solo la foto pública del profesional y la región de la sede dentro del tenant', function (): void {
    $configAnterior = config('database.connections.tenant');
    DB::purge('tenant');
    config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    try {
        $schema = Schema::connection('tenant');
        $schema->create('ofertas', function (Blueprint $t): void {
            $t->id();
            $t->string('ulid');
            $t->string('nombre');
            $t->string('politica_reserva');
            $t->string('modalidad')->nullable();
            $t->integer('precio_clase_minor')->nullable();
            $t->integer('duracion_minutos')->nullable();
        });
        $schema->create('sucursales', function (Blueprint $t): void {
            $t->id();
            $t->string('ulid');
            $t->string('nombre');
            $t->string('zona_horaria');
            $t->string('region')->nullable();
        });
        $schema->create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('ulid');
            $t->string('name');
            $t->string('email');
            $t->json('roles');
            $t->string('foto_ruta')->nullable();
            $t->softDeletes();
        });
        // Dónde atiende cada profesional: las sedes en que tiene horario.
        $schema->create('horarios_atencion', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('instructor_id');
            $t->unsignedBigInteger('sucursal_id');
        });
        DB::connection('tenant')->table('sucursales')->insert(['ulid' => 'sede-publica', 'nombre' => 'Centro', 'zona_horaria' => 'America/Mexico_City', 'region' => 'CDMX']);
        foreach (['Ana' => ['instructor', 'tenants/demo/perfiles/ana.webp'], 'Luis' => ['instructor', null], 'Cliente' => ['miembro', 'privada.webp']] as $nombre => [$rol, $foto]) {
            DB::connection('tenant')->table('users')->insert(['ulid' => $nombre, 'name' => $nombre, 'email' => $nombre.'@example.test', 'roles' => json_encode([$rol]), 'foto_ruta' => $foto]);
        }
        $ana = (int) DB::connection('tenant')->table('users')->where('ulid', 'Ana')->value('id');
        $sede = (int) DB::connection('tenant')->table('sucursales')->value('id');
        DB::connection('tenant')->table('horarios_atencion')->insert(['instructor_id' => $ana, 'sucursal_id' => $sede]);
        $cobro = Mockery::mock(CobroDeCitasTenant::class);
        $cobro->shouldReceive('paraPantalla')->andReturn(['pago_obligatorio' => true, 'pago_en_linea' => true]);
        $whatsapp = Mockery::mock(WhatsAppTenant::class);
        $whatsapp->shouldReceive('enUso')->andReturn(false);
        // Sin cuenta (página pública) no se consultan bonos.
        $derechos = Mockery::mock(ResolverDerechoTenant::class);
        $derechos->shouldNotReceive('ofertasCubiertas');
        $opciones = (new OpcionesCitaTenant($cobro, $whatsapp, $derechos))->listar();
        expect($opciones['hay_con_plan'])->toBeFalse();
        expect($opciones['instructores'])->toBe([
            ['id' => 'Ana', 'nombre' => 'Ana', 'foto_url' => Storage::disk('public')->url('tenants/demo/perfiles/ana.webp'), 'sucursales' => ['sede-publica']],
            ['id' => 'Luis', 'nombre' => 'Luis', 'foto_url' => null, 'sucursales' => []],
        ]);
        expect($opciones['sucursales'][0])->toMatchArray(['id' => 'sede-publica', 'region' => 'CDMX']);
    } finally {
        DB::purge('tenant');
        config(['database.connections.tenant' => $configAnterior]);
    }
});
