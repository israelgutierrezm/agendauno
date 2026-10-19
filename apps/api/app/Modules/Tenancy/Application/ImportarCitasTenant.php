<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\Exceptions\PersonaDadaDeBaja;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Importa las citas futuras de otro sistema (H, ADR 0160): cliente, servicio,
 * profesional, sede, fecha y hora. Atómico: si una fila no se puede, no se importa
 * ninguna. La vista previa ejecuta las mismas reglas y revierte.
 *
 * - **Correspondencias:** un servicio, profesional o sede con otro nombre en el archivo
 *   se elige una vez (`correspondencias[campo][valor] = ulid`) y vale para todas sus
 *   filas. Sin correspondencia, se busca por nombre sin importar mayúsculas ni acentos.
 * - **Clientes (R14):** se reconocen por su identificador en el otro sistema
 *   (`cliente_ref`, queda en su ficha), su correo o su celular; si no, se crea su
 *   ficha. Sin ninguno de los tres no se importa la fila (se duplicaría la persona: no
 *   se deduplica por nombre). Si el correo y el celular (o el identificador) son de
 *   fichas distintas, es un dato ambiguo y se pide resolverlo.
 * - **Conflictos:** las mismas reglas que al agendar en recepción (el profesional no
 *   atiende dos a la vez, cabina libre).
 * - **Reimportar:** una cita idéntica (persona, servicio, hora, sucursal y profesional;
 *   o la misma `referencia` con los mismos datos) no se duplica; la misma cita con otro
 *   profesional, otra sucursal u otra hora es un cambio que se decide en la agenda, no
 *   en silencio.
 * - **Lo que se cobra (R14):** las citas quedan por cobrar en caja. Lo que ya se pagó
 *   en el otro sistema (`pagado`, un anticipo o todo) se descuenta de lo que queda por
 *   cobrar y no se registra como ingreso nuevo. El resumen dice cuántas quedan por
 *   cobrar y cuánto.
 * - **Avisos apagados:** importar no le manda nada a nadie (sus eventos quedan
 *   publicados sin consumidores). Los recordatorios antes de la cita, solo si se pide.
 */
class ImportarCitasTenant
{
    /** Columnas de la plantilla (las de `OPCIONALES` pueden faltar). */
    public const COLUMNAS = ['referencia', 'cliente', 'cliente_ref', 'email', 'celular', 'servicio', 'profesional', 'sucursal', 'fecha', 'hora', 'pagado'];

    public const OPCIONALES = ['referencia', 'cliente_ref', 'email', 'celular', 'pagado'];

    /** Lo que se elige por correspondencia. */
    public const CAMPOS = ['servicio', 'profesional', 'sucursal'];

