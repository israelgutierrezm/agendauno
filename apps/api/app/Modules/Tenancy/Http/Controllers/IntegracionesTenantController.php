<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\IntegracionSoloMexico;
use App\Modules\Tenancy\Integraciones\ClienteTotalPass;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ProveedorPartner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Configuracion de integraciones de bienestar del estudio (Wellhub / TotalPass): el
 * propietario conecta sus credenciales. Se guardan cifradas y NUNCA se devuelven:
 * solo se informa que llaves estan configuradas. Solo propietario, solo negocios de
 * clases (ADR 0104) y solo en México, donde operan. Encenderla, apagarla o cambiar
 * una llave queda en la bitácora con los NOMBRES de las llaves, nunca sus valores (M10).
 *
 * Check-in automático (ADR 0148): al conectarla se genera la dirección donde la
 * plataforma avisa de cada check-in. En TotalPass se registra sola con su API (con la
 * llave de la sede); en Wellhub se le da a Wellhub junto con la llave con que firma.
 *
 * No se activa sin las llaves con que valida un check-in (las ya guardadas cuentan):
 * encendida sin ellas, la plataforma rechazaría a cada visitante.
 *
 * La conexión se informa por lo que de verdad pasó, como en las pasarelas:
 * `sin_configurar` → `credenciales` (llaves guardadas, apagada) → `activa_sin_comprobar`
 * → `comprobada` (la plataforma ya respondió: un check-in que aprobó o un aviso suyo
 * que llegó a la dirección del negocio). Guardar llaves o encenderla no la comprueba.
 */
class IntegracionesTenantController
{
    public function __construct(
        private readonly RegionNegocioTenant $region,
        private readonly RegistrarAuditoria $auditoria,
        private readonly ClienteTotalPass $totalpass,
    ) {}

