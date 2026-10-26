<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ModoCobroSaas;
use App\Modules\Tenancy\ProductoComercial;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Recibo de pago de la renta SIN VALOR FISCAL, en PDF de una página: el comprobante
 * de quien paga la renta y no puede recibir la factura (CFDI) — otra moneda, otro país
 * o la plataforma aún sin facturación. Se arma a mano (PDF 1.4 con Helvetica), sin
 * dependencias, como los documentos de muestra del demo.
 */
final class ReciboRentaPdf
{
    public const LEYENDA = 'Recibo sin valor fiscal';

    /** Nombres de las pasarelas con que se paga la renta. */
    private const PASARELAS = ['stripe' => 'Stripe', 'mercadopago' => 'Mercado Pago', 'openpay' => 'Openpay'];

    /**
     * Subtotal, impuesto y total de lo cobrado: los del desglose del cargo cuando cuadran
     * con el monto; si no (cuota fija, IVA incluido), se desglosan hacia atrás con la
     * misma tasa que la factura ({@see EmitirFacturaPlataforma::ivaPorcentaje()}).
     *
     * @return array{subtotal_minor: int, iva_porcentaje: int, impuesto_minor: int, total_minor: int}
     */
    public static function importes(CargoRenta $cargo): array
    {
        $total = $cargo->monto_minor;
        $iva = EmitirFacturaPlataforma::ivaPorcentaje($cargo);
        $desglose = is_array($cargo->desglose) ? $cargo->desglose : [];
        $subtotal = $desglose['subtotal_minor'] ?? null;
        $impuesto = $desglose['iva_minor'] ?? null;

        if ($cargo->modo_cobro !== ModoCobroSaas::Fijo && is_int($subtotal) && is_int($impuesto) && $subtotal + $impuesto === $total) {
            return ['subtotal_minor' => $subtotal, 'iva_porcentaje' => $iva, 'impuesto_minor' => $impuesto, 'total_minor' => $total];
        }

        $subtotal = intdiv($total * 100, 100 + $iva);

        return ['subtotal_minor' => $subtotal, 'iva_porcentaje' => $iva, 'impuesto_minor' => $total - $subtotal, 'total_minor' => $total];
    }

    /**
     * Qué se cobró: la suscripción del periodo, un cambio de plan o timbres, con la
     * marca del negocio (AgendaUno o TurnoUno, ADR 0131). Es también el concepto del
     * cargo en la pasarela y de la factura.
     */
    public static function concepto(CargoRenta $cargo): string
    {
        $marca = self::marca($cargo);
        $cubre = self::rango($cargo);
        $cuando = $cubre !== null ? $cubre[0]->toDateString().' al '.$cubre[1]->toDateString() : (string) $cargo->periodo;

        return match ($cargo->concepto) {
            'timbres' => "Timbres de facturación {$marca}",
            'whatsapp' => "Mensajes de WhatsApp {$marca}",
            'ajuste' => "Cambio de plan {$marca} {$cuando}",
            // Un cargo que hizo la plataforma a mano (ADR 0214): con su descripción.
            'manual' => is_array($cargo->desglose) && is_string($cargo->desglose['descripcion'] ?? null)
                ? (string) $cargo->desglose['descripcion']
                : "Servicio {$marca}",
            default => "Suscripción {$marca} {$cuando}",
        };
    }

    /**
     * Lo que cubre el cargo cuando no es su mes de calendario (un plan anual, un ajuste
     * por días sueltos); null si cubre el mes de su periodo o no lo dice.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private static function rango(CargoRenta $cargo): ?array
    {
        if ($cargo->cubre_desde === null || $cargo->cubre_hasta === null) {
            return null;
        }
        $desde = CarbonImmutable::parse($cargo->cubre_desde->toDateString());
        $hasta = CarbonImmutable::parse($cargo->cubre_hasta->toDateString());
        $delMes = $desde->format('Y-m') === (string) $cargo->periodo && $desde->day === 1
            && $hasta->toDateString() === $desde->endOfMonth()->toDateString();

        return $delMes ? null : [$desde, $hasta];
    }

    /** La marca del negocio del cargo; sin él, la de la plataforma. */
    private static function marca(CargoRenta $cargo): string
    {
        $estudio = $cargo->estudio;

        return $estudio instanceof Estudio ? $estudio->producto()->nombre() : ProductoComercial::AgendaUno->nombre();
    }

