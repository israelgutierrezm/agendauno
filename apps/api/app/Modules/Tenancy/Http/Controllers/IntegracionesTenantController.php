<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\IntegracionSoloMexico;
use App\Modules\Tenancy\Integraciones\ClienteTotalPass;
use App\Modules\Tenancy\Integraciones\PublicadorWellhub;
use App\Modules\Tenancy\Integraciones\RegistroDeIntegracionesTenant;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\PublicacionPlataformaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
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
        private readonly RegistroDeIntegracionesTenant $registro,
        private readonly RegionNegocioTenant $region,
        private readonly RegistrarAuditoria $auditoria,
        private readonly ClienteTotalPass $totalpass,
        private readonly PublicadorWellhub $wellhub,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function index(): JsonResponse
    {
        $disponibles = array_values(array_filter(ProveedorPartner::cases(), fn (ProveedorPartner $p): bool => $p->disponibleEn($this->region->pais())));
        if ($disponibles === []) {
            throw new IntegracionSoloMexico('Wellhub, TotalPass y Fitpass aún no se ofrecen en el país de tu negocio.');
        }
        // De cada plataforma, la conexión del negocio entero (o la primera de sus sedes).
        $data = array_map(fn (ProveedorPartner $proveedor): array => $this->presentar(
            $proveedor,
            $this->registro->integracion($proveedor->value),
        ), $disponibles);

        return response()->json(['data' => $data]);
    }

    /** La conexión de una plataforma para una sede (`?sucursal=`) o la del negocio. */
    public function show(Request $request): JsonResponse
    {
        $proveedor = ProveedorPartner::tryFrom((string) $request->route('proveedor'));
        abort_if($proveedor === null, 404);
        $this->exigirDisponible($proveedor);

        return response()->json(['data' => $this->presentar($proveedor, $this->conexion($request, $proveedor), $this->sedePedida($request))]);
    }

    /**
     * Quita la conexión de una sede (la plataforma ya no la ve como gimnasio de este
     * negocio aquí). Primero se deja de publicar sus clases.
     */
    public function quitar(Request $request): JsonResponse
    {
        $proveedor = ProveedorPartner::tryFrom((string) $request->route('proveedor'));
        abort_if($proveedor === null, 404);
        $config = $this->conexion($request, $proveedor);
        if (! $config instanceof IntegracionTenant) {
            return response()->json(null, 204);
        }
        if ($config->reservas_activas) {
            throw ValidationException::withMessages(['sucursal' => ['Primero deja de publicar las clases de esa sede en '.$proveedor->nombre().'.']]);
        }
        if ($proveedor === ProveedorPartner::TotalPass && $config->webhook_registrado_en !== null) {
            $this->totalpass->quitarAviso($config->llaves()['place_api_key'] ?? '');
        }
        PublicacionPlataformaTenant::query()->where('integracion_id', $config->getKey())->delete();
        $config->delete();
        $usuario = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($usuario instanceof Usuario ? $usuario : null, 'integracion.quitada', 'integracion', $proveedor->value, null, ['sucursal' => $config->sucursal_id]);

        return response()->json(null, 204);
    }

    /** La sede pedida (`?sucursal=`, su identificador público); null sin pedirla. */
    private function sedePedida(Request $request): ?SucursalTenant
    {
        $ulid = trim((string) $request->query('sucursal', ''));
        if ($ulid === '') {
            return null;
        }
        $sede = SucursalTenant::query()->where('ulid', $ulid)->first();
        if (! $sede instanceof SucursalTenant) {
            throw ValidationException::withMessages(['sucursal' => ['Esa sede no existe.']]);
        }

        return $sede;
    }

    /**
     * La conexión de la sede pedida o, sin pedirla, la del negocio entero (ADR 0202). Con
     * `$crear`, una nueva si aún no hay.
     */
    private function conexion(Request $request, ProveedorPartner $proveedor, bool $crear = false): ?IntegracionTenant
    {
        $sede = $this->sedePedida($request);
        if ($sede instanceof SucursalTenant) {
            return IntegracionTenant::query()->where('proveedor', $proveedor->value)->where('sucursal_id', $sede->getKey())->first()
                ?? ($crear ? new IntegracionTenant(['proveedor' => $proveedor->value, 'sucursal_id' => $sede->getKey()]) : null);
        }

        return $this->registro->integracion($proveedor->value)
            ?? ($crear ? new IntegracionTenant(['proveedor' => $proveedor->value]) : null);
    }

    public function upsert(Request $request): JsonResponse
    {
        $proveedor = ProveedorPartner::tryFrom((string) $request->route('proveedor'));
        abort_if($proveedor === null, 404);
        $this->exigirDisponible($proveedor);

        $validado = $request->validate([
            'activa' => ['required', 'boolean'],
            // Solo las credenciales que pide este proveedor.
            'credenciales' => ['nullable', 'array:'.implode(',', $proveedor->credenciales())],
            'credenciales.*' => ['nullable', 'string', 'max:500'],
            // Wellhub acepta llaves de firma de hasta 100 caracteres.
            'credenciales.webhook_secret' => ['nullable', 'string', 'max:100'],
        ]);

        // Cada sede es otro gimnasio en la plataforma: su propia conexión (ADR 0202).
        $config = $this->conexion($request, $proveedor, true);
        abort_unless($config instanceof IntegracionTenant, 404);
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

        // Otro gimnasio, otro token de Wellhub u otra sede de TotalPass: sus avisos de
        // reserva se vuelven a registrar al guardar la publicación (ADR 0149).
        if (array_intersect($cambiadas, ['api_key', 'gym_id', 'place_api_key']) !== []) {
            $config->reservas_registradas_en = null;
        }
        // Apagada: TotalPass deja de avisar de los check-in de la sede.
        if ($proveedor === ProveedorPartner::TotalPass && $antes['activa'] && ! $config->activa && $config->webhook_registrado_en !== null) {
            $this->totalpass->quitarAviso($config->llaves()['place_api_key'] ?? '');
            $config->webhook_registrado_en = null;
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

        // Wellhub: un negocio que solo valida visitas (sin publicar clases) también recibe
        // los avisos de check-in; se registran al activarla (o con otras llaves). Solo con
        // la dirección pública (HTTPS) de la plataforma.
        if ($proveedor === ProveedorPartner::Wellhub && $config->activa && str_starts_with((string) config('app.url'), 'https://')
            && ($config->webhook_registrado_en === null || array_intersect($cambiadas, ['api_key', 'gym_id']) !== [])) {
            $this->wellhub->registrarAvisos($config, self::urlDeAvisos($proveedor, (string) $config->token_webhook));
        }

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

        return response()->json(['data' => $this->presentar($proveedor, $config, $this->sedePedida($request))]);
    }

    /**
     * La integración como la ve el negocio: sus llaves (solo los nombres) y su check-in
     * automático: la dirección de avisos y si ya está listo. Con sus conexiones por sede
     * (`sedes`) y de qué sede es esta (`sede`).
     *
     * @return array<string, mixed>
     */
    private function presentar(ProveedorPartner $proveedor, ?IntegracionTenant $config, ?SucursalTenant $pedida = null): array
    {
        $sedes = IntegracionTenant::query()->where('proveedor', $proveedor->value)->with('sucursal')->orderBy('id')->get()
            ->map(static fn (IntegracionTenant $i): array => [
                'sucursal' => $i->sucursal === null ? null : ['id' => $i->sucursal->ulid, 'nombre' => $i->sucursal->nombre],
                'activa' => $i->activa,
            ])->values()->all();
        $sede = $config->sucursal ?? $pedida;
        $llaves = $config instanceof IntegracionTenant ? $config->llaves() : [];
        $token = $config instanceof IntegracionTenant ? (string) $config->token_webhook : '';
        $activa = $config instanceof IntegracionTenant && $config->activa;
        $ultima = $llaves !== [] && $activa ? $this->evidencia($proveedor, $config) : null;
        // Solo cuenta la que llegó con sus llaves de ahora (R13); la anterior es historial.
        $desde = $config?->configurada_en;
        $evidencia = $ultima !== null && ($desde === null || CarbonImmutable::parse($ultima['en'])->greaterThanOrEqualTo($desde)) ? $ultima : null;

        return [
            'proveedor' => $proveedor->value,
            // De qué sede es esta conexión (null: del negocio entero) y las que hay.
            'sede' => $sede instanceof SucursalTenant ? ['id' => $sede->ulid, 'nombre' => $sede->nombre] : null,
            'sedes' => $sedes,
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
            // Lo que se le da a Wellhub al elegir a AgendaUno como su sistema (ADR 0202).
            'codigo_para_plataforma' => $proveedor === ProveedorPartner::Wellhub ? $this->gestor->actual()?->slug : null,
            'llaves_obligatorias' => $proveedor->credencialesObligatorias()[0] ?? [],
            // Otro grupo que también basta (TotalPass: solo la llave de la sede).
            'llaves_alternativas' => array_slice($proveedor->credencialesObligatorias(), 1),
            'automatico' => [
                'aviso_url' => $token !== '' ? self::urlDeAvisos($proveedor, $token) : null,
                'listo' => match ($proveedor) {
                    // Wellhub firma con la llave que da (la del negocio o la de AgendaUno), o
                    // con la que se le registró al publicar las clases (ADR 0149).
                    ProveedorPartner::Wellhub => $token !== ''
                        && (($llaves['webhook_secret'] ?? '') !== '' || (string) config('agendauno.integraciones.wellhub_webhook_secret') !== ''),
                    ProveedorPartner::TotalPass => $config instanceof IntegracionTenant && $config->webhook_registrado_en !== null,
                    // Sin check-in automático: recepción registra cada visita (ADR 0197).
                    ProveedorPartner::Fitpass => false,
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
    private function evidencia(ProveedorPartner $proveedor, ?IntegracionTenant $config = null): ?array
    {
        // De su sede, si la conexión es de una.
        $sede = $config?->sucursal_id;
        $checkin = CheckinTenant::query()
            ->where('proveedor', $proveedor->value)
            ->where('estado', CheckinTenant::VALIDADO)
            ->when($sede !== null, fn ($q) => $q->whereHas('sesion', fn ($s) => $s->where('sucursal_id', $sede)))
            ->max('registrado_en');
        $aviso = EventoPlataformaTenant::query()
            ->where('proveedor', $proveedor->value)
            ->when($config?->exists === true, fn ($q) => $q->where(fn ($w) => $w->where('integracion_id', $config?->getKey())->orWhereNull('integracion_id')))
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
        $grupos = $proveedor->credencialesObligatorias();
        $faltantes = array_map(fn (array $grupo): array => array_values(array_filter(
            $grupo,
            static fn (string $nombre): bool => trim($llaves[$nombre] ?? '') === '',
        )), $grupos);
        if ($grupos === [] || in_array([], $faltantes, true)) {
            return;
        }
        // Las del primer grupo (el de siempre) son las que se piden.
        $faltan = $faltantes[0];

        $errores = ['activa' => ["Para activar {$proveedor->nombre()} captura sus llaves."]];
        foreach ($faltan as $nombre) {
            $errores["credenciales.{$nombre}"] = ['Captúrala para activar la integración.'];
        }

        throw ValidationException::withMessages($errores);
    }

    private function exigirDisponible(ProveedorPartner $proveedor): void
    {
        if (! $proveedor->disponibleEn($this->region->pais())) {
            throw new IntegracionSoloMexico($proveedor->nombre().' aún no se ofrece en el país de tu negocio.');
        }
    }
}
