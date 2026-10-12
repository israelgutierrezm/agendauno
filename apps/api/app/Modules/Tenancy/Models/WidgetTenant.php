<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Un widget para la web del negocio (ADR 0141): su calendario de clases o sus planes,
 * dentro de su propio sitio, con los filtros, el tema y el color que eligió, y las webs
 * donde se puede mostrar.
 *
 * @property int $id
 * @property string $ulid
 * @property string $tipo calendario | planes
 * @property string $nombre
 * @property array{sucursal?: string|null, actividad?: string|null}|null $filtros ulids
 * @property string $tema claro | oscuro | auto
 * @property string|null $acento #rrggbb
 * @property list<string>|null $dominios
 * @property bool $activo
 */
class WidgetTenant extends Model
{
    use HasPublicId;

    public const TIPOS = ['calendario', 'planes'];

    public const TEMAS = ['claro', 'oscuro', 'auto'];

    protected $connection = 'tenant';

    protected $table = 'widgets';

    protected $fillable = ['tipo', 'nombre', 'filtros', 'tema', 'acento', 'dominios', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'filtros' => 'array',
        'dominios' => 'array',
        'activo' => 'boolean',
    ];
}
