<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoBajaDeNegocio;
use App\Modules\Tenancy\Mail\CorreoExportacionLista;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ExportacionNegocio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| Ciclo de vida del negocio (ADR 0213): la prueba que termina pasa a activo, el
| estado de facturación se deriva, y la baja (del dueño o de la plataforma) corta el
| acceso, se puede revertir en el plazo y, vencido, borra los datos del negocio.
*/

/** El negocio de la prueba, del control plane. */
function negocioDeLaBaja(string $slug): Estudio
{
    return Estudio::query()->where('slug', $slug)->sole();
}

it('el dueño da de baja su negocio: solo él entra, su equipo y sus clientes ya no, y puede revertirla', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-baja', 'dueno@baja.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@baja.mx', 'recepcionista');
    alumnoConSesion($e, 'Vale', 'vale@baja.mx');

    // Confirma con su contraseña y escribiendo la dirección del negocio.
    $this->postJson("/api/v1/app/{$e['slug']}/negocio/baja", ['password' => 'otra', 'confirmacion' => $e['slug']], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['password'], 'meta.errors');
    $this->postJson("/api/v1/app/{$e['slug']}/negocio/baja", ['password' => 'secreto123', 'confirmacion' => 'otro'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['confirmacion'], 'meta.errors');
    // Nadie más que un dueño.
    $this->postJson("/api/v1/app/{$e['slug']}/negocio/baja", ['password' => 'secreto123', 'confirmacion' => $e['slug']], conBearer($recepcion))
        ->assertForbidden();

    $this->postJson("/api/v1/app/{$e['slug']}/negocio/baja", [
        'password' => 'secreto123', 'confirmacion' => strtoupper($e['slug']), 'motivo' => 'Cerramos el estudio',
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'cancelled')
        ->assertJsonPath('data.datos_borrar_en', now()->addDays(30)->toDateString());

    $negocio = negocioDeLaBaja($e['slug']);
    expect($negocio->baja_por)->toBe('dueno')->and($negocio->baja_motivo)->toBe('Cerramos el estudio')
        ->and($negocio->estadoDeFacturacion()->value)->toBe('cancelled');
    Mail::assertQueued(CorreoBajaDeNegocio::class, fn (CorreoBajaDeNegocio $m): bool => $m->hasTo('dueno@baja.mx') && $m->laPidioElDueno);

    // El equipo perdió su sesión y ni él ni los clientes vuelven a entrar.
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($recepcion))->assertUnauthorized();
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'recepcion@baja.mx', 'password' => 'secreto123'])
        ->assertStatus(422)->assertJsonValidationErrors(['email'], 'meta.errors');
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'vale@baja.mx', 'password' => 'secreto123'])
        ->assertStatus(422);

    // El dueño entra: ve la baja y su renta, pero no el resto del panel.
    $dueno = (string) $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'dueno@baja.mx', 'password' => 'secreto123'])
        ->assertOk()->assertJsonPath('data.estudio.estado', 'cancelled')
        ->assertJsonPath('data.estudio.baja.puede_revertir', true)
        ->json('data.token');
    $this->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($dueno))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($dueno))->assertNotFound();

    // La revierte: el negocio vuelve a abrir.
    $this->postJson("/api/v1/app/{$e['slug']}/negocio/revertir-baja", [], conBearer($dueno))
        ->assertOk()->assertJsonPath('data.estado', 'trialing');
    $this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($dueno))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'recepcion@baja.mx', 'password' => 'secreto123'])->assertOk();
    expect(negocioDeLaBaja($e['slug'])->datos_borrar_en)->toBeNull();
});

it('la plataforma da de baja con un motivo; el dueño no la revierte, la plataforma sí', function (): void {
    Mail::fake();
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('estudio-baja-plat', 'dueno@plat.mx');

    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/baja", [], conPlataforma())
        ->assertStatus(422)->assertJsonValidationErrors(['motivo'], 'meta.errors');
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/baja", ['motivo' => 'Uso indebido'], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.estado', 'cancelled')
        ->assertJsonPath('data.baja.por', 'plataforma')
        ->assertJsonPath('data.baja.motivo', 'Uso indebido');
    Mail::assertQueued(CorreoBajaDeNegocio::class, fn (CorreoBajaDeNegocio $m): bool => ! $m->laPidioElDueno);
    // Ya estaba dada.
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/baja", ['motivo' => 'Otra vez'], conPlataforma())->assertStatus(422);

    $dueno = (string) $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'dueno@plat.mx', 'password' => 'secreto123'])
        ->assertOk()->assertJsonPath('data.estudio.baja.puede_revertir', false)->json('data.token');
    $this->postJson("/api/v1/app/{$e['slug']}/negocio/revertir-baja", [], conBearer($dueno))->assertStatus(422);

    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/revertir-baja", [], conPlataforma())
        ->assertOk()->assertJsonPath('data.estado', 'trialing')->assertJsonPath('data.baja', null);
});

