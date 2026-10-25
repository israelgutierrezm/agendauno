<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Legales\DocumentoLegal;
use App\Modules\Platform\Legales\DocumentosLegales;
use App\Modules\Tenancy\Application\DemosAgendables;
use App\Modules\Tenancy\Application\HorarioDemos;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\VerificarRecaptcha;
use App\Modules\Tenancy\CalificacionDemo;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Mail\CorreoDemoAgendada;
use App\Modules\Tenancy\Models\DemoAgendada;
use App\Modules\Tenancy\Parametros\CatalogoParametros;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\ProductoComercial;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La demo de 15 minutos agendable desde la landing (ADR 0193): sus horarios libres,
 * agendarla (público, con captcha y el aviso de privacidad aceptado) y cancelarla con
 * el enlace de su correo. El superadmin, en Demos, ve cuántas hay y las marca, y carga
 * su horario semanal (ADR 0204).
 */
class DemosController
{
    public function __construct(
        private readonly DemosAgendables $demos,
        private readonly VerificarRecaptcha $recaptcha,
        private readonly DocumentosLegales $legales,
        private readonly ClienteWhatsApp $whatsapp,
        private readonly HorarioDemos $horario,
        private readonly ParametrosTenant $parametros,
    ) {}

    /** Los parámetros de las demos que se editan con su horario (Demos › Horario). */
    private const PARAMETROS = [
        'minutos' => 'demos.minutos',
        'por_horario' => 'demos.por_horario',
        'dias_adelante' => 'demos.dias_adelante',
        'horas_anticipacion' => 'demos.horas_anticipacion',
    ];

    /**
     * El sello de tiempo de la página (ADR 0204): se entrega con los horarios y vuelve al
     * agendar. Un envío en menos de esto lo hizo un programa, no una persona; uno con la
     * página abierta más de esto trae horarios viejos.
     */
    private const SEGUNDOS_MINIMOS = 3;

    private const HORAS_MAXIMAS = 6;

    /** «09:30», o «24:00» para terminar a la medianoche. */
    private const HORA = '/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/';

    public function horarios(): JsonResponse
    {
        return response()->json(['data' => [
            'activas' => $this->demos->activas(),
            'minutos' => $this->demos->minutos(),
            'zona' => $this->demos->zona(),
            // Solo si la plataforma confirma por WhatsApp se ofrece.
            'whatsapp' => $this->whatsapp->activoParaDuenos(),
            'dias' => $this->demos->horarios(),
            // Cuándo se abrió el formulario, cifrado: vuelve al agendar.
            'sello' => Crypt::encryptString((string) now()->getTimestamp()),
        ]]);
    }

