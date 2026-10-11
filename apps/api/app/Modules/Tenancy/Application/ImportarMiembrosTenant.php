<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Http\Requests\DatosPersonales;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Importación masiva de miembros por CSV (R37), tenant-local. Valida fila por fila
 * (preview). Al importar, sin `parcial` es todo-o-nada: si alguna fila es inválida o
 * está en conflicto no escribe nada (rollback). El nombre se importa desglosado
 * (R-personas), con celular, fecha de nacimiento y género opcionales.
 *
 * Migración guiada (C07): cada fila queda `valida`, `invalida` (sus datos no sirven),
 * `existente` (ya está en el negocio con ese correo y ese nombre: repetir el archivo
 * no la duplica) o `conflicto` (el correo o el celular son de otra persona, o vienen
 * repetidos en el archivo). Con `parcial` se cargan solo las válidas y el resto se
 * corrige y se vuelve a subir sin repetir lo ya cargado.
 */
class ImportarMiembrosTenant
{
    public const VALIDA = 'valida';

    public const INVALIDA = 'invalida';

    public const EXISTENTE = 'existente';

    public const CONFLICTO = 'conflicto';

    public function __construct(private readonly CelularesTenant $celulares) {}

    /**
     * Valida las filas sin escribir. Devuelve el resultado por fila + un resumen.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{resumen: array{total: int, validas: int, invalidas: int, rechazadas: int, conflictos: int, existentes: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>, estado: string}>}
     */
    public function analizar(array $filas): array
    {
        // Con los dados de baja: su correo es suyo (se reactivan desde Miembros).
        $existentes = PersonaTenant::withTrashed()
            ->whereNotNull('email')
            ->get(['email', 'nombre', 'primer_apellido', 'deleted_at'])
            ->keyBy(static fn (PersonaTenant $p): string => mb_strtolower((string) $p->email));

        $vistos = [];
        $celularesVistos = [];
        $salida = [];

        foreach ($filas as $indice => $cruda) {
            $datos = $this->normalizar($cruda);
            $errores = $this->validar($datos);
            $estado = $errores === [] ? self::VALIDA : self::INVALIDA;

            $email = is_string($datos['email'] ?? null) ? mb_strtolower($datos['email']) : null;
            if ($email !== null && $estado === self::VALIDA) {
                $previa = $existentes->get($email);
                if ($previa instanceof PersonaTenant) {
                    // La misma persona (mismo correo y nombre, sin baja): ya está; no se repite.
                    if ($previa->deleted_at === null && self::nombre($previa->nombre, $previa->primer_apellido) === self::nombre($datos['nombre'], $datos['primer_apellido'])) {
                        $estado = self::EXISTENTE;
                    } else {
                        $estado = self::CONFLICTO;
                        $errores[] = "Ya existe un miembro con el correo {$email}.";
                    }
                } elseif (isset($vistos[$email])) {
                    $estado = self::CONFLICTO;
                    $errores[] = "El correo {$email} está repetido en el archivo.";
                }
            }
            if ($email !== null) {
                $vistos[$email] = true;
            }

            $celular = $datos['celular'];
            if ($estado === self::VALIDA && is_string($celular)) {
                $numero = (string) preg_replace('/\D/', '', $celular);
                if (isset($celularesVistos[$numero])) {
                    $estado = self::CONFLICTO;
                    $errores[] = "El celular {$celular} está repetido en el archivo.";
                } elseif ($this->celulares->buscar(PersonaTenant::withTrashed(), $celular) !== null) {
                    $estado = self::CONFLICTO;
                    $errores[] = "El celular {$celular} ya es de otra persona del negocio.";
                }
                $celularesVistos[$numero] = true;
            }

            $salida[] = ['fila' => $indice + 1, 'datos' => $datos, 'errores' => $errores, 'estado' => $estado];
        }

        return ['resumen' => self::resumen($salida), 'filas' => $salida];
    }

