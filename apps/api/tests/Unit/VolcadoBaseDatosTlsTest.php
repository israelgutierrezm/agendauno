<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\VolcadoBaseDatos;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;
use Symfony\Component\Process\Process;

/*
| TLS de los respaldos en MySQL: mysqldump (volcar) y mysql (cargar) reciben las
| mismas opciones de conexión, antes del nombre de la base. La imagen trae el
| cliente de MariaDB, que verifica el certificado del servidor por omisión. Los
| procesos no se corren: se revisa su línea de comandos.
*/

beforeEach(function (): void {
    $this->volcadoTls = new class extends VolcadoBaseDatos
    {
        /** @var list<Process> */
        public array $procesos = [];

        protected function ejecutar(Process $proceso): void
        {
            $this->procesos[] = $proceso;
        }
    };
    $this->configTls = [
        'driver' => 'mysql', 'host' => 'mysql', 'port' => '3306', 'database' => 'tenant_tls_prueba',
        'username' => 'agendauno', 'password' => 'secreto', 'prefix' => '', 'options' => [],
    ];
    // Lo que traen los respaldos sin configurar, sea cual sea el .env local.
    config([
        'agendauno.respaldos.tls.ca' => null,
        'agendauno.respaldos.tls.verificar' => true,
        'agendauno.respaldos.opciones' => '',
    ]);
});

afterEach(function (): void {
    DB::purge('respaldo_tls');
});

/**
 * Vuelca y carga con la configuración actual y devuelve las dos líneas de comandos.
 *
 * @param  array<string, mixed>  $config
 * @return array{volcar: string, cargar: string}
 */
function lineasDeRespaldoTls(object $volcado, array $config): array
{
    config(['database.connections.respaldo_tls' => $config]);
    DB::purge('respaldo_tls');
    $volcado->procesos = [];
    $volcado->volcar('respaldo_tls', storage_path('app/volcado-tls.sql'));
    $volcado->cargar($config, __FILE__); // no se corre: basta un archivo legible

    return ['volcar' => $volcado->procesos[0]->getCommandLine(), 'cargar' => $volcado->procesos[1]->getCommandLine()];
}

it('sin configurar no agrega opciones de TLS (el cliente decide)', function (): void {
    $lineas = lineasDeRespaldoTls($this->volcadoTls, $this->configTls);

    expect($lineas['volcar'])->toContain('mysqldump')->not->toContain('--ssl')
        ->and($lineas['cargar'])->toContain('mysql')->not->toContain('--ssl');
});

it('pasa la CA, no verificar y las opciones extra a mysqldump y a mysql, antes de la base', function (): void {
    config([
        'agendauno.respaldos.tls.ca' => '/var/www/html/storage/credenciales/mysql-ca.pem',
        'agendauno.respaldos.tls.verificar' => false,
        'agendauno.respaldos.opciones' => ' --protocol=TCP   --connect-timeout=20 ',
    ]);

    $lineas = lineasDeRespaldoTls($this->volcadoTls, $this->configTls);

    foreach ($lineas as $linea) {
        expect($linea)->toContain('--ssl-ca=/var/www/html/storage/credenciales/mysql-ca.pem')
            ->toContain('--skip-ssl-verify-server-cert')
            ->toContain('--protocol=TCP')
            ->toContain('--connect-timeout=20')
            ->not->toContain('secreto')
            ->and(strpos($linea, '--skip-ssl-verify-server-cert'))->toBeLessThan(strpos($linea, 'tenant_tls_prueba'))
            ->and(strpos($linea, '--protocol=TCP'))->toBeLessThan(strpos($linea, '--ssl-ca='));
    }
    // La contraseña va por el entorno del proceso, no en la línea de comandos.
    expect($this->volcadoTls->procesos[0]->getEnv())->toMatchArray(['MYSQL_PWD' => 'secreto'])
        ->and($this->volcadoTls->procesos[1]->getEnv())->toMatchArray(['MYSQL_PWD' => 'secreto']);
});

it('sin CA propia usa la de la conexión de PHP, y la de los respaldos tiene prioridad', function (): void {
    $atributo = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;
    $config = [...$this->configTls, 'options' => [$atributo => '/certs/proveedor-ca.pem']];

    foreach (lineasDeRespaldoTls($this->volcadoTls, $config) as $linea) {
        expect($linea)->toContain('--ssl-ca=/certs/proveedor-ca.pem')->not->toContain('--skip-ssl-verify-server-cert');
    }

    config(['agendauno.respaldos.tls.ca' => '/certs/respaldos-ca.pem']);
    foreach (lineasDeRespaldoTls($this->volcadoTls, $config) as $linea) {
        expect($linea)->toContain('--ssl-ca=/certs/respaldos-ca.pem')->not->toContain('proveedor-ca.pem');
    }
})->skip(fn (): bool => ! extension_loaded('pdo_mysql'), 'La CA de la conexión es una opción de pdo_mysql.');
