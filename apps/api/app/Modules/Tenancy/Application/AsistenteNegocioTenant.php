<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\SegmentoComunicacion;
use App\Modules\Tenancy\Http\Controllers\ReporteCohortesTenantController;
use App\Modules\Tenancy\Http\Controllers\ReporteDemandaTenantController;
use App\Modules\Tenancy\Http\Controllers\ReporteNegocioTenantController;
use App\Modules\Tenancy\Http\Controllers\ReporteSucursalesTenantController;
use App\Modules\Tenancy\Http\Controllers\ReporteTendenciasTenantController;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * El asistente sobre los datos del negocio (ADR 0196): preguntas en lenguaje natural
 * sobre ocupación, ingresos, demanda y retención, y acciones que la persona revisa antes
 * de aplicar.
 *
 * - Responde con los reportes del propio negocio, como herramientas: los mismos números
 *   que la pantalla de Reportes, con el alcance por sede de quien pregunta. Solo cifras
 *   agregadas: ninguna herramienta devuelve nombres, correos ni teléfonos de clientes.
 * - Cada herramienta revisa el permiso de quien pregunta (y la función del plan): el
 *   asistente no ve lo que esa persona no podría ver.
 * - Las acciones (generar la agenda de una semana, mandar una campaña) solo se
 *   proponen: vuelven firmadas y cifradas, la persona las revisa y las aplica; al
 *   aplicarlas se vuelven a revisar permisos. Nada toca dinero ni créditos.
 * - El modelo es Claude (API de Anthropic, por HTTP); la llave es de la plataforma. Sin
 *   ella, o apagado por el superadmin, no se ofrece. Cada negocio tiene un tope de
 *   preguntas al día.
 */
class AsistenteNegocioTenant
{
    private const VUELTAS = 6;

