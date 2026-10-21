<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SucursalTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reserva con Google (ADR 0198), integración «redirect» del Actions Center: Google
 * muestra el botón de reservar en la ficha de cada sede y manda a la página del negocio
 * con un `rwg_token`; cuando esa visita termina en una reserva, se le avisa a Google
 * (conversión).
 *
 * - El negocio decide aparecer (con su página publicada). Cada sede con dirección y
 *   ubicación es una entidad de Google.
 * - Los feeds (entidades y acciones) se generan como JSON en el disco configurado; la
 *   plataforma los sube al SFTP que da Google.
 * - Sin el id de socio de la plataforma (`conversion_partner_id`) no se genera ni se
 *   avisa nada: Google aprueba a la plataforma como socio antes.
 */
class ReservaConGoogle
{
    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function configurada(): bool
    {
        return trim((string) config('agendauno.reserva_google.partner_id')) !== '';
    }

    /**
     * Lo que le falta a un negocio para aparecer, o [] si puede.
     *
     * @return list<string>
     */
    public function faltantes(Estudio $estudio): array
    {
        $faltan = [];
        if (! $estudio->paginaPublica()) {
            $faltan[] = 'pagina';
        }
        $sedes = $this->gestor->ejecutarEn($estudio, fn (): int => $this->sedesListas()->count());
        if ($sedes === 0) {
            $faltan[] = 'ubicacion';
        }

        return $faltan;
    }

    /**
     * Escribe los feeds de todos los negocios que aparecen. Devuelve cuántas sedes van.
     */
    public function generarFeeds(): int
    {
        if (! $this->configurada()) {
            return 0;
        }
        $entidades = [];
        $acciones = [];
        Estudio::query()
            ->whereNotNull('en_reserva_google_desde')
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->orderBy('id')
            ->each(function (Estudio $estudio) use (&$entidades, &$acciones): void {
                if (! $estudio->paginaPublica() || ! $this->gestor->baseDeDatosExiste($estudio)) {
                    return;
                }
                try {
                    $this->gestor->ejecutarEn($estudio, function () use ($estudio, &$entidades, &$acciones): void {
                        foreach ($this->sedesListas() as $sede) {
                            $id = $estudio->slug.'-'.strtolower((string) $sede->ulid);
                            $url = $this->urlDeReserva($estudio, $sede);
                            $entidades[] = [
                                'entity_id' => $id,
                                'name' => $estudio->nombre.($sede->nombre !== '' && $sede->nombre !== $estudio->nombre ? ' · '.$sede->nombre : ''),
                                'telephone' => (string) ($sede->telefono ?? ''),
                                'url' => $url,
                                'location' => [
                                    'latitude' => (float) $sede->latitud,
                                    'longitude' => (float) $sede->longitud,
                                    'address' => [
                                        'country' => (string) ($estudio->pais ?: 'MX'),
                                        'locality' => (string) ($estudio->ciudad ?? ''),
                                        'street_address' => (string) $sede->direccion,
                                    ],
                                ],
                            ];
                            $acciones[] = [
                                'entity_id' => $id,
                                'link_id' => $id.'-reservar',
                                'url' => $url,
                                'actions' => [['appointment_info' => (object) []]],
                            ];
                        }
                    });
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $marca = now()->getTimestamp();
        $disco = Storage::disk((string) config('agendauno.reserva_google.disco', 'local'));
        $carpeta = trim((string) config('agendauno.reserva_google.carpeta', 'reserva-google'), '/');
        $disco->put($carpeta.'/entity_'.$marca.'.json', self::feed($entidades, $marca));
        $disco->put($carpeta.'/action_'.$marca.'.json', self::feed($acciones, $marca));

        return count($entidades);
    }

    /**
     * Avisa a Google que una visita que llegó desde Reserva con Google terminó en una
     * reserva. Si Google no responde, se reporta y la reserva sigue igual.
     */
    public function conversion(string $rwgToken): bool
    {
        $token = trim($rwgToken);
        if (! $this->configurada() || $token === '' || strlen($token) > 1000) {
            return false;
        }
        try {
            return Http::timeout(10)->post((string) config('agendauno.reserva_google.url_conversion'), [
                'conversion_partner_id' => (string) config('agendauno.reserva_google.partner_id'),
                'rwg_token' => $token,
                // 2: reservó en el mismo negocio por el que llegó.
                'merchant_changed' => 2,
            ])->successful();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $datos
     */
    private static function feed(array $datos, int $marca): string
    {
        return (string) json_encode([
            'metadata' => [
                'processing_instruction' => 'PROCESS_AS_COMPLETE',
                'shard_number' => 0,
                'total_shards' => 1,
                'nonce' => (string) $marca,
                'generation_timestamp' => $marca,
            ],
            'data' => $datos,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Las sedes con dirección y ubicación (las que Google puede mostrar).
     *
     * @return Collection<int, SucursalTenant>
     */
    private function sedesListas(): Collection
    {
        return SucursalTenant::query()
            ->whereNotNull('latitud')
            ->whereNotNull('longitud')
            ->whereNotNull('direccion')
            ->where('direccion', '!=', '')
            ->orderBy('id')
            ->get();
    }

    private function urlDeReserva(Estudio $estudio, SucursalTenant $sede): string
    {
        $base = app(SeoPaginaNegocio::class)->urlBase($estudio);
        $ruta = $estudio->modalidad()->value === 'citas'
            ? '/agendar/'.$estudio->slug.'?sucursal='.$sede->ulid
            : '/estudio/'.$estudio->slug.'?sucursal='.$sede->ulid;

        return $base.$ruta;
    }
}
