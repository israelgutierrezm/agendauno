<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Captacion\EstadoProspecto;
use App\Modules\Tenancy\Captacion\OrigenProspecto;
use App\Modules\Tenancy\Captacion\TipoEventoProspecto;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\CampoFormularioCaptacionTenant;
use App\Modules\Tenancy\Models\EventoProspectoTenant;
use App\Modules\Tenancy\Models\FormularioCaptacionTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\TipoCampo;
use App\Modules\Tenancy\TipoSesionTenant;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La captación pública del negocio (ADR 0117): quien todavía no es miembro deja sus
 * datos y llega a Prospectos. No crea fichas, cuentas ni reservas.
 *
 * - El negocio la enciende o la apaga (`captacion.activa`); apagada, no hay formulario.
 * - En producción no capta sin su aviso de privacidad publicado, y guarda la versión que
 *   se aceptó (si cambió mientras se llenaba, pide revisarlo).
 * - Un envío repetido con el mismo correo o teléfono de un prospecto abierto lo actualiza
 *   (queda «reenvío» en su historial): no abre cinco prospectos iguales.
 * - Lo que le interesaba (sede, actividad, clase o plan) se guarda si es algo público.
 */
class CaptacionTenant
{
    /** Largo máximo de cada respuesta libre. */
    private const MAX_RESPUESTA = 1000;

    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly WaiversTenant $waivers,
        private readonly RegistrarEventoTenant $eventos,
        private readonly FormulariosCaptacionTenant $formularios,
    ) {}

    public function activa(): bool
    {
        return $this->parametros->siNo('captacion.activa');
    }

    /**
     * El formulario público: el pedido (activo) o, sin ulid, el predeterminado del sitio.
     *
     * @throws NotFoundHttpException si la captación está apagada o el formulario no existe
     */
    public function formularioPublico(?string $ulid): FormularioCaptacionTenant
    {
        if (! $this->activa()) {
            throw new NotFoundHttpException('Este negocio no está recibiendo solicitudes.');
        }
        $formulario = $ulid !== null
            ? FormularioCaptacionTenant::query()->where('ulid', $ulid)->where('activo', true)->first()
            : $this->formularios->predeterminado();
        if (! $formulario instanceof FormularioCaptacionTenant) {
            throw new NotFoundHttpException('Ese formulario no existe.');
        }

        return $formulario->load('campos');
    }

    /**
     * Recibe un envío del formulario.
     *
     * @param  array{nombre: string, email: string, telefono?: string|null, respuestas?: array<string, mixed>|null, interes?: array<string, string|null>|null, fuente?: string|null, aviso_version?: int|null}  $datos
     */
    public function recibir(FormularioCaptacionTenant $formulario, array $datos): ProspectoTenant
    {
        $aviso = $this->waivers->avisoPrivacidad();
        if ($aviso === null && app()->environment('production')) {
            throw ValidationException::withMessages([
                'acepta_aviso' => ['Este negocio aún no publica su aviso de privacidad; escríbele directamente.'],
            ]);
        }
        $leida = $datos['aviso_version'] ?? null;
        if ($leida !== null && $aviso !== null && (int) $leida !== (int) $aviso->version) {
            throw ValidationException::withMessages([
                'acepta_aviso' => ['El aviso de privacidad cambió mientras llenabas tus datos: revísalo y vuelve a aceptarlo.'],
            ]);
        }

        $email = mb_strtolower(trim($datos['email']));
        $telefono = self::telefono($datos['telefono'] ?? null);
        if ($formulario->pide_telefono && $formulario->telefono_obligatorio && $telefono === null) {
            throw ValidationException::withMessages(['telefono' => ['Escribe tu teléfono.']]);
        }
        $respuestas = $this->respuestas($formulario->campos, (array) ($datos['respuestas'] ?? []));
        $interes = $this->interes((array) ($datos['interes'] ?? []));

        return DB::connection('tenant')->transaction(function () use ($formulario, $datos, $email, $telefono, $respuestas, $interes, $aviso): ProspectoTenant {
            $abierto = ProspectoTenant::query()
                ->whereIn('estado', EstadoProspecto::abiertos())
                ->where(function ($q) use ($email, $telefono): void {
                    $q->where('email', $email);
                    if ($telefono !== null) {
                        $q->orWhere('telefono', $telefono);
                    }
                })
                ->lockForUpdate()
                ->orderBy('id')
                ->first();

            $comunes = [
                'aviso_version' => $aviso?->version,
                'acepto_aviso_en' => now(),
            ];

            if ($abierto instanceof ProspectoTenant) {
                // Mismo interesado: se actualiza lo que trae de nuevo, sin abrir otro.
                $abierto->update(array_filter([
                    'telefono' => $abierto->telefono ?? $telefono,
                    'email' => $abierto->email ?? $email,
                    ...array_filter($interes, static fn (?int $id): bool => $id !== null),
                    'respuestas' => $respuestas !== [] ? $respuestas : null,
                ], static fn (mixed $v): bool => $v !== null) + $comunes);
                $this->evento($abierto, TipoEventoProspecto::Reenvio, ['formulario' => $formulario->nombre]);

                return $abierto;
            }

            $prospecto = ProspectoTenant::query()->create([
                'nombre' => trim($datos['nombre']),
                'email' => $email,
                'telefono' => $telefono,
                ...$interes,
                'formulario_id' => $formulario->getKey(),
                'origen' => OrigenProspecto::Formulario->value,
                'fuente' => self::texto($datos['fuente'] ?? null, 120),
                'estado' => EstadoProspecto::Nuevo->value,
                'respuestas' => $respuestas !== [] ? $respuestas : null,
                ...$comunes,
            ]);
            $this->evento($prospecto, TipoEventoProspecto::Creado, ['formulario' => $formulario->nombre]);
            // Aviso al equipo que atiende prospectos (plantilla del negocio, ADR 0027).
            $this->eventos->registrar('prospecto.creado', 'prospecto', (string) $prospecto->ulid, [
                'prospecto_id' => (string) $prospecto->ulid,
                'nombre' => $prospecto->nombre,
                'formulario' => $formulario->nombre,
            ]);

            return $prospecto;
        });
    }

    /**
     * Valida las respuestas contra las preguntas del formulario y las guarda con la
     * etiqueta de hoy (el formulario puede cambiar después).
     *
     * @param  Collection<int, CampoFormularioCaptacionTenant>  $campos
     * @param  array<string, mixed>  $entrada
     * @return list<array{campo: string, etiqueta: string, valor: mixed}>
     */
    private function respuestas(Collection $campos, array $entrada): array
    {
        $respuestas = [];
        foreach ($campos as $campo) {
            $valor = $entrada[$campo->ulid] ?? null;
            if (is_string($valor)) {
                $valor = trim($valor);
            }
            $vacio = $valor === null || $valor === '' || $valor === [];
            if ($campo->obligatorio && $vacio && $campo->tipo !== TipoCampo::Booleano) {
                throw ValidationException::withMessages(["respuestas.{$campo->ulid}" => ["Responde «{$campo->etiqueta}»."]]);
            }
            if ($vacio) {
                continue;
            }
            $valor = match ($campo->tipo) {
                TipoCampo::Texto, TipoCampo::Textarea => is_string($valor) && mb_strlen($valor) <= self::MAX_RESPUESTA ? $valor : null,
                TipoCampo::Numero => is_numeric($valor) ? $valor + 0 : null,
                TipoCampo::Booleano => filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                TipoCampo::Seleccion => is_string($valor) && in_array($valor, $campo->opciones ?? [], true) ? $valor : null,
                TipoCampo::Fecha => is_string($valor) && ($f = DateTimeImmutable::createFromFormat('!Y-m-d', $valor)) !== false && $f->format('Y-m-d') === $valor ? $valor : null,
            };
            if ($valor === null) {
                throw ValidationException::withMessages(["respuestas.{$campo->ulid}" => ["Revisa «{$campo->etiqueta}»."]]);
            }
            if ($campo->tipo === TipoCampo::Booleano && $campo->obligatorio && $valor !== true) {
                throw ValidationException::withMessages(["respuestas.{$campo->ulid}" => ["Responde «{$campo->etiqueta}»."]]);
            }
            $respuestas[] = ['campo' => $campo->ulid, 'etiqueta' => $campo->etiqueta, 'valor' => $valor];
        }

        return $respuestas;
    }

    /**
     * Lo que le interesaba, solo si es algo público del negocio: una sede, una actividad,
     * una clase programada por venir o un plan a la venta. Lo demás se ignora.
     *
     * @param  array<string, mixed>  $interes
     * @return array{sucursal_id: int|null, actividad_id: int|null, sesion_id: int|null, producto_id: int|null}
     */
    private function interes(array $interes): array
    {
        $ulid = static fn (string $clave): ?string => is_string($interes[$clave] ?? null) && $interes[$clave] !== '' ? $interes[$clave] : null;

        $sesion = $ulid('sesion') !== null
            ? SesionTenant::query()->where('ulid', $ulid('sesion'))
                ->where('tipo', TipoSesionTenant::Clase->value)
                ->where('estado', EstadoSesionTenant::Programada->value)
                ->where('inicia_en', '>', now())
                ->first()
            : null;
        $producto = $ulid('producto') !== null
            ? ProductoTenant::query()->where('ulid', $ulid('producto'))->where('archivado', false)->first()
            : null;
        $actividad = $ulid('actividad') !== null ? ActividadTenant::query()->where('ulid', $ulid('actividad'))->first() : null;
        $sucursal = $ulid('sucursal') !== null ? SucursalTenant::query()->where('ulid', $ulid('sucursal'))->first() : null;

        return [
            'sucursal_id' => $sucursal?->getKey() ?? $sesion?->sucursal_id,
            'actividad_id' => $actividad?->getKey() ?? $sesion?->oferta?->actividad_id,
            'sesion_id' => $sesion?->getKey(),
            'producto_id' => $producto?->getKey(),
        ];
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    private function evento(ProspectoTenant $prospecto, TipoEventoProspecto $tipo, array $detalle = []): void
    {
        EventoProspectoTenant::query()->create([
            'prospecto_id' => $prospecto->getKey(),
            'tipo' => $tipo->value,
            'detalle' => $detalle,
        ]);
    }

    /** Teléfono como número (con su «+» si lo trae), para comparar envíos repetidos. */
    public static function telefono(?string $telefono): ?string
    {
        if ($telefono === null) {
            return null;
        }
        $digitos = preg_replace('/\D/', '', $telefono) ?? '';
        if (strlen($digitos) < 8) {
            return null;
        }

        return (str_starts_with(trim($telefono), '+') ? '+' : '').substr($digitos, 0, 20);
    }

    private static function texto(mixed $valor, int $largo): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? mb_substr(trim($valor), 0, $largo) : null;
    }
}