    /**
     * Importa las filas válidas. Sin `$parcial` es todo-o-nada: si alguna fila es
     * inválida o está en conflicto no escribe nada y devuelve el análisis con
     * `ok=false`. Las que ya estaban se omiten. Crea en una transacción.
     *
     * @param  list<array<string, string>>  $filas
     * @return array{ok: bool, resumen: array{total: int, validas: int, invalidas: int, rechazadas: int, conflictos: int, existentes: int}, filas: list<array{fila: int, datos: array<string, mixed>, errores: list<string>, estado: string}>, creados?: int}
     */
    public function importar(array $filas, bool $parcial = false): array
    {
        $analisis = $this->analizar($filas);

        if ($analisis['resumen']['total'] === 0 || (! $parcial && $analisis['resumen']['invalidas'] > 0)) {
            return ['ok' => false] + $analisis;
        }

        $creados = DB::connection('tenant')->transaction(function () use ($analisis): int {
            $n = 0;
            foreach ($analisis['filas'] as $fila) {
                if ($fila['estado'] !== self::VALIDA) {
                    continue;
                }
                PersonaTenant::query()->create([
                    'nombre' => $fila['datos']['nombre'],
                    'segundo_nombre' => $fila['datos']['segundo_nombre'] ?: null,
                    'primer_apellido' => $fila['datos']['primer_apellido'] ?: null,
                    'segundo_apellido' => $fila['datos']['segundo_apellido'] ?: null,
                    'email' => $fila['datos']['email'] ?: null,
                    'celular' => $fila['datos']['celular'] ?: null,
                    'fecha_nacimiento' => $fila['datos']['fecha_nacimiento'] ?: null,
                    'genero' => $fila['datos']['genero'] ?: null,
                    'tipo' => $fila['datos']['tipo'] ?: TipoPersonaTenant::Miembro->value,
                    'activo' => true,
                    'es_facturable' => $fila['datos']['es_facturable'],
                    'archivado' => false,
                ]);
                $n++;
            }

            return $n;
        });

        return ['ok' => true, 'creados' => $creados] + $analisis;
    }

    /**
     * @param  list<array{estado: string}>  $filas
     * @return array{total: int, validas: int, invalidas: int, rechazadas: int, conflictos: int, existentes: int}
     */
    public static function resumen(array $filas): array
    {
        $contar = static fn (string $estado): int => count(array_filter($filas, static fn (array $f): bool => $f['estado'] === $estado));
        $rechazadas = $contar(self::INVALIDA);
        $conflictos = $contar(self::CONFLICTO);

        return [
            'total' => count($filas),
            'validas' => $contar(self::VALIDA),
            // Lo que impide cargar todo de una vez (datos que no sirven o en conflicto).
            'invalidas' => $rechazadas + $conflictos,
            'rechazadas' => $rechazadas,
            'conflictos' => $conflictos,
            'existentes' => $contar(self::EXISTENTE),
        ];
    }

    /** Nombre comparable: sin mayúsculas, acentos ni espacios de más. */
    public static function nombre(?string ...$partes): string
    {
        $junto = implode(' ', array_filter(array_map(static fn (?string $p): string => trim((string) $p), $partes)));

        return (string) preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($junto)));
    }

    /**
     * @param  array<string, string>  $cruda
     * @return array<string, mixed>
     */
    private function normalizar(array $cruda): array
    {
        $tomar = static fn (string $clave): string => trim((string) ($cruda[$clave] ?? ''));
        $opcional = static fn (string $clave): ?string => ($v = trim((string) ($cruda[$clave] ?? ''))) !== '' ? $v : null;
        $genero = $opcional('genero');

        return [
            'nombre' => $tomar('nombre'),
            'segundo_nombre' => $opcional('segundo_nombre'),
            'primer_apellido' => $opcional('primer_apellido'),
            'segundo_apellido' => $opcional('segundo_apellido'),
            'email' => $opcional('email'),
            'celular' => $opcional('celular'),
            'fecha_nacimiento' => $opcional('fecha_nacimiento'),
            'genero' => $genero !== null ? str_replace(' ', '_', mb_strtolower($genero)) : null,
            'tipo' => $opcional('tipo'),
            'es_facturable' => $this->interpretarBool($tomar('es_facturable')),
        ];
    }

    private function interpretarBool(string $valor): bool
    {
        // Vacío = facturable por defecto; solo valores negativos explícitos lo apagan.
        return ! in_array(mb_strtolower($valor), ['0', 'false', 'no', 'n'], true);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    private function validar(array $datos): array
    {
        $validador = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:255'],
            'segundo_nombre' => ['nullable', 'string', 'max:255'],
            'primer_apellido' => ['nullable', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'celular' => ['nullable', 'string', 'max:40'],
            'tipo' => ['nullable', 'in:miembro,instructor,staff'],
            ...DatosPersonales::reglas(),
        ], DatosPersonales::mensajes(), [
            'nombre' => 'nombre',
            'email' => 'correo',
            'tipo' => 'tipo',
        ]);

        return array_values($validador->errors()->all());
    }
}
