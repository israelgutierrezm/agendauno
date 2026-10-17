<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;

/**
 * El contenido de la página pública de un negocio en HTML, para quien no ejecuta
 * JavaScript (buscadores; ADR 0155). nginx lo pone con SSI dentro de `#app` y la web lo
 * reemplaza al cargar. Solo texto escapado de lo que el negocio publica: su nombre y
 * descripción, lo que escribió en su sitio, sus servicios o clases con precio, próximas
 * clases, su equipo y sus sucursales con dirección, teléfono y horario.
 */
final class CuerpoPaginaNegocio
{
    private const MAX_SERVICIOS = 60;

    private const MAX_EQUIPO = 30;

    private const MAX_SESIONES = 10;

    private const DIAS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

    /**
     * @param  array{pagina: string, propia: string|null}  $pagina
     * @param  array<string, mixed>  $datos  los de `DatosPublicosNegocio`
     */
    public function html(Estudio $estudio, array $pagina, array $datos): string
    {
        $citas = $estudio->modalidad() === ModalidadServicio::Citas;
        $nombre = (string) $estudio->nombre;
        $sitio = is_array($datos['sitio'] ?? null) ? $datos['sitio'] : [];

        if ($pagina['pagina'] === 'propia') {
            foreach ((array) ($sitio['paginas'] ?? []) as $p) {
                if (is_array($p) && ($p['slug'] ?? null) === $pagina['propia']) {
                    return $this->envolver(
                        '<h1>'.$this->e((string) ($p['titulo'] ?? '')).'</h1>'
                        .'<p>'.$this->e($nombre).'</p>'
                        .$this->secciones((array) ($p['secciones'] ?? [])),
                    );
                }
            }

            return '';
        }

        $partes = ['<h1>'.$this->e($nombre).'</h1>'];
        $descripcion = trim((string) $estudio->descripcion);
        if ($descripcion !== '') {
            $partes[] = '<p>'.$this->e($descripcion).'</p>';
        }

        $partes[] = match ($pagina['pagina']) {
            'sucursales' => $this->sucursales($datos),
            'agendar' => $this->servicios($datos, true),
            'enlaces' => $this->redes($datos),
            default => $this->secciones((array) ($sitio['secciones'] ?? []))
                .$this->servicios($datos, $citas)
                .($citas ? '' : $this->proximas($datos))
                .$this->equipo($datos, $citas)
                .$this->sucursales($datos),
        };

        return $this->envolver(implode('', $partes));
    }

    private function envolver(string $contenido): string
    {
        return '<main class="pagina-publica-texto">'.$contenido.'</main>';
    }

