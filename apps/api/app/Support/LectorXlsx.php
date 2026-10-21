<?php

declare(strict_types=1);

namespace App\Support;

use DomainException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lee la primera hoja de un archivo de Excel (.xlsx) como una tabla de textos, sin
 * dependencias: un .xlsx es un zip con XML. Sirve para los reportes que dan los portales
 * de las plataformas (el de Wellhub es un .xlsx, ADR 0202). Las celdas vacías se
 * respetan por su columna; una fecha de Excel llega como su número de serie.
 */
final class LectorXlsx
{
    /** Lo más grande que se descomprime de la hoja (protege de un zip inflado). */
    private const MAXIMO_BYTES = 40 * 1024 * 1024;

    /**
     * @return list<list<string>>
     */
    public static function filas(string $ruta, int $maximoFilas): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException('Este servidor no lee archivos de Excel: guárdalo como CSV.');
        }
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new DomainException('El archivo de Excel no se pudo abrir.');
        }
        try {
            $compartidos = self::compartidos($zip);
            $hoja = self::leerParte($zip, self::primeraHoja($zip));
            if ($hoja === null) {
                throw new DomainException('El archivo de Excel no trae hojas.');
            }
            $xml = self::xml($hoja);
            $filas = [];
            foreach ($xml->sheetData->row ?? [] as $fila) {
                if (count($filas) > $maximoFilas) {
                    break;
                }
                $celdas = [];
                foreach ($fila->c as $celda) {
                    $columna = self::columna((string) $celda['r']);
                    $tipo = (string) $celda['t'];
                    $valor = match ($tipo) {
                        's' => $compartidos[(int) $celda->v] ?? '',
                        'inlineStr' => self::texto($celda->is),
                        default => (string) $celda->v,
                    };
                    $celdas[$columna ?? count($celdas)] = trim($valor);
                }
                if ($celdas === []) {
                    continue;
                }
                $ultima = max(array_keys($celdas));
                $completa = [];
                for ($i = 0; $i <= $ultima; $i++) {
                    $completa[] = $celdas[$i] ?? '';
                }
                $filas[] = $completa;
            }

            return $filas;
        } finally {
            $zip->close();
        }
    }

    /**
     * Los textos compartidos (Excel guarda cada texto una vez y las celdas lo señalan).
     *
     * @return list<string>
     */
    private static function compartidos(ZipArchive $zip): array
    {
        $contenido = self::leerParte($zip, 'xl/sharedStrings.xml');
        if ($contenido === null) {
            return [];
        }
        $textos = [];
        foreach (self::xml($contenido)->si as $si) {
            $textos[] = self::texto($si);
        }

        return $textos;
    }

    /** La ruta de la primera hoja según el libro (casi siempre sheet1). */
    private static function primeraHoja(ZipArchive $zip): string
    {
        $relaciones = self::leerParte($zip, 'xl/_rels/workbook.xml.rels');
        $libro = self::leerParte($zip, 'xl/workbook.xml');
        if ($relaciones !== null && $libro !== null) {
            $hoja = self::xml($libro)->sheets->sheet[0] ?? null;
            $id = $hoja?->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? null;
            foreach (self::xml($relaciones)->Relationship as $relacion) {
                if ($id !== null && (string) $relacion['Id'] === (string) $id) {
                    $destino = ltrim((string) $relacion['Target'], '/');

                    return str_starts_with($destino, 'xl/') ? $destino : 'xl/'.$destino;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function leerParte(ZipArchive $zip, string $nombre): ?string
    {
        $info = $zip->statName($nombre);
        if ($info === false) {
            return null;
        }
        if ($info['size'] > self::MAXIMO_BYTES) {
            throw new DomainException('El archivo de Excel es demasiado grande: divídelo por mes.');
        }
        $contenido = $zip->getFromName($nombre);

        return $contenido === false ? null : $contenido;
    }

    private static function xml(string $contenido): SimpleXMLElement
    {
        // Sin red ni entidades externas.
        $xml = simplexml_load_string($contenido, SimpleXMLElement::class, LIBXML_NONET);
        if (! $xml instanceof SimpleXMLElement) {
            throw new DomainException('El archivo de Excel está dañado.');
        }

        return $xml;
    }

    /** El texto de una celda o de un texto compartido (con o sin formato). */
    private static function texto(?SimpleXMLElement $nodo): string
    {
        if ($nodo === null) {
            return '';
        }
        if (isset($nodo->t)) {
            return (string) $nodo->t;
        }
        $texto = '';
        foreach ($nodo->r as $parte) {
            $texto .= (string) $parte->t;
        }

        return $texto;
    }

    /** «C7» → 2 (la columna, desde 0). */
    private static function columna(string $referencia): ?int
    {
        if (preg_match('/^([A-Z]+)\d+$/', strtoupper($referencia), $m) !== 1) {
            return null;
        }
        $numero = 0;
        foreach (str_split($m[1]) as $letra) {
            $numero = $numero * 26 + (ord($letra) - 64);
        }

        return $numero - 1;
    }
}