it('vencido el plazo se borran su base, sus archivos y sus respaldos; su registro y su renta se quedan', function (): void {
    Mail::fake();
    Storage::fake('public');
    Storage::fake('local');
    $e = estudioConSesion('estudio-baja-borra', 'dueno@borra.mx');
    $negocio = negocioDeLaBaja($e['slug']);
    Storage::disk('public')->put("estudios/{$negocio->id}/logo.png", 'logo');
    Storage::disk('local')->put("documentos/{$negocio->id}/contrato.pdf", 'pdf');
    Storage::disk('local')->put('documentos/999/de-otro.pdf', 'pdf');
    CargoRenta::query()->create([
        'estudio_id' => $negocio->id, 'periodo' => '2026-09', 'clave' => 'periodo', 'concepto' => 'renta',
        'modo_cobro' => 'fijo', 'monto_minor' => 0, 'moneda' => 'MXN', 'estado' => 'sin_cargo',
    ]);

    $this->postJson("/api/v1/app/{$e['slug']}/negocio/baja", ['password' => 'secreto123', 'confirmacion' => $e['slug']], conBearer($e['bearer']))->assertOk();

    // Antes del plazo, nada se borra.
    $this->artisan('agendauno:ciclo-de-negocios')->assertSuccessful();
    expect(app(GestorDeConexionTenant::class)->baseDeDatosExiste(negocioDeLaBaja($e['slug'])))->toBeTrue();

    $this->travelTo(now()->addDays(30)->setTime(10, 0));
    $this->artisan('agendauno:ciclo-de-negocios')->assertSuccessful();

    $negocio = negocioDeLaBaja($e['slug']);
    expect($negocio->datos_borrados_en)->not->toBeNull()
        ->and(app(GestorDeConexionTenant::class)->baseDeDatosExiste($negocio))->toBeFalse()
        ->and(CargoRenta::query()->where('estudio_id', $negocio->id)->count())->toBe(1);
    Storage::disk('public')->assertMissing("estudios/{$negocio->id}/logo.png");
    Storage::disk('local')->assertMissing("documentos/{$negocio->id}/contrato.pdf");
    Storage::disk('local')->assertExists('documentos/999/de-otro.pdf');

    // Ya no entra nadie y no se puede revertir.
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'dueno@borra.mx', 'password' => 'secreto123'])->assertNotFound();
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/revertir-baja", [], conPlataforma())->assertStatus(422);
    $this->travelBack();
});

it('la prueba que terminó pasa a activo y el estado de facturación sale de su renta', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('estudio-ciclo', 'dueno@ciclo.mx');
    expect(negocioDeLaBaja($e['slug'])->estadoDeFacturacion()->value)->toBe('trial');

    Estudio::query()->where('slug', $e['slug'])->update(['trial_termina_en' => now()->subDay()->toDateString()]);
    $this->artisan('agendauno:ciclo-de-negocios')->assertSuccessful();
    $negocio = negocioDeLaBaja($e['slug']);
    expect($negocio->estado->value)->toBe('active')->and($negocio->estadoDeFacturacion()->value)->toBe('active');

    // Con una renta vencida y sin pagar, debe la renta.
    CargoRenta::query()->create([
        'estudio_id' => $negocio->id, 'periodo' => '2026-08', 'clave' => 'periodo', 'concepto' => 'renta',
        'modo_cobro' => 'fijo', 'monto_minor' => 149900, 'moneda' => 'MXN', 'estado' => 'pendiente',
        'vence_en' => now()->subDays(3)->toDateString(),
    ]);
    $this->getJson('/api/v1/plataforma/estudios', conPlataforma())->assertOk()
        ->assertJsonPath('data.0.estado_facturacion', 'past_due');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estudio.estado_facturacion', 'past_due');
    // Ya no se captura a mano.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}", [
        'modo_cobro' => 'fijo', 'cuota_fija_minor' => 149900, 'estado_facturacion' => 'active',
    ], conPlataforma())->assertOk()->assertJsonPath('data.estado_facturacion', 'past_due');
});