    /**
     * Lo que el negocio escribió en su sitio: títulos, textos, preguntas, beneficios y
     * testimonios.
     *
     * @param  array<int, mixed>  $secciones
     */
    private function secciones(array $secciones): string
    {
        $html = '';
        foreach ($secciones as $s) {
            if (! is_array($s)) {
                continue;
            }
            $titulo = self::texto($s['titulo'] ?? null);
            $texto = self::texto($s['texto'] ?? null);
            $items = array_values(array_filter((array) (($s['datos'] ?? [])['items'] ?? []), 'is_array'));
            $cuerpo = $texto !== null ? '<p>'.$this->e($texto).'</p>' : '';
            $cuerpo .= match ($s['tipo'] ?? null) {
                'preguntas' => $items === [] ? '' : '<dl>'.implode('', array_map(
                    fn (array $i): string => '<dt>'.$this->e((string) ($i['pregunta'] ?? '')).'</dt><dd>'.$this->e((string) ($i['respuesta'] ?? '')).'</dd>',
                    $items,
                )).'</dl>',
                'beneficios' => $items === [] ? '' : '<ul>'.implode('', array_map(
                    fn (array $i): string => '<li><strong>'.$this->e((string) ($i['titulo'] ?? '')).'</strong> '.$this->e((string) ($i['texto'] ?? '')).'</li>',
                    $items,
                )).'</ul>',
                'testimonios' => implode('', array_map(
                    fn (array $i): string => '<blockquote><p>'.$this->e((string) ($i['texto'] ?? '')).'</p>'
                        .(self::texto($i['nombre'] ?? null) !== null ? '<footer>'.$this->e((string) $i['nombre']).'</footer>' : '').'</blockquote>',
                    $items,
                )),
                default => '',
            };
            if ($titulo === null && $cuerpo === '') {
                continue;
            }
            $html .= '<section>'.($titulo !== null ? '<h2>'.$this->e($titulo).'</h2>' : '').$cuerpo.'</section>';
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function servicios(array $datos, bool $citas): string
    {
        $servicios = array_slice(array_values(array_filter((array) ($datos['servicios'] ?? []), 'is_array')), 0, self::MAX_SERVICIOS);
        if ($servicios === []) {
            return '';
        }
        $filas = array_map(function (array $o): string {
            $detalle = array_filter([
                self::texto($o['categoria'] ?? null),
                ($o['duracion_minutos'] ?? 0) > 0 ? ((int) $o['duracion_minutos']).' min' : null,
                ($o['precio_minor'] ?? 0) > 0 ? self::dinero((int) $o['precio_minor'], (string) ($o['moneda'] ?? '')) : null,
            ]);
            $descripcion = self::texto($o['descripcion'] ?? null);

            return '<li><strong>'.$this->e((string) ($o['nombre'] ?? '')).'</strong>'
                .($detalle !== [] ? ' · '.$this->e(implode(' · ', $detalle)) : '')
                .($descripcion !== null ? '. '.$this->e($descripcion) : '').'</li>';
        }, $servicios);

        return '<section><h2>'.($citas ? 'Servicios' : 'Clases').'</h2><ul>'.implode('', $filas).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function proximas(array $datos): string
    {
        $sesiones = array_slice(array_values(array_filter((array) ($datos['proximas_sesiones'] ?? []), 'is_array')), 0, self::MAX_SESIONES);
        if ($sesiones === []) {
            return '';
        }
        $filas = array_map(function (array $s): string {
            $momento = CarbonImmutable::parse((string) $s['inicia_en'])->setTimezone((string) ($s['zona_horaria'] ?? 'UTC'))->locale('es');

            return '<li>'.$this->e(implode(' · ', array_filter([
                (string) ($s['clase'] ?? ''),
                $momento->isoFormat('ddd D MMM, HH:mm'),
                self::texto($s['instructor'] ?? null),
                self::texto($s['sucursal'] ?? null),
            ]))).'</li>';
        }, $sesiones);

        return '<section><h2>Próximas clases</h2><ul>'.implode('', $filas).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function equipo(array $datos, bool $citas): string
    {
        $equipo = array_slice(array_values(array_filter((array) ($datos['instructores'] ?? []), 'is_array')), 0, self::MAX_EQUIPO);
        if ($equipo === []) {
            return '';
        }
        $filas = array_map(fn (array $u): string => '<li><strong>'.$this->e((string) ($u['nombre'] ?? '')).'</strong>'
            .(self::texto($u['semblanza'] ?? null) !== null ? '. '.$this->e((string) $u['semblanza']) : '').'</li>', $equipo);

        return '<section><h2>'.($citas ? 'Equipo' : 'Instructores').'</h2><ul>'.implode('', $filas).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function sucursales(array $datos): string
    {
        $sucursales = array_values(array_filter((array) ($datos['sucursales'] ?? []), 'is_array'));
        if ($sucursales === []) {
            return '';
        }
        $filas = array_map(function (array $s): string {
            $horario = array_map(
                static fn (array $d): string => (self::DIAS[(int) ($d['dia'] ?? 0)] ?? '').' '.$d['abre'].'–'.$d['cierra'],
                array_values(array_filter((array) ($s['horario'] ?? []), 'is_array')),
            );

            return '<li><strong>'.$this->e((string) ($s['nombre'] ?? '')).'</strong>'
                .$this->e(implode('', array_map(static fn (string $x): string => ' · '.$x, array_filter([
                    self::texto($s['direccion'] ?? null),
                    self::texto($s['region'] ?? null),
                    self::texto($s['telefono'] ?? null),
                    $horario !== [] ? 'Horario: '.implode(', ', $horario) : null,
                ])))).'</li>';
        }, $sucursales);

        return '<section><h2>'.(count($sucursales) === 1 ? 'Dónde estamos' : 'Sucursales').'</h2><ul>'.implode('', $filas).'</ul></section>';
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function redes(array $datos): string
    {
        $redes = array_values(array_filter((array) (($datos['estudio'] ?? [])['redes'] ?? []), 'is_array'));
        if ($redes === []) {
            return '';
        }

        return '<ul>'.implode('', array_map(
            fn (array $r): string => '<li><a href="'.$this->e((string) ($r['url'] ?? '')).'" rel="noopener">'.$this->e(ucfirst((string) ($r['red'] ?? $r['url'] ?? ''))).'</a></li>',
            $redes,
        )).'</ul>';
    }

    private static function texto(mixed $texto): ?string
    {
        return is_string($texto) && trim($texto) !== '' ? trim($texto) : null;
    }

    private static function dinero(int $minor, string $moneda): string
    {
        return '$'.number_format($minor / 100, 2, '.', ',').($moneda !== '' ? " {$moneda}" : '');
    }

    private function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
