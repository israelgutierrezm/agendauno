<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Integraciones\ConsultaDns;
use App\Modules\Tenancy\Models\DominioEstudio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El dominio propio de un negocio (ADR 0189): su sitio en `reservas.minegocio.com`.
 *
 * - El dueño lo escribe; se guarda con un token. Uno por negocio y un dominio no puede
 *   ser de dos negocios ni de la plataforma.
 * - Prueba que es suyo con un TXT en `_agendauno.{dominio}` (una vez) y lo apunta a la
 *   plataforma con un CNAME a `sitios.{dominio de su producto}` o, en la raíz de su
 *   dominio, con registros A a las IPs de la plataforma. Que apunte se revisa cada vez:
 *   si deja de apuntar, deja de servirse.
 * - Se sirve (resuelve, canónica, certificado) solo con el sitio avanzado de su plan
 *   (`sitio_avanzado`, «Plus»).
 */
class DominiosPropios
{
    private const CACHE_HOST = 'dominio-propio:host:';

    private const CACHE_ESTUDIO = 'dominio-propio:estudio:';

    // Terminaciones que no son de internet (RFC 2606 y 6761).
    private const RESERVADOS = ['localhost', 'local', 'internal', 'test', 'invalid', 'example', 'onion', 'arpa', 'lan', 'home'];

    public function __construct(private readonly FuncionesPlan $funciones) {}

    public function deEstudio(Estudio $estudio): ?DominioEstudio
    {
        return DominioEstudio::query()->where('estudio_id', $estudio->getKey())->first();
    }