    private const MINUTOS_PROPUESTA = 30;

    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly FuncionesPlan $funciones,
        private readonly FechasNegocioTenant $fechas,
        private readonly GenerarAgendaTenant $agenda,
        private readonly DifundirComunicacionTenant $difundir,
        private readonly ModalidadNegocioTenant $modalidad,
        private readonly AccesoSesionTenant $acceso,
    ) {}

    /** ¿Se ofrece a esta persona en este negocio? */
    public function disponible(Estudio $estudio, Usuario $usuario): bool
    {
        return $this->parametros->siNo('asistente.activo')
            && trim((string) config('agendauno.asistente.llave')) !== ''
            && $this->parametros->entero('asistente.preguntas_por_dia') > 0
            && $this->funciones->tiene($estudio, 'reportes_avanzados')
            && $usuario->puede('facturacion.ver');
    }

    /**
     * Contesta la conversación. `mensajes`: lo dicho hasta ahora, el último de la
     * persona.
     *
     * @param  list<array{rol: string, texto: string}>  $mensajes
     * @return array{respuesta: string, propuestas: list<array{tipo: string, resumen: string, datos: array<string, mixed>, token: string}>}
     */
    public function preguntar(Request $request, Estudio $estudio, Usuario $usuario, array $mensajes): array
    {
        if (! $this->disponible($estudio, $usuario)) {
            throw new HttpException(403, 'El asistente no está disponible.');
        }
        $this->contarPregunta($estudio);

        $conversacion = array_map(static fn (array $m): array => [
            'role' => $m['rol'] === 'asistente' ? 'assistant' : 'user',
            'content' => $m['texto'],
        ], $mensajes);
        $propuestas = [];
        $texto = '';

        for ($vuelta = 0; $vuelta < self::VUELTAS; $vuelta++) {
            $respuesta = $this->llamarModelo($estudio, $usuario, $conversacion);
            $bloques = (array) ($respuesta['content'] ?? []);
            $usos = array_values(array_filter($bloques, static fn (mixed $b): bool => is_array($b) && ($b['type'] ?? '') === 'tool_use'));
            $texto = trim(implode("\n", array_map(
                static fn (array $b): string => (string) ($b['text'] ?? ''),
                array_values(array_filter($bloques, static fn (mixed $b): bool => is_array($b) && ($b['type'] ?? '') === 'text')),
            )));
            if ($usos === [] || ($respuesta['stop_reason'] ?? '') !== 'tool_use') {
                break;
            }

            $conversacion[] = ['role' => 'assistant', 'content' => $bloques];
            $resultados = [];
            foreach ($usos as $uso) {
                $entrada = is_array($uso['input'] ?? null) ? $uso['input'] : [];
                [$contenido, $error, $propuesta] = $this->usarHerramienta($request, $estudio, $usuario, (string) ($uso['name'] ?? ''), $entrada);
                if ($propuesta !== null) {
                    $propuestas[] = $propuesta;
                }
                $resultados[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) ($uso['id'] ?? ''),
                    'content' => $contenido,
                    ...($error ? ['is_error' => true] : []),
                ];
            }
            $conversacion[] = ['role' => 'user', 'content' => $resultados];
        }

        return [
            'respuesta' => $texto !== '' ? $texto : 'No pude contestar eso. Intenta con otra pregunta.',
            'propuestas' => $propuestas,
        ];
    }

    /**
     * Aplica una acción propuesta que la persona revisó. Se vuelve a revisar que sea suya,
     * de este negocio, vigente y que aún tenga el permiso. Cada propuesta se aplica una
     * sola vez: un doble clic o un reintento no la repiten (si falla, se puede volver a
     * intentar).
     *
     * @return array{tipo: string, resultado: array<string, mixed>}
     */
    public function aplicar(Estudio $estudio, Usuario $usuario, string $token): array
    {
        try {
            /** @var array{tipo: string, datos: array<string, mixed>, usuario: int, estudio: int, vence: int, id?: string} $propuesta */
            $propuesta = Crypt::decrypt($token);
        } catch (DecryptException) {
            throw ValidationException::withMessages(['token' => ['Esa propuesta no es válida.']]);
        }
        if ((int) $propuesta['usuario'] !== (int) $usuario->getKey() || (int) $propuesta['estudio'] !== (int) $estudio->getKey()) {
            throw ValidationException::withMessages(['token' => ['Esa propuesta no es tuya.']]);
        }
        if ((int) $propuesta['vence'] < now()->getTimestamp()) {
            throw ValidationException::withMessages(['token' => ['La propuesta venció: vuelve a pedirla.']]);
        }
        $id = (string) ($propuesta['id'] ?? '');
        if ($id === '') {
            throw ValidationException::withMessages(['token' => ['Esa propuesta no es válida: vuelve a pedirla.']]);
        }
        $usada = 'asistente:propuesta:'.$estudio->getKey().':'.$id;
        if (! Cache::add($usada, true, now()->addMinutes(self::MINUTOS_PROPUESTA + 5))) {
            throw ValidationException::withMessages(['token' => ['Esa propuesta ya se aplicó.']]);
        }

        try {
            return $this->aplicarPropuesta($estudio, $usuario, $propuesta['tipo'], $propuesta['datos']);
        } catch (Throwable $e) {
            // No se aplicó: la misma propuesta se puede volver a intentar.
            Cache::forget($usada);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{tipo: string, resultado: array<string, mixed>}
     */
    private function aplicarPropuesta(Estudio $estudio, Usuario $usuario, string $tipo, array $datos): array
    {
        if ($tipo === 'generar_agenda') {
            abort_unless($usuario->puede('agenda.gestionar') && $this->modalidad->esClases(), 403);
            $creadas = 0;
            $omitidas = 0;
            // Con el alcance de quien la aplica, como el endpoint de cada serie: solo las
            // de sus sedes (y, si imparte, las suyas).
            foreach (PlantillaHorarioTenant::query()->where('activo', true)->get() as $plantilla) {
                $instructor = $plantilla->instructor_id !== null ? (int) $plantilla->instructor_id : null;
                if (! $this->acceso->puedeOperarEn((int) $plantilla->sucursal_id, $instructor, $usuario)) {
                    continue;
                }
                $generacion = $this->agenda->ejecutar($plantilla, (string) $datos['desde'], (string) $datos['hasta']);
                $creadas += $generacion->creadas;
                $omitidas += count($generacion->omitidas);
            }

            return ['tipo' => 'generar_agenda', 'resultado' => ['creadas' => $creadas, 'omitidas' => $omitidas]];
        }
        if ($tipo === 'campana') {
            abort_unless($usuario->puede('comunicaciones.gestionar') && $this->funciones->tiene($estudio, 'mensajes'), 403);
            // El mismo camino que una campaña desde Comunicaciones: candado y la regla de
            // no repetirla en 24 horas.
            $difusion = $this->difundir->unaVez(
                SegmentoComunicacion::from((string) $datos['segmento']),
                CanalComunicacion::from((string) $datos['canal']),
                (string) $datos['asunto'],
                (string) $datos['cuerpo'],
            );

            return ['tipo' => 'campana', 'resultado' => ['enviados' => $difusion->total]];
        }

        throw ValidationException::withMessages(['token' => ['No se reconoce esa acción.']]);
    }

    /**
     * Las herramientas que ve el modelo.
     *
     * @return list<array<string, mixed>>
     */
    public static function herramientas(): array
    {
        $periodo = [
            'type' => 'object',
            'properties' => [
                'desde' => ['type' => 'string', 'description' => 'Fecha inicial, AAAA-MM-DD (día del negocio).'],
                'hasta' => ['type' => 'string', 'description' => 'Fecha final, AAAA-MM-DD, incluida.'],
            ],
            'required' => ['desde', 'hasta'],
        ];

        return [
            ['name' => 'reporte_negocio', 'description' => 'Dinero (vendido, cobrado, devuelto y neto por moneda, en centavos), órdenes pagadas, ocupación de clases, inasistencias, clientes activos e ingreso por cliente de un periodo.', 'input_schema' => $periodo],
            ['name' => 'reporte_sucursales', 'description' => 'Cada sucursal hoy: clientes con plan activo y sesiones próximas (no depende del periodo ni trae dinero).', 'input_schema' => $periodo],
            ['name' => 'reporte_demanda', 'description' => 'Demanda por día de la semana y hora: reservas, ocupación y lista de espera en un periodo.', 'input_schema' => $periodo],
            ['name' => 'reporte_tendencias', 'description' => 'Cómo va el dinero en el tiempo (vendido, cobrado, devuelto, ventas y lo más vendido), agrupado por día, semana o mes.', 'input_schema' => [
                ...$periodo,
                'properties' => [...$periodo['properties'], 'agrupacion' => ['type' => 'string', 'enum' => ['dia', 'semana', 'mes']]],
            ]],
            ['name' => 'reporte_cohortes', 'description' => 'Retención por mes de alta: de los clientes que empezaron cada mes, cuántos siguen activos después.', 'input_schema' => [
                'type' => 'object',
                'properties' => ['meses' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 12]],
            ]],
            ['name' => 'segmentos_de_clientes', 'description' => 'Cuántos clientes hay en cada segmento para campañas (todos, por vencer, vencidos, primerizos…), con su clave.', 'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'proponer_accion', 'description' => 'Propone una acción para que la persona la revise y la aplique (no se hace sola): generar la agenda de clases de un periodo desde las plantillas de horario, o mandar una campaña a un segmento por correo o en la app.', 'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'tipo' => ['type' => 'string', 'enum' => ['generar_agenda', 'campana']],
                    'resumen' => ['type' => 'string', 'description' => 'Qué hará, en una línea, para quien la revisa.'],
                    'desde' => ['type' => 'string', 'description' => 'generar_agenda: AAAA-MM-DD.'],
                    'hasta' => ['type' => 'string', 'description' => 'generar_agenda: AAAA-MM-DD.'],
                    'segmento' => ['type' => 'string', 'description' => 'campana: clave del segmento.'],
                    'canal' => ['type' => 'string', 'enum' => ['email', 'interno']],
                    'asunto' => ['type' => 'string'],
                    'cuerpo' => ['type' => 'string'],
                ],
                'required' => ['tipo', 'resumen'],
            ]],
        ];
    }

    /**
     * Usa una herramienta con el permiso de quien pregunta. Devuelve su contenido para el
     * modelo, si fue un error y, si es una propuesta, la propuesta firmada.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: string, 1: bool, 2: array{tipo: string, resumen: string, datos: array<string, mixed>, token: string}|null}
     */
    private function usarHerramienta(Request $request, Estudio $estudio, Usuario $usuario, string $nombre, array $entrada): array
    {
        try {
            if ($nombre === 'proponer_accion') {
                $propuesta = $this->proponer($estudio, $usuario, $entrada);

                return ['Propuesta lista para que la persona la revise y la aplique.', false, $propuesta];
            }
            $avanzado = in_array($nombre, ['reporte_demanda', 'reporte_tendencias', 'reporte_cohortes'], true);
            if (! $usuario->puede('facturacion.ver') || ($avanzado && ! $this->funciones->tiene($estudio, 'reportes_avanzados'))) {
                return ['Quien pregunta no tiene permiso para ver ese reporte.', true, null];
            }
            $controlador = match ($nombre) {
                'reporte_negocio' => ReporteNegocioTenantController::class,
                'reporte_sucursales' => ReporteSucursalesTenantController::class,
                'reporte_demanda' => ReporteDemandaTenantController::class,
                'reporte_tendencias' => ReporteTendenciasTenantController::class,
                'reporte_cohortes' => ReporteCohortesTenantController::class,
                'segmentos_de_clientes' => null,
                default => throw new RuntimeException('No existe esa herramienta.'),
            };
            if ($controlador === null) {
                $segmentos = app(ResolverSegmentoTenant::class)->conteos();

                return [(string) json_encode($segmentos, JSON_UNESCAPED_UNICODE), false, null];
            }

            return [$this->reporte($request, $controlador, $entrada), false, null];
        } catch (ValidationException $e) {
            return ['Datos inválidos: '.implode(' ', array_merge(...array_values($e->errors()))), true, null];
        } catch (Throwable $e) {
            report($e);

            return ['No se pudo consultar ese reporte.', true, null];
        }
    }

    /**
     * Un reporte del negocio, como lo ve la pantalla de Reportes (con el alcance por sede
     * de quien pregunta).
     *
     * @param  class-string  $controlador
     * @param  array<string, mixed>  $parametros
     */
    private function reporte(Request $request, string $controlador, array $parametros): string
    {
        $sub = Request::create('/', 'GET', array_filter($parametros, static fn (mixed $v): bool => is_scalar($v)));
        foreach ($request->attributes->all() as $clave => $valor) {
            $sub->attributes->set($clave, $valor);
        }
        $sub->headers->replace($request->headers->all());
        $respuesta = app()->call([app($controlador), '__invoke'], ['request' => $sub]);

        return $respuesta instanceof JsonResponse ? (string) $respuesta->getContent() : '{}';
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{tipo: string, resumen: string, datos: array<string, mixed>, token: string}
     */
    private function proponer(Estudio $estudio, Usuario $usuario, array $entrada): array
    {
        $tipo = (string) ($entrada['tipo'] ?? '');
        $datos = match ($tipo) {
            'generar_agenda' => [
                'desde' => CarbonImmutable::parse((string) ($entrada['desde'] ?? ''))->toDateString(),
                'hasta' => CarbonImmutable::parse((string) ($entrada['hasta'] ?? ''))->toDateString(),
            ],
            'campana' => [
                'segmento' => SegmentoComunicacion::from((string) ($entrada['segmento'] ?? ''))->value,
                'canal' => in_array($entrada['canal'] ?? '', ['email', 'interno'], true) ? (string) $entrada['canal'] : 'interno',
                'asunto' => mb_substr(trim((string) ($entrada['asunto'] ?? '')), 0, 255),
                'cuerpo' => mb_substr(trim((string) ($entrada['cuerpo'] ?? '')), 0, 5000),
            ],
            default => throw ValidationException::withMessages(['tipo' => ['Esa acción no existe.']]),
        };
        if ($tipo === 'campana' && ($datos['asunto'] === '' || $datos['cuerpo'] === '')) {
            throw ValidationException::withMessages(['cuerpo' => ['La campaña lleva asunto y texto.']]);
        }

        return [
            'tipo' => $tipo,
            'resumen' => mb_substr(trim((string) ($entrada['resumen'] ?? '')), 0, 300),
            'datos' => $datos,
            'token' => Crypt::encrypt([
                // Para aplicarla una sola vez.
                'id' => bin2hex(random_bytes(16)),
                'tipo' => $tipo,
                'datos' => $datos,
                'usuario' => (int) $usuario->getKey(),
                'estudio' => (int) $estudio->getKey(),
                'vence' => now()->addMinutes(self::MINUTOS_PROPUESTA)->getTimestamp(),
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $conversacion
     * @return array<string, mixed>
     */
    private function llamarModelo(Estudio $estudio, Usuario $usuario, array $conversacion): array
    {
        $hoy = CarbonImmutable::now($this->fechas->zona());
        $sistema = 'Eres el asistente de '.$estudio->nombre.', un negocio de '.($this->modalidad->esClases() ? 'clases' : 'citas')
            .'. Hoy es '.$hoy->locale('es')->translatedFormat('l j \d\e F \d\e Y').' en la zona del negocio.'
            .' Contesta en español, breve y claro, a '.$usuario->name.'.'
            .' Usa las herramientas para cualquier número: nunca lo inventes; si un reporte no lo tiene, dilo.'
            .' Los montos vienen en centavos (divide entre 100) con su moneda.'
            .' Si piden hacer algo, usa proponer_accion y explica que deben revisarla y aplicarla; tú no la aplicas.'
            .' No pidas ni muestres datos personales de clientes.';

        $respuesta = Http::withHeaders([
            'x-api-key' => (string) config('agendauno.asistente.llave'),
            'anthropic-version' => '2023-06-01',
        ])->timeout((int) config('agendauno.asistente.timeout', 60))
            ->post((string) config('agendauno.asistente.url'), [
                'model' => (string) config('agendauno.asistente.modelo'),
                'max_tokens' => 1500,
                'system' => $sistema,
                'tools' => self::herramientas(),
                'messages' => $conversacion,
            ]);
        if (! $respuesta->successful()) {
            throw new HttpException(502, 'El asistente no respondió. Intenta en un momento.');
        }

        return (array) $respuesta->json();
    }

    /** Cuenta la pregunta en el tope del día del negocio. */
    private function contarPregunta(Estudio $estudio): void
    {
        $clave = 'asistente:'.$estudio->getKey().':'.CarbonImmutable::now($this->fechas->zona())->toDateString();
        Cache::add($clave, 0, 86400 * 2);
        if ((int) Cache::increment($clave) > $this->parametros->entero('asistente.preguntas_por_dia')) {
            throw new HttpException(429, 'Llegaste a las preguntas del día al asistente. Vuelve mañana.');
        }
    }
}
