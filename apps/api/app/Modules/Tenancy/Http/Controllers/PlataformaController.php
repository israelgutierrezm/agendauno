<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Legales\DocumentoLegal;
use App\Modules\Platform\Legales\DocumentosLegales;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaPlataforma;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MedicionUso;
use App\Modules\Tenancy\ModoCobroSaas;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administración de plataforma (PlatformAdmin): el operador de AgendaUno ve todos los
 * estudios y gestiona la configuración global (p. ej. la cuenta FacturAPI usada para
 * timbrar por todos). Opera sobre el control plane (BD compartida); autenticado por
 * token de plataforma. Nunca devuelve secretos.
 */
class PlataformaController
{
    public function estudios(): JsonResponse
    {
        $estudios = Estudio::query()->orderBy('slug')->get();

        // Uso del último periodo medido y adeudo (cargos pendientes) por estudio,
        // en dos consultas agregadas (sin una por fila).
        $ultimos = MedicionUso::query()
            ->selectRaw('estudio_id, max(periodo) as periodo')
            ->groupBy('estudio_id');
        $uso = MedicionUso::query()
            ->joinSub($ultimos, 'u', fn ($j) => $j->on('mediciones_uso.estudio_id', '=', 'u.estudio_id')
                ->on('mediciones_uso.periodo', '=', 'u.periodo'))
            ->get(['mediciones_uso.estudio_id', 'mediciones_uso.cantidad', 'mediciones_uso.metrica'])
            ->keyBy('estudio_id');
        $adeudo = CargoRenta::query()
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->selectRaw('estudio_id, sum(monto_minor) as total')
            ->groupBy('estudio_id')
            ->pluck('total', 'estudio_id');

        return response()->json([
            'data' => $estudios->map(static fn (Estudio $e): array => [
                ...PlataformaEstudiosController::resumen($e),
                'uso' => $uso->has($e->getKey())
                    ? ['cantidad' => (int) $uso[$e->getKey()]->cantidad, 'metrica' => $uso[$e->getKey()]->metrica]
                    : null,
                'adeudo_minor' => (int) ($adeudo[$e->getKey()] ?? 0),
            ])->all(),
            'total' => $estudios->count(),
        ]);
    }

    /**
     * Ajusta la facturación SaaS de un estudio: modo de cobro (por uso según su
     * modalidad, o cuota fija pactada) y estado de facturación. Solo el admin de la
     * plataforma. Los precios por uso viven en las tarifas versionadas.
     */
    public function actualizarEstudio(Request $request, string $estudio): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', $estudio)->firstOrFail();

        $validado = $request->validate([
            'modo_cobro' => ['required', Rule::enum(ModoCobroSaas::class)],
            'cuota_fija_minor' => ['required', 'integer', 'min:0'],
            // La cuota pactada puede ser en dólares (en México se cobra en pesos, ADR 0107).
            'cuota_fija_moneda' => ['nullable', Rule::in(['MXN', 'USD'])],
            'estado_facturacion' => ['nullable', Rule::enum(EstadoFacturacion::class)],
        ]);

        $modelo->update([
            'modo_cobro' => $validado['modo_cobro'],
            'cuota_fija_minor' => (int) $validado['cuota_fija_minor'],
            'cuota_fija_moneda' => $validado['cuota_fija_moneda'] ?? $modelo->cuota_fija_moneda,
            'estado_facturacion' => $validado['estado_facturacion'] ?? $modelo->estado_facturacion->value,
        ]);

