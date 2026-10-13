<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Mostrador: el negocio agrupa sus productos en categorías propias (para filtrarlos al
| vender) y le pone foto a cada producto. Quitar una categoría deja sus productos sin
| ella; editar un producto sin mandar su categoría no se la quita.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('crea, ordena y renombra categorías, sin nombres repetidos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/categorias-articulo";

    $bebidas = (string) $this->postJson($url, ['nombre' => ' Bebidas '], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.nombre', 'Bebidas')->assertJsonPath('data.orden', 1)->json('data.id');
    $this->postJson($url, ['nombre' => 'Ropa'], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.orden', 2);
    $this->postJson($url, ['nombre' => 'bebidas'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.nombre.0', 'Ya hay una categoría con ese nombre.');

    // Renombrar a su mismo nombre sí; a uno de otra, no.
    $this->putJson("{$url}/{$bebidas}", ['nombre' => 'Bebidas frías', 'orden' => 5], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.nombre', 'Bebidas frías');
    $this->putJson("{$url}/{$bebidas}", ['nombre' => 'ROPA'], conBearer($e['bearer']))->assertUnprocessable();

    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.nombre', 'Ropa')
        ->assertJsonPath('data.1.nombre', 'Bebidas frías');
});

it('un producto lleva su categoría; quitarla lo deja sin categoría', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $categoria = (string) $this->postJson("/api/v1/app/{$e['slug']}/categorias-articulo", ['nombre' => 'Bebidas'], conBearer($e['bearer']))
        ->json('data.id');

    $agua = (string) $this->postJson("/api/v1/app/{$e['slug']}/articulos", [
        'nombre' => 'Agua', 'precio_minor' => 2500, 'categoria_id' => $categoria,
    ], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.categoria.id', $categoria)
        ->assertJsonPath('data.categoria.nombre', 'Bebidas')
        ->json('data.id');

    // Una categoría que no existe, no.
    $this->postJson("/api/v1/app/{$e['slug']}/articulos", [
        'nombre' => 'Jugo', 'precio_minor' => 3000, 'categoria_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonPath('meta.errors.categoria_id.0', 'Esa categoría no existe.');

    // Editar sin mandarla no se la quita.
    $this->putJson("/api/v1/app/{$e['slug']}/articulos/{$agua}", ['nombre' => 'Agua 1 L', 'precio_minor' => 3000], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.categoria.nombre', 'Bebidas');
    $this->getJson("/api/v1/app/{$e['slug']}/categorias-articulo", conBearer($e['bearer']))
        ->assertJsonPath('data.0.productos', 1);

    $this->deleteJson("/api/v1/app/{$e['slug']}/categorias-articulo/{$categoria}", [], conBearer($e['bearer']))->assertNoContent();
    $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.nombre', 'Agua 1 L')
        ->assertJsonPath('data.0.categoria', null);
});

it('sube y quita la foto de un producto', function (): void {
    Storage::fake('public');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $agua = crearArticulo($e);
    $url = "/api/v1/app/{$e['slug']}/articulos/{$agua}/foto";

    $this->postJson($url, ['foto' => UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf')], conBearer($e['bearer']))
        ->assertUnprocessable();

    $foto = $this->postJson($url, ['foto' => UploadedFile::fake()->image('agua.png', 400, 400)], conBearer($e['bearer']))
        ->assertOk()->json('data.foto_url');
    expect($foto)->toBeString();
    $archivos = Storage::disk('public')->allFiles();
    expect($archivos)->toHaveCount(1);

    // Otra foto reemplaza la anterior (no se acumulan).
    $this->postJson($url, ['foto' => UploadedFile::fake()->image('agua2.jpg', 400, 400)], conBearer($e['bearer']))->assertOk();
    expect(Storage::disk('public')->allFiles())->toHaveCount(1)->not->toContain($archivos[0]);

    $this->deleteJson($url, [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.foto_url', null);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('solo quien gestiona el inventario administra categorías y fotos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $agua = crearArticulo($e);
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->postJson("/api/v1/app/{$e['slug']}/categorias-articulo", ['nombre' => 'Bebidas'], conBearer($instructor))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/articulos/{$agua}/foto", ['foto' => UploadedFile::fake()->image('a.png')], conBearer($instructor))
        ->assertForbidden();
});
