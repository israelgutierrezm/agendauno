<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\ExcepcionHorarioTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Carga acotada y atómica. La vista previa ejecuta las mismas reglas y revierte. */
class ImportarClasesTenant
{
    public function __construct(
        private readonly VerificarAgendaTenant $agenda,
        private readonly GenerarAgendaTenant $generador,
        private readonly ResolverAccesoTenant $acceso,
        private readonly ParametrosTenant $parametros,
        private readonly AccesoSesionTenant $accesoSesion,
    ) {}

    /**
     * @param  list<array<string, string|int|null>>  $filas
     * @return array<string, mixed>
     */
    public function ejecutar(array $filas, string $modo, Usuario $actor, bool $guardar = false, ?string $revision = null): array
    {
        $db = DB::connection('tenant');
        $nivel = $db->transactionLevel();
        $db->beginTransaction();
        try {
            // Serializa cargas del mismo negocio, incluso sesiones sin profesional/sala.
            // Orden estable antes de los candados habituales del verificador de agenda.
            $sedes = SucursalTenant::query()->orderBy('id')->get();
            foreach ($sedes as $sede) {
                $db->table('sucursales')->where('id', $sede->id)->update(['id' => DB::raw('id')]);
            }
            $salida = [];
            $vistas = [];
            $lote = (string) Str::ulid();
            $cantidad = 0;
            $max = $this->parametros->entero('importaciones.max_sesiones_agenda');
            foreach ($filas as $indice => $cruda) {
                $fila = (int) ($cruda['_fila'] ?? $indice + 2);
                unset($cruda['_fila']);
                $datos = array_map(static fn ($v): string => trim((string) $v), $cruda);
                $resultado = ['fila' => $fila, 'datos' => $datos, 'errores' => [], 'avisos' => [], 'sesiones' => [], 'omitida' => false];
                $db->beginTransaction();
                try {
                    $this->validar($datos, $modo);
                    $referencia = mb_strtolower($datos['referencia']);
                    if (isset($vistas[$referencia])) {
                        $this->error('referencia', 'La referencia está repetida en este archivo.');
                    }
                    $vistas[$referencia] = true;
                    $oferta = $this->resolver(OfertaTenant::query(), $datos['clase'], 'nombre', 'clase');
                    $sucursal = $this->resolver(SucursalTenant::query(), $datos['sucursal'], 'nombre', 'sucursal');
                    if (! $this->acceso->permiteSucursal($actor, (int) $sucursal->id)) {
                        $this->error('sucursal', 'No tienes acceso a esta sucursal.');
                    }
                    $instructor = ($datos['instructor'] ?? '') === '' ? null
                        : $this->resolver(Usuario::query()->profesionales()->where('activo', true), $datos['instructor'], 'name', 'instructor', true);
                    if ($this->accesoSesion->esInstructorAcotado($actor)
                        && $instructor?->id !== $actor->id
                        && ! $this->acceso->rolAsignadoPermite($actor, 'agenda.gestionar', (int) $sucursal->id)) {
                        $this->error('instructor', 'Tu rol permite importar únicamente tus propias clases.');
                    }
                    if ($instructor !== null && ! $this->acceso->permiteSucursal($instructor, (int) $sucursal->id)) {
                        $this->error('instructor', 'El instructor no está asignado a esta sucursal.');
                    }
                    $recurso = ($datos['sala'] ?? '') === '' ? null
                        : $this->resolver(RecursoTenant::query()->where('sucursal_id', $sucursal->id)->where('activo', true), $datos['sala'], 'nombre', 'sala');
                    $capacidad = ($datos['cupo'] ?? '') !== '' ? (int) $datos['cupo'] : $oferta->capacidad;
                    if ($capacidad === null || $capacidad < 1) {
                        $this->error('cupo', 'Indica el cupo: esta clase no tiene uno configurado.');
                    }
                    $duracion = $this->minutos($datos['fin']) - $this->minutos($datos['inicio']);
                    if ($duracion <= 0) {
                        $this->error('fin', 'La hora final debe ser posterior al inicio, dentro del mismo día.');
                    }
                    $dias = $modo === 'semanal' ? $this->dias($datos['dia']) : [];
                    $desde = $modo === 'semanal' ? $datos['desde'] : $datos['fecha'];
                    $hasta = $modo === 'semanal' ? $datos['hasta'] : $desde;
                    $fechas = [];
                    $cerradas = ExcepcionHorarioTenant::query()->whereBetween('fecha', [$desde, $hasta])->get()
                        ->map(fn ($e): string => $e->fecha->toDateString())->all();
                    for ($dia = CarbonImmutable::parse($desde); $dia->toDateString() <= $hasta; $dia = $dia->addDay()) {
                        if ($modo === 'fechas' || in_array($dia->dayOfWeekIso, $dias, true)) {
                            if (in_array($dia->toDateString(), $cerradas, true)) {
                                if ($modo === 'fechas' && ($datos['estado'] ?? '') !== 'suspendida') {
                                    $this->error('fecha', 'La fecha está cerrada en las excepciones de horario.');
                                }
                                if ($modo === 'semanal') {
                                    $resultado['avisos'][] = $dia->toDateString().': cierre configurado; no se generará.';

                                    continue;
                                }
                            }
                            $fechas[] = $dia->toDateString();
                        }
                    }
                    if ($fechas === []) {
                        $this->error('dia', 'El rango no contiene fechas disponibles para ese día de la semana.');
                    }
                    $cantidad += count($fechas);
                    if ($cantidad > $max) {
                        $this->error('archivo', "El archivo genera más de {$max} sesiones. Divide la carga en varios archivos.");
                    }
                    // Identidad del origen, no de la fecha actual: mover/cancelar luego no recrea nada.
                    ksort($datos);
                    $huella = hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
                    $anterior = $db->table('importaciones_agenda')->where('modo', $modo)->where('referencia', $referencia)->first();
                    if ($anterior !== null) {
                        if ($anterior->huella !== $huella) {
                            $this->error('referencia', 'Esta referencia ya se importó con otros datos. Modifica la sesión desde la agenda; no se sobrescribirá.');
                        }
                        $resultado['omitida'] = true;
                        $resultado['avisos'][] = 'Ya importada. Se conserva tal como está, aunque después se haya movido o cancelado.';
                    } else {
                        if ($instructor === null) {
                            $resultado['avisos'][] = 'Sin instructor asignado. Comprueba que sea intencional.';
                        }
                        $resultado['datos']['clase'] = $oferta->nombre;
                        $resultado['datos']['sucursal'] = $sucursal->nombre;
                        $resultado['datos']['instructor'] = $instructor?->name;
                        $resultado['datos']['zona_horaria'] = $sucursal->zona_horaria;
                        $resultado['datos']['cupo'] = $capacidad;
                        $this->agenda->bloquear($instructor?->id, $recurso);
                        foreach ($fechas as $fecha) {
                            $local = $fecha.' '.$datos['inicio'];
                            $inicio = CarbonImmutable::parse($local, $sucursal->zona_horaria);
                            if ($inicio->format('Y-m-d H:i') !== $local) {
                                $this->error('inicio', 'La hora no existe en la zona horaria de la sucursal (cambio de horario).');
                            }
                            $inicio = $inicio->utc();
                            $finLocal = CarbonImmutable::parse($fecha.' '.$datos['fin'], $sucursal->zona_horaria)->utc();
                            if ((int) $inicio->diffInMinutes($finLocal) !== $duracion) {
                                $this->error('fin', "{$fecha}: el intervalo atraviesa un cambio de horario de la sucursal. Ajusta esa sesión por separado.");
                            }
                            // Evita duplicar también sesiones creadas manualmente o con otra referencia.
                            if (SesionTenant::query()->where('sucursal_id', $sucursal->id)->where('oferta_id', $oferta->id)
                                ->where('inicia_en', $inicio)->where('recurso_id', $recurso?->id)->exists()) {
                                $this->error('fecha', "{$fecha}: ya existe esta clase a esa hora y en esa sala. Revisa la agenda.");
                            }
                        }
                        $serie = null;
                        if ($modo === 'semanal') {
                            $serie = PlantillaHorarioTenant::query()->create([
                                'oferta_id' => $oferta->id, 'sucursal_id' => $sucursal->id,
                                'instructor_id' => $instructor?->id, 'recurso_id' => $recurso?->id,
                                'dias_semana' => $dias, 'hora_local' => $datos['inicio'], 'duracion_minutos' => $duracion,
                                'capacidad' => $capacidad, 'vigente_desde' => $desde, 'vigente_hasta' => $hasta, 'activo' => true,
                            ]);
                            $generacion = $this->generador->ejecutar($serie, $desde, $hasta);
                            if ($generacion->omitidas !== []) {
                                $this->error('horario', implode(' / ', array_map(fn ($o): string => $o['fecha'].': '.$o['motivo'], $generacion->omitidas)));
                            }
                            $sesiones = SesionTenant::query()->where('serie_id', $serie->id)->orderBy('inicia_en')->get();
                        } else {
                            $inicio = CarbonImmutable::parse($desde.' '.$datos['inicio'], $sucursal->zona_horaria)->utc();
                            $estado = ($datos['estado'] ?? '') === 'suspendida' ? EstadoSesionTenant::Cancelada : EstadoSesionTenant::Programada;
                            if ($estado === EstadoSesionTenant::Programada) {
                                $this->agenda->exigirSinConflictos($instructor?->id, $recurso, $inicio, $inicio->addMinutes($duracion), null, (int) $sucursal->id, MargenesServicio::de($oferta));
                            }
                            $sesiones = collect([SesionTenant::query()->create([
                                'oferta_id' => $oferta->id, 'sucursal_id' => $sucursal->id,
                                'instructor_id' => $instructor?->id, 'recurso_id' => $recurso?->id,
                                'inicia_en' => $inicio, 'termina_en' => $inicio->addMinutes($duracion),
                                'zona_horaria' => $sucursal->zona_horaria, 'capacidad' => $capacidad, 'estado' => $estado,
                            ])]);
                        }
                        $resultado['sesiones'] = $sesiones->map(fn ($s): array => [
                            'fecha' => $s->inicia_en->copy()->setTimezone($sucursal->zona_horaria)->toDateString(),
                            'inicio' => $datos['inicio'], 'fin' => $datos['fin'], 'estado' => $s->estado->value,
                        ])->all();
                        $db->table('importaciones_agenda')->insert([
                            'modo' => $modo, 'referencia' => $referencia, 'huella' => $huella, 'lote' => $lote,
                            'sesiones' => json_encode($sesiones->pluck('ulid')->all(), JSON_THROW_ON_ERROR),
                            'serie_ulid' => $serie?->ulid, 'created_at' => now(),
                        ]);
                    }
                    $db->commit();
                } catch (ValidationException $e) {
                    $db->rollBack();
                    $resultado['errores'] = array_values(array_merge(...array_values($e->errors())));
                    $resultado['sesiones'] = [];
                }
                $salida[] = $resultado;
            }
            $invalidas = count(array_filter($salida, fn ($f): bool => $f['errores'] !== []));
            $omitidas = count(array_filter($salida, fn ($f): bool => $f['omitida']));
            $creadas = array_sum(array_map(fn ($f): int => count($f['sesiones']), $salida));
            $ok = $invalidas === 0 && $salida !== [];
            // El archivo no cambió, pero sí podrían haber cambiado cupos, cierres o
            // nombres/zonas del catálogo desde la revisión. No aplicar algo distinto.
            if ($guardar && $ok && $revision !== null && ! hash_equals($revision, self::revision($salida))) {
                $this->error('archivo', 'Cambió la programación desde la vista previa. Vuelve a revisar el archivo antes de importar.');
            }
            if ($guardar && $ok) {
                AuditoriaTenant::query()->create([
                    'actor_id' => $actor->id, 'actor_nombre' => $actor->name,
                    'accion' => 'agenda.importar', 'entidad_tipo' => 'importacion_agenda', 'entidad_id' => $lote,
                    'despues' => ['modo' => $modo, 'creadas' => $creadas, 'omitidas' => $omitidas, 'referencias' => array_keys($vistas)],
                ]);
                $db->commit();
            } else {
                $db->rollBack();
            }

            return ['ok' => $ok, 'lote' => $guardar && $ok ? $lote : null, 'creados' => $guardar && $ok ? $creadas : 0,
                'resumen' => ['total' => count($salida), 'validas' => count($salida) - $invalidas - $omitidas, 'invalidas' => $invalidas, 'omitidas' => $omitidas, 'sesiones' => $creadas], 'filas' => $salida];
        } finally {
            while ($db->transactionLevel() > $nivel) {
                $db->rollBack();
            }
        }
    }

