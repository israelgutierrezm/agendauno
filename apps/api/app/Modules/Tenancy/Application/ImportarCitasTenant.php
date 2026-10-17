<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\PersonaDadaDeBaja;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
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
 * - **Clientes:** se reconocen por su correo o su celular; si no, se crea su ficha.
 * - **Conflictos:** las mismas reglas que al agendar en recepción (el profesional no
 *   atiende dos a la vez, cabina libre). Una cita igual ya agendada no se duplica.
 * - **Avisos apagados:** importar no le manda nada a nadie (sus eventos quedan
 *   publicados sin consumidores). Los recordatorios antes de la cita, solo si se pide.
 */
class ImportarCitasTenant
{
    /** Columnas de la plantilla (las de `OPCIONALES` pueden faltar). */
    public const COLUMNAS = ['referencia', 'cliente', 'email', 'celular', 'servicio', 'profesional', 'sucursal', 'fecha', 'hora'];

    public const OPCIONALES = ['referencia', 'email', 'celular'];

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
     * @return array{ok: bool, creados: int, lote: string|null, resumen: array{total: int, validas: int, invalidas: int, omitidas: int, clientes_nuevos: int}, faltantes: array<string, list<string>>, filas: list<array<string, mixed>>}
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
            $nuevos = 0;
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
                    $clave = mb_strtolower(($datos['email'] ?: $datos['celular'] ?: $datos['cliente']).'|'.$inicia->toIso8601String());
                    if (isset($vistas[$clave])) {
                        $this->error('fecha', 'Esta cita está repetida en el archivo.');
                    }
                    $vistas[$clave] = true;

                    [$persona, $esNueva] = $this->cliente($datos, $actor, (int) $sucursal->getKey());
                    $resultado['cliente'] = ['nombre' => $persona->nombreCompleto(), 'nueva' => $esNueva];
                    $resultado['inicia_en'] = $inicia->toIso8601String();
                    if ($this->yaAgendada($persona, $oferta, $inicia)) {
                        $resultado['omitida'] = true;
                        $resultado['avisos'][] = 'Ya estaba agendada: no se duplica.';
                    } else {
                        $reserva = $this->agendar->agendar($oferta, $sucursal, $persona, (int) $profesional->getKey(), $inicia, 0, true);
                        $this->sinAvisos($reserva, $recordar);
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
            $resumen = ['total' => count($salida), 'validas' => count($salida) - $invalidas, 'invalidas' => $invalidas, 'omitidas' => $omitidas, 'clientes_nuevos' => $nuevos];
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
            'email' => ['nullable', 'email', 'max:255'],
            'celular' => ['nullable', 'string', 'max:30'],
            'servicio' => ['required', 'string', 'max:255'],
            'profesional' => ['required', 'string', 'max:255'],
            'sucursal' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
        ], [
            'fecha.date_format' => 'La fecha va como AAAA-MM-DD.',
            'hora.date_format' => 'La hora va como HH:MM (24 horas).',
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
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
     * Su ficha: por correo, por celular o una nueva.
     *
     * @param  array<string, string>  $datos
     * @return array{0: PersonaTenant, 1: bool}
     */
    private function cliente(array $datos, Usuario $actor, int $sucursalId): array
    {
        if ($datos['email'] !== '') {
            $porCorreo = PersonaTenant::query()->where('email', $datos['email'])->first();
            if ($porCorreo instanceof PersonaTenant) {
                return [$porCorreo, false];
            }
        }
        if ($datos['celular'] !== '') {
            $porCelular = $this->celulares->buscar(PersonaTenant::query(), $datos['celular']);
            if ($porCelular instanceof PersonaTenant) {
                return [$porCelular, false];
            }
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

        return [$persona, ! $reactivado];
    }

    private function yaAgendada(PersonaTenant $persona, OfertaTenant $oferta, CarbonImmutable $inicia): bool
    {
        return ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value])
            ->whereHas('sesion', fn ($q) => $q->where('oferta_id', $oferta->getKey())->where('inicia_en', $inicia))
            ->exists();
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
