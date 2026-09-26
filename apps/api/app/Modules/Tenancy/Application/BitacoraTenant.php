<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consulta de la bitácora (append-only): quién hizo qué y cuándo, filtrable por
 * fechas (en la zona del negocio), persona del equipo, categoría, acción, entidad o
 * texto. Cada asiento trae una descripción legible ("Dio de baja a Ana López",
 * "Registró un cobro de $899.00 MXN") para la pantalla y la descarga.
 */
class BitacoraTenant
{
    /**
     * Categorías por prefijo de la acción.
     *
     * @var array<string, list<string>>
     */
    public const CATEGORIAS = [
        'equipo' => ['usuario.'],
        'alumnos' => ['miembro.'],
        'pagos' => ['pago.', 'orden.'],
        'membresias' => ['membresia.', 'credito.', 'pago_automatico.'],
        'catalogo' => ['producto.', 'promocion.', 'plantilla', 'recurso.', 'automatizacion.', 'capacidad.', 'excepcion_horario.', 'webhook.', 'asignacion.'],
        'privacidad' => ['privacidad.'],
        'configuracion' => ['pasarela.', 'estudio.'],
    ];

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /**
     * @param  array{desde?: string|null, hasta?: string|null, usuario?: string|null, categoria?: string|null, accion?: string|null, entidad_tipo?: string|null, entidad_id?: string|null, q?: string|null}  $filtros
     * @return Builder<AuditoriaTenant>
     */
    public function consulta(array $filtros): Builder
    {
        $zona = (string) ($this->gestor->actual()?->zona_horaria ?: 'America/Mexico_City');
        $consulta = AuditoriaTenant::query()->orderByDesc('id');

        if (($filtros['desde'] ?? '') !== '' && $filtros['desde'] !== null) {
            $consulta->where('created_at', '>=', CarbonImmutable::parse($filtros['desde'], $zona)->startOfDay()->utc());
        }
        if (($filtros['hasta'] ?? '') !== '' && $filtros['hasta'] !== null) {
            $consulta->where('created_at', '<=', CarbonImmutable::parse($filtros['hasta'], $zona)->endOfDay()->utc());
        }
        if (($filtros['usuario'] ?? '') !== '' && $filtros['usuario'] !== null) {
            $consulta->where('actor_id', (int) (Usuario::withTrashed()->where('ulid', $filtros['usuario'])->value('id') ?? 0));
        }
        $prefijos = self::CATEGORIAS[(string) ($filtros['categoria'] ?? '')] ?? null;
        if ($prefijos !== null) {
            $consulta->where(function (Builder $q) use ($prefijos): void {
                foreach ($prefijos as $prefijo) {
                    $q->orWhere('accion', 'like', $prefijo.'%');
                }
            });
        }
        foreach (['accion', 'entidad_tipo', 'entidad_id'] as $campo) {
            $valor = trim((string) ($filtros[$campo] ?? ''));
            if ($valor !== '') {
                $consulta->where($campo, $valor);
            }
        }
        $texto = trim((string) ($filtros['q'] ?? ''));
        if ($texto !== '') {
            $consulta->where(fn (Builder $q) => $q
                ->where('actor_nombre', 'like', "%{$texto}%")
                ->orWhere('motivo', 'like', "%{$texto}%")
                ->orWhere('antes', 'like', "%{$texto}%")
                ->orWhere('despues', 'like', "%{$texto}%"));
        }

        return $consulta;
    }

    /**
     * Las personas del equipo que aparecen en la bitácora (para el filtro).
     *
     * @return list<array{id: string, nombre: string}>
     */
    public function actores(): array
    {
        $ids = AuditoriaTenant::query()->whereNotNull('actor_id')->distinct()->pluck('actor_id')->all();

        return Usuario::withTrashed()->whereIn('id', $ids)->orderBy('name')->get(['ulid', 'name'])
            ->map(static fn (Usuario $u): array => ['id' => (string) $u->ulid, 'nombre' => (string) $u->name])
            ->values()->all();
    }

    public static function categoria(string $accion): ?string
    {
        foreach (self::CATEGORIAS as $categoria => $prefijos) {
            foreach ($prefijos as $prefijo) {
                if (str_starts_with($accion, $prefijo)) {
                    return $categoria;
                }
            }
        }

        return null;
    }

