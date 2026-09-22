<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Ordenes\EstadoOrden;
use App\Modules\Pagos\MetodoPago;
use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\CobrarOrdenTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Citas PÚBLICAS (guest, sin cuenta): un cliente reserva y paga una cita desde el
 * escaparate del estudio, sin registrarse. Crea (o reutiliza por correo) una persona
 * guest, agenda la cita (reserva pendiente + orden por la sesión) y la cobra en línea.
 * Solo para estudios en el directorio; el `orden_id` (ULID aleatorio) es la capacidad
 * para pagar sin sesión. El webhook de la pasarela confirma la reserva (fulfillment).
 */
class PublicoCitasController
{
    public function __construct(
        private readonly AgendarCitaTenant $agendar,
        private readonly CobrarOrdenTenant $cobrar,
    ) {}

    public function agendar(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        abort_unless($estudio->enDirectorio(), 404);

        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'oferta_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'instructor_id' => ['required', 'string'],
            'inicia_en_local' => ['required', 'date'],
            'duracion_minutos' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        $oferta = OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail();
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        $instructor = Usuario::query()->where('ulid', $validado['instructor_id'])->firstOrFail();
        $persona = $this->personaGuest($validado);
        $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sucursal->zona_horaria)->utc();

        $reserva = $this->agendar->agendar($oferta, $sucursal, $persona, (int) $instructor->getKey(), $inicia, (int) $validado['duracion_minutos']);
        $reserva->load('orden');

        return response()->json(['data' => [
            'reserva' => $reserva->ulid,
            'estado' => $reserva->estado->value,
            'orden_id' => $reserva->orden?->ulid,
            'total_minor' => $reserva->orden?->total_minor,
            'moneda' => $reserva->orden?->moneda,
        ]], 201);
    }

    public function pagar(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        abort_unless($estudio->enDirectorio(), 404);

        $validado = $request->validate([
            'orden_id' => ['required', 'string'],
            'proveedor' => ['required', 'string'],
            'metodo' => ['nullable', Rule::enum(MetodoPago::class)],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        // El pago público solo admite pasarelas en línea (no efectivo/ventanilla/manual).
        if (in_array($validado['proveedor'], ['manual', 'efectivo', 'ventanilla'], true)) {
            throw ValidationException::withMessages([
                'proveedor' => ['El pago en efectivo se hace en el estudio; en línea usa una tarjeta.'],
            ]);
        }

        // La orden debe ser una CITA (por sesión) pendiente; el ULID es la capacidad.
        $orden = OrdenTenant::query()
            ->where('ulid', $validado['orden_id'])
            ->whereNotNull('sesion_id')
            ->where('estado', EstadoOrden::Pendiente->value)
            ->firstOrFail();

        $metodo = isset($validado['metodo']) ? MetodoPago::from((string) $validado['metodo']) : null;
        $key = ($validado['idempotency_key'] ?? '') !== '' ? (string) $validado['idempotency_key'] : null;
        $pago = $this->cobrar->ejecutar($orden, (string) $validado['proveedor'], $metodo, $key);

        return response()->json(['data' => [
            'pago' => $pago->ulid,
            'estado' => $pago->estado->value,
            'checkout' => $pago->checkout,
        ]], 201);
    }

    /**
     * Persona guest: reutiliza por correo si existe; si no, la crea (sin usuario/login).
     *
     * @param  array<string, mixed>  $datos
     */
    private function personaGuest(array $datos): PersonaTenant
    {
        $email = isset($datos['email']) && $datos['email'] !== '' ? (string) $datos['email'] : null;

        if ($email !== null) {
            $existente = PersonaTenant::query()->where('email', $email)->first();
            if ($existente instanceof PersonaTenant) {
                return $existente;
            }
        }

        return PersonaTenant::query()->create([
            'nombre' => (string) $datos['nombre'],
            'email' => $email,
            'tipo' => TipoPersonaTenant::Miembro->value,
            'activo' => true,
            'es_facturable' => true,
            'archivado' => false,
        ]);
    }
}
