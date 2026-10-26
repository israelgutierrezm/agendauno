<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

use App\Modules\Tenancy\Application\EmitirFacturaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manda por correo el CFDI timbrado (PDF y XML) a quien se le facturó (ADR 0212): lo
 * envía FacturAPI con la llave del negocio. En cola, fuera del timbrado. Lleva el
 * negocio: corre en su base. Sin correo del receptor no hace nada.
 */
class EnviarFacturaPorCorreo implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(
        public readonly int $estudioId,
        public readonly string $facturaUlid,
        public readonly ?string $correo = null,
    ) {}

    public function handle(GestorDeConexionTenant $gestor, OrganizacionesCfdi $organizaciones): void
    {
        $estudio = Estudio::query()->find($this->estudioId);
        if (! $estudio instanceof Estudio) {
            return;
        }
        $enviar = function () use ($organizaciones): void {
            $factura = FacturaTenant::query()->where('ulid', $this->facturaUlid)->first();
            $emisor = DatosFiscalesTenant::query()->first();
            $correo = $this->correo ?? $factura?->receptor_email;
            if (! $factura instanceof FacturaTenant || ! $emisor instanceof DatosFiscalesTenant
                || $factura->facturapi_id === null || $correo === null || $correo === '') {
                return;
            }
            $organizaciones->enviarPorCorreo(EmitirFacturaTenant::llaveDe($emisor), (string) $factura->facturapi_id, $correo);
        };
        // Con la cola síncrona corre dentro del despacho de ese mismo negocio: sin cambiar
        // de conexión (cambiarla cortaría lo que el despacho tiene abierto).
        $actual = $gestor->actual();
        if ($actual instanceof Estudio && (int) $actual->getKey() === (int) $estudio->getKey()) {
            $enviar();

            return;
        }
        $gestor->ejecutarEn($estudio, $enviar);
    }
}
