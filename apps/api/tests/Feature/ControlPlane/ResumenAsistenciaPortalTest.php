<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Resumen personal del cliente (comparativo del 2026-10-20): clases a las que vino este
| mes, semanas seguidas viniendo y total.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('cuenta las clases del mes y las semanas seguidas que lleva viniendo', function (): void {
    $this->travelTo('2026-09-14 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $app = "/api/v1/app/{$e['slug']}";
    $alumna = alumnoConSesion($e);
    $persona = (string) collect($this->getJson("{$app}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $this->postJson("{$app}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e, 20000), 'motivo' => 'Prueba'], conBearer($e['bearer']))->assertCreated();

    // Vino tres semanas seguidas (la del 14, la del 21 y la del 28 de septiembre).
    foreach (['2026-09-15 08:00:00', '2026-09-22 08:00:00', '2026-09-29 08:00:00', '2026-10-01 05:30:00'] as $cuando) {
        $sesion = crearSesionTenant($e, $semilla, 10, $cuando);
        $reserva = (string) $this->postJson("{$app}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated()->json('data.id');
        $this->travelTo(CarbonImmutable::parse($cuando, 'America/Mexico_City')->utc()->addMinutes(5));
        $this->postJson("{$app}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertSuccessful();
    }
    alRelojDePrueba();

    $this->getJson("{$app}/mi/perfil", conBearer($alumna['bearer']))->assertOk()
        ->assertJsonPath('data.resumen_asistencia.clases_mes', 1)
        ->assertJsonPath('data.resumen_asistencia.racha_semanas', 3)
        ->assertJsonPath('data.resumen_asistencia.total', 4);
});
