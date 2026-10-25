<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Membresias\Aniversario;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\PoliticaRollover;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Membresias\VigenciaProducto;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saldos de apertura al migrar de otro sistema (plan de mejoras C07): el plan que
 * cada alumno ya traía, con su saldo y su vigencia AL CORTE acordado. No inventa una
 * venta ni un pago: crea el plan (`origen` = apertura) y un asiento de concesión con
 * origen `apertura` en el libro de créditos, con el corte y la evidencia.
 *
 * Columnas: `email` y `plan` (obligatorias), `creditos` (lo que le queda; obligatorio
 * si el plan no es ilimitado; admite medios: 2.5), `vence` (último día que vale; no
 * antes del corte) e `inicio` (opcional; por omisión, el corte). Cada fila queda
 * `valida`, `invalida` (no se puede cargar), `existente` (ya se cargó con ese corte:
 * repetir el archivo no la duplica) o `conflicto` (repetida en el archivo, o la
 * persona ya tiene ese plan vigente aquí: decídelo antes para no duplicar su saldo).
 */
class CargarSaldosAperturaTenant
{
    public const VALIDA = 'valida';

    public const INVALIDA = 'invalida';

    public const EXISTENTE = 'existente';

    public const CONFLICTO = 'conflicto';

    private const UNIDADES_POR_CREDITO = 1000;

    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly AlcancePersonaTenant $alcance,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * Topes de una migración real (parámetros de la plataforma, ADR 0042 y 0207): el
     * corte es de hoy o de hasta un año atrás, y lo que se carga vence a más tardar dos
     * años después del corte (un plan anual con margen), por omisión. Lo de más no es un
     * saldo que se traiga de otro sistema: es regalar un plan.
     */
    public function diasMaximosCorte(): int
    {
        return $this->parametros->entero('importaciones.dias_maximos_corte_saldos');
    }

    public function mesesMaximosVigencia(): int
    {
        return $this->parametros->entero('importaciones.meses_maximos_vigencia_saldos');
    }

    /**
     * Con `$actor` acotado por sucursal (R19, ADR 0098), quien no es de sus sedes se trata
     * como si no existiera: ni se le carga nada ni se revela que su correo está aquí.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{resumen: array{total: int, validas: int, invalidas: int, rechazadas: int, conflictos: int, existentes: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>, estado: string}>}
     */
    public function analizar(array $filas, string $corte, ?Usuario $actor = null): array
    {
        $meses = $this->mesesMaximosVigencia();
        $venceMaximo = CarbonImmutable::parse($corte)->addMonthsNoOverflow($meses)->toDateString();
        $productos = ProductoTenant::query()->get()
            ->groupBy(static fn (ProductoTenant $p): string => self::clave((string) $p->nombre));
        $vistos = [];
        $salida = [];

        foreach ($filas as $indice => $cruda) {
            $datos = $this->normalizar($cruda, $corte);
            $errores = [];
            $estado = self::VALIDA;

            $persona = $datos['email'] === '' ? null : PersonaTenant::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower($datos['email'])])
                ->where('tipo', TipoPersonaTenant::Miembro->value)
                ->first();
            if ($persona instanceof PersonaTenant && ! $this->alcance->puedeVer($actor, $persona)) {
                $persona = null;
            }
            $candidatos = $productos->get(self::clave($datos['plan']));
            $producto = $candidatos !== null && $candidatos->count() === 1 ? $candidatos->first() : null;

            if ($datos['email'] === '') {
                $errores[] = 'Falta el correo del alumno.';
            } elseif (! $persona instanceof PersonaTenant) {
                $errores[] = "No hay un alumno con el correo {$datos['email']}: impórtalo primero en Miembros.";
            }
            if ($datos['plan'] === '') {
                $errores[] = 'Falta el nombre del plan.';
            } elseif ($candidatos === null) {
                $errores[] = "No hay un plan llamado «{$datos['plan']}»: créalo antes con el mismo nombre.";
            } elseif ($candidatos->count() > 1) {
                $errores[] = "Hay más de un plan llamado «{$datos['plan']}»: cámbiale el nombre a uno.";
            } elseif ($producto instanceof ProductoTenant && $producto->tipo === TipoProducto::AddOn) {
                $errores[] = "«{$datos['plan']}» son clases extra: carga el saldo en el plan al que se suman.";
            }
            if ($datos['inicio'] === null) {
                $errores[] = 'Escribe la fecha de inicio como AAAA-MM-DD.';
            } elseif ($datos['inicio'] > $corte) {
                $errores[] = 'El inicio no puede ser después del corte.';
            }
            if ($datos['vence'] === null) {
                $errores[] = 'Escribe la fecha de vencimiento (vence) como AAAA-MM-DD.';
            } elseif ($datos['vence'] < $corte) {
                $errores[] = 'Ya había vencido al corte: no hay saldo que cargar.';
            } elseif ($datos['vence'] > $venceMaximo) {
                $errores[] = 'Vence más de '.$meses." meses después del corte ({$venceMaximo}): revisa la fecha.";
            }
            if ($producto instanceof ProductoTenant && ! $producto->ilimitado) {
                if ($datos['unidades'] === null) {
                    $errores[] = 'Escribe los créditos que le quedan (por ejemplo 4 o 2.5).';
                } elseif ($datos['unidades'] <= 0) {
                    $errores[] = 'Sin créditos no hay saldo que cargar.';
                }
            }

