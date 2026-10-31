<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\CanalReserva;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\CupoLleno;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaException;
use App\Modules\Tenancy\Reservas\Exceptions\SinDerechoDisponible;
use App\Modules\Tenancy\Reservas\Exceptions\YaReservado;
use App\Modules\Tenancy\Support\RevisionSinCandados;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Importa las reservas futuras de clases de otro sistema (ADR 0176): quién, qué clase,
 * dónde y cuándo, confirmadas o en lista de espera. Cada reserva entra como cualquier
 * reserva hecha aquí: con su lugar, su crédito apartado y la política de cancelación de
 * hoy, así cancelar a tiempo devuelve el crédito sin pasos aparte.
 *
 * - **Correspondencia:** el alumno por su correo (ya importado); la clase por su sede,
 *   su fecha y hora, y su nombre (el de la clase o el de la actividad, sin importar
 *   mayúsculas ni acentos). Sin sede, la única del negocio.
 * - **Corte:** solo clases desde el día del corte y que aún no empiezan; lo anterior
 *   queda en el otro sistema.
 * - **Sin descontar dos veces:** si los saldos de apertura ya descontaron estas reservas
 *   (`ya_descontadas`), cada reserva confirmada reintegra su crédito como apertura en el
 *   plan que la va a cubrir y lo aparta: el saldo neto no cambia y el libro lo muestra.
 *   Si no, la reserva aparta su crédito del saldo cargado. La lista de espera no
 *   aparta crédito en ningún sistema: no se reintegra.
 * - **Estados por fila:** `valida` (entra), `existente` (ya estaba: no se duplica),
 *   `pendiente` (los datos sirven, pero no hay plan con saldo o la clase está llena: lo
 *   decide el negocio) e `invalida` (los datos no sirven).
 * - **Por partes:** sin `parcial` es todo o nada; con `parcial` entran las válidas y el
 *   archivo corregido se vuelve a subir (lo ya importado queda `existente`).
 * - **Avisos apagados:** importar no le manda nada a nadie (como las citas, ADR 0160).
 * - **Sin detener las reservas (ADR 0230):** la revisión de todo el archivo (la vista
 *   previa y el primer paso de importar) corre sin candados ({@see RevisionSinCandados}) y
 *   lo que pasa la revisión se guarda en lotes chicos, cada uno con sus candados y su
 *   transacción. Sin `parcial`, si entre la revisión y un lote una fila ya no se puede, ese
 *   lote no entra y se dice cuántas ya entraron; volver a subir el archivo no las duplica.
 */
class ImportarReservasTenant
{
    public const COLUMNAS = ['referencia', 'email', 'clase', 'sucursal', 'fecha', 'hora', 'estado', 'posicion'];

    public const OPCIONALES = ['referencia', 'sucursal', 'estado', 'posicion'];

    public const VALIDA = 'valida';

    public const EXISTENTE = 'existente';

    public const PENDIENTE = 'pendiente';

    public const INVALIDA = 'invalida';

    /** Filas por transacción al guardar: lo que una clase espera a lo más. */
    public const FILAS_POR_LOTE = 20;

    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly ResolverDerechoTenant $derechos,
        private readonly LibroMayorTenant $libro,
        private readonly ResolverAccesoTenant $acceso,
        private readonly FechasNegocioTenant $fechas,
        private readonly ParametrosTenant $parametros,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @param  list<array<string, string|int|null>>  $filas
     * @return array{ok: bool, importadas: int, lote: string|null, resumen: array{total: int, validas: int, existentes: int, pendientes: int, invalidas: int, en_espera: int, creditos_reintegrados: int}, filas: list<array<string, mixed>>}
     */
    public function ejecutar(array $filas, Usuario $actor, string $corte, bool $yaDescontadas, bool $guardar = false, bool $parcial = false, bool $recordar = false): array
    {
        $max = $this->parametros->entero('importaciones.max_filas');
        if (count($filas) > $max) {
            throw ValidationException::withMessages(['archivo' => ["Se importan hasta {$max} reservas a la vez."]]);
        }
        $lote = (string) Str::ulid();
        $porFila = $this->ordenar($filas);

        // La revisión de todo el archivo: las mismas reglas, sin candados y sin que se quede nada.
        $revisado = self::recorridoNuevo();
        RevisionSinCandados::correr(function () use ($porFila, $actor, $corte, $yaDescontadas, $recordar, $lote, &$revisado): void {
            $this->recorrer($porFila, $actor, $corte, $yaDescontadas, $recordar, $lote, $revisado);
        });
        $resumen = self::resumen($revisado);
        $ok = $resumen['pendientes'] === 0 && $resumen['invalidas'] === 0;

        if (! $guardar || (! $ok && ! $parcial)) {
            return ['ok' => $ok, 'importadas' => 0, 'lote' => null, 'resumen' => $resumen, 'filas' => self::porNumeroDeFila($revisado['salida'])];
        }

        $guardado = $this->guardarPorLotes($porFila, $actor, $corte, $yaDescontadas, $parcial, $recordar, $lote);
        $resumen = self::resumen($guardado);
        $ok = $resumen['pendientes'] === 0 && $resumen['invalidas'] === 0;

        return ['ok' => $ok, 'importadas' => $resumen['validas'], 'lote' => $lote, 'resumen' => $resumen, 'filas' => self::porNumeroDeFila($guardado['salida'])];
    }

