<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CobrosManualesRenta;
use App\Modules\Tenancy\Application\FacturasRentaPlataforma;
use App\Modules\Tenancy\Exceptions\CargoRentaNoFacturable;
use App\Modules\Tenancy\Exceptions\CargoRentaNoPagable;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * La renta a mano desde el superadmin (ADR 0214): registrar un pago recibido fuera de
 * la pasarela, crear un cargo manual y la factura de cada cargo (emitirla o
 * reintentarla, descargarla y cancelarla). Cada acción queda en la bitácora.
 */
class PlataformaRentaController
{
    public function __construct(
        private readonly CobrosManualesRenta $cobros,
        private readonly FacturasRentaPlataforma $facturas,
    ) {}

    public function registrarPago(Request $request, string $cargo): JsonResponse
    {
        $modelo = CargoRenta::query()->where('ulid', $cargo)->firstOrFail();
        $validado = $request->validate([
            'metodo' => ['required', Rule::in(array_keys(CobrosManualesRenta::METODOS))],
            'referencia' => ['nullable', 'string', 'max:100'],
            'pagado_en' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        try {
            $pagado = $this->cobros->registrarPago(
                $modelo,
                (string) $validado['metodo'],
                isset($validado['referencia']) ? trim((string) $validado['referencia']) : null,
                isset($validado['pagado_en']) ? Carbon::parse((string) $validado['pagado_en'])->setTime(12, 0) : null,
            );
        } catch (CargoRentaNoPagable $e) {
            throw ValidationException::withMessages(['cargo' => [$e->getMessage()]]);
        }

        return response()->json(['data' => self::cargo($pagado)]);
    }

    public function crearCargo(Request $request, string $estudio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();
        $validado = $request->validate([
            'descripcion' => ['required', 'string', 'max:200'],
            'monto_minor' => ['required', 'integer', 'min:100', 'max:100000000'],
            'moneda' => ['nullable', Rule::in(['MXN', 'USD'])],
            'vence_en' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $cargo = $this->cobros->crearCargo(
            $modelo,
            trim((string) $validado['descripcion']),
            (int) $validado['monto_minor'],
            (string) ($validado['moneda'] ?? ($modelo->enMexico() ? 'MXN' : 'USD')),
            Carbon::parse((string) $validado['vence_en']),
        );

        return response()->json(['data' => self::cargo($cargo)], 201);
    }

    public function emitirFactura(string $cargo): JsonResponse
    {
        $modelo = CargoRenta::query()->where('ulid', $cargo)->firstOrFail();
        try {
            $factura = $this->facturas->emitir($modelo);
        } catch (CargoRentaNoFacturable $e) {
            throw ValidationException::withMessages(['cargo' => [$e->getMessage()]]);
        }

        return response()->json(['data' => self::factura($factura)], $factura->estado->value === 'error' ? 422 : 201);
    }

    public function descargarFactura(string $factura, string $formato): Response
    {
        abort_unless(in_array($formato, ['pdf', 'xml'], true), 404);
        $modelo = FacturaPlataforma::query()->where('ulid', $factura)->firstOrFail();
        try {
            $contenido = $this->facturas->descargar($modelo, $formato);
        } catch (CargoRentaNoFacturable $e) {
            throw ValidationException::withMessages(['factura' => [$e->getMessage()]]);
        }

        return response($contenido, 200, [
            'Content-Type' => $formato === 'pdf' ? 'application/pdf' : 'application/xml',
            'Content-Disposition' => "attachment; filename=\"factura-renta-{$modelo->uuid}.{$formato}\"",
        ]);
    }

    public function cancelarFactura(Request $request, string $factura): JsonResponse
    {
        $modelo = FacturaPlataforma::query()->where('ulid', $factura)->firstOrFail();
        $validado = $request->validate(['motivo' => ['required', Rule::in(['02', '03'])]]);
        try {
            $modelo = $this->facturas->cancelar($modelo, (string) $validado['motivo']);
        } catch (CargoRentaNoFacturable|TimbradoFallido $e) {
            throw ValidationException::withMessages(['factura' => [$e->getMessage()]]);
        }

        return response()->json(['data' => self::factura($modelo)]);
    }

    public function consultarCancelacion(string $factura): JsonResponse
    {
        $modelo = FacturaPlataforma::query()->where('ulid', $factura)->firstOrFail();
        try {
            $modelo = $this->facturas->consultar($modelo);
        } catch (TimbradoFallido $e) {
            throw ValidationException::withMessages(['factura' => [$e->getMessage()]]);
        }

        return response()->json(['data' => self::factura($modelo)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function factura(FacturaPlataforma $f): array
    {
        return [
            'id' => $f->ulid,
            'estado' => $f->estado->value,
            'uuid' => $f->uuid,
            'motivo_error' => $f->motivo_error,
            'forma_pago' => $f->forma_pago,
            'cancelacion_estado' => $f->cancelacion_estado,
            'cancelada_en' => $f->cancelada_en?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cargo(CargoRenta $c): array
    {
        return [
            'id' => $c->ulid,
            'periodo' => $c->periodo,
            'concepto' => $c->concepto ?? 'renta',
            'monto_minor' => $c->monto_minor,
            'moneda' => $c->moneda,
            'estado' => $c->estado->value,
            'vence_en' => $c->vence_en?->toDateString(),
            'pagado_en' => $c->pagado_en?->toIso8601String(),
            'metodo_pago' => $c->metodo_pago,
        ];
    }
}