    public function __construct(
        private readonly AgendarCitaTenant $agendar,
        private readonly AltaDeMiembroTenant $altas,
        private readonly CelularesTenant $celulares,
        private readonly ResolverAccesoTenant $acceso,
        private readonly ParametrosTenant $parametros,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @param  list<array<string, string|int|null>>  $filas
     * @param  array<string, array<string, string>>  $correspondencias
     * @return array{ok: bool, creados: int, lote: string|null, resumen: array{total: int, validas: int, invalidas: int, omitidas: int, clientes_nuevos: int, por_cobrar: int, por_cobrar_minor: int, pagadas_antes: int, moneda: string}, faltantes: array<string, list<string>>, filas: list<array<string, mixed>>}
     */
    public function ejecutar(array $filas, Usuario $actor, array $correspondencias = [], bool $recordar = false, bool $guardar = false, ?string $revision = null): array
    {
        $max = $this->parametros->entero('importaciones.max_filas');
        if (count($filas) > $max) {
            throw ValidationException::withMessages(['archivo' => ["Se importan hasta {$max} citas a la vez."]]);
        }
        $db = DB::connection('tenant');
        $db->beginTransaction();
        try {
            $salida = [];
            $faltantes = array_fill_keys(self::CAMPOS, []);
            $vistas = [];
            $referencias = [];
            $nuevos = 0;
            $porCobrar = ['citas' => 0, 'minor' => 0, 'pagadas_antes' => 0];
            $moneda = $this->parametros->moneda();
            $lote = (string) Str::ulid();
            foreach ($filas as $indice => $cruda) {
                $numero = (int) ($cruda['_fila'] ?? $indice + 2);
                unset($cruda['_fila']);
                $datos = array_map(static fn ($v): string => trim((string) $v), $cruda) + array_fill_keys(self::COLUMNAS, '');
                $resultado = ['fila' => $numero, 'datos' => $datos, 'errores' => [], 'avisos' => [], 'omitida' => false, 'cliente' => null, 'inicia_en' => null];
                $db->beginTransaction();
                try {
                    $this->validar($datos);
                    $oferta = $this->resolver(OfertaTenant::query()->vigentes(), 'servicio', $datos['servicio'], 'nombre', $correspondencias, $faltantes);
                    $sucursal = $this->resolver(SucursalTenant::query(), 'sucursal', $datos['sucursal'], 'nombre', $correspondencias, $faltantes);
                    $profesional = $this->resolver(Usuario::query()->profesionales()->where('activo', true), 'profesional', $datos['profesional'], 'name', $correspondencias, $faltantes);
                    if (! $this->acceso->permiteSucursal($actor, (int) $sucursal->getKey())) {
                        $this->error('sucursal', 'No tienes acceso a esta sucursal.');
                    }
                    if (! $this->acceso->permiteSucursal($profesional, (int) $sucursal->getKey())) {
                        $this->error('profesional', "{$profesional->name} no atiende en {$sucursal->nombre}.");
                    }
                    $zona = $sucursal->zona_horaria !== '' ? $sucursal->zona_horaria : 'America/Mexico_City';
                    $inicia = CarbonImmutable::parse("{$datos['fecha']} {$datos['hora']}", $zona)->utc();
                    if (! $inicia->isFuture()) {
                        $this->error('fecha', 'Ya pasó: solo se importan citas futuras.');
                    }
                    $clave = mb_strtolower(($datos['cliente_ref'] ?: $datos['email'] ?: $datos['celular']).'|'.$inicia->toIso8601String());
                    if (isset($vistas[$clave])) {
                        $this->error('fecha', 'Esta cita está repetida en el archivo.');
                    }
                    $vistas[$clave] = true;
                    if ($datos['referencia'] !== '') {
                        if (isset($referencias[$datos['referencia']])) {
                            $this->error('referencia', 'Esa referencia está repetida en el archivo.');
                        }
                        $referencias[$datos['referencia']] = true;
                    }
                    $pagado = $this->pagado($datos['pagado']);

                    [$persona, $esNueva] = $this->cliente($datos, $actor, (int) $sucursal->getKey());
                    $resultado['cliente'] = ['nombre' => $persona->nombreCompleto(), 'nueva' => $esNueva];
                    $resultado['inicia_en'] = $inicia->toIso8601String();
                    if ($this->yaImportada($datos['referencia'], $persona, $oferta, $sucursal, $profesional, $inicia)) {
                        $resultado['omitida'] = true;
                        $resultado['avisos'][] = 'Ya estaba agendada: no se duplica.';
                    } else {
                        $reserva = $this->agendar->agendar($oferta, $sucursal, $persona, (int) $profesional->getKey(), $inicia, 0, true);
                        if ($datos['referencia'] !== '') {
                            $reserva->forceFill(['referencia_externa' => $datos['referencia']])->save();
                        }
                        $this->sinAvisos($reserva, $recordar);
                        $resultado['avisos'] = [...$resultado['avisos'], ...$this->saldoInicial($reserva, $pagado, $porCobrar, $moneda)];
                        $nuevos += $esNueva ? 1 : 0;
                        if ($esNueva) {
                            $resultado['avisos'][] = 'Se crea su ficha.';
                        }
                    }
                    $db->commit();
                } catch (ValidationException $e) {
                    $db->rollBack();
                    $resultado['errores'] = array_values(array_merge(...array_values($e->errors())));
                } catch (ReservaException $e) {
                    $db->rollBack();
                    $resultado['errores'][] = $e->getMessage();
                }
                $salida[] = $resultado;
            }

            $invalidas = count(array_filter($salida, static fn (array $f): bool => $f['errores'] !== []));
            $omitidas = count(array_filter($salida, static fn (array $f): bool => $f['omitida']));
            $ok = $invalidas === 0;
            $creados = count($salida) - $invalidas - $omitidas;
            $resumen = [
                'total' => count($salida), 'validas' => count($salida) - $invalidas, 'invalidas' => $invalidas, 'omitidas' => $omitidas, 'clientes_nuevos' => $nuevos,
                // Lo que quedará por cobrar en caja (R14) y lo ya pagado en el otro sistema.
                'por_cobrar' => $porCobrar['citas'], 'por_cobrar_minor' => $porCobrar['minor'], 'pagadas_antes' => $porCobrar['pagadas_antes'], 'moneda' => $moneda,
            ];
            $faltantes = array_map(static fn (array $v): array => array_values(array_unique($v)), $faltantes);

            if (! $guardar || ! $ok || ($revision !== null && $revision !== self::revision($salida))) {
                $db->rollBack();
                if ($guardar && $ok) {
                    throw ValidationException::withMessages(['archivo' => ['Algo cambió desde la vista previa (otra cita ocupó un horario): vuelve a revisar el archivo.']]);
                }

                return ['ok' => $ok, 'creados' => 0, 'lote' => null, 'resumen' => $resumen, 'faltantes' => $faltantes, 'filas' => $salida];
            }

            $this->auditoria->registrar($actor, 'importacion.citas', 'importacion', $lote, null, [
                'citas' => $creados, 'omitidas' => $omitidas, 'clientes_nuevos' => $nuevos, 'recordatorios' => $recordar,
            ]);
            $db->commit();

            return ['ok' => true, 'creados' => $creados, 'lote' => $lote, 'resumen' => $resumen, 'faltantes' => $faltantes, 'filas' => $salida];
        } catch (\Throwable $e) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Huella de lo que dio la vista previa: si al importar algo cambió (un horario se
     * ocupó, un cliente ya existe), se pide revisar de nuevo.
     *
     * @param  list<array<string, mixed>>  $filas
     */
    public static function revision(array $filas): string
    {
        return hash('sha256', json_encode(array_map(static fn (array $f): array => [$f['fila'], $f['errores'], $f['omitida'], $f['inicia_en']], $filas), JSON_THROW_ON_ERROR));
    }

    /** @param  array<string, string>  $datos */
    private function validar(array $datos): void
    {
        $v = Validator::make($datos, [
            'cliente' => ['required', 'string', 'max:255'],
            'cliente_ref' => ['nullable', 'string', 'max:120'],
            'referencia' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'celular' => ['nullable', 'string', 'max:30'],
            // Lo ya pagado en el otro sistema, en pesos (p. ej. 150 o 150.50).
            'pagado' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'servicio' => ['required', 'string', 'max:255'],
            'profesional' => ['required', 'string', 'max:255'],
            'sucursal' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
        ], [
            'fecha.date_format' => 'La fecha va como AAAA-MM-DD.',
            'hora.date_format' => 'La hora va como HH:MM (24 horas).',
            'pagado.regex' => 'Lo pagado va como número (p. ej. 150 o 150.50).',
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        if ($datos['cliente_ref'] === '' && $datos['email'] === '' && $datos['celular'] === '') {
            $this->error('cliente', 'Sin correo, celular ni número de cliente (cliente_ref) no se sabe si ya existe y se duplicaría su ficha: agrega uno.');
        }
    }

    /** Lo ya pagado en el otro sistema, en centavos (0 sin dato). */
    private function pagado(string $texto): int
    {
        if ($texto === '') {
            return 0;
        }
        [$enteros, $decimales] = array_pad(explode('.', $texto, 2), 2, '0');

        return (int) $enteros * 100 + (int) str_pad(substr($decimales, 0, 2), 2, '0');
    }

    /**
     * Lo que queda por cobrar de la cita importada (R14): su orden menos lo ya pagado en
     * el otro sistema, sin registrar ese pago como ingreso nuevo. Si ya se pagó todo, no
     * queda nada por cobrar.
     *
     * @param  array{citas: int, minor: int, pagadas_antes: int}  $porCobrar
     * @return list<string>
     */
    private function saldoInicial(ReservaTenant $reserva, int $pagado, array &$porCobrar, string $moneda): array
    {
        $orden = $reserva->orden;
        if ($orden === null) {
            return [];
        }
        $resto = max(0, (int) $orden->total_minor - $pagado);
        if ($pagado > 0) {
            $orden->update($resto === 0
                ? ['total_minor' => 0, 'estado' => EstadoOrden::Cancelada->value, 'cancelada_en' => now()]
                : ['total_minor' => $resto]);
        }
        if ($resto === 0) {
            $porCobrar['pagadas_antes']++;

            return ['Pagada en el sistema anterior: no queda por cobrar (no se registra como ingreso).'];
        }
        $porCobrar['citas']++;
        $porCobrar['minor'] += $resto;

        return $pagado > 0
            ? ['Pagó '.DatosDeOrden::dinero($pagado, $moneda).' en el sistema anterior: quedan '.DatosDeOrden::dinero($resto, $moneda).' por cobrar.']
            : ['Queda por cobrar '.DatosDeOrden::dinero($resto, $moneda).'.'];
    }

    /**
     * Por su correspondencia elegida o por nombre (sin mayúsculas ni acentos).
     *
     * @template T of Model
     *
     * @param  Builder<T>  $consulta
     * @param  array<string, array<string, string>>  $correspondencias
     * @param  array<string, list<string>>  $faltantes
     * @return T
     */
    private function resolver(Builder $consulta, string $campo, string $valor, string $columna, array $correspondencias, array &$faltantes): Model
    {
        $elegido = $correspondencias[$campo][$valor] ?? null;
        if (is_string($elegido) && $elegido !== '') {
            $encontrado = (clone $consulta)->where('ulid', $elegido)->first();
            if ($encontrado !== null) {
                return $encontrado;
            }
        }
        $buscado = self::normalizar($valor);
        $coinciden = (clone $consulta)->get()->filter(static fn (Model $m): bool => self::normalizar((string) $m->getAttribute($columna)) === $buscado);
        if ($coinciden->count() === 1) {
            return $coinciden->first();
        }
        $faltantes[$campo][] = $valor;
        $this->error($campo, $coinciden->count() > 1
            ? "Hay más de un {$campo} «{$valor}»: elige cuál es en Correspondencias."
            : "No encontramos el {$campo} «{$valor}»: elige su correspondencia.");
    }

    private static function normalizar(string $texto): string
    {
        return mb_strtolower(trim(Str::ascii($texto)));
    }

    /**
     * Su ficha: por su número en el otro sistema, su correo, su celular o una nueva. Si
     * apuntan a fichas distintas es un dato ambiguo: se pide resolverlo (R14).
     *
     * @param  array<string, string>  $datos
     * @return array{0: PersonaTenant, 1: bool}
     */
    private function cliente(array $datos, Usuario $actor, int $sucursalId): array
    {
        $candidatas = array_filter([
            'cliente_ref' => $datos['cliente_ref'] !== '' ? PersonaTenant::query()->where('referencia_externa', $datos['cliente_ref'])->first() : null,
            'email' => $datos['email'] !== '' ? PersonaTenant::query()->where('email', $datos['email'])->first() : null,
            'celular' => $datos['celular'] !== '' ? $this->celulares->buscar(PersonaTenant::query(), $datos['celular']) : null,
        ], static fn ($p): bool => $p instanceof PersonaTenant);
        $distintas = array_unique(array_map(static fn (PersonaTenant $p): int => (int) $p->getKey(), $candidatas));
        if (count($distintas) > 1) {
            $this->error('cliente', 'El '.implode(' y el ', array_map(static fn (string $c): string => ['cliente_ref' => 'número de cliente', 'email' => 'correo', 'celular' => 'celular'][$c], array_keys($candidatas))).' son de fichas distintas: corrige la fila o une esas fichas antes de importar.');
        }
        $encontrada = reset($candidatas);
        if ($encontrada instanceof PersonaTenant) {
            // Su número del otro sistema queda en su ficha (para reimportar sin dudas).
            if ($datos['cliente_ref'] !== '' && $encontrada->referencia_externa !== $datos['cliente_ref']) {
                if ($encontrada->referencia_externa !== null && $encontrada->referencia_externa !== '') {
                    $this->error('cliente_ref', "Esta ficha ya tiene otro número de cliente ({$encontrada->referencia_externa}): revisa la fila.");
                }
                $encontrada->forceFill(['referencia_externa' => $datos['cliente_ref']])->save();
            }

            return [$encontrada, false];
        }
        $partes = preg_split('/\s+/', $datos['cliente'], 2) ?: [$datos['cliente']];
        try {
            ['persona' => $persona, 'reactivado' => $reactivado] = $this->altas->alta([
                'nombre' => $partes[0],
                'primer_apellido' => $partes[1] ?? null,
                'email' => $datos['email'] !== '' ? $datos['email'] : null,
                'celular' => $datos['celular'] !== '' ? $datos['celular'] : null,
            ], $actor, $sucursalId);
        } catch (PersonaDadaDeBaja) {
            // Un número que fue de otra persona no se le quita sin que el negocio lo decida.
            $this->error('celular', 'Ese celular es de una ficha dada de baja: reactívala o quita el celular de esta fila.');
        }
        if ($datos['cliente_ref'] !== '') {
            $persona->forceFill(['referencia_externa' => $datos['cliente_ref']])->save();
        }

        return [$persona, ! $reactivado];
    }

    /**
     * ¿Ya se importó o ya estaba agendada (R14)? Idéntica (misma persona, servicio,
     * hora, sucursal y profesional) → no se duplica. La misma cita (por su referencia,
     * o la persona y el servicio a esa hora) con otros datos es un cambio que se decide
     * en la agenda: la fila no se importa y se dice qué difiere.
     */
    private function yaImportada(string $referencia, PersonaTenant $persona, OfertaTenant $oferta, SucursalTenant $sucursal, Usuario $profesional, CarbonImmutable $inicia): bool
    {
        $activas = [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value];
        $existente = $referencia !== ''
            ? ReservaTenant::query()->where('referencia_externa', $referencia)->whereIn('estado', $activas)->with('sesion.instructor', 'sesion.sucursal')->first()
            : null;
        $existente ??= ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', $activas)
            ->whereHas('sesion', fn ($q) => $q->where('oferta_id', $oferta->getKey())->where('inicia_en', $inicia))
            ->with('sesion.instructor', 'sesion.sucursal')
            ->first();
        if (! $existente instanceof ReservaTenant || $existente->sesion === null) {
            return false;
        }
        $sesion = $existente->sesion;
        $identica = (int) $existente->persona_id === (int) $persona->getKey()
            && (int) $sesion->oferta_id === (int) $oferta->getKey()
            && $sesion->inicia_en->equalTo($inicia)
            && (int) $sesion->sucursal_id === (int) $sucursal->getKey()
            && (int) $sesion->instructor_id === (int) $profesional->getKey();
        if (! $identica) {
            $this->error('profesional', 'Esta cita ya está agendada con otros datos ('.($sesion->instructor->name ?? '—').' en '.($sesion->sucursal->nombre ?? '—').', '
                .$sesion->inicia_en->setTimezone((string) ($sesion->zona_horaria ?: 'America/Mexico_City'))->format('Y-m-d H:i').'): cámbiala en la agenda o quita la fila.');
        }

        return true;
    }

    /**
     * Importar no avisa: los eventos de esta reserva quedan publicados sin consumidores
     * (ni correos, ni webhooks). Los recordatorios, solo si se pidieron.
     */
    private function sinAvisos(ReservaTenant $reserva, bool $recordar): void
    {
        EventoOutboxTenant::query()
            ->where('agregado_id', (string) $reserva->ulid)
            ->whereNull('publicado_en')
            ->update(['publicado_en' => now(), 'ultimo_error' => 'Importada: sin avisos.']);
        if ($reserva->orden_id !== null) {
            EventoOutboxTenant::query()
                ->where('agregado_id', (string) $reserva->orden?->ulid)
                ->whereNull('publicado_en')
                ->update(['publicado_en' => now(), 'ultimo_error' => 'Importada: sin avisos.']);
        }
        $reserva->forceFill(['sin_avisos' => ! $recordar])->save();
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => [$mensaje]]);
    }
}
