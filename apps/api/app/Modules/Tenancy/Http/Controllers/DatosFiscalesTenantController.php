<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AltaFacturacionNegocioTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Datos fiscales del emisor (el estudio) para CFDI, tenant-local. Cada tenant carga
 * los suyos; la llave de su organización FacturAPI nunca se devuelve (solo se informa
 * si ya está conectado). Opera sobre la BD del estudio resuelto.
 */
class DatosFiscalesTenantController
{
    public function __construct(private readonly AltaFacturacionNegocioTenant $alta) {}

    public function show(): JsonResponse
    {
        $datos = DatosFiscalesTenant::query()->first();

        $region = app(RegionNegocioTenant::class);

        return response()->json([
            'data' => $datos instanceof DatosFiscalesTenant ? $this->presentar($datos) : null,
            // Fuera de pesos mexicanos o de México no se factura (ADR 0099).
            'meta' => [
                'disponible' => $region->factura(),
                'motivo' => $region->factura() ? null : RegionNegocioTenant::MOTIVO_FACTURACION,
            ],
        ]);
    }

    /**
     * Fuera de pesos mexicanos o de México no se cargan datos para facturar.
     */
    private function exigirFacturacion(): void
    {
        if (! app(RegionNegocioTenant::class)->factura()) {
            throw ValidationException::withMessages(['facturacion' => [RegionNegocioTenant::MOTIVO_FACTURACION]]);
        }
    }

    public function guardar(Request $request): JsonResponse
    {
        $this->exigirFacturacion();
        $validado = $request->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            // RFC persona moral (12) o física (13).
            'rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/i'],
            'regimen_fiscal' => ['required', 'string', 'regex:/^[0-9]{3,4}$/'],
            'codigo_postal' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
        ]);

        $datos = DatosFiscalesTenant::query()->firstOrNew([]);
        $rfcAntes = $datos->exists ? $datos->rfc : null;
        $datos->fill([
            'razon_social' => $validado['razon_social'],
            'rfc' => mb_strtoupper((string) $validado['rfc']),
            'regimen_fiscal' => $validado['regimen_fiscal'],
            'codigo_postal' => $validado['codigo_postal'],
        ]);
        // Otro RFC es otro emisor: su sello y su alta en FacturAPI empiezan de nuevo.
        if (is_string($rfcAntes) && $rfcAntes !== $datos->rfc) {
            $datos->fill([
                'facturapi_organizacion_id' => null, 'facturapi_llave' => null, 'facturapi_lista' => false,
                'facturapi_pendientes' => null, 'sello_cer' => null, 'sello_key' => null, 'sello_password' => null,
            ]);
        }
        $datos->save();
        // Con su organización ya creada, sus datos se actualizan allá también (ADR 0212).
        if (($datos->facturapi_organizacion_id ?? '') !== '') {
            $this->alta->datosLegales($datos, $this->estudio($request));
        }

        return response()->json(['data' => $this->presentar($datos->refresh())]);
    }

    /**
     * Carga el sello digital (CSD): certificado .cer + llave .key + contraseña. Se
     * guardan cifrados. Requiere datos fiscales previos.
     */
    public function subirSello(Request $request): JsonResponse
    {
        $this->exigirFacturacion();
        $request->validate([
            'certificado' => ['required', 'file', 'max:64'],
            'llave' => ['required', 'file', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $datos = DatosFiscalesTenant::query()->firstOrNew([]);
        if (! $datos->exists) {
            throw ValidationException::withMessages([
                'datos_fiscales' => ['Carga primero tus datos fiscales.'],
            ]);
        }

        $certificado = (string) $request->file('certificado')?->get();
        $llave = (string) $request->file('llave')?->get();
        $contrasena = (string) $request->input('password');
        // Primero en FacturAPI (ADR 0212): un sello que rechaza no se guarda.
        $this->alta->sello($datos, $this->estudio($request), $certificado, $llave, $contrasena);
        $datos->fill([
            'sello_cer' => base64_encode($certificado),
            'sello_key' => base64_encode($llave),
            'sello_password' => $contrasena,
        ])->save();

        return response()->json(['data' => $this->presentar($datos->refresh())]);
    }

    /** Vuelve a preguntar a FacturAPI si ya puede facturar (tras firmar la carta). */
    public function revisar(): JsonResponse
    {
        $this->exigirFacturacion();
        $datos = DatosFiscalesTenant::query()->firstOrFail();
        $this->alta->revisar($datos);

        return response()->json(['data' => $this->presentar($datos->refresh())]);
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(DatosFiscalesTenant $datos): array
    {
        return [
            'id' => $datos->ulid,
            'razon_social' => $datos->razon_social,
            'rfc' => $datos->rfc,
            'regimen_fiscal' => $datos->regimen_fiscal,
            'codigo_postal' => $datos->codigo_postal,
            // Estado del propio sello del tenant (no se expone nada del proveedor).
            'sellos_cargados' => $datos->sellosCargados(),
            // Su alta en FacturAPI (ADR 0212): si ya factura, lo que le falta y dónde
            // firmar la carta manifiesto. Nunca la llave.
            'facturacion_lista' => (bool) $datos->facturapi_lista,
            'pendientes' => $datos->facturapi_pendientes ?? [],
            'error' => $datos->facturapi_error,
            'portal_manifiesto' => AltaFacturacionNegocioTenant::PORTAL_MANIFIESTO,
        ];
    }
}
