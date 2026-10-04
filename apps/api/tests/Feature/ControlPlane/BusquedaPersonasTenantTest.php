<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Ids de quienes encuentra el directorio con esa búsqueda.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return list<string>
 */
function encontradosEnDirectorio(array $e, string $busqueda): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros?".http_build_query(['q' => $busqueda, 'page' => 1]), conBearer($e['bearer']))
        ->assertOk()->json('data'))->pluck('id')->all();
}

it('busca por nombre completo, correo o celular (con o sin espacios)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $valeria = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Valeria', 'primer_apellido' => 'Ríos', 'email' => 'vale@correo.mx',
        'celular' => '55 1467 2303', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Valeria', 'primer_apellido' => 'Pérez', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated();

    // Nombre y apellido están en campos distintos: cada palabra debe aparecer.
    expect(encontradosEnDirectorio($e, 'Valeria Ríos'))->toBe([$valeria]);
    expect(encontradosEnDirectorio($e, 'vale@correo'))->toBe([$valeria]);
    expect(encontradosEnDirectorio($e, '5514672303'))->toBe([$valeria]);
    expect(encontradosEnDirectorio($e, '1467 2303'))->toBe([$valeria]);
    expect(encontradosEnDirectorio($e, 'Valeria'))->toHaveCount(2);
    expect(encontradosEnDirectorio($e, 'Valeria Gómez'))->toBe([]);
});