            if ($errores !== []) {
                $estado = self::INVALIDA;
            } elseif ($persona instanceof PersonaTenant && $producto instanceof ProductoTenant) {
                $referencia = self::referencia($corte, (int) $persona->getKey(), (int) $producto->getKey());
                $datos['referencia'] = $referencia;
                if (isset($vistos[$referencia])) {
                    $estado = self::CONFLICTO;
                    $errores[] = 'Este alumno y plan están repetidos en el archivo.';
                } elseif (AcuerdoTenant::query()->where('referencia_apertura', $referencia)->exists()) {
                    $estado = self::EXISTENTE;
                } elseif ($this->tieneVigente($persona, $producto, $corte)) {
                    $estado = self::CONFLICTO;
                    $errores[] = "Ya tiene «{$producto->nombre}» vigente aquí: revísalo para no duplicar su saldo.";
                }
                $vistos[$referencia] = true;
            }

            $salida[] = ['fila' => $indice + 1, 'datos' => $datos, 'errores' => $errores, 'estado' => $estado];
        }

        return ['resumen' => self::resumen($salida), 'filas' => $salida];
    }

    /**
     * Carga las filas válidas. Sin `$parcial`, todo o nada: con una fila inválida o en
     * conflicto no carga ninguna. Las ya cargadas con ese corte se omiten.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{ok: bool, resumen: array{total: int, validas: int, invalidas: int, rechazadas: int, conflictos: int, existentes: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>, estado: string}>, cargados?: int}
     */
    public function importar(array $filas, string $corte, string $evidencia, ?Usuario $actor, bool $parcial = false): array
    {
        $analisis = $this->analizar($filas, $corte, $actor);
        if ($analisis['resumen']['total'] === 0 || (! $parcial && $analisis['resumen']['invalidas'] > 0)) {
            return ['ok' => false] + $analisis;
        }

        $cargados = DB::connection('tenant')->transaction(function () use ($analisis, $corte, $evidencia, $actor): int {
            $n = 0;
            foreach ($analisis['filas'] as $fila) {
                if ($fila['estado'] !== self::VALIDA) {
                    continue;
                }
                $this->cargar($fila['datos'], $fila['fila'], $corte, $evidencia, $actor);
                $n++;
            }

            return $n;
        });

        return ['ok' => true, 'cargados' => $cargados] + $analisis;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function cargar(array $datos, int $fila, string $corte, string $evidencia, ?Usuario $actor): void
    {
        // La misma ficha que encontró el análisis: la del alumno, no otra con el mismo
        // correo (p. ej. alguien del equipo).
        $persona = PersonaTenant::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower((string) $datos['email'])])
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->firstOrFail();
        $producto = ProductoTenant::query()->get()
            ->first(static fn (ProductoTenant $p): bool => self::clave((string) $p->nombre) === self::clave((string) $datos['plan']));
        if (! $producto instanceof ProductoTenant) {
            return;
        }

        $inicio = (string) $datos['inicio'];
        $vence = (string) $datos['vence'];
        $reset = $producto->politica_reset ?? PoliticaReset::Ninguno;
        // En citas no se renueva: vale hasta `vence` (ADR 0130).
        $recurrente = $reset !== PoliticaReset::Ninguno && ! app(MembresiasPorModalidadTenant::class)->enCitas();
        $siguiente = CarbonImmutable::parse($vence)->addDay()->toDateString();

        // Recurrente: el ciclo en curso termina en `vence` y el siguiente se cobra al día
        // siguiente. Pack o pase: vale hasta `vence`.
        $acuerdo = AcuerdoTenant::query()->create([
            'persona_id' => $persona->getKey(),
            'producto_comercial_id' => $producto->getKey(),
            'fecha_inicio' => $inicio,
            'proxima_cobro_en' => $recurrente ? $siguiente : null,
            'dia_ancla' => $recurrente ? ($reset === PoliticaReset::Calendario ? 1 : Aniversario::diaDe($siguiente)) : null,
            'estado' => EstadoAcuerdo::Activo->value,
            'origen' => AcuerdoTenant::ORIGEN_APERTURA,
            'referencia_apertura' => $datos['referencia'],
        ]);

        $derecho = $acuerdo->derechos()->create([
            'ambito' => 'general',
            'actividad_id' => $producto->actividad_id,
            'sucursal_id' => $producto->sucursal_id,
            'sucursales_ids' => $producto->sucursales_ids,
            'ilimitado' => $producto->ilimitado,
            'politica_reset' => $reset->value,
            'unidades_por_ciclo' => $producto->unidades_por_ciclo,
            'politica_rollover' => ($producto->politica_rollover ?? PoliticaRollover::Ninguno)->value,
            'rollover_max' => $producto->rollover_max,
            'ciclo_inicio' => $recurrente ? $inicio : null,
            'ciclo_fin' => $recurrente ? $vence : null,
            'valido_desde' => $inicio,
            'valido_hasta' => $recurrente ? VigenciaProducto::de($producto)?->hasta($inicio) : $vence,
        ]);
        $derecho->ofertas()->sync($producto->ofertas()->pluck('ofertas.id')->all());

        if (! $producto->ilimitado && is_int($datos['unidades']) && $datos['unidades'] > 0) {
            $this->libro->registrar(
                $derecho,
                TipoMovimiento::Concesion,
                $datos['unidades'],
                "Saldo de apertura al {$corte}",
                ContextoMovimiento::para(OrigenMovimiento::Apertura, 'acuerdo', (string) $acuerdo->ulid, $actor, [
                    'corte' => $corte,
                    'evidencia' => $evidencia,
                    'fila' => $fila,
                ]),
            );
        }
    }

    /** ¿Ya tiene ese plan vigente aquí al corte (vendido o cargado con otro corte)? */
    private function tieneVigente(PersonaTenant $persona, ProductoTenant $producto, string $corte): bool
    {
        return AcuerdoTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('producto_comercial_id', $producto->getKey())
            ->where('estado', EstadoAcuerdo::Activo->value)
            ->whereHas('derechos', fn ($q) => $q->where(fn ($v) => $v->whereNull('valido_hasta')->orWhere('valido_hasta', '>=', $corte)))
            ->exists();
    }

    /**
     * @param  array<string, string>  $cruda
     * @return array{email: string, plan: string, creditos: string, unidades: int|null, inicio: string|null, vence: string|null, referencia?: string}
     */
    private function normalizar(array $cruda, string $corte): array
    {
        $tomar = static fn (string $clave): string => trim((string) ($cruda[$clave] ?? ''));
        $creditos = str_replace(',', '.', $tomar('creditos'));

        return [
            'email' => $tomar('email'),
            'plan' => $tomar('plan'),
            'creditos' => $creditos,
            // 1 crédito = 1000 unidades; hasta milésimas.
            'unidades' => preg_match('/^-?\d+(\.\d{1,3})?$/', $creditos) === 1 ? (int) round((float) $creditos * self::UNIDADES_POR_CREDITO) : null,
            'inicio' => $tomar('inicio') === '' ? $corte : self::fecha($tomar('inicio')),
            'vence' => self::fecha($tomar('vence')),
        ];
    }

    private static function fecha(string $valor): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $valor);

        return $fecha instanceof CarbonImmutable && $fecha->toDateString() === $valor ? $valor : null;
    }

    /** Nombre comparable: sin mayúsculas, acentos ni espacios de más. */
    private static function clave(string $nombre): string
    {
        return (string) preg_replace('/\s+/', ' ', mb_strtolower(trim(Str::ascii($nombre))));
    }

    private static function referencia(string $corte, int $persona, int $producto): string
    {
        return hash('sha256', "apertura:{$corte}:{$persona}:{$producto}");
    }

    /**
     * @param  list<array{estado: string}>  $filas
     * @return array{total: int, validas: int, invalidas: int, rechazadas: int, conflictos: int, existentes: int}
     */
    private static function resumen(array $filas): array
    {
        $contar = static fn (string $estado): int => count(array_filter($filas, static fn (array $f): bool => $f['estado'] === $estado));
        $rechazadas = $contar(self::INVALIDA);
        $conflictos = $contar(self::CONFLICTO);

        return [
            'total' => count($filas),
            'validas' => $contar(self::VALIDA),
            // Lo que impide cargar todo de una vez.
            'invalidas' => $rechazadas + $conflictos,
            'rechazadas' => $rechazadas,
            'conflictos' => $conflictos,
            'existentes' => $contar(self::EXISTENTE),
        ];
    }
}