    public function agendar(Request $request): JsonResponse
    {
        $sinEnlaces = function (string $campo, mixed $valor, Closure $falla): void {
            if (preg_match('~(https?://|www\.|<[^>]*>|\[url)~i', (string) $valor) === 1) {
                $falla('Escribe solo el nombre, sin enlaces.');
            }
        };
        $validado = $request->validate([
            'producto' => ['required', Rule::enum(ProductoComercial::class)],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'nombre' => ['required', 'string', 'min:2', 'max:120', $sinEnlaces],
            'negocio' => ['required', 'string', 'min:2', 'max:120', $sinEnlaces],
            'giro' => ['nullable', Rule::enum(PerfilNegocio::class)],
            'correo' => ['required', 'email:rfc,filter', 'max:190'],
            'telefono' => ['required', 'string', 'max:30'],
            'sello' => ['required', 'string', 'max:500'],
            // Lo que cuenta de su negocio (opcional, ADR 0204): ventas lo lee antes.
            'operando' => ['nullable', 'boolean'],
            'tamano' => ['nullable', Rule::in(CalificacionDemo::TAMANOS)],
            'apertura' => ['nullable', Rule::in(CalificacionDemo::APERTURAS)],
            'sucursales' => ['nullable', Rule::in(CalificacionDemo::SUCURSALES)],
            'objetivos' => ['nullable', 'array', 'max:'.CalificacionDemo::MAXIMO_OBJETIVOS],
            'objetivos.*' => ['string', Rule::in(CalificacionDemo::OBJETIVOS)],
            'giro_otro' => ['nullable', 'string', 'max:80', $sinEnlaces],
            'redes' => ['nullable', 'string', 'max:190'],
            'acepta_whatsapp' => ['nullable', 'boolean'],
            'acepta_aviso' => ['accepted'],
            'aviso_version' => ['nullable', 'integer', 'min:1'],
            'recaptcha_token' => ['nullable', 'string', 'max:4000'],
            // Campo trampa: un bot lo llena.
            'sitio_web' => ['prohibited'],
        ]);
        if (! $this->demos->activas()) {
            throw ValidationException::withMessages(['fecha' => ['Por ahora no estamos agendando demos.']]);
        }
        $this->revisarSello((string) $validado['sello']);
        $correo = mb_strtolower(trim((string) $validado['correo']));
        $dominio = substr((string) strrchr($correo, '@'), 1);
        if (in_array($dominio, (array) config('agendauno.ventas.dominios_desechables', []), true)) {
            throw ValidationException::withMessages(['correo' => ['Usa un correo que revises: ahí te llega la confirmación y el enlace para cancelarla.']]);
        }
        if (! $this->recaptcha->aprobado((string) ($validado['recaptcha_token'] ?? ''), $request->ip())) {
            throw ValidationException::withMessages(['recaptcha' => ['No pudimos verificar que no eres un robot. Recarga e inténtalo de nuevo.']]);
        }
        $aviso = $this->legales->vigente(DocumentoLegal::AVISO);
        if ($aviso === null && app()->environment('production')) {
            throw ValidationException::withMessages(['acepta_aviso' => ['Las demos abren cuando el aviso de privacidad esté publicado.']]);
        }
        $telefono = TelefonoWhatsApp::normalizar((string) $validado['telefono'], '52');
        if ($telefono === null) {
            throw ValidationException::withMessages(['telefono' => ['Escribe un teléfono válido, con su lada.']]);
        }
        // Una demo próxima por persona: para otro horario, se cancela la anterior con el
        // enlace de su correo (no dice cuándo es, para no revelar la agenda de nadie).
        $yaTiene = DemoAgendada::query()->where('estado', 'agendada')->where('inicia_en', '>=', now())
            ->where(fn ($q) => $q->where('correo', $correo)->orWhere('telefono', $telefono))
            ->exists();
        if ($yaTiene) {
            throw ValidationException::withMessages(['correo' => ['Ya hay una demo agendada con este correo o teléfono. Para cambiarla, cancélala con el enlace de tu correo y agenda otra.']]);
        }

        $demo = $this->demos->agendar([
            'producto' => (string) $validado['producto'],
            'fecha' => (string) $validado['fecha'],
            'hora' => (string) $validado['hora'],
            'nombre' => (string) $validado['nombre'],
            'negocio' => (string) $validado['negocio'],
            'giro' => isset($validado['giro']) ? (string) $validado['giro'] : null,
            'correo' => $correo,
            'telefono' => $telefono,
            'acepta_whatsapp' => (bool) ($validado['acepta_whatsapp'] ?? false) && $this->whatsapp->activoParaDuenos(),
            'aviso_version' => $aviso?->version,
            'calificacion' => CalificacionDemo::desde($validado),
        ]);
        Log::info('demo.agendada', ['demo' => $demo->ulid, 'producto' => $demo->producto]);
        $this->avisar($demo);

        return response()->json(['data' => [
            'id' => $demo->ulid,
            'inicia_en' => $demo->inicia_en->toIso8601String(),
            'whatsapp' => $demo->acepta_whatsapp,
        ]], 201);
    }

    /**
     * El sello de la página: que se haya abierto hace al menos unos segundos (una persona
     * tarda en llenar el formulario) y no hace horas (los horarios ya cambiaron).
     */
    private function revisarSello(string $sello): void
    {
        try {
            $abierto = (int) Crypt::decryptString($sello);
        } catch (DecryptException) {
            throw ValidationException::withMessages(['sello' => ['Recarga la página y vuelve a intentarlo.']]);
        }
        $segundos = now()->getTimestamp() - $abierto;
        if ($segundos < self::SEGUNDOS_MINIMOS) {
            throw ValidationException::withMessages(['sello' => ['Revisa tus datos un momento y vuelve a intentarlo.']]);
        }
        if ($segundos > self::HORAS_MAXIMAS * 3600) {
            throw ValidationException::withMessages(['sello' => ['La página lleva horas abierta: recárgala para ver los horarios al día.']]);
        }
    }

    /** Con el enlace de su correo: la cancela y libera el horario. */
    public function cancelar(Request $request): JsonResponse
    {
        $demo = DemoAgendada::query()->where('ulid', (string) $request->route('demo'))->firstOrFail();
        abort_unless(hash_equals($demo->token, (string) $request->input('token', '')), 404);
        if ($demo->estado === 'agendada') {
            $this->demos->cancelar($demo);
        }

        return response()->json(['data' => ['estado' => $demo->estado]]);
    }

