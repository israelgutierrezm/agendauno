<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Mail\CorreoActivacion;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Soporte desde el superadmin (ADR 0226): dar de alta un negocio a nombre de su dueño y
| corregir su contacto. El correo con que entra solo se corrige antes de que active su
| cuenta, y entonces le llega una activación nueva.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** @return array<string, string> */
function contactoAlta(string $correo): array
{
    return [
        'contacto_nombre' => 'Rocío', 'contacto_primer_apellido' => 'Luna', 'contacto_email' => $correo,
        'contacto_whatsapp_pais' => '52', 'contacto_telefono' => '5512345678',
    ];
}

it('el superadmin da de alta un negocio y corrige el correo del dueño antes de que lo active', function (): void {
    Mail::fake();
    $this->postJson('/api/v1/plataforma/estudios', [
        'nombre' => 'Pilates Luna', 'slug' => 'pilates-luna', 'perfil_negocio' => 'pilates', 'pais' => 'MX',
        ...contactoAlta('rocio@corre.mx'),
    ], conPlataforma())->assertCreated()->assertJsonPath('data.slug', 'pilates-luna');
    $estudio = Estudio::query()->where('slug', 'pilates-luna')->sole();
    expect($estudio->estado)->toBe(EstadoEstudio::Trialing);
    Mail::assertQueued(CorreoActivacion::class, fn (CorreoActivacion $m): bool => $m->hasTo('rocio@corre.mx'));

    // Tenía un error en el correo: se corrige aquí y en su negocio, con activación nueva.
    $this->putJson('/api/v1/plataforma/estudios/pilates-luna/contacto', contactoAlta('rocio@correo.mx'), conPlataforma())
        ->assertOk();
    expect($estudio->refresh()->contacto_email)->toBe('rocio@correo.mx')
        ->and(app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => Usuario::query()->where('email', 'rocio@correo.mx')->exists()))->toBeTrue();
    Mail::assertQueued(CorreoActivacion::class, fn (CorreoActivacion $m): bool => $m->hasTo('rocio@correo.mx'));

    // Ya activó su cuenta: el correo con que entra lo cambia ella; el nombre, sí.
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => Usuario::query()->where('email', 'rocio@correo.mx')->update(['activo' => true]));
    $this->putJson('/api/v1/plataforma/estudios/pilates-luna/contacto', contactoAlta('otro@correo.mx'), conPlataforma())
        ->assertStatus(422);
    $this->putJson('/api/v1/plataforma/estudios/pilates-luna/contacto', [...contactoAlta('rocio@correo.mx'), 'contacto_nombre' => 'María Rocío'], conPlataforma())
        ->assertOk();
    expect($estudio->refresh()->contacto_nombre)->toBe('María Rocío');
});
