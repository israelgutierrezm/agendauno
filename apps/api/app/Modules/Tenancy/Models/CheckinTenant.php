<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Check-in de un usuario de una plataforma de bienestar (Wellhub / TotalPass) en una
 * sesion, tenant-local. No consume creditos del estudio. Estados (M10): `validado`
 * (el proveedor lo aprobó), `no_verificable` (el proveedor no respondió: no cuenta
 * como aprobado) y `excepcion` (alguien con permiso lo autorizó sin el proveedor, con
 * su motivo y evidencia). Un código rechazado no se guarda.
 */
class CheckinTenant extends Model
{
    use HasPublicId;

    public const VALIDADO = 'validado';

    public const NO_VERIFICABLE = 'no_verificable';

    public const EXCEPCION = 'excepcion';

    protected $connection = 'tenant';

    protected $table = 'checkins';

    protected $fillable = [
        'sesion_id', 'proveedor', 'referencia_externa', 'nombre_usuario', 'estado', 'registrado_en',
        'codigo_huella', 'motivo', 'intentos', 'registrado_por_id',
        'autorizado_por_id', 'autorizado_en', 'motivo_excepcion', 'evidencia',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'registrado_en' => 'datetime',
        'autorizado_en' => 'datetime',
        'intentos' => 'integer',
    ];

    /**
     * @return BelongsTo<SesionTenant, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionTenant::class, 'sesion_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autorizado_por_id');
    }
}