it('dado de baja, su último mes se cobra y los siguientes no', function (): void {
    Mail::fake();
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('estudio-baja-renta', 'dueno@renta.mx');
    terminarPrueba($e);
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}", ['modo_cobro' => 'fijo', 'cuota_fija_minor' => 149900], conPlataforma())->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/negocio/baja", ['password' => 'secreto123', 'confirmacion' => $e['slug']], conBearer($e['bearer']))->assertOk();
    $mesDeBaja = now('America/Mexico_City')->format('Y-m');

    // Dos meses después (aún en el plazo de un negocio con más días para borrar).
    Estudio::query()->where('slug', $e['slug'])->update(['datos_borrar_en' => now()->addDays(90)->toDateString()]);
    $this->travelTo(now('America/Mexico_City')->addMonthsNoOverflow(2)->startOfMonth()->setTime(12, 0));
    $this->artisan('agendauno:generar-cargos-renta')->assertSuccessful();

    $periodos = CargoRenta::query()->where('estudio_id', negocioDeLaBaja($e['slug'])->id)->where('clave', 'periodo')->pluck('periodo')->all();
    expect($periodos)->toContain($mesDeBaja)
        ->and(max($periodos))->toBe($mesDeBaja);
    $this->travelBack();
});

it('el dueño descarga todos los datos del negocio, sin secretos, también dado de baja', function (): void {
    Mail::fake();
    Storage::fake('public');
    Storage::fake('local');
    $e = estudioConSesion('estudio-exporta', 'dueno@exporta.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@exporta.mx', 'recepcionista');
    $negocio = negocioDeLaBaja($e['slug']);
    Storage::disk('local')->put("documentos/{$negocio->id}/contrato.pdf", 'pdf');

    $this->postJson("/api/v1/app/{$e['slug']}/negocio/exportar", [], conBearer($recepcion))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/negocio/exportar", conBearer($e['bearer']))->assertOk()->assertJsonPath('data', null);

    // La cola de las pruebas es síncrona: queda lista al pedirla.
    $id = (string) $this->postJson("/api/v1/app/{$e['slug']}/negocio/exportar", [], conBearer($e['bearer']))
        ->assertStatus(202)->json('data.id');
    $this->getJson("/api/v1/app/{$e['slug']}/negocio/exportar", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'lista');
    Mail::assertQueued(CorreoExportacionLista::class, fn (CorreoExportacionLista $m): bool => $m->hasTo('dueno@exporta.mx'));

    $respuesta = $this->get("/api/v1/app/{$e['slug']}/negocio/exportar/{$id}/descargar", conBearer($e['bearer']))->assertOk();
    $zipLocal = (string) tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($zipLocal, $respuesta->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($zipLocal))->toBeTrue();
    $usuarios = (string) $zip->getFromName('datos/users.csv');
    expect($usuarios)->toContain('dueno@exporta.mx')->toContain('email')
        ->not->toContain('password')->not->toContain('remember_token')
        ->and($zip->locateName('datos/personal_access_tokens.csv'))->toBeFalse()
        ->and($zip->getFromName('archivos/documentos/'.$negocio->id.'/contrato.pdf'))->toBe('pdf')
        ->and((string) $zip->getFromName('LEEME.txt'))->toContain('users.csv');
    $zip->close();
    unlink($zipLocal);

    // Dado de baja, el dueño aún pide y descarga sus datos.
    $this->postJson("/api/v1/app/{$e['slug']}/negocio/baja", ['password' => 'secreto123', 'confirmacion' => $e['slug']], conBearer($e['bearer']))->assertOk();
    $otra = (string) $this->postJson("/api/v1/app/{$e['slug']}/negocio/exportar", [], conBearer($e['bearer']))
        ->assertStatus(202)->json('data.id');
    $this->get("/api/v1/app/{$e['slug']}/negocio/exportar/{$otra}/descargar", conBearer($e['bearer']))->assertOk();
    // La anterior se reemplazó.
    expect(ExportacionNegocio::query()->where('ulid', $id)->exists())->toBeFalse();

    // Vencida, ya no se descarga y la limpieza borra su archivo.
    $ruta = (string) ExportacionNegocio::query()->where('ulid', $otra)->value('ruta');
    $this->travelTo(now()->addDays(8));
    $this->get("/api/v1/app/{$e['slug']}/negocio/exportar/{$otra}/descargar", conBearer($e['bearer']))->assertNotFound();
    Estudio::query()->where('slug', $e['slug'])->update(['datos_borrar_en' => now()->addDays(30)->toDateString()]);
    $this->artisan('agendauno:ciclo-de-negocios')->assertSuccessful();
    Storage::disk('local')->assertMissing($ruta);
    expect(ExportacionNegocio::query()->where('ulid', $otra)->exists())->toBeFalse();
    $this->travelBack();
});
