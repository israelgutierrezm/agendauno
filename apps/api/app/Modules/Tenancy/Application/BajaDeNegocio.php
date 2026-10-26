<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\VolcadoBaseDatos;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Exceptions\BajaNoPermitida;
use App\Modules\Tenancy\Mail\CorreoBajaDeNegocio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ExportacionNegocio;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Baja de un negocio (ADR 0213), la pida su dueño o la plataforma:
 *
 * - Al darla, el negocio queda `cancelled`: nadie más entra, sus clientes ya no ven su
 *   página ni su app, no corren sus avisos y se quitan los pagos automáticos de sus
 *   clientes. Su dueño sí entra, solo a descargar sus datos, pagar lo pendiente de la
 *   renta o revertir la baja.
 * - Durante N días (parámetro de la plataforma {@see CLAVE_DIAS}) se puede revertir.
 * - Pasado el plazo se borran su base, sus archivos y sus respaldos. Su registro, sus
 *   cargos y sus facturas de la renta se quedan: la ley obliga a guardarlos.
 */
class BajaDeNegocio
{
    /** Parámetro de la plataforma: días para borrar los datos tras la baja. */
    public const CLAVE_DIAS = 'negocios.dias_borrar_baja';

    public const POR_DUENO = 'dueno';

    public const POR_PLATAFORMA = 'plataforma';

    /**
     * Las carpetas de sus archivos, por disco (cada una lleva su id al final).
     *
     * @var array{publico: list<string>, privado: list<string>}
     */
    private const CARPETAS = [
        'publico' => ['estudios', 'servicios', 'productos', 'sucursales', 'usuarios'],
        'privado' => ['documentos', 'bandeja'],
    ];

    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly ParametrosTenant $parametros,
        private readonly SuspensionPorRenta $suspension,
        private readonly VolcadoBaseDatos $volcado,
    ) {}

    public function dias(): int
    {
        return $this->parametros->entero(self::CLAVE_DIAS);
    }

    /**
     * Las carpetas de sus archivos con su disco: lo que se borra con sus datos y lo que
     * lleva su descarga.
     *
     * @return list<array{0: FilesystemAdapter, 1: string}>
     */
    public static function carpetas(Estudio $estudio): array
    {
        $id = (string) $estudio->getKey();
        $carpetas = [];
        foreach (self::CARPETAS['publico'] as $carpeta) {
            $carpetas[] = [DiscosDeArchivos::publico(), $carpeta.'/'.$id];
        }
        foreach (self::CARPETAS['privado'] as $carpeta) {
            $carpetas[] = [DiscosDeArchivos::privado(), $carpeta.'/'.$id];
        }

        return $carpetas;
    }

    /**
     * Da de baja el negocio. Uno a medio aprovisionar o ya dado de baja, no.
     *
     * @param  self::POR_DUENO|self::POR_PLATAFORMA  $por
     */
    public function dar(Estudio $estudio, string $por, ?string $motivo): Estudio
    {
        if ($estudio->estado === EstadoEstudio::Provisioning || $estudio->estado === EstadoEstudio::Cancelled) {
            throw new BajaNoPermitida($estudio->estado === EstadoEstudio::Cancelled
                ? 'El negocio ya está dado de baja.'
                : 'El negocio aún no termina de crearse.');
        }

        $borrarEn = Carbon::today()->addDays($this->dias());
        $estudio->update([
            'estado' => EstadoEstudio::Cancelled->value,
            'dado_de_baja_en' => Carbon::now(),
            'baja_por' => $por,
            'baja_motivo' => $motivo,
            'datos_borrar_en' => $borrarEn->toDateString(),
            'suspendido_por' => null,
            'suspendido_en' => null,
        ]);
        // Los pagos automáticos de sus clientes y las sesiones del equipo, en cola: la
        // pasarela puede tardar y la baja ya quedó.
        CerrarNegocioDadoDeBaja::dispatch((int) $estudio->getKey());

        $correo = (string) $estudio->contacto_email;
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) !== false) {
            Mail::to($correo)->queue(new CorreoBajaDeNegocio(
                (string) $estudio->nombre,
                $borrarEn->toDateString(),
                $por === self::POR_DUENO,
                $estudio->producto(),
            ));
        }
        Log::info('negocio.dado_de_baja', ['estudio' => $estudio->slug, 'por' => $por, 'borrar_en' => $borrarEn->toDateString()]);

        return $estudio->refresh();
    }

    /**
     * Revierte la baja mientras sus datos sigan ahí: vuelve a prueba si aún no termina,
     * si no, queda activo. Los pagos automáticos que se quitaron no vuelven solos: cada
     * cliente los activa de nuevo.
     */
    public function revertir(Estudio $estudio): Estudio
    {
        if (! $estudio->enPlazoDeBaja()) {
            throw new BajaNoPermitida('Este negocio no está dado de baja o sus datos ya se borraron.');
        }

        $desde = $estudio->dado_de_baja_en;
        $enPrueba = $estudio->trial_termina_en !== null && ! $estudio->trial_termina_en->isPast();
        $estudio->update([
            'estado' => ($enPrueba ? EstadoEstudio::Trialing : EstadoEstudio::Active)->value,
            'dado_de_baja_en' => null,
            'baja_por' => null,
            'baja_motivo' => null,
            'datos_borrar_en' => null,
        ]);
        // Lo que pasó mientras estuvo cerrado no se le cobra a sus clientes (ADR 0166).
        $this->suspension->alReabrir($estudio, $desde);
        Log::info('negocio.baja_revertida', ['estudio' => $estudio->slug]);

        return $estudio->refresh();
    }

    /**
     * Borra los datos de los negocios cuyo plazo ya venció. Uno que falla se reporta y
     * se reintenta en la siguiente corrida, sin detener a los demás.
     *
     * @return array{borrados: list<string>, errores: array<string, string>}
     */
    public function borrarVencidos(): array
    {
        $resultado = ['borrados' => [], 'errores' => []];
        Estudio::query()
            ->where('estado', EstadoEstudio::Cancelled->value)
            ->whereNotNull('dado_de_baja_en')
            ->whereNull('datos_borrados_en')
            ->whereNotNull('datos_borrar_en')
            ->whereDate('datos_borrar_en', '<=', Carbon::today()->toDateString())
            ->orderBy('id')
            ->get()
            ->each(function (Estudio $estudio) use (&$resultado): void {
                try {
                    $this->borrarDatos($estudio);
                    $resultado['borrados'][] = (string) $estudio->slug;
                } catch (Throwable $e) {
                    report($e);
                    $resultado['errores'][(string) $estudio->slug] = $e->getMessage();
                }
            });

        return $resultado;
    }

    /** Sus archivos, sus respaldos y su base, en ese orden; al final se anota. */
    private function borrarDatos(Estudio $estudio): void
    {
        foreach (self::carpetas($estudio) as [$disco, $carpeta]) {
            $disco->deleteDirectory($carpeta);
        }
        // Y sus descargas de datos.
        DiscosDeArchivos::privado()->deleteDirectory('exportaciones/'.$estudio->getKey());
        ExportacionNegocio::query()->where('estudio_id', $estudio->getKey())->delete();
        $this->volcado->disco()->deleteDirectory($this->volcado->carpeta((string) $estudio->slug));
        if ($this->gestor->baseDeDatosExiste($estudio)) {
            $this->gestor->borrarBaseDeDatos($estudio);
        }
        $estudio->forceFill(['datos_borrados_en' => Carbon::now()])->save();
        Log::info('negocio.datos_borrados', ['estudio' => $estudio->slug]);
    }
}