    public function index(): JsonResponse
    {
        $this->exigirMexico();
        $configs = IntegracionTenant::query()->get()->keyBy('proveedor');

        $data = array_map(fn (ProveedorPartner $proveedor): array => $this->presentar(
            $proveedor,
            $configs->get($proveedor->value),
        ), ProveedorPartner::cases());

        return response()->json(['data' => $data]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $this->exigirMexico();
        $proveedor = ProveedorPartner::tryFrom((string) $request->route('proveedor'));
        abort_if($proveedor === null, 404);

        $validado = $request->validate([
            'activa' => ['required', 'boolean'],
            // Solo las credenciales que pide este proveedor.
            'credenciales' => ['nullable', 'array:'.implode(',', $proveedor->credenciales())],
            'credenciales.*' => ['nullable', 'string', 'max:500'],
        ]);

        $config = IntegracionTenant::query()->firstOrNew(['proveedor' => $proveedor->value]);
        $antes = ['activa' => $config->exists && $config->activa, 'llaves' => array_keys($config->llaves())];
        $config->activa = (bool) $validado['activa'];
        $cambiadas = [];

        // Merge: solo actualiza las llaves provistas con valor; conserva las demas.
        $credenciales = $validado['credenciales'] ?? null;
        if (is_array($credenciales)) {
            $nuevas = [];
            foreach ($credenciales as $nombre => $valor) {
                if (is_string($valor) && $valor !== '') {
                    $nuevas[(string) $nombre] = trim($valor);
                }
            }
            $actuales = $config->llaves();
            $cambiadas = array_keys(array_filter($nuevas, static fn (string $valor, string $nombre): bool => ($actuales[$nombre] ?? null) !== $valor, ARRAY_FILTER_USE_BOTH));
            $config->credenciales = array_merge($actuales, $nuevas);
        }
        // Otras llaves (u otra cuenta): la comprobación anterior ya no es de esta (R13).
        if ($cambiadas !== [] || ! $config->exists) {
            $config->configurada_en = now();
        }
        if ($config->activa) {
            $this->exigirLlaves($proveedor, $config);
        }

        // Otro gimnasio u otro token de Wellhub: sus avisos de reserva se vuelven a
        // registrar al guardar la publicación (ADR 0149).
        if ($proveedor === ProveedorPartner::Wellhub && array_intersect($cambiadas, ['api_key', 'gym_id']) !== []) {
            $config->reservas_registradas_en = null;
        }
        // La dirección de sus avisos (check-in automático), una vez por integración.
        if ((string) $config->token_webhook === '') {
            $config->token_webhook = Str::random(40);
        }
        // TotalPass: con la llave de la sede, su aviso se registra solo (una vez, o de nuevo
        // si cambió la llave).
        if ($proveedor === ProveedorPartner::TotalPass && $config->activa
            && ($config->llaves()['place_api_key'] ?? '') !== ''
            && ($config->webhook_registrado_en === null || in_array('place_api_key', $cambiadas, true))) {
            $config->webhook_registrado_en = $this->totalpass->registrarAviso(
                $config->llaves()['place_api_key'],
                self::urlDeAvisos($proveedor, (string) $config->token_webhook),
            ) ? now() : null;
        }
        $config->save();

        if ($antes['activa'] !== $config->activa || $cambiadas !== []) {
            $usuario = $request->attributes->get('usuario_tenant');
            $this->auditoria->registrar(
                $usuario instanceof Usuario ? $usuario : null,
                'integracion.actualizada',
                'integracion',
                $proveedor->value,
                $antes,
                ['activa' => $config->activa, 'llaves' => array_keys($config->llaves()), 'llaves_cambiadas' => $cambiadas],
            );
        }

        return response()->json(['data' => $this->presentar($proveedor, $config)]);
    }

    /**
     * La integración como la ve el negocio: sus llaves (solo los nombres) y su check-in
     * automático: la dirección de avisos y si ya está listo.
     *
     * @return array<string, mixed>
     */
    private function presentar(ProveedorPartner $proveedor, ?IntegracionTenant $config): array
    {
        $llaves = $config instanceof IntegracionTenant ? $config->llaves() : [];
        $token = $config instanceof IntegracionTenant ? (string) $config->token_webhook : '';
        $activa = $config instanceof IntegracionTenant && $config->activa;
        $ultima = $llaves !== [] && $activa ? $this->evidencia($proveedor) : null;
        // Solo cuenta la que llegó con sus llaves de ahora (R13); la anterior es historial.
        $desde = $config?->configurada_en;
        $evidencia = $ultima !== null && ($desde === null || CarbonImmutable::parse($ultima['en'])->greaterThanOrEqualTo($desde)) ? $ultima : null;

        return [
            'proveedor' => $proveedor->value,
            'activa' => $activa,
            // sin_configurar → credenciales → activa_sin_comprobar → comprobada.
            'estado_conexion' => match (true) {
                $llaves === [] => 'sin_configurar',
                ! $activa => 'credenciales',
                $evidencia === null => 'activa_sin_comprobar',
                default => 'comprobada',
            },
            // Lo último que llegó de la plataforma y qué fue (`checkin` o `aviso`).
            'comprobada_en' => $evidencia['en'] ?? null,
            'comprobada_por' => $evidencia['por'] ?? null,
            // Lo último que llegó con llaves anteriores (solo historial).
            'ultimo_exito_anterior_en' => $evidencia === null ? ($ultima['en'] ?? null) : null,
            // Las que pide el proveedor y las que ya se capturaron.
            'llaves' => $proveedor->credenciales(),
            'llaves_configuradas' => array_keys($llaves),
            // Sin estas no se puede activar.
            'llaves_obligatorias' => $proveedor->credencialesObligatorias(),
            'automatico' => [
                'aviso_url' => $token !== '' ? self::urlDeAvisos($proveedor, $token) : null,
                'listo' => match ($proveedor) {
                    // Wellhub firma con la llave que da (la del negocio o la de AgendaUno), o
                    // con la que se le registró al publicar las clases (ADR 0149).
                    ProveedorPartner::Wellhub => $token !== ''
                        && (($llaves['webhook_secret'] ?? '') !== '' || (string) config('agendauno.integraciones.wellhub_webhook_secret') !== ''),
                    ProveedorPartner::TotalPass => $config instanceof IntegracionTenant && $config->webhook_registrado_en !== null,
                },
                'registrado_en' => $config?->webhook_registrado_en?->toIso8601String(),
            ],
        ];
    }

    /**
     * La prueba más reciente de que la plataforma sí responde: un check-in que aprobó
     * (de recepción o automático; no una excepción ni uno sin verificar) o un aviso suyo
     * que llegó a la dirección del negocio (con su token y, en Wellhub, su firma).
     *
     * @return array{en: string, por: 'checkin'|'aviso'}|null
     */
    private function evidencia(ProveedorPartner $proveedor): ?array
    {
        $checkin = CheckinTenant::query()
            ->where('proveedor', $proveedor->value)
            ->where('estado', CheckinTenant::VALIDADO)
            ->max('registrado_en');
        $aviso = EventoPlataformaTenant::query()
            ->where('proveedor', $proveedor->value)
            ->max('recibido_en');

        $enCheckin = $checkin !== null ? CarbonImmutable::parse((string) $checkin) : null;
        $enAviso = $aviso !== null ? CarbonImmutable::parse((string) $aviso) : null;

        if ($enAviso !== null && ($enCheckin === null || $enAviso->greaterThan($enCheckin))) {
            return ['en' => $enAviso->toIso8601String(), 'por' => 'aviso'];
        }

        return $enCheckin !== null ? ['en' => $enCheckin->toIso8601String(), 'por' => 'checkin'] : null;
    }

    /** La dirección donde la plataforma avisa al negocio (check-in y reservas). */
    public static function urlDeAvisos(ProveedorPartner $proveedor, string $token): string
    {
        return route('api.v1.webhooks.bienestar', [
            'estudio' => (string) app(GestorDeConexionTenant::class)->actual()?->slug,
            'proveedor' => $proveedor->value,
            'token' => $token,
        ]);
    }

    /** Para activarla, las llaves sin las que no valida ningún check-in. */
    private function exigirLlaves(ProveedorPartner $proveedor, IntegracionTenant $config): void
    {
        $llaves = $config->llaves();
        $faltan = array_values(array_filter(
            $proveedor->credencialesObligatorias(),
            static fn (string $nombre): bool => trim($llaves[$nombre] ?? '') === '',
        ));
        if ($faltan === []) {
            return;
        }

        $errores = ['activa' => ["Para activar {$proveedor->nombre()} captura sus llaves."]];
        foreach ($faltan as $nombre) {
            $errores["credenciales.{$nombre}"] = ['Captúrala para activar la integración.'];
        }

        throw ValidationException::withMessages($errores);
    }

    private function exigirMexico(): void
    {
        if (! $this->region->enMexico()) {
            throw new IntegracionSoloMexico('Wellhub y TotalPass solo están disponibles para negocios en México.');
        }
    }
}
