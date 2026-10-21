<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\MovimientoWhatsAppTenant;
use App\Modules\Tenancy\Models\PlantillaMensajeTenant;
use App\Modules\Tenancy\Models\SaldoWhatsAppTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp por mensaje (ADR 0186). Los avisos salen del número de la plataforma con
 * sus plantillas aprobadas (ADR 0069, 0083). Cuando la plataforma lo cobra
 * (`whatsapp.cobro_por_mensaje`), cada aviso enviado gasta un mensaje del saldo
 * prepagado del negocio (paquetes al precio por mensaje que fija el superadmin, más
 * IVA) y respeta el tope mensual que el negocio decide (`whatsapp.tope_mensual`, 0 =
 * sin tope). Sin saldo o en el tope, el aviso no sale (se descarta con el motivo).
 *
 * El saldo se mueve con su fila bloqueada: dos avisos a la vez no gastan el mismo
 * mensaje, y una compra se suma una sola vez.
 */
class MensajesWhatsAppTenant
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /** ¿La plataforma cobra los avisos por WhatsApp a los negocios? */
    public function cobra(): bool
    {
        return $this->parametros->siNo('whatsapp.cobro_por_mensaje');
    }

    /** Precio de un mensaje en centavos de peso, sin IVA. */
    public function precioMinor(): int
    {
        return $this->parametros->entero('whatsapp.precio_mensaje_centavos');
    }

    /**
     * Cuántos mensajes del saldo gasta uno de esa categoría (ADR 0190): utilidad, uno;
     * marketing, los que fija la plataforma.
     */
    public function unidadesPor(string $categoria): int
    {
        return $categoria === 'marketing' ? max(1, $this->parametros->entero('whatsapp.mensajes_por_marketing')) : 1;
    }

    /**
     * Los paquetes a la venta con su precio (sin IVA, en pesos).
     *
     * @return list<array{cantidad: int, precio_minor: int}>
     */
    public function paquetes(): array
    {
        $precio = $this->precioMinor();

        return array_map(static fn (int $cantidad): array => ['cantidad' => $cantidad, 'precio_minor' => $cantidad * $precio], ConfiguracionPlataforma::paquetesWhatsApp());
    }

    public function disponibles(): int
    {
        return (int) (SaldoWhatsAppTenant::query()->value('disponibles') ?? 0);
    }

    /** El tope de mensajes al mes que fijó el negocio (0 = sin tope). */
    public function tope(): int
    {
        return $this->parametros->entero('whatsapp.tope_mensual');
    }

    /** Mensajes enviados este mes (en la zona del negocio). */
    public function usadosEsteMes(): int
    {
        $zona = (string) ($this->gestor->actual()?->zona_horaria ?: 'UTC');

        return (int) abs((int) MovimientoWhatsAppTenant::query()
            ->where('tipo', 'consumo')
            ->where('created_at', '>=', CarbonImmutable::now($zona)->startOfMonth()->utc())
            ->sum('cantidad'));
    }

    /** Por qué un aviso (que gasta `$unidades` mensajes) no puede salir ahora; null si puede. */
    public function motivoParaNoEnviar(int $unidades = 1): ?string
    {
        if (! $this->cobra()) {
            return null;
        }
        $tope = $this->tope();
        if ($tope > 0 && $this->usadosEsteMes() >= $tope) {
            return "Llegaste al tope de {$tope} mensajes de WhatsApp de este mes.";
        }
        if ($this->disponibles() < max(1, $unidades)) {
            return 'Sin saldo de WhatsApp: compra un paquete de mensajes en Mi suscripción.';
        }

        return null;
    }

    /** Gasta los mensajes de un aviso enviado (una vez por aviso). */
    public function gastar(string $referencia, int $unidades = 1): void
    {
        if (! $this->cobra()) {
            return;
        }
        DB::connection('tenant')->transaction(function () use ($referencia, $unidades): void {
            $saldo = $this->bloquear();
            if (MovimientoWhatsAppTenant::query()->where('tipo', 'consumo')->where('referencia', $referencia)->exists()) {
                return;
            }
            $this->mover($saldo, 'consumo', -max(1, $unidades), $referencia, null);
        });
    }

    /** Suma los mensajes de una compra pagada (una sola vez por referencia). */
    public function acreditar(int $cantidad, string $referencia, string $detalle): void
    {
        DB::connection('tenant')->transaction(function () use ($cantidad, $referencia, $detalle): void {
            $saldo = $this->bloquear();
            if (MovimientoWhatsAppTenant::query()->where('tipo', 'compra')->where('referencia', $referencia)->exists()) {
                return;
            }
            $this->mover($saldo, 'compra', $cantidad, $referencia, $detalle);
        });
    }

    /**
     * Los avisos por WhatsApp que el negocio puede mandar (las plantillas aprobadas de
     * la plataforma), con su texto y si los tiene encendidos.
     *
     * @return list<array{evento: string, titulo: string, texto: string, categoria: string, activo: bool}>
     */
    public function plantillas(): array
    {
        $modalidad = $this->gestor->actual()?->modalidad();
        $activos = PlantillaMensajeTenant::query()
            ->where('canal', CanalComunicacion::WhatsApp->value)
            ->where('activo', true)
            ->pluck('clave')
            ->map(static fn (mixed $e): string => (string) $e)
            ->flip();

        return collect(PlantillasWhatsApp::eventos())
            ->map(static function (string $evento) use ($modalidad, $activos): ?array {
                $plantilla = PlantillasWhatsApp::para($evento, $modalidad);

                return $plantilla === null ? null : [
                    'evento' => $evento,
                    'titulo' => $plantilla['titulo'],
                    'texto' => $plantilla['texto'],
                    'categoria' => 'utilidad',
                    'activo' => $activos->has($evento),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function bloquear(): SaldoWhatsAppTenant
    {
        $saldo = SaldoWhatsAppTenant::query()->orderBy('id')->lockForUpdate()->first();

        return $saldo ?? SaldoWhatsAppTenant::query()->create(['disponibles' => 0]);
    }

    private function mover(SaldoWhatsAppTenant $saldo, string $tipo, int $cantidad, ?string $referencia, ?string $detalle): void
    {
        $saldo->disponibles = max(0, $saldo->disponibles + $cantidad);
        $saldo->save();
        MovimientoWhatsAppTenant::query()->create([
            'tipo' => $tipo,
            'cantidad' => $cantidad,
            'saldo_despues' => $saldo->disponibles,
            'referencia' => $referencia === null ? null : mb_substr($referencia, 0, 64),
            'detalle' => $detalle,
        ]);
    }
}