    /**
     * Lo que escribe el dueño, como dominio: sin esquema, ruta, puerto ni punto final.
     * Null si no es un dominio de internet o es de la plataforma.
     */
    public function normalizar(string $valor): ?string
    {
        $dominio = strtolower(trim($valor));
        $dominio = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $dominio);
        $dominio = (string) preg_replace('#[/?\#].*$#s', '', $dominio);
        $dominio = rtrim((string) preg_replace('#:\d*$#', '', $dominio), '.');
        if (strlen($dominio) > 253
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $dominio) !== 1) {
            return null;
        }
        $terminacion = substr((string) strrchr($dominio, '.'), 1);
        if (in_array($terminacion, self::RESERVADOS, true) || $this->esDeLaPlataforma($dominio)) {
            return null;
        }

        return $dominio;
    }

    /** Conecta (o cambia) el dominio del negocio. Queda pendiente hasta revisarlo. */
    public function conectar(Estudio $estudio, string $valor): DominioEstudio
    {
        $dominio = $this->normalizar($valor)
            ?? throw ValidationException::withMessages(['dominio' => ['Escribe un dominio como reservas.tunegocio.com.']]);
        $deOtro = DominioEstudio::query()->where('dominio', $dominio)->where('estudio_id', '!=', $estudio->getKey())->exists();
        if ($deOtro) {
            throw ValidationException::withMessages(['dominio' => ['Ese dominio ya está conectado a otro negocio.']]);
        }

        $anterior = $this->deEstudio($estudio);
        if ($anterior !== null && $anterior->dominio === $dominio) {
            return $anterior;
        }

        $nuevo = DB::transaction(function () use ($estudio, $dominio, $anterior): DominioEstudio {
            $anterior?->delete();

            return DominioEstudio::query()->create([
                'estudio_id' => $estudio->getKey(),
                'dominio' => $dominio,
                'token' => bin2hex(random_bytes(16)),
            ]);
        });
        $this->olvidar($estudio, $anterior?->dominio);

        return $nuevo;
    }

    public function quitar(Estudio $estudio): ?string
    {
        $actual = $this->deEstudio($estudio);
        if ($actual === null) {
            return null;
        }
        $actual->delete();
        $this->olvidar($estudio, $actual->dominio);

        return $actual->dominio;
    }

    /**
     * Revisa sus registros y deja qué falta (`problema`): sin el TXT, con otro TXT o sin
     * apuntar a la plataforma. Que es suyo queda probado una vez; que apunta, no.
     */
    public function revisar(DominioEstudio $dominio): DominioEstudio
    {
        $estudio = $dominio->estudio ?? throw new \RuntimeException('El dominio no tiene negocio.');
        $ahora = now();

        $textos = array_map(fn (string $t): string => trim($t, " \t\""), $this->dns()->txt('_agendauno.'.$dominio->dominio));
        $esSuyo = $dominio->propiedad_verificada_en !== null || in_array($this->valorTxt($dominio), $textos, true);
        $apunta = $this->apunta($estudio, $dominio->dominio);

        $dominio->forceFill([
            'propiedad_verificada_en' => $esSuyo ? ($dominio->propiedad_verificada_en ?? $ahora) : null,
            'apunta_desde' => $apunta ? ($dominio->apunta_desde ?? $ahora) : null,
            'revisado_en' => $ahora,
            'problema' => match (true) {
                ! $esSuyo => $textos === [] ? 'sin_txt' : 'txt_distinto',
                ! $apunta => 'no_apunta',
                default => null,
            },
        ])->save();
        $this->olvidar($estudio, $dominio->dominio);

        return $dominio;
    }

    /** El valor del TXT con que prueba que es suyo. */
    public function valorTxt(DominioEstudio $dominio): string
    {
        return 'agendauno-verificacion='.$dominio->token;
    }

    /** A dónde apunta su CNAME: `sitios.{dominio de su producto}` o el fijado. */
    public function destino(Estudio $estudio): string
    {
        $fijo = strtolower(trim((string) config('agendauno.dominios_propios.destino')));

        return $fijo !== '' ? $fijo : 'sitios.'.$estudio->producto()->dominio();
    }

    /**
     * Los registros que debe crear en su DNS.
     *
     * @return list<array{tipo: string, nombre: string, valor: string}>
     */
    public function registros(Estudio $estudio, DominioEstudio $dominio): array
    {
        $registros = [['tipo' => 'TXT', 'nombre' => '_agendauno.'.$dominio->dominio, 'valor' => $this->valorTxt($dominio)]];
        $ips = $this->ipsFijas();
        // En la raíz de su dominio (sin subdominio) muchos proveedores no admiten CNAME.
        if (substr_count($dominio->dominio, '.') === 1 && $ips !== []) {
            foreach ($ips as $ip) {
                $registros[] = ['tipo' => 'A', 'nombre' => $dominio->dominio, 'valor' => $ip];
            }

            return $registros;
        }
        $registros[] = ['tipo' => 'CNAME', 'nombre' => $dominio->dominio, 'valor' => $this->destino($estudio)];

        return $registros;
    }

    /**
     * El negocio de un dominio propio que se sirve (suyo, apuntando y con su plan), o
     * null. Con caché corta: se resuelve en cada página.
     */
    public function estudioDelHost(string $host): ?Estudio
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || $this->esDeLaPlataforma($host)) {
            return null;
        }
        $id = (int) Cache::remember(self::CACHE_HOST.$host, 300, function () use ($host): int {
            $dominio = DominioEstudio::query()->where('dominio', $host)->first();

            return $dominio !== null && $dominio->activo() ? $dominio->estudio_id : 0;
        });
        $estudio = $id > 0 ? Estudio::query()->find($id) : null;

        return $estudio instanceof Estudio && $this->funciones->tiene($estudio, 'sitio_avanzado') ? $estudio : null;
    }

    /** El dominio propio con que se sirve el negocio, o null (su subdominio). */
    public function servidoDe(Estudio $estudio): ?string
    {
        $dominio = (string) Cache::remember(self::CACHE_ESTUDIO.$estudio->getKey(), 300, function () use ($estudio): string {
            $actual = $this->deEstudio($estudio);

            return $actual !== null && $actual->activo() ? $actual->dominio : '';
        });

        return $dominio !== '' && $this->funciones->tiene($estudio, 'sitio_avanzado') ? $dominio : null;
    }

    /**
     * La configuración dinámica de Traefik con los dominios que se sirven: un router por
     * dominio con su certificado por HTTP-01. Los dominios ya pasaron `normalizar`.
     */
    public function configuracionTraefik(): string
    {
        $config = config('agendauno.dominios_propios');
        $routers = [];
        $activos = DominioEstudio::query()->whereNotNull('propiedad_verificada_en')->whereNotNull('apunta_desde')
            ->with('estudio')->orderBy('dominio')->get();
        foreach ($activos as $dominio) {
            if (! $dominio->estudio instanceof Estudio || ! $this->funciones->tiene($dominio->estudio, 'sitio_avanzado')) {
                continue;
            }
            $routers[] = '    agendauno-propio-'.strtolower((string) $dominio->ulid).":\n"
                ."      rule: \"Host(`{$dominio->dominio}`)\"\n"
                ."      entryPoints: [\"{$config['entrada_traefik']}\"]\n"
                ."      service: \"{$config['servicio_traefik']}\"\n"
                ."      tls:\n"
                ."        certResolver: \"{$config['certresolver']}\"\n";
        }

        return "# Generado por agendauno:dominios-propios (ADR 0189). No editar a mano.\n"
            ."http:\n"
            .($routers === [] ? "  routers: {}\n" : "  routers:\n".implode('', $routers));
    }

    /**
     * Escribe la configuración de Traefik si cambió. False si no hay archivo configurado
     * o no cambió.
     */
    public function escribirTraefik(): bool
    {
        $archivo = trim((string) config('agendauno.dominios_propios.archivo_traefik'));
        if ($archivo === '') {
            return false;
        }
        $contenido = $this->configuracionTraefik();
        if (is_file($archivo) && file_get_contents($archivo) === $contenido) {
            return false;
        }
        // Traefik vigila el directorio: se escribe aparte y se mueve de una vez.
        $temporal = $archivo.'.tmp';
        file_put_contents($temporal, $contenido);
        rename($temporal, $archivo);

        return true;
    }

    private function apunta(Estudio $estudio, string $dominio): bool
    {
        $cname = $this->dns()->cname($dominio);
        if ($cname !== null) {
            return $cname === $this->destino($estudio);
        }
        $esperadas = $this->ipsFijas();
        if ($esperadas === []) {
            $esperadas = $this->dns()->ips($this->destino($estudio));
        }
        $suyas = $this->dns()->ips($dominio);

        return $suyas !== [] && $esperadas !== [] && array_diff($suyas, $esperadas) === [];
    }

    // Del contenedor en cada consulta: el servicio vive tanto como su controlador.
    private function dns(): ConsultaDns
    {
        return app(ConsultaDns::class);
    }

    /**
     * @return list<string>
     */
    private function ipsFijas(): array
    {
        $ips = config('agendauno.dominios_propios.ips');

        return is_array($ips) ? array_values(array_filter($ips, 'is_string')) : [];
    }

    private function esDeLaPlataforma(string $host): bool
    {
        foreach (ProductoComercial::cases() as $producto) {
            $dominio = $producto->dominio();
            if ($dominio !== '' && ($host === $dominio || str_ends_with($host, '.'.$dominio))) {
                return true;
            }
        }

        return false;
    }

    private function olvidar(Estudio $estudio, ?string $dominio): void
    {
        Cache::forget(self::CACHE_ESTUDIO.$estudio->getKey());
        if ($dominio !== null) {
            Cache::forget(self::CACHE_HOST.$dominio);
        }
        $actual = $this->deEstudio($estudio);
        if ($actual !== null) {
            Cache::forget(self::CACHE_HOST.$actual->dominio);
        }
    }
}
