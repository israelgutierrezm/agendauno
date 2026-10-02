<?php

declare(strict_types=1);

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    /**
     * El «ahora» de todas las pruebas. Escriben fechas (p. ej. una clase el
     * '2026-10-05 08:00') pensando en un hoy de principios de octubre de 2026: con el
     * reloj real, esas fechas se vuelven pasado y las pruebas fallan según el día en
     * que se corran. Con el reloj fijo dan lo mismo hoy que en un año. Una prueba que
     * necesita otro momento lo fija (`travelTo`); las que comparan con fechas de
     * archivos del disco vuelven al reloj real (`travelBack`).
     */
    public const AHORA = '2026-10-01 06:00:00';

    public const ZONA_AHORA = 'America/Mexico_City';

    protected function setUp(): void
    {
        // Antes de arrancar la app: las migraciones y sus semillas (p. ej. la tarifa
        // vigente) también ven este reloj. Laravel lo limpia al terminar cada prueba.
        Carbon::setTestNow(CarbonImmutable::parse(self::AHORA, self::ZONA_AHORA));

        parent::setUp();

        // Las pruebas nunca usan el storage real: ahí viven las BD de los negocios
        // de desarrollo (storage/tenants), que las pruebas borran al limpiar. En
        // paralelo (`pest --parallel`) cada proceso tiene además su propia carpeta,
        // para que sus BD, archivos y llaves no choquen con las de otro.
        $proceso = ParallelTesting::token();
        $ruta = base_path('storage/testing/'.($proceso !== false ? 'proceso-'.$proceso : 'serie'));
        File::ensureDirectoryExists($ruta.'/app');
        File::ensureDirectoryExists($ruta.'/framework/testing');
        $this->app->useStoragePath($ruta);
    }
}
