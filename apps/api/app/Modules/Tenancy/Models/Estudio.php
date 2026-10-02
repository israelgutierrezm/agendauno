<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\ModoCobroSaas;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\TerminologiaNegocio;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro central de un estudio (tenant SaaS) en el control plane. Vive en la
 * conexión por defecto (central); su BD operativa (data plane) es independiente
 * y se describe con `db_driver` + `db_database`. No usa BelongsToTenant: ES el
 * catálogo de tenants, no un dato tenant-scoped. Guarda su identidad pública: logo,
 * portada, descripción y redes.
 *
 * @property string|null $descripcion
 * @property string|null $portada_url
 * @property array<string, string>|null $redes
 * @property bool $whatsapp_habilitado los avisos por WhatsApp a sus clientes; solo los activa el superadministrador (ADR 0083)
 */
class Estudio extends Model
{
    use HasPublicId;

    protected $table = 'estudios';

    protected $fillable = [
        'nombre',
        'slug',
        'perfil_negocio',
        'terminologia',
        'logo_url',
        'descripcion',
        'portada_url',
        'redes',
        'estado',
        'paso_aprovisionamiento',
        'aprovisionado_en',
        'publicado',
        'privado',
        'pais',
        'ciudad',
        'zona_horaria',
        'contacto_nombre',
        'contacto_segundo_nombre',
        'contacto_primer_apellido',
        'contacto_segundo_apellido',
        'contacto_email',
        'contacto_whatsapp_pais',
        'contacto_telefono',
        'contacto_whatsapp_verificado_en',
        'contacto_whatsapp_aceptado_en',
        'whatsapp_habilitado',
        'suspendido_por',
        'suspendido_en',
        'sin_suspension_hasta',
        'trial_inicia_en',
        'trial_termina_en',
        'plan',
        'precio_por_alumno_minor',
        'modo_cobro',
        'cuota_fija_minor',
        'moneda',
        'estado_facturacion',
        'db_driver',
        'db_database',
        'version_migraciones',
        'onboarding_pasos',
        'onboarding_completo',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoEstudio::class,
        'perfil_negocio' => PerfilNegocio::class,
        'terminologia' => 'array',
        'redes' => 'array',
        'estado_facturacion' => EstadoFacturacion::class,
        'publicado' => 'boolean',
        'privado' => 'boolean',
        'aprovisionado_en' => 'datetime',
        'trial_inicia_en' => 'date',
        'trial_termina_en' => 'date',
        'precio_por_alumno_minor' => 'integer',
        'modo_cobro' => ModoCobroSaas::class,
        'cuota_fija_minor' => 'integer',
        'onboarding_pasos' => 'array',
        'onboarding_completo' => 'boolean',
        'contacto_whatsapp_verificado_en' => 'datetime',
        'contacto_whatsapp_aceptado_en' => 'datetime',
        'whatsapp_habilitado' => 'boolean',
        'suspendido_en' => 'datetime',
        'sin_suspension_hasta' => 'date',
    ];

    /**
     * ¿Se suspendió solo por una renta vencida? Entonces el dueño aún puede entrar a
     * pagarla y, al pagarla, se reactiva (ADR 0073).
     */
    public function suspendidoPorRenta(): bool
    {
        return $this->estado === EstadoEstudio::Suspended && $this->suspendido_por === 'renta';
    }

    /**
     * ¿El estudio aparece en el directorio público?
     */
    public function enDirectorio(): bool
    {
        return $this->publicado && ! $this->privado && $this->estado->operativo();
    }

    /**
     * ¿Su página pública (y la reserva en línea) está abierta? Publicado y operativo,
     * aparezca o no en el directorio: «solo con enlace» (`privado`) no la cierra, solo
     * la saca de las búsquedas (ADR 0090).
     */
    public function paginaPublica(): bool
    {
        return $this->publicado && $this->estado->operativo();
    }

    /**
     * Modalidad de servicio del estudio (clases con cupo vs citas 1 a 1), derivada de
     * su perfil de negocio.
     */
    public function modalidad(): ModalidadServicio
    {
        return ModalidadServicio::paraPerfil($this->perfil_negocio);
    }

    /**
     * Configuración que el frontend usa para adaptarse sin forks: terminología (la del
     * perfil con la que eligió el negocio encima, ADR 0049) y feature-flags del perfil,
     * más la modalidad de servicio.
     *
     * @return array{terminologia: array<string, string>, flags: array<string, bool>, modalidad: string}
     */
    public function perfilConfig(): array
    {
        $config = $this->perfil_negocio->configuracion();
        $config['terminologia'] = TerminologiaNegocio::completa($config['terminologia'], $this->terminologia);

        return $config + ['modalidad' => $this->modalidad()->value];
    }

    /**
     * Nombre completo del contacto propietario, compuesto de sus partes (omite vacías).
     * `contacto_nombre` es el primer nombre; el resto es opcional.
     */
    public function nombreContacto(): string
    {
        $completo = trim(implode(' ', array_filter([
            $this->contacto_nombre,
            $this->contacto_segundo_nombre,
            $this->contacto_primer_apellido,
            $this->contacto_segundo_apellido,
        ])));

        return $completo !== '' ? $completo : (string) $this->contacto_nombre;
    }

    /**
     * WhatsApp del contacto en formato +<lada><numero> (o null si no hay número).
     */
    public function whatsappCompleto(): ?string
    {
        $numero = trim((string) ($this->contacto_telefono ?? ''));
        if ($numero === '') {
            return null;
        }

        return '+'.($this->contacto_whatsapp_pais ?? '52').' '.$numero;
    }
}
