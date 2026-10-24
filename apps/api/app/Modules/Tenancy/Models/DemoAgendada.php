<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una demo de 15 minutos agendada desde la landing (control plane, ADR 0193). Estados:
 * `agendada`, `cancelada` (por quien la pidió o por la plataforma), `atendida` y
 * `no_asistio`.
 *
 * @property string $producto
 * @property Carbon $inicia_en
 * @property string|null $ranura
 * @property string $nombre
 * @property string $negocio
 * @property string|null $giro
 * @property string $correo
 * @property string $telefono
 * @property bool $acepta_whatsapp
 * @property string $estado
 * @property string $token
 * @property int|null $aviso_version
 * @property Carbon|null $acepto_aviso_en
 * @property Carbon|null $recordatorio_dia_en
 * @property Carbon|null $recordatorio_hora_en
 * @property array<string, mixed>|null $calificacion
 * @property Carbon $created_at
 */
class DemoAgendada extends Model
{
    use HasPublicId;

    public const ESTADOS = ['agendada', 'cancelada', 'atendida', 'no_asistio'];

    protected $table = 'demos_agendadas';

    protected $fillable = ['producto', 'inicia_en', 'ranura', 'nombre', 'negocio', 'giro', 'correo', 'telefono', 'acepta_whatsapp', 'estado', 'token', 'aviso_version', 'acepto_aviso_en', 'calificacion'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'inicia_en' => 'datetime',
        'acepta_whatsapp' => 'boolean',
        'acepto_aviso_en' => 'datetime',
        'recordatorio_dia_en' => 'datetime',
        'recordatorio_hora_en' => 'datetime',
        'calificacion' => 'array',
    ];
}