        return response()->json(['data' => [
            'slug' => $modelo->slug,
            'modo_cobro' => $modelo->modo_cobro->value,
            'cuota_fija_minor' => $modelo->cuota_fija_minor,
            'cuota_fija_moneda' => $modelo->cuota_fija_moneda,
            'estado_facturacion' => $modelo->estado_facturacion->value,
        ]]);
    }

    /**
     * Proveedores de pasarela que la plataforma puede activar para cobrar la renta.
     *
     * @return list<string>
     */
    private function proveedores(): array
    {
        return ['stripe', 'mercadopago', 'openpay'];
    }

    /**
     * Estado de las pasarelas de la plataforma (activa/modo + qué llaves están puestas).
     * Nunca devuelve las credenciales.
     */
    public function pasarelas(): JsonResponse
    {
        $configs = ConfiguracionPasarelaPlataforma::query()->get()->keyBy('proveedor');

        $data = array_map(function (string $proveedor) use ($configs): array {
            $config = $configs->get($proveedor);

            return [
                'proveedor' => $proveedor,
                'activa' => $config instanceof ConfiguracionPasarelaPlataforma ? $config->activa : false,
                'modo' => $config instanceof ConfiguracionPasarelaPlataforma ? $config->modo : 'test',
                'llaves_configuradas' => $config instanceof ConfiguracionPasarelaPlataforma ? array_keys($config->llaves()) : [],
                'disponible' => in_array($proveedor, ProveedorPasarela::implementadasPlataforma(), true),
                'lista' => app(RegistroDePasarelasPlataforma::class)->activa($proveedor),
            ];
        }, $this->proveedores());

        return response()->json(['data' => $data]);
    }

    /**
     * Activa/configura una pasarela de la plataforma. Las llaves se combinan (solo se
     * actualizan las provistas con valor); nunca se devuelven.
     */
    public function guardarPasarela(Request $request, string $proveedor): JsonResponse
    {
        abort_unless(in_array($proveedor, $this->proveedores(), true), 404);

        $validado = $request->validate([
            'activa' => ['required', 'boolean'],
            'modo' => ['required', Rule::in(['test', 'live'])],
            'credenciales' => ['nullable', 'array'],
            'credenciales.*' => ['nullable', 'string'],
        ]);

        if ((bool) $validado['activa'] && ! in_array($proveedor, ProveedorPasarela::implementadasPlataforma(), true)) {
            throw ValidationException::withMessages(['activa' => ['Esta pasarela aún no está disponible.']]);
        }

        $config = ConfiguracionPasarelaPlataforma::query()->firstOrNew(['proveedor' => $proveedor]);
        $config->activa = (bool) $validado['activa'];
        $config->modo = (string) $validado['modo'];

        // Merge: solo actualiza las llaves con valor; conserva las demás.
        $credenciales = $validado['credenciales'] ?? null;
        if (is_array($credenciales)) {
            $nuevas = [];
            foreach ($credenciales as $nombre => $valor) {
                if (is_string($valor) && $valor !== '') {
                    $nuevas[(string) $nombre] = $valor;
                }
            }
            $config->credenciales = array_merge($config->llaves(), $nuevas);
        }
        $config->save();

        return response()->json(['data' => [
            'proveedor' => $proveedor,
            'activa' => $config->activa,
            'modo' => $config->modo,
            'llaves_configuradas' => array_keys($config->llaves()),
            'disponible' => true,
            'lista' => app(RegistroDePasarelasPlataforma::class)->activa($proveedor),
        ]]);
    }

    public function configuracion(): JsonResponse
    {
        return response()->json(['data' => $this->presentarConfiguracion()]);
    }

    /**
     * Guarda solo lo que viene: la llave de FacturAPI y/o el correo del
     * superadministrador (vacío vuelve al de ALERTAS_CORREO).
     */
    public function guardarConfiguracion(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'facturapi_llave' => ['nullable', 'string', 'max:255'],
            'correo_alertas' => ['nullable', 'email', 'max:255'],
            // Modelo comercial (ADR 0107): ventas, Banco de México y paquetes de timbres.
            'ventas_correo' => ['nullable', 'email', 'max:255'],
            'ventas_whatsapp' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'banxico_token' => ['nullable', 'string', 'max:255'],
            'timbres_paquetes' => ['nullable', 'array', 'min:1', 'max:10'],
            'timbres_paquetes.*' => ['integer', 'distinct', 'min:1', 'max:100000'],
        ]);

        foreach (['facturapi_llave', 'correo_alertas', 'ventas_correo', 'banxico_token'] as $clave) {
            if ($request->exists($clave)) {
                ConfiguracionPlataforma::establecer($clave, $validado[$clave] ?? null);
            }
        }
        if ($request->exists('ventas_whatsapp')) {
            ConfiguracionPlataforma::establecer('ventas_whatsapp', preg_replace('/\D/', '', (string) ($validado['ventas_whatsapp'] ?? '')) ?: null);
        }
        if ($request->exists('timbres_paquetes')) {
            $paquetes = $validado['timbres_paquetes'] ?? null;
            ConfiguracionPlataforma::establecer('timbres_paquetes', is_array($paquetes) ? (string) json_encode(array_map('intval', $paquetes)) : null);
        }

        return response()->json(['data' => $this->presentarConfiguracion()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarConfiguracion(): array
    {
        return [
            // Nunca se devuelve la llave; solo si está configurada.
            'facturapi_configurada' => ConfiguracionPlataforma::llaveFacturapi() !== null,
            // A dónde llegan las alertas y las rentas vencidas (ADR 0072).
            'correo_alertas' => ConfiguracionPlataforma::correoAlertas(),
            // Modelo comercial (ADR 0107). El token del Banco de México nunca se devuelve.
            'ventas_correo' => ConfiguracionPlataforma::ventasCorreo(),
            'ventas_whatsapp' => ConfiguracionPlataforma::ventasWhatsApp(),
            'banxico_configurado' => ConfiguracionPlataforma::tokenBanxico() !== null,
            'timbres_paquetes' => ConfiguracionPlataforma::paquetesTimbres(),
        ];
    }

    /**
     * Parámetros de plataforma (ADR 0042): el valor que aplica a todos los negocios que
     * no ajustaron el suyo, y los que solo fija la plataforma (vigencias de enlaces).
     */
    public function parametros(ParametrosTenant $parametros): JsonResponse
    {
        return response()->json(['data' => $parametros->deLaPlataformaParaEditar()]);
    }

    /**
     * `valores`: {clave: número | null}; null vuelve al valor inicial.
     */
    public function guardarParametros(Request $request, ParametrosTenant $parametros): JsonResponse
    {
        $validado = $request->validate(['valores' => ['required', 'array']]);
        $parametros->guardarDePlataforma($validado['valores']);

        return response()->json(['data' => $parametros->deLaPlataformaParaEditar()]);
    }

    /**
     * Documentos legales de la plataforma (aviso de privacidad y términos): el
     * borrador que edita el superadministrador y las versiones publicadas (lo único
     * que ven los usuarios).
     */
    public function legales(DocumentosLegales $legales): JsonResponse
    {
        $publicado = static fn (?DocumentoLegal $d): ?array => $d === null ? null : [
            'version' => $d->version,
            'vigente_desde' => $d->vigente_desde->toIso8601String(),
        ];

        return response()->json(['data' => [
            ...$legales->borrador(),
            'publicados' => [
                'aviso_privacidad' => $publicado($legales->vigente(DocumentoLegal::AVISO)),
                'terminos' => $publicado($legales->vigente(DocumentoLegal::TERMINOS)),
            ],
        ]]);
    }

    /** Guarda el borrador (no cambia lo publicado). */
    public function guardarLegales(Request $request, DocumentosLegales $legales): JsonResponse
    {
        $validado = $request->validate([
            'aviso_privacidad' => ['nullable', 'string', 'max:50000'],
            'terminos' => ['nullable', 'string', 'max:50000'],
            'responsable' => ['nullable', 'array'],
            'responsable.nombre' => ['nullable', 'string', 'max:200'],
            'responsable.domicilio' => ['nullable', 'string', 'max:400'],
            'responsable.contacto' => ['nullable', 'email', 'max:200'],
            'responsable.area' => ['nullable', 'string', 'max:200'],
        ]);
        $legales->guardarBorrador($validado);

        return $this->legales($legales);
    }

    /** Publica el borrador de un documento como su versión siguiente. */
    public function publicarLegal(Request $request, DocumentosLegales $legales): JsonResponse
    {
        $documento = $legales->publicar((string) $request->route('tipo'));

        return response()->json(['data' => [
            'tipo' => $documento->tipo,
            'version' => $documento->version,
            'vigente_desde' => $documento->vigente_desde->toIso8601String(),
        ]], 201);
    }
}
