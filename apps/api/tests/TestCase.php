<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
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
