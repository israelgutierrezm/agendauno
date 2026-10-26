<?php

declare(strict_types=1);

use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\ResultadoTimbre;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use Illuminate\Support\Facades\Config;

/*
| La renta a mano desde el superadmin (ADR 0214): registrar un pago recibido fuera de
| la pasarela, crear un cargo manual y la factura de cada cargo (con la forma de pago
| real), su descarga y su cancelación.
*/

/** El proveedor de facturación de la prueba: guarda lo que se le manda a timbrar. */
function timbradoDeRentaManual(ArrayObject $enviadas): void
{
    app()->instance(ClienteFacturacion::class, new class($enviadas) implements ClienteFacturacion
    {
        public function __construct(private ArrayObject $enviadas) {}

        public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
        {
            $this->enviadas->append($factura);

            return new ResultadoTimbre('fac_'.count($this->enviadas), 'UUID-RENTA-'.count($this->enviadas));
        }

        public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
        {
            return $formato === 'pdf' ? '%PDF-1.4 '.$facturaId : '<cfdi/>';
        }
    });
}

it('el superadmin registra un pago por transferencia: reactiva al negocio y su CFDI sale con esa forma de pago', function (): void {
    $enviadas = new ArrayObject;
    timbradoDeRentaManual($enviadas);
    $e = estudioConSesion('estudio-transfer', 'dueno@transfer.mx');
    cargarDatosFiscales($e);
    $cargo = cargoRentaPendiente($e);
    Estudio::query()->where('slug', $e['slug'])->update(['estado' => 'suspended', 'suspendido_por' => 'renta', 'suspendido_en' => now()]);

    $this->postJson("/api/v1/plataforma/cargos/{$cargo}/pago", ['metodo' => 'bitcoin'], conPlataforma())
        ->assertStatus(422)->assertJsonValidationErrors(['metodo'], 'meta.errors');
    $this->postJson("/api/v1/plataforma/cargos/{$cargo}/pago", ['metodo' => 'transferencia', 'referencia' => 'SPEI-889'], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.estado', 'pagado')
        ->assertJsonPath('data.metodo_pago', 'transferencia');
    // Una sola vez.
    $this->postJson("/api/v1/plataforma/cargos/{$cargo}/pago", ['metodo' => 'efectivo'], conPlataforma())->assertStatus(422);
    expect(Estudio::query()->where('slug', $e['slug'])->value('estado'))->not->toBe('suspended')
        ->and(CargoRenta::query()->where('ulid', $cargo)->value('referencia_pago'))->toBe('manual:SPEI-889');

    // Su factura, desde la ficha: con transferencia (03).
    $id = (string) $this->postJson("/api/v1/plataforma/cargos/{$cargo}/factura", [], conPlataforma())
        ->assertCreated()
        ->assertJsonPath('data.estado', 'timbrada')
        ->assertJsonPath('data.forma_pago', '03')
        ->json('data.id');
    expect($enviadas)->toHaveCount(1)->and($enviadas[0]['payment_form'])->toBe('03');
    $this->get("/api/v1/plataforma/facturas/{$id}/pdf", conPlataforma())->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // La ficha la muestra con su detalle.
    $this->getJson("/api/v1/plataforma/estudios/{$e['slug']}", conPlataforma())->assertOk()
        ->assertJsonPath('data.cargos.0.metodo_pago', 'transferencia')
        ->assertJsonPath('data.cargos.0.factura_detalle.uuid', 'UUID-RENTA-1');

    // Se cancela con un motivo del SAT (el doble la acepta al momento).
    app()->forgetInstance(ClienteFacturacion::class);
    $this->postJson("/api/v1/plataforma/facturas/{$id}/cancelar", ['motivo' => '01'], conPlataforma())
        ->assertStatus(422)->assertJsonValidationErrors(['motivo'], 'meta.errors');
    $this->postJson("/api/v1/plataforma/facturas/{$id}/cancelar", ['motivo' => '02'], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.estado', 'cancelada')
        ->assertJsonPath('data.cancelacion_estado', 'aceptada');
    expect(FacturaPlataforma::query()->where('ulid', $id)->value('cancelacion_motivo'))->toBe('02');
});

it('sin los datos fiscales del negocio no se factura su renta', function (): void {
    $e = estudioConSesion('estudio-sin-rfc', 'dueno@sinrfc.mx');
    $cargo = cargoRentaPendiente($e);
    $this->postJson("/api/v1/plataforma/cargos/{$cargo}/pago", ['metodo' => 'efectivo'], conPlataforma())->assertOk();

    $this->postJson("/api/v1/plataforma/cargos/{$cargo}/factura", [], conPlataforma())
        ->assertStatus(422)->assertJsonPath('code', 'FISCAL_DATA_REQUIRED');
});

it('el superadmin crea un cargo manual que el negocio ve y paga en su suscripción', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('estudio-manual', 'dueno@manual.mx');

    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/cargos", [
        'descripcion' => 'Migración de datos', 'monto_minor' => 116000, 'vence_en' => now()->subDay()->toDateString(),
    ], conPlataforma())->assertStatus(422)->assertJsonValidationErrors(['vence_en'], 'meta.errors');

    $id = (string) $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/cargos", [
        'descripcion' => 'Migración de datos', 'monto_minor' => 116000, 'vence_en' => now()->addDays(10)->toDateString(),
    ], conPlataforma())->assertCreated()
        ->assertJsonPath('data.concepto', 'manual')
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.moneda', 'MXN')
        ->json('data.id');

    $cargo = CargoRenta::query()->where('ulid', $id)->sole();
    expect($cargo->desglose['subtotal_minor'])->toBe(100000)
        ->and($cargo->desglose['iva_minor'])->toBe(16000)
        ->and($cargo->desglose['descripcion'])->toBe('Migración de datos');

    $cargos = collect($this->getJson("/api/v1/app/{$e['slug']}/renta/cargos", conBearer($e['bearer']))->assertOk()->json('data'));
    expect($cargos->firstWhere('id', $id))->not->toBeNull()
        ->and($cargos->firstWhere('id', $id)['monto_minor'])->toBe(116000);
});
