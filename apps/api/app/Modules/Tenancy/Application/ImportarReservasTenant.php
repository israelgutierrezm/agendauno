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
 */
class ImportarReservasTenant
{
    public const COLUMNAS = ['referencia', 'email', 'clase', 'sucursal', 'fecha', 'hora', 'estado', 'posicion'];

    public const OPCIONALES = ['referencia', 'sucursal', 'estado', 'posicion'];

    public const VALIDA = 'valida';

    public const EXISTENTE = 'existente';

    public const PENDIENTE = 'pendiente';

    public const INVALIDA = 'invalida';

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
    public function ejecutar(array $filas, Usuario $actor, string $corte, bool $yaDescontadas, bool $guardar = false, bool $parcial = false): array
    {
        $max = $this->parametros->entero('importaciones.max_filas');
        if (count($filas) > $max) {
            throw ValidationException::withMessages(['archivo' => ["Se importan hasta {$max} reservas a la vez."]]);
        }
        $db = DB::connection('tenant');
        $db->beginTransaction();
        try {
            $lote = (string) Str::ulid();
            $sucursales = SucursalTenant::query()->get();
            $vistas = [];
            $referencias = [];
            $reintegradas = 0;
            $salida = [];
            foreach ($this->ordenar($filas) as $numero => $datos) {
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
                    if (isset($vistas[$clave])) {
                        $this->error('clase', 'Esta reserva está repetida en el archivo.');
                    }
                    $vistas[$clave] = true;
                    if ($datos['referencia'] !== '') {
                        if (isset($referencias[$datos['referencia']])) {
                            $this->error('referencia', 'Esa referencia está repetida en el archivo.');
                        }
                        $referencias[$datos['referencia']] = true;
                    }

                    $idempotencia = 'importacion:'.hash('sha256', $datos['referencia'] !== ''
                        ? 'referencia|'.$datos['referencia']
                        : (string) $persona->ulid.'|'.(string) $sesion->ulid);
                    if ($this->yaEsta($idempotencia, $persona, $sesion)) {
                        $resultado['estado'] = self::EXISTENTE;
                        $resultado['avisos'][] = 'Ya estaba: no se duplica.';
                        $db->commit();
                        $salida[] = $resultado;

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
                    $this->sinAvisos($reserva);
                    $reintegradas += $reintegro;
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
                $salida[] = $resultado;
            }
            usort($salida, static fn (array $a, array $b): int => $a['fila'] <=> $b['fila']);

            $cuenta = static fn (string $estado): int => count(array_filter($salida, static fn (array $f): bool => $f['estado'] === $estado));
            $resumen = [
                'total' => count($salida),
                'validas' => $cuenta(self::VALIDA),
                'existentes' => $cuenta(self::EXISTENTE),
                'pendientes' => $cuenta(self::PENDIENTE),
                'invalidas' => $cuenta(self::INVALIDA),
                'en_espera' => count(array_filter($salida, static fn (array $f): bool => $f['queda'] === EstadoReserva::EnEspera->value)),
                'creditos_reintegrados' => $reintegradas,
            ];
            $ok = $resumen['pendientes'] === 0 && $resumen['invalidas'] === 0;

            if (! $guardar || (! $ok && ! $parcial)) {
                $db->rollBack();

                return ['ok' => $ok, 'importadas' => 0, 'lote' => null, 'resumen' => $resumen, 'filas' => $salida];
            }

            $this->auditoria->registrar($actor, 'importacion.reservas', 'importacion', $lote, null, [
                'resumen' => $resumen, 'corte' => $corte, 'ya_descontadas' => $yaDescontadas, 'parcial' => $parcial,
            ]);
            $db->commit();

            return ['ok' => $ok, 'importadas' => $resumen['validas'], 'lote' => $lote, 'resumen' => $resumen, 'filas' => $salida];
        } catch (\Throwable $e) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }

            throw $e;
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
     * (ni correos, ni webhooks) y no lleva recordatorios.
     */
    private function sinAvisos(ReservaTenant $reserva): void
    {
        EventoOutboxTenant::query()
            ->where('agregado_id', (string) $reserva->ulid)
            ->whereNull('publicado_en')
            ->update(['publicado_en' => now(), 'ultimo_error' => 'Importada: sin avisos.']);
        $reserva->forceFill(['sin_avisos' => true])->save();
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