    /**
     * Para el superadmin: las demos por páginas, con cuántas hay agendadas (hoy, en los
     * próximos 7 días y en total) y cómo fueron las de los últimos 30 días. `ver`:
     * `proximas` (agendadas que no han empezado, la más cercana primero), `pasadas` (las
     * que ya empezaron, la más reciente primero), `canceladas` o `todas`.
     */
    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'ver' => ['nullable', Rule::in(['proximas', 'pasadas', 'canceladas', 'todas'])],
            'producto' => ['nullable', Rule::enum(ProductoComercial::class)],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $ahora = now();
        $consulta = DemoAgendada::query()
            ->when($validado['producto'] ?? null, fn (Builder $q, string $p) => $q->where('producto', $p))
            ->when(trim((string) ($validado['q'] ?? '')), function (Builder $q, string $texto): void {
                $like = '%'.addcslashes(mb_strtolower($texto), '%_\\').'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(nombre) like ?', [$like])
                    ->orWhereRaw('lower(negocio) like ?', [$like])
                    ->orWhere('correo', 'like', $like)
                    ->orWhere('telefono', 'like', $like));
            });
        match ($validado['ver'] ?? 'proximas') {
            'proximas' => $consulta->where('estado', 'agendada')->where('inicia_en', '>=', $ahora)->orderBy('inicia_en'),
            'pasadas' => $consulta->where('inicia_en', '<', $ahora)->where('estado', '!=', 'cancelada')->orderByDesc('inicia_en'),
            'canceladas' => $consulta->where('estado', 'cancelada')->orderByDesc('inicia_en'),
            default => $consulta
                ->orderByRaw('case when inicia_en >= ? then 0 else 1 end', [$ahora])
                ->orderByRaw('case when inicia_en >= ? then inicia_en end asc', [$ahora])
                ->orderByDesc('inicia_en')
                // A la misma hora, la agendada antes que la cancelada.
                ->orderByRaw("case when estado = 'agendada' then 0 else 1 end"),
        };
        $pagina = $consulta->orderByDesc('id')->paginate(15, ['*'], 'page', (int) ($validado['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (DemoAgendada $d): array => $this->presentar($d))->all(),
            'meta' => [
                'page' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'per_page' => $pagina->perPage(),
                'resumen' => $this->resumen(),
            ],
        ]);
    }

    /**
     * Cuántas demos hay agendadas (hoy en la zona de ventas, en los próximos 7 días y
     * todas las que vienen) y cómo fueron las de los últimos 30 días.
     *
     * @return array<string, int>
     */
    private function resumen(): array
    {
        $ahora = CarbonImmutable::now($this->demos->zona());
        $agendadas = fn () => DemoAgendada::query()->where('estado', 'agendada');
        $ultimos30 = fn (string $estado): int => DemoAgendada::query()->where('estado', $estado)
            ->whereBetween('inicia_en', [$ahora->subDays(30)->utc(), $ahora->utc()])->count();

        return [
            'hoy' => $agendadas()->whereBetween('inicia_en', [$ahora->utc(), $ahora->endOfDay()->utc()])->count(),
            'semana' => $agendadas()->whereBetween('inicia_en', [$ahora->utc(), $ahora->addDays(7)->utc()])->count(),
            'proximas' => $agendadas()->where('inicia_en', '>=', $ahora->utc())->count(),
            'atendidas' => $ultimos30('atendida'),
            'no_asistio' => $ultimos30('no_asistio'),
            'canceladas' => DemoAgendada::query()->where('estado', 'cancelada')
                ->whereBetween('inicia_en', [$ahora->subDays(30)->utc(), $ahora->addDays(30)->utc()])->count(),
        ];
    }

    /** Demos › Horario: si se agendan, su duración, cupo, ventana y el horario semanal. */
    public function horario(): JsonResponse
    {
        return response()->json(['data' => $this->presentarHorario()]);
    }

    public function guardarHorario(Request $request): JsonResponse
    {
        $limites = fn (string $clave): string => 'between:'.CatalogoParametros::de($clave)->minimo.','.CatalogoParametros::de($clave)->maximo;
        $validado = $request->validate([
            'activas' => ['required', 'boolean'],
            ...array_map(fn (string $clave): array => ['required', 'integer', $limites($clave)], self::PARAMETROS),
            'rangos' => ['present', 'array', 'max:'.HorarioDemos::MAXIMO_RANGOS],
            'rangos.*.dia' => ['required', 'integer', 'between:1,7'],
            'rangos.*.desde' => ['required', 'string', 'regex:'.self::HORA],
            'rangos.*.hasta' => ['required', 'string', 'regex:'.self::HORA],
            'bloqueos' => ['present', 'array', 'max:'.HorarioDemos::MAXIMO_BLOQUEOS],
            'bloqueos.*.fecha' => ['required', 'date_format:Y-m-d'],
            'bloqueos.*.motivo' => ['nullable', 'string', 'max:120'],
        ], [
            'rangos.*.desde.regex' => 'Escribe la hora como 09:30.',
            'rangos.*.hasta.regex' => 'Escribe la hora como 18:00.',
        ]);
        if ($validado['activas'] && $validado['rangos'] === []) {
            throw ValidationException::withMessages(['rangos' => ['Para agendar demos, agrega al menos un horario.']]);
        }

        DB::transaction(function () use ($validado): void {
            $this->horario->guardar(
                array_map(fn (array $r): array => ['dia' => (int) $r['dia'], 'desde' => (string) $r['desde'], 'hasta' => (string) $r['hasta']], $validado['rangos']),
                array_map(fn (array $b): array => ['fecha' => (string) $b['fecha'], 'motivo' => $b['motivo'] ?? null], $validado['bloqueos']),
                $this->demos->zona(),
            );
            $valores = ['demos.activas' => $validado['activas'] ? 1 : 0];
            foreach (self::PARAMETROS as $campo => $clave) {
                $valores[$clave] = (int) $validado[$campo];
            }
            $this->parametros->guardarDePlataforma($valores);
        });

        return response()->json(['data' => $this->presentarHorario()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarHorario(): array
    {
        $datos = ['activas' => $this->demos->activas(), 'zona' => $this->demos->zona()];
        $limites = [];
        foreach (self::PARAMETROS as $campo => $clave) {
            $definicion = CatalogoParametros::de($clave);
            $datos[$campo] = $this->parametros->entero($clave);
            $limites[$campo] = ['minimo' => $definicion->minimo, 'maximo' => $definicion->maximo];
        }

        return [...$datos, ...$this->horario->leer($this->demos->zona()), 'limites' => $limites];
    }

    public function actualizar(Request $request): JsonResponse
    {
        $validado = $request->validate(['estado' => ['required', Rule::in(['cancelada', 'atendida', 'no_asistio'])]]);
        $demo = DemoAgendada::query()->where('ulid', (string) $request->route('demo'))->firstOrFail();
        if ($validado['estado'] === 'cancelada') {
            $this->demos->cancelar($demo);
        } else {
            $demo->forceFill(['estado' => $validado['estado']])->save();
        }

        return response()->json(['data' => $this->presentar($demo)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(DemoAgendada $d): array
    {
        return [
            'id' => $d->ulid,
            'producto' => $d->producto,
            'inicia_en' => $d->inicia_en->toIso8601String(),
            'nombre' => $d->nombre,
            'negocio' => $d->negocio,
            'giro' => $d->giro,
            'correo' => $d->correo,
            'telefono' => $d->telefono,
            'acepta_whatsapp' => $d->acepta_whatsapp,
            'estado' => $d->estado,
            'calificacion' => CalificacionDemo::enOrden($d->calificacion),
        ];
    }

    /**
     * Confirmación por correo (y por WhatsApp, si la pidió) y aviso a ventas. Si algo
     * falla, la demo queda agendada: se reporta.
     */
    private function avisar(DemoAgendada $demo): void
    {
        $producto = ProductoComercial::from($demo->producto);
        $cuando = $this->demos->cuando($demo);
        $texto = $this->demos->textoCuando($demo);
        $cancelar = $this->demos->enlaceCancelar($demo);
        $minutos = (string) $this->demos->minutos();
        try {
            Mail::to($demo->correo)->send(new CorreoDemoAgendada($producto, $texto, $minutos, $cancelar));
            $ventas = trim((string) config('agendauno.ventas.correo'));
            if ($ventas !== '') {
                Mail::to($ventas)->send(new CorreoDemoAgendada($producto, $texto, $minutos, null, [
                    'Nombre' => $demo->nombre,
                    'Negocio' => $demo->negocio,
                    'Giro' => $demo->giro ?? '—',
                    'Correo' => $demo->correo,
                    'Teléfono' => $demo->telefono,
                    'Confirmar por WhatsApp' => $demo->acepta_whatsapp ? 'Sí' : 'No',
                    ...CalificacionDemo::paraVentas($demo->calificacion),
                ]));
            }
        } catch (Throwable $e) {
            report($e);
        }

        $plantilla = PlantillasWhatsApp::demoAgendada();
        if ($demo->acepta_whatsapp && $this->whatsapp->activoParaDuenos()) {
            try {
                $this->whatsapp->enviarPlantilla($demo->telefono, $plantilla['nombre'], PlantillasWhatsApp::parametros($plantilla['texto'], [
                    'nombre' => trim(explode(' ', $demo->nombre)[0]),
                    'marca' => $producto->nombre(),
                    'fecha' => $cuando->locale('es')->translatedFormat('l j \d\e F'),
                    'hora' => $cuando->format('H:i'),
                    'enlace' => $cancelar,
                ]));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