    /** @param array<string, string> $d */
    private function validar(array $d, string $modo): void
    {
        $reglas = [
            'referencia' => ['required', 'string', 'max:120', 'regex:/^[\pL\pN_.-]+$/u'],
            'clase' => ['required', 'string', 'max:255'], 'sucursal' => ['required', 'string', 'max:255'],
            'instructor' => ['nullable', 'string', 'max:255'], 'sala' => ['nullable', 'string', 'max:255'],
            'inicio' => ['required', 'date_format:H:i'], 'fin' => ['required', 'date_format:H:i'],
            'cupo' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
        $reglas += $modo === 'fechas' ? ['fecha' => ['required', 'date_format:Y-m-d'], 'estado' => ['nullable', 'in:programada,suspendida']]
            : ['dia' => ['required', 'string'], 'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde']];
        Validator::make($d, $reglas)->validate();
        if ($modo === 'semanal' && CarbonImmutable::parse($d['desde'])->diffInDays(CarbonImmutable::parse($d['hasta'])) > 366) {
            $this->error('hasta', 'La vigencia no puede superar un año.');
        }
    }

    /** @param list<array<string, mixed>> $filas */
    public static function revision(array $filas): string
    {
        return hash('sha256', json_encode($filas, JSON_THROW_ON_ERROR));
    }