    /**
     * Qué pasó, en palabras.
     */
    public static function descripcion(AuditoriaTenant $a): string
    {
        $antes = is_array($a->antes) ? $a->antes : [];
        $despues = is_array($a->despues) ? $a->despues : [];
        $nombre = (string) ($despues['nombre'] ?? $antes['nombre'] ?? '');
        $de = $nombre !== '' ? " a {$nombre}" : '';

        return match ($a->accion) {
            'miembro.baja' => "Dio de baja{$de}",
            'miembro.reactivado' => "Reactivó{$de}",
            'miembro.actualizado' => 'Editó los datos'.($nombre !== '' ? " de {$nombre}" : ''),
            'miembro.celular_liberado' => 'Quitó el teléfono '.((string) ($antes['celular'] ?? '')).' a una persona dada de baja',
            'usuario.baja' => "Dio de baja del equipo{$de}",
            'usuario.reactivado' => "Reactivó en el equipo{$de}",
            'usuario.invitado' => "Invitó al equipo{$de}",
            'usuario.roles' => 'Cambió los roles'.($nombre !== '' ? " de {$nombre}" : '').(isset($despues['roles']) && is_array($despues['roles']) ? ': '.implode(', ', $despues['roles']) : ''),
            'pago.registrado' => 'Registró un cobro de '.self::dinero($despues),
            'pago.reembolso' => 'Devolvió '.self::dinero($despues),
            'credito.top_up' => 'Agregó créditos'.(isset($despues['unidades']) ? ' ('.((int) $despues['unidades'] / 1000).')' : ''),
            'producto.actualizado' => 'Editó el producto'.($nombre !== '' ? " {$nombre}" : ''),
            'membresia.pausada' => 'Pausó una membresía'.(isset($despues['hasta']) ? ' hasta el '.$despues['hasta'] : ''),
            'membresia.reanudada' => 'Reanudó una membresía',
            'privacidad.baja_atendida' => 'Canceló los datos personales (ARCO) de una persona',
            'privacidad.baja_rechazada' => 'Rechazó una solicitud de cancelación de datos',
            'reserva.reprogramada' => 'Reprogramó una cita o reserva'.(isset($antes['fecha'], $despues['fecha']) ? " del {$antes['fecha']} {$antes['hora']} al {$despues['fecha']} {$despues['hora']}" : ''),
            'sesion.reprogramada' => 'Cambió el horario de '.((string) ($despues['actividad'] ?? 'una clase')).(isset($despues['fecha']) ? " al {$despues['fecha']} {$despues['hora']}" : ''),
            'plantilla_horario.cambiada' => 'Cambió una clase recurrente desde el '.((string) ($despues['desde'] ?? '')).(isset($despues['movidas']) ? " ({$despues['movidas']} fechas)" : ''),
            'bloqueo_agenda.creado' => 'Bloqueó la agenda'.(isset($despues['motivo']) ? ': '.$despues['motivo'] : ''),
            'pasarela.configurada' => 'Configuró la pasarela '.((string) ($despues['proveedor'] ?? '')).(isset($despues['activa']) ? ($despues['activa'] ? ' (activa)' : ' (inactiva)') : ''),
            default => self::eliminacion($a->accion, $antes, $despues) ?? $a->accion,
        };
    }

    /**
     * Registros de catálogo y configuración eliminados o restaurados.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     */
    private static function eliminacion(string $accion, array $antes, array $despues): ?string
    {
        $etiquetas = [
            'promocion' => 'la promoción',
            'plantilla_mensaje' => 'el mensaje automático',
            'plantilla_horario' => 'la clase recurrente',
            'recurso' => 'el recurso',
            'automatizacion' => 'la automatización',
            'capacidad' => 'la regla de cupo por canal',
            'excepcion_horario' => 'la excepción de horario',
            'webhook' => 'el webhook',
            'asignacion' => 'la asignación de sede',
            'bloqueo_agenda' => 'el bloqueo de agenda',
        ];
        foreach (['eliminado' => 'Eliminó', 'restaurado' => 'Restauró'] as $sufijo => $verbo) {
            if (! str_ends_with($accion, '.'.$sufijo)) {
                continue;
            }
            $tipo = substr($accion, 0, -strlen($sufijo) - 1);
            $datos = $sufijo === 'eliminado' ? $antes : $despues;
            $nombre = (string) ($datos['nombre'] ?? $datos['codigo'] ?? $datos['url'] ?? $datos['clave'] ?? $datos['motivo'] ?? '');

            return trim($verbo.' '.($etiquetas[$tipo] ?? $tipo).' '.$nombre);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private static function dinero(array $datos): string
    {
        $minor = (int) ($datos['monto_minor'] ?? 0);

        return '$'.number_format($minor / 100, 2, '.', ',').' '.strtoupper((string) ($datos['moneda'] ?? 'MXN'));
    }
}