    public function generar(CargoRenta $cargo, Estudio $estudio): string
    {
        $zona = (string) ($estudio->zona_horaria ?: 'America/Mexico_City');
        $importes = self::importes($cargo);
        $moneda = strtoupper((string) $cargo->moneda);
        $dinero = static fn (int $minor): string => number_format($minor / 100, 2, '.', ',').' '.$moneda;
        $fecha = static fn (CarbonImmutable $f): string => $f->setTimezone($zona)->locale('es')->translatedFormat('j \d\e F \d\e Y');
        $inicio = CarbonImmutable::createFromFormat('Y-m-d', $cargo->periodo.'-01', $zona);
        $periodo = $inicio instanceof CarbonImmutable ? $inicio->locale('es')->translatedFormat('F \d\e Y') : (string) $cargo->periodo;
        $cubre = self::rango($cargo);
        if ($cubre !== null) {
            $dia = static fn (CarbonImmutable $f): string => $f->locale('es')->translatedFormat('j \d\e F \d\e Y');
            $periodo = $dia($cubre[0]).' al '.$dia($cubre[1]);
        }
        $pagado = $cargo->pagado_en !== null ? $fecha(CarbonImmutable::instance($cargo->pagado_en)) : '—';
        $metodo = (string) ($cargo->metodo_pago ?? '');

        $filas = [
            // Una línea: lo que no cabe en la página se recorta.
            ['Negocio', mb_strimwidth((string) $estudio->nombre, 0, 64, '…')],
            ['Folio', (string) $cargo->ulid],
            ['Periodo', $periodo],
            ['Concepto', self::concepto($cargo)],
            ['Fecha de pago', $pagado],
        ];
        if ($metodo !== '') {
            $filas[] = ['Forma de pago', self::PASARELAS[$metodo] ?? Str::headline($metodo)];
        }
        $importesFilas = [
            ['Subtotal', $dinero($importes['subtotal_minor'])],
            ["Impuesto (IVA {$importes['iva_porcentaje']} %)", $dinero($importes['impuesto_minor'])],
            ['Total', $dinero($importes['total_minor'])],
            ['Moneda', $moneda],
        ];

        $c = $this->texto('F2', 20, 72, 770, $estudio->producto()->nombre())
            .$this->texto('F1', 13, 72, 746, 'Recibo de pago de la suscripción')
            .$this->texto('F2', 11, 72, 726, self::LEYENDA)
            ."0.8 G 0.5 w 72 712 m 523 712 l S\n";
        $y = 688;
        foreach ($filas as [$etiqueta, $valor]) {
            $c .= $this->texto('F2', 10, 72, $y, $etiqueta).$this->texto('F1', 10, 190, $y, $valor);
            $y -= 20;
        }
        $y -= 6;
        $c .= "0.8 G 0.5 w 72 {$y} m 523 {$y} l S\n";
        $y -= 22;
        foreach ($importesFilas as [$etiqueta, $valor]) {
            $fuente = $etiqueta === 'Total' ? 'F2' : 'F1';
            $c .= $this->texto('F2', 10, 72, $y, $etiqueta).$this->texto($fuente, 10, 190, $y, $valor);
            $y -= 20;
        }
        $c .= $this->texto('F1', 9, 72, 90, 'Este documento no es una factura ni un comprobante fiscal.')
            .$this->texto('F1', 9, 72, 76, 'Emitido el '.$fecha(CarbonImmutable::now()).'.');

        return $this->documento($c);
    }

    /** Una línea de texto en la posición dada (puntos, origen abajo a la izquierda). */
    private function texto(string $fuente, int $tamano, int $x, int $y, string $texto): string
    {
        return "BT /{$fuente} {$tamano} Tf {$x} {$y} Td (".$this->literal($texto).") Tj ET\n";
    }

    /**
     * Cadena literal de PDF en WinAnsi (acentos y ñ); lo que no cabe en ella se
     * translitera.
     */
    private function literal(string $texto): string
    {
        $salida = '';
        foreach (mb_str_split($texto) as $caracter) {
            $convertido = mb_convert_encoding($caracter, 'Windows-1252', 'UTF-8');
            $salida .= $convertido === '?' && $caracter !== '?' ? Str::ascii($caracter) : $convertido;
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $salida);
    }

    /** Arma el PDF (una página A4) con el contenido dado. */
    private function documento(string $contenido): string
    {
        $contenido = rtrim($contenido);
        $fuente = static fn (string $base): string => "<< /Type /Font /Subtype /Type1 /BaseFont /{$base} /Encoding /WinAnsiEncoding >>";
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            $fuente('Helvetica'),
            $fuente('Helvetica-Bold'),
            '<< /Length '.strlen($contenido)." >>\nstream\n{$contenido}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $posiciones = [];
        foreach ($objetos as $i => $objeto) {
            $posiciones[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$objeto}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach ($posiciones as $p) {
            $pdf .= sprintf("%010d 00000 n \n", $p);
        }

        return $pdf.'trailer << /Size '.(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