    /**
     * Guarda el archivo ya revisado, un lote chico por transacción. Con `parcial`, la fila
     * que no se puede se queda fuera, como en la revisión. Sin él, una fila que pasó la
     * revisión y ya no se puede (la clase se llenó justo ahora) deshace su lote y se
     * responde cuántas ya quedaron: volver a subirlo no las duplica.
     *
     * @param  array<int, array<string, string>>  $porFila
     * @return array{salida: list<array<string, mixed>>, vistas: array<string, true>, referencias: array<string, true>, reintegradas: int}
     */
    private function guardarPorLotes(array $porFila, Usuario $actor, string $corte, bool $yaDescontadas, bool $parcial, bool $recordar, string $lote): array
    {
        $db = DB::connection('tenant');
        $nivel = $db->transactionLevel();
        $recorrido = self::recorridoNuevo();
        $confirmado = $recorrido;
        try {
            foreach (array_chunk($porFila, self::FILAS_POR_LOTE, true) as $filasDelLote) {
                $db->transaction(function () use ($filasDelLote, $actor, $corte, $yaDescontadas, $parcial, $recordar, $lote, &$recorrido): void {
                    $yaVistas = count($recorrido['salida']);
                    $this->recorrer($filasDelLote, $actor, $corte, $yaDescontadas, $recordar, $lote, $recorrido);
                    if ($parcial) {
                        return;
                    }
                    foreach (array_slice($recorrido['salida'], $yaVistas) as $fila) {
                        if ($fila['errores'] !== []) {
                            throw new FilaYaNoSePuede((int) $fila['fila'], (string) $fila['errores'][0]);
                        }
                    }
                });
                $confirmado = $recorrido;
            }
        } catch (FilaYaNoSePuede $e) {
            $entraron = self::resumen($confirmado)['validas'];
            $this->anotarImportacion($actor, $lote, $confirmado, $corte, $yaDescontadas, $parcial, $recordar);

            throw ValidationException::withMessages(['archivo' => [
                "Algo cambió mientras se importaba (fila {$e->fila}: {$e->getMessage()}). Ya entraron {$entraron} reservas: vuelve a revisar el archivo; las que ya entraron no se duplican.",
            ]]);
        } catch (\Throwable $e) {
            // Nada del lote queda abierto (una fila pudo cortarse a la mitad).
            while ($db->transactionLevel() > $nivel) {
                $db->rollBack();
            }

            throw $e;
        }
        $this->anotarImportacion($actor, $lote, $recorrido, $corte, $yaDescontadas, $parcial, $recordar);

        return $recorrido;
    }

    /**
     * @param  array{salida: list<array<string, mixed>>, vistas: array<string, true>, referencias: array<string, true>, reintegradas: int}  $recorrido
     */
    private function anotarImportacion(Usuario $actor, string $lote, array $recorrido, string $corte, bool $yaDescontadas, bool $parcial, bool $recordar): void
    {
        $this->auditoria->registrar($actor, 'importacion.reservas', 'importacion', $lote, null, [
            'resumen' => self::resumen($recorrido), 'corte' => $corte, 'ya_descontadas' => $yaDescontadas, 'parcial' => $parcial, 'recordatorios' => $recordar,
        ]);
    }

    /**
     * Lo que se lleva de una fila a la siguiente al recorrer un archivo.
     *
     * @return array{salida: list<array<string, mixed>>, vistas: array<string, true>, referencias: array<string, true>, reintegradas: int}
     */
    private static function recorridoNuevo(): array
    {
        return ['salida' => [], 'vistas' => [], 'referencias' => [], 'reintegradas' => 0];
    }

