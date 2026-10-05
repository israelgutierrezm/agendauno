<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EmitirFacturaTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Pagos\CatalogoMonedas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Facturas (CFDI) tenant-local: emitir (timbrar vía FacturAPI), listar y consultar.
 * Requiere que el estudio haya cargado sus datos fiscales. Opera sobre la BD del
 * estudio resuelto.
 */
class FacturasTenantController
{
    public function __construct(private readonly EmitirFacturaTenant $emisor) {}

    public function index(): JsonResponse
    {
        $facturas = FacturaTenant::query()->orderByDesc('id')->limit(100)->get();

        return response()->json([
            'data' => $facturas->map(fn (FacturaTenant $f): array => $this->presentar($f))->all(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $factura = FacturaTenant::query()->where('ulid', (string) $request->route('factura'))->firstOrFail();

        return response()->json(['data' => $this->presentar($factura)]);
    }

    public function emitir(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'receptor.nombre' => ['required', 'string', 'max:255'],
            'receptor.rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/i'],
            'receptor.email' => ['nullable', 'email', 'max:255'],
            'receptor.regimen_fiscal' => ['nullable', 'string', 'regex:/^[0-9]{3,4}$/'],
            'receptor.codigo_postal' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            'uso_cfdi' => ['required', 'string', 'max:4'],
            'forma_pago' => ['nullable', 'string', 'max:2'],
            'moneda' => ['nullable', 'string', 'size:3', CatalogoMonedas::regla()],
            'items' => ['required', 'array', 'min:1'],
            'items.*.descripcion' => ['required', 'string', 'max:1000'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'items.*.precio_unitario_minor' => ['required', 'integer', 'min:1'],
            'items.*.clave_prod_serv' => ['required', 'string', 'max:8'],
            'items.*.clave_unidad' => ['required', 'string', 'max:3'],
        ]);

        $datos = DatosFiscalesTenant::query()->first();
        if (! $datos instanceof DatosFiscalesTenant) {
            throw ValidationException::withMessages([
                'datos_fiscales' => ['Carga los datos fiscales del estudio antes de facturar.'],
            ]);
        }

        /** @var list<array{descripcion: string, cantidad: int, precio_unitario_minor: int, clave_prod_serv: string, clave_unidad: string}> $items */
        $items = $validado['items'];

        $factura = $this->emisor->emitir(
            $datos,
            $validado['receptor'],
            $items,
            (string) $validado['uso_cfdi'],
            (string) ($validado['forma_pago'] ?? '01'),
            mb_strtoupper((string) ($validado['moneda'] ?? app(ParametrosTenant::class)->moneda())),
        );

        $estado = $factura->estado === EstadoFactura::Timbrada ? 201 : 422;

        return response()->json(['data' => $this->presentar($factura)], $estado);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(FacturaTenant $factura): array
    {
        return [
            'id' => $factura->ulid,
            'estado' => $factura->estado->value,
            'receptor' => ['nombre' => $factura->receptor_nombre, 'rfc' => $factura->receptor_rfc],
            'uso_cfdi' => $factura->uso_cfdi,
            'moneda' => $factura->moneda,
            'subtotal_minor' => $factura->subtotal_minor,
            'impuesto_minor' => $factura->impuesto_minor,
            'total_minor' => $factura->total_minor,
            'uuid' => $factura->uuid,
            'pdf_url' => $factura->pdf_url,
            'xml_url' => $factura->xml_url,
            'motivo_error' => $factura->motivo_error,
            'timbrada_en' => $factura->timbrada_en?->toIso8601String(),
        ];
    }
}