    /** @return list<int> */
    private function dias(string $valor): array
    {
        $mapa = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];
        $dia = $mapa[Str::ascii(mb_strtolower($valor))] ?? (ctype_digit($valor) ? (int) $valor : 0);
        if ($dia < 1 || $dia > 7) {
            $this->error('dia', 'Indica un día por fila: lunes a domingo (o 1 a 7).');
        }

        return [$dia];
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $q
     * @return T
     */
    private function resolver(Builder $q, string $valor, string $nombre, string $campo, bool $correo = false): Model
    {
        $resultados = $q->where(function ($q) use ($valor, $nombre, $correo): void {
            $q->where('ulid', $valor)->orWhereRaw('LOWER('.$nombre.') = ?', [mb_strtolower($valor)]);
            if ($correo) {
                $q->orWhereRaw('LOWER(email) = ?', [mb_strtolower($valor)]);
            }
        })->limit(2)->get();
        if ($resultados->count() !== 1) {
            $this->error($campo, "No se encontró un único {$campo} disponible con ese nombre o identificador. Revisa el catálogo; no se creará automáticamente.");
        }

        return $resultados->first();
    }

    private function minutos(string $hora): int
    {
        return ((int) substr($hora, 0, 2)) * 60 + (int) substr($hora, 3, 2);
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => [$mensaje]]);
    }
}
