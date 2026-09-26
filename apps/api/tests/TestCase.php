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

        // En paralelo (`pest --parallel`) cada proceso usa su propia carpeta de
        // storage: las BD de los negocios (storage/tenants), archivos y llaves de
        // prueba de un proceso no chocan con las de otro.
        $proceso = ParallelTesting::token();
        if ($proceso !== false) {
            $ruta = base_path('storage/testing/proceso-'.$proceso);
            File::ensureDirectoryExists($ruta.'/app');
            File::ensureDirectoryExists($ruta.'/framework/testing');
            $this->app->useStoragePath($ruta);
        }
    }
}
