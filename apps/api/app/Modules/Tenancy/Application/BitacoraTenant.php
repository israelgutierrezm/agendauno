<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ProveedorPartner;
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
        'equipo' => ['usuario.', 'nomina.'],
        'alumnos' => ['miembro.', 'grupo.'],
        'pagos' => ['pago.', 'orden.'],
        'membresias' => ['membresia.', 'credito.', 'pago_automatico.'],
        'catalogo' => ['oferta.', 'producto.', 'promocion.', 'lealtad.', 'plantilla', 'recurso.', 'automatizacion.', 'capacidad.', 'excepcion_horario.', 'webhook.', 'asignacion.'],
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
            // Contracargos (ADR 0168): los registra la pasarela. Sin nombrar al público:
            // el CSV de la bitácora sale del servidor, que no adapta la terminología.
            'pago.contracargo' => match ((string) ($despues['estado'] ?? '')) {
                'ganado' => 'Se ganó el contracargo de '.self::dinero($despues),
                'perdido' => 'Se perdió el contracargo de '.self::dinero($despues).': se devolvió el dinero a quien pagó',
                default => 'El banco de quien pagó disputa un pago de '.self::dinero($despues),
            },
            'credito.top_up' => 'Agregó créditos'.(isset($despues['unidades']) ? ' ('.((int) $despues['unidades'] / 1000).')' : ''),
            'producto.actualizado' => 'Editó el producto'.($nombre !== '' ? " {$nombre}" : ''),
            'recurso.actualizado' => 'Editó el espacio'.($nombre !== '' ? " {$nombre}" : ''),
            // Grupos y cursos: salidas con fecha y cierre.
            'grupo.baja' => 'Dio de baja'.$de.' del grupo '.((string) ($despues['grupo'] ?? '')).' desde el '.((string) ($despues['desde'] ?? '')),
            'grupo.cambio' => 'Cambió'.$de.' del grupo '.((string) ($antes['grupo'] ?? '')).' a '.((string) ($despues['grupo'] ?? '')).' desde el '.((string) ($despues['desde'] ?? '')),
            'grupo.cerrado' => 'Cerró el grupo '.((string) ($despues['grupo'] ?? '')).((int) ($despues['bajas'] ?? 0) > 0 ? ' y dio de baja a sus '.((int) $despues['bajas']).' inscritos desde el '.((string) ($despues['desde'] ?? '')) : ''),
            'grupo.reabierto' => 'Reabrió el grupo '.((string) ($despues['grupo'] ?? '')),
            'grupo.fechas_nuevas' => self::fechasNuevasDeGrupo($despues),
            // Cortes de nómina: cerrar, pagar, reabrir y ajustar un periodo.
            'nomina.corte_cerrado' => 'Cerró la nómina'.self::periodoNomina($despues),
            'nomina.corte_pagado' => 'Registró el pago de la nómina'.self::periodoNomina($despues).(isset($despues['referencia']) ? ' (referencia '.((string) $despues['referencia']).')' : ''),
            'nomina.corte_reabierto' => 'Reabrió la nómina'.self::periodoNomina($antes),
            'nomina.ajuste' => 'Ajustó la nómina'.self::periodoNomina($despues).' de '.((string) ($despues['persona'] ?? '')).': '.((string) ($despues['motivo'] ?? '')),
            // Servicios y clases (ADR 0154): retirados y vueltos a ofrecer, con su historial.
            'oferta.archivada' => 'Archivó'.($nombre !== '' ? " {$nombre}" : ' un servicio'),
            'oferta.restaurada' => 'Volvió a ofrecer'.($nombre !== '' ? " {$nombre}" : ' un servicio'),
            'oferta.actualizada' => self::ofertaCorregida($antes, $despues),
            // Promociones y lealtad: quién las creó o cambió y qué cambió.
            'promocion.creada' => 'Creó la promoción '.((string) ($despues['codigo'] ?? '')),
            'promocion.actualizada' => self::corregido('Editó la promoción '.((string) ($despues['codigo'] ?? $antes['codigo'] ?? '')), $antes, $despues, [
                'codigo' => 'el código', 'descripcion' => 'la descripción', 'tipo' => 'el tipo', 'valor' => 'el valor',
                'monto_minimo_minor' => 'el monto mínimo', 'usos_maximos' => 'los usos', 'vence_en' => 'la vigencia', 'activa' => 'si está activa',
            ]),
            'lealtad.programa' => (bool) ($despues['activa'] ?? false) !== (bool) ($antes['activa'] ?? false)
                ? ((bool) ($despues['activa'] ?? false) ? 'Activó el programa de lealtad' : 'Pausó el programa de lealtad')
                : self::corregido('Cambió cómo se ganan puntos', $antes, $despues, [
                    'puntos_por_asistencia' => 'por asistir', 'puntos_por_moneda' => 'por comprar',
                ]),
            'lealtad.recompensa_creada' => 'Creó la recompensa '.((string) ($despues['nombre'] ?? '')),
            'lealtad.recompensa_actualizada' => (bool) ($despues['activa'] ?? true) !== (bool) ($antes['activa'] ?? true)
                ? ((bool) ($despues['activa'] ?? true) ? 'Volvió a ofrecer' : 'Dejó de ofrecer').' la recompensa '.((string) ($despues['nombre'] ?? ''))
                : self::corregido('Editó la recompensa '.((string) ($despues['nombre'] ?? '')), $antes, $despues, [
                    'nombre' => 'el nombre', 'descripcion' => 'la descripción', 'costo_puntos' => 'los puntos',
                ]),
            'membresia.pausada' => 'Pausó una membresía'.(isset($despues['hasta']) ? ' hasta el '.$despues['hasta'] : ''),
            'membresia.reanudada' => 'Reanudó una membresía',
            'privacidad.baja_atendida' => 'Canceló los datos personales (ARCO) de una persona',
            'privacidad.baja_rechazada' => 'Rechazó una solicitud de cancelación de datos',
            'reserva.reprogramada' => 'Reprogramó una cita o reserva'.(isset($antes['fecha'], $despues['fecha']) ? " del {$antes['fecha']} {$antes['hora']} al {$despues['fecha']} {$despues['hora']}" : ''),
            'sesion.reprogramada' => 'Cambió el horario de '.((string) ($despues['actividad'] ?? 'una clase')).(isset($despues['fecha']) ? " al {$despues['fecha']} {$despues['hora']}" : ''),
            'plantilla_horario.cambiada' => 'Cambió una clase recurrente desde el '.((string) ($despues['desde'] ?? '')).(isset($despues['movidas']) ? " ({$despues['movidas']} fechas)" : ''),
            'bloqueo_agenda.creado' => 'Bloqueó la agenda'.(isset($despues['motivo']) ? ': '.$despues['motivo'] : ''),
            'estudio.modalidad' => 'La plataforma cambió el negocio a '.((string) ($despues['modalidad'] ?? '')).(isset($despues['perfil']) ? " (giro: {$despues['perfil']})" : ''),
            'pasarela.configurada' => 'Configuró la pasarela '.((string) ($despues['proveedor'] ?? '')).(isset($despues['activa']) ? ($despues['activa'] ? ' (activa)' : ' (inactiva)') : ''),
            // Wellhub y TotalPass (ADR 0124, 0149): de la plataforma solo su nombre.
            'integracion.actualizada' => 'Configuró la integración con '.self::plataforma($a).(isset($despues['activa']) ? ($despues['activa'] ? ' (activa)' : ' (inactiva)') : ''),
            'integracion.reservas' => (($despues['activas'] ?? false) ? 'Publicó sus clases en ' : 'Dejó de publicar sus clases en ').self::plataforma($a),
            default => self::eliminacion($a->accion, $antes, $despues) ?? $a->accion,
        };
    }

    /**
     * « del 2026-09-01 al 2026-09-15».
     *
     * @param  array<string, mixed>  $datos
     */
    private static function periodoNomina(array $datos): string
    {
        return isset($datos['desde'], $datos['hasta'])
            ? ' del '.((string) $datos['desde']).' al '.((string) $datos['hasta'])
            : '';
    }

    /**
     * «Reservó a los inscritos del grupo X en 3 fechas nuevas: 5 reservadas, 1 sin
     * reservar».
     *
     * @param  array<string, mixed>  $despues
     */
    private static function fechasNuevasDeGrupo(array $despues): string
    {
        $fechas = (int) ($despues['fechas'] ?? 0);
        $partes = array_filter([
            (int) ($despues['reservadas'] ?? 0) > 0 ? ((int) $despues['reservadas']).' reservadas' : null,
            (int) ($despues['en_espera'] ?? 0) > 0 ? ((int) $despues['en_espera']).' en espera' : null,
            (int) ($despues['no_reservadas'] ?? 0) > 0 ? ((int) $despues['no_reservadas']).' sin reservar' : null,
        ]);

        return 'Reservó a los inscritos del grupo '.((string) ($despues['grupo'] ?? ''))
            .' en '.$fechas.($fechas === 1 ? ' fecha nueva' : ' fechas nuevas')
            .($partes !== [] ? ': '.implode(', ', $partes) : '');
    }

    /**
     * «Editó X: cambió el valor y la vigencia» (lo que cambió, en palabras).
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @param  array<string, string>  $etiquetas
     */
    private static function corregido(string $inicio, array $antes, array $despues, array $etiquetas): string
    {
        $cambios = [];
        foreach ($etiquetas as $campo => $etiqueta) {
            if (($antes[$campo] ?? null) != ($despues[$campo] ?? null)) {
                $cambios[] = $etiqueta;
            }
        }
        if ($cambios === []) {
            return $inicio;
        }
        $ultimo = array_pop($cambios);

        return $inicio.': cambió '.($cambios === [] ? $ultimo : implode(', ', $cambios).' y '.$ultimo);
    }

    /**
     * «Renombró A a B», «Cambió el cupo de A de 12 a 15» o los dos.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     */
    private static function ofertaCorregida(array $antes, array $despues): string
    {
        $nombreAntes = (string) ($antes['nombre'] ?? '');
        $nombre = (string) ($despues['nombre'] ?? $nombreAntes);
        $partes = [];
        if ($nombreAntes !== '' && $nombre !== $nombreAntes) {
            $partes[] = "Renombró {$nombreAntes} a {$nombre}";
        }
        if (($antes['capacidad'] ?? null) !== ($despues['capacidad'] ?? null)) {
            $partes[] = ($partes === [] ? "Cambió el cupo de {$nombre}" : 'cambió su cupo')
                .' de '.($antes['capacidad'] ?? 'sin cupo').' a '.($despues['capacidad'] ?? 'sin cupo');
        }

        return $partes === [] ? "Editó {$nombre}" : implode(' y ', $partes);
    }

    /** El nombre de la plataforma de bienestar de una integración. */
    private static function plataforma(AuditoriaTenant $a): string
    {
        return ProveedorPartner::tryFrom((string) $a->entidad_id)?->nombre() ?? (string) $a->entidad_id;
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