    /**
     * @param  array{salida: list<array<string, mixed>>, vistas: array<string, true>, referencias: array<string, true>, reintegradas: int}  $recorrido
     * @return array{total: int, validas: int, existentes: int, pendientes: int, invalidas: int, en_espera: int, creditos_reintegrados: int}
     */
    private static function resumen(array $recorrido): array
    {
        $salida = $recorrido['salida'];
        $cuenta = static fn (string $estado): int => count(array_filter($salida, static fn (array $f): bool => $f['estado'] === $estado));

        return [
            'total' => count($salida),
            'validas' => $cuenta(self::VALIDA),
            'existentes' => $cuenta(self::EXISTENTE),
            'pendientes' => $cuenta(self::PENDIENTE),
            'invalidas' => $cuenta(self::INVALIDA),
            'en_espera' => count(array_filter($salida, static fn (array $f): bool => $f['queda'] === EstadoReserva::EnEspera->value)),
            'creditos_reintegrados' => $recorrido['reintegradas'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $salida
     * @return list<array<string, mixed>>
     */
    private static function porNumeroDeFila(array $salida): array
    {
        usort($salida, static fn (array $a, array $b): int => $a['fila'] <=> $b['fila']);

        return $salida;
    }

    /**
     * Aplica cada fila con las reglas de reservar, cada una con su punto de regreso: la que
     * no se puede se deshace y dice por qué. Debe correr dentro de una transacción.
     *
     * @param  array<int, array<string, string>>  $porFila
     * @param  array{salida: list<array<string, mixed>>, vistas: array<string, true>, referencias: array<string, true>, reintegradas: int}  $recorrido
     */
    private function recorrer(array $porFila, Usuario $actor, string $corte, bool $yaDescontadas, bool $recordar, string $lote, array &$recorrido): void
    {
        $db = DB::connection('tenant');
        $sucursales = SucursalTenant::query()->get();
        foreach ($porFila as $numero => $datos) {
            $resultado = ['fila' => $numero, 'datos' => $datos, 'estado' => self::VALIDA, 'errores' => [], 'avisos' => [], 'alumno' => null, 'clase' => null, 'inicia_en' => null, 'queda' => null];
            $db->beginTransaction();
            try {
                $this->validar($datos);
                $persona = PersonaTenant::query()
                    ->whereRaw('lower(email) = ?', [mb_strtolower($datos['email'])])
                    ->where('tipo', TipoPersonaTenant::Miembro->value)
                    ->where('archivado', false)
                    ->first() ?? $this->error('email', 'No hay un alumno con ese correo: impórtalo primero.');
                $sucursal = $this->sucursal($sucursales, $datos['sucursal'], $actor);
                $zona = $sucursal->zona_horaria !== '' ? $sucursal->zona_horaria : $this->fechas->zona();
                $inicia = CarbonImmutable::parse("{$datos['fecha']} {$datos['hora']}", $zona)->utc();
                if (! $inicia->isFuture()) {
                    $this->error('fecha', 'Ya pasó: solo se importan reservas de clases que aún no empiezan.');
                }
                if ($inicia->lessThan(CarbonImmutable::parse("{$corte} 00:00:00", $zona)->utc())) {
                    $this->error('fecha', "Es antes del corte ({$corte}): queda en el otro sistema.");
                }
                $sesion = $this->sesion($sucursal, $inicia, $datos['clase']);
                $resultado['alumno'] = $persona->nombreCompleto();
                $resultado['clase'] = $sesion->oferta->nombre ?? $datos['clase'];
                $resultado['inicia_en'] = $inicia->toIso8601String();

                $clave = (string) $persona->getKey().'|'.(string) $sesion->getKey();
                if (isset($recorrido['vistas'][$clave])) {
                    $this->error('clase', 'Esta reserva está repetida en el archivo.');
                }
                $recorrido['vistas'][$clave] = true;
                if ($datos['referencia'] !== '') {
                    if (isset($recorrido['referencias'][$datos['referencia']])) {
                        $this->error('referencia', 'Esa referencia está repetida en el archivo.');
                    }
                    $recorrido['referencias'][$datos['referencia']] = true;
                }

                $idempotencia = 'importacion:'.hash('sha256', $datos['referencia'] !== ''
                    ? 'referencia|'.$datos['referencia']
                    : (string) $persona->ulid.'|'.(string) $sesion->ulid);
                if ($this->yaEsta($idempotencia, $persona, $sesion)) {
                    $resultado['estado'] = self::EXISTENTE;
                    $resultado['avisos'][] = 'Ya estaba: no se duplica.';
                    $db->commit();
                    $recorrido['salida'][] = $resultado;

                    continue;
                }

                $enEspera = $datos['estado'] === 'en_espera';
                $reintegro = $yaDescontadas && ! $enEspera
                    ? $this->reintegrar($persona, $sesion, $corte, $numero, $lote, $actor)
                    : 0;
                $reserva = $this->reservas->crear($sesion, $persona, $idempotencia, $enEspera, null, CanalReserva::Directo->value);
                if ($datos['referencia'] !== '') {
                    $reserva->forceFill(['referencia_externa' => $datos['referencia']])->save();
                }
                $this->sinAvisos($reserva, $recordar);
                $recorrido['reintegradas'] += $reintegro;
                if ($reintegro > 0) {
                    $resultado['avisos'][] = 'Su crédito ya se había descontado en el otro sistema: se reintegra y se aparta para esta clase.';
                }
                $resultado['queda'] = $reserva->estado->value;
                if ($enEspera && $reserva->estado === EstadoReserva::Confirmada) {
                    $resultado['avisos'][] = 'Había lugar: queda confirmada y aparta su crédito.';
                }
                $db->commit();
            } catch (ValidationException $e) {
                $db->rollBack();
                $resultado['estado'] = self::INVALIDA;
                $resultado['errores'] = array_values(array_merge(...array_values($e->errors())));
            } catch (YaReservado) {
                $db->rollBack();
                $resultado['estado'] = self::EXISTENTE;
                $resultado['avisos'][] = 'Ya tenía esta clase reservada: no se duplica.';
            } catch (SinDerechoDisponible $e) {
                $db->rollBack();
                $resultado['estado'] = self::PENDIENTE;
                $resultado['errores'][] = $e->getMessage().' Carga su saldo de apertura o véndele un plan y vuelve a subir el archivo.';
            } catch (CupoLleno) {
                $db->rollBack();
                $resultado['estado'] = self::PENDIENTE;
                $resultado['errores'][] = 'La clase ya está llena aquí: abre más lugares o súbela como «en_espera».';
            } catch (ReservaException $e) {
                $db->rollBack();
                $resultado['estado'] = self::INVALIDA;
                $resultado['errores'][] = $e->getMessage();
            }
            $recorrido['salida'][] = $resultado;
        }
    }

    /**
     * Las confirmadas primero (en el orden del archivo) y después la lista de espera por
     * su posición: así cada quien queda en su lugar de la fila.
     *
     * @param  list<array<string, string|int|null>>  $filas
     * @return array<int, array<string, string>> número de fila => datos
     */
    private function ordenar(array $filas): array
    {
        $normalizadas = [];
        foreach ($filas as $indice => $cruda) {
            $numero = (int) ($cruda['_fila'] ?? $indice + 2);
            unset($cruda['_fila']);
            $datos = array_map(static fn ($v): string => trim((string) $v), $cruda) + array_fill_keys(self::COLUMNAS, '');
            $datos['estado'] = $datos['estado'] === '' ? 'confirmada' : mb_strtolower(str_replace([' ', '-'], '_', $datos['estado']));
            $normalizadas[$numero] = $datos;
        }
        uksort($normalizadas, static function (int $a, int $b) use ($normalizadas): int {
            $espera = static fn (array $d): int => $d['estado'] === 'en_espera' ? 1 : 0;
            $posicion = static fn (array $d): int => ctype_digit($d['posicion']) ? (int) $d['posicion'] : PHP_INT_MAX;

            return [$espera($normalizadas[$a]), $posicion($normalizadas[$a]), $a] <=> [$espera($normalizadas[$b]), $posicion($normalizadas[$b]), $b];
        });

        return $normalizadas;
    }

    /**
     * @param  array<string, string>  $datos
     */
    private function validar(array $datos): void
    {
        Validator::make($datos, [
            'email' => ['required', 'email'],
            'clase' => ['required', 'string', 'max:160'],
            'sucursal' => ['nullable', 'string', 'max:160'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'estado' => ['required', 'in:confirmada,en_espera'],
            'posicion' => ['nullable', 'integer', 'min:1'],
            'referencia' => ['nullable', 'string', 'max:120'],
        ], [
            'email.required' => 'Falta el correo del alumno.',
            'email.email' => 'El correo no es válido.',
            'clase.required' => 'Falta la clase.',
            'fecha.date_format' => 'Escribe la fecha como AAAA-MM-DD.',
            'hora.date_format' => 'Escribe la hora como HH:MM (24 horas).',
            'estado.in' => 'El estado es «confirmada» o «en_espera».',
            'posicion.integer' => 'La posición en la lista de espera es un número.',
        ])->validate();
    }

    /**
     * La sede por su nombre (sin mayúsculas ni acentos) entre las que el actor ve; sin
     * nombre, la única del negocio.
     *
     * @param  Collection<int, SucursalTenant>  $sucursales
     */
    private function sucursal(Collection $sucursales, string $nombre, Usuario $actor): SucursalTenant
    {
        if ($nombre === '') {
            if ($sucursales->count() !== 1) {
                $this->error('sucursal', 'Indica la sucursal: el negocio tiene varias.');
            }
            $sede = $sucursales->first();
        } else {
            $sede = $sucursales->first(fn (SucursalTenant $s): bool => self::clave((string) $s->nombre) === self::clave($nombre))
                ?? $this->error('sucursal', "No hay una sucursal «{$nombre}».");
        }
        if (! $sede instanceof SucursalTenant || ! $this->acceso->permiteSucursal($actor, (int) $sede->getKey())) {
            $this->error('sucursal', 'No tienes acceso a esta sucursal.');
        }

        return $sede;
    }

    /** La clase de esa sede a esa hora con ese nombre (el de la clase o su actividad). */
    private function sesion(SucursalTenant $sucursal, CarbonImmutable $inicia, string $nombre): SesionTenant
    {
        $buscada = self::clave($nombre);
        $sesiones = SesionTenant::query()
            ->where('sucursal_id', $sucursal->getKey())
            ->where('inicia_en', $inicia)
            ->with('oferta.actividad')
            ->get()
            ->filter(static fn (SesionTenant $s): bool => self::clave((string) $s->oferta?->nombre) === $buscada
                || self::clave((string) $s->oferta?->actividad?->nombre) === $buscada);
        if ($sesiones->isEmpty()) {
            $this->error('clase', "No hay una clase «{$nombre}» en {$sucursal->nombre} ese día a esa hora: importa primero el horario o revisa el nombre.");
        }
        if ($sesiones->count() > 1) {
            $this->error('clase', "Hay varias clases «{$nombre}» a esa hora en {$sucursal->nombre}: usa el nombre de la clase, no el de la actividad.");
        }

        return $sesiones->first();
    }

    /** ¿Ya se importó (misma referencia o misma persona y clase) o ya la tiene reservada? */
    private function yaEsta(string $idempotencia, PersonaTenant $persona, SesionTenant $sesion): bool
    {
        return ReservaTenant::query()->where('idempotency_key', $idempotencia)->exists()
            || ReservaTenant::query()
                ->where('sesion_id', $sesion->getKey())
                ->where('persona_id', $persona->getKey())
                ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value])
                ->exists();
    }

    /**
     * Los saldos de apertura ya descontaron esta reserva: su crédito vuelve como apertura
     * al plan que la va a cubrir, para que al apartarlo el saldo neto no cambie. Es el
     * plan que se usaría al reservar; si ninguno tiene saldo, el primero que la cubriría.
     * Uno ilimitado no gasta créditos: no se reintegra nada. Unidades reintegradas.
     */
    private function reintegrar(PersonaTenant $persona, SesionTenant $sesion, string $corte, int $fila, string $lote, Usuario $actor): int
    {
        $unidades = ReservasTenant::UNIDADES_POR_SESION;
        $elegido = $this->derechos->paraSesion($persona, $sesion, $unidades);
        $destino = $elegido !== null
            ? ($elegido->ilimitado ? null : $elegido)
            : $this->derechos->paraReintegro($persona, $sesion);
        if ($destino === null) {
            return 0;
        }
        $this->libro->registrar(
            $destino,
            TipoMovimiento::Concesion,
            $unidades,
            "Reserva importada: su crédito ya se había descontado en el otro sistema (corte {$corte})",
            ContextoMovimiento::para(OrigenMovimiento::Apertura, 'importacion', $lote, $actor, [
                'corte' => $corte,
                'fila' => $fila,
                'sesion' => (string) $sesion->ulid,
            ]),
        );

        return $unidades;
    }

    /**
     * Importar no avisa: los eventos de esta reserva quedan publicados sin consumidores
     * (ni correos, ni webhooks). Los recordatorios antes de la clase son aparte: solo
     * si se pidieron al importar.
     */
    private function sinAvisos(ReservaTenant $reserva, bool $recordar): void
    {
        EventoOutboxTenant::query()
            ->where('agregado_id', (string) $reserva->ulid)
            ->whereNull('publicado_en')
            ->update(['publicado_en' => now(), 'ultimo_error' => 'Importada: sin avisos.']);
        $reserva->forceFill(['sin_avisos' => ! $recordar])->save();
    }

    private static function clave(string $nombre): string
    {
        return (string) preg_replace('/\s+/', ' ', mb_strtolower(trim(Str::ascii($nombre))));
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => [$mensaje]]);
    }
}
