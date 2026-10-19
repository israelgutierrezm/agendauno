<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\CifradoRespaldos;
use App\Modules\Platform\Operacion\RespaldosPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Respaldos cifrados (ADR 0171): con RESPALDOS_LLAVE, la base central, la de cada
| negocio y los archivos subidos salen del servidor cifrados (AES-256-GCM por bloques).
| Se restauran con la llave con que se guardaron; cualquier cambio se detecta.
*/

beforeEach(function (): void {
    // Respaldos comparan fechas de archivos del disco: con el reloj real.
    $this->travelBack();
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('local');
    Storage::fake('public');
    $this->llave = base64_encode(random_bytes(32));
    config(['agendauno.respaldos.llave' => $this->llave, 'agendauno.respaldos.llaves_anteriores' => '']);
    $this->trabajo = storage_path('framework/testing/cifrado');
    File::ensureDirectoryExists($this->trabajo);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
    File::deleteDirectory($this->trabajo);
});

it('cifra y descifra por bloques, de vacío a varios MiB', function (): void {
    $cifrado = app(CifradoRespaldos::class);
    foreach ([0, 1, 1048576, 2 * 1048576 + 7] as $tamano) {
        $plano = $tamano === 0 ? '' : random_bytes($tamano);
        file_put_contents("{$this->trabajo}/plano", $plano);

        $cifrado->cifrar("{$this->trabajo}/plano", "{$this->trabajo}/cifrado");
        $cifrado->descifrar("{$this->trabajo}/cifrado", "{$this->trabajo}/vuelta");

        expect($cifrado->estaCifrado("{$this->trabajo}/cifrado"))->toBeTrue()
            ->and(file_get_contents("{$this->trabajo}/vuelta"))->toBe($plano);
    }
});

it('detecta un byte cambiado, un corte, datos de más y una llave que no es', function (): void {
    $cifrado = app(CifradoRespaldos::class);
    file_put_contents("{$this->trabajo}/plano", random_bytes(2 * 1048576 + 7));
    $cifrado->cifrar("{$this->trabajo}/plano", "{$this->trabajo}/cifrado");
    $bueno = (string) file_get_contents("{$this->trabajo}/cifrado");
    $intentar = function (string $contenido) use ($cifrado): string {
        file_put_contents("{$this->trabajo}/malo", $contenido);
        try {
            $cifrado->descifrar("{$this->trabajo}/malo", "{$this->trabajo}/vuelta");
        } catch (RuntimeException $e) {
            expect(file_exists("{$this->trabajo}/vuelta"))->toBeFalse();

            return $e->getMessage();
        }

        return 'se descifró';
    };

    $cambiado = $bueno;
    $cambiado[100] = $cambiado[100] === 'a' ? 'b' : 'a';
    expect($intentar($cambiado))->toContain('dañado')
        // Sin el último bloque (1 MiB + su marco) parece completo, pero no lo está.
        ->and($intentar(substr($bueno, 0, -(7 + 21))))->toContain('incompleto')
        ->and($intentar($bueno.'x'))->toContain('de más');

    // Con otra llave no se puede; con la de antes en las anteriores, sí.
    config(['agendauno.respaldos.llave' => base64_encode(random_bytes(32))]);
    expect($intentar($bueno))->toContain('Falta la llave');
    config(['agendauno.respaldos.llaves_anteriores' => $this->llave]);
    expect($intentar($bueno))->toBe('se descifró');
});

it('con llave, los respaldos salen cifrados, se restauran y el simulacro los prueba', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearMiembroTenant($e, 'Ana');
    Storage::disk('local')->put('documentos/estudio-a/reglamento.pdf', 'contenido');

    $this->artisan('agendauno:respaldar-plataforma')->assertSuccessful();
    $this->artisan('agendauno:respaldar-estudios')->assertSuccessful();

    $plataforma = app(RespaldosPlataforma::class)->listar()[0];
    $archivos = app(RespaldosPlataforma::class)->listar(RespaldosPlataforma::ARCHIVOS)[0];
    $negocio = collect(Storage::disk('local')->files('respaldos/estudio-a'))->first(fn (string $a): bool => str_ends_with($a, '.gz.enc'));
    foreach ([$plataforma, $archivos, $negocio] as $ruta) {
        $guardado = (string) Storage::disk('local')->get($ruta);
        expect($ruta)->toEndWith('.gz.enc')
            ->and(substr($guardado, 0, 4))->toBe('AU1C')
            ->and(@gzdecode($guardado))->toBeFalse()
            ->and(Storage::disk('local')->get($ruta.'.sha256'))->toBe(hash('sha256', $guardado));
    }

    $this->artisan('agendauno:simulacro-restauracion --estudio=estudio-a')
        ->expectsOutputToContain('Documentos, fotos y logos: 1 archivo(s) en uso idénticos a los recuperados.')
        ->expectsOutputToContain('Los respaldos se restauran bien.')
        ->assertSuccessful();

    // Se restaura un negocio: vuelve a como estaba al respaldarlo.
    crearMiembroTenant($e, 'Beto');
    $this->artisan('agendauno:restaurar-estudio', ['estudio' => 'estudio-a', '--force' => true])->assertSuccessful();
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->pluck('nombre')->all())
        ->toContain('Ana')->not->toContain('Beto');

    // Los archivos se bajan descifrados (y comprimidos) para restaurarlos a mano.
    $destino = "{$this->trabajo}/archivos.tar.gz";
    $this->artisan('agendauno:bajar-respaldo', ['destino' => $destino])->assertSuccessful();
    file_put_contents("{$this->trabajo}/archivos.tar", (string) gzdecode((string) file_get_contents($destino)));
    $contenido = collect(new RecursiveIteratorIterator(new PharData("{$this->trabajo}/archivos.tar")))->map(fn ($f): string => $f->getPathname())->implode("\n");
    expect($contenido)->toContain('private/documentos/estudio-a/reglamento.pdf');

    // Sin la llave no se restaura.
    config(['agendauno.respaldos.llave' => null]);
    $this->artisan('agendauno:restaurar-estudio', ['estudio' => 'estudio-a', '--force' => true])
        ->expectsOutputToContain('Falta la llave')
        ->assertFailed();
});

it('sin llave se guardan como antes y la verificación de producción lo pide', function (): void {
    config(['agendauno.respaldos.llave' => null]);
    estudioConSesion('estudio-a', 'a@correo.mx');

    $this->artisan('agendauno:respaldar-estudios')->assertSuccessful();
    $respaldo = collect(Storage::disk('local')->files('respaldos/estudio-a'))->first(fn (string $a): bool => str_ends_with($a, '.sqlite.gz') || str_ends_with($a, '.sql.gz'));
    expect($respaldo)->not->toBeNull()
        ->and(@gzdecode((string) Storage::disk('local')->get((string) $respaldo)))->not->toBeFalse();

    $this->artisan('agendauno:verificar-produccion')->expectsOutputToContain('FALTA Respaldos cifrados')->assertFailed();
    config(['agendauno.respaldos.llave' => $this->llave]);
    $this->artisan('agendauno:verificar-produccion')->expectsOutputToContain('OK    Respaldos cifrados')->assertFailed();
});
