<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\WhatsApp;

use App\Modules\Tenancy\CatalogoPaises;

/**
 * El celular de una persona en el formato que pide WhatsApp: solo dígitos, con la
 * clave del país y sin "+" (525512345678).
 *
 * - Con "+" (o "00") ya trae su lada: se toman los dígitos tal cual. Si viene como lo
 *   arman la web y el registro, «+<lada> <número>», el número se limpia como uno
 *   nacional: sin el 0 de marcación nacional y sin la lada si la repite
 *   («+593 0991234567» → 593991234567, «+52 52 55 1234 5678» → 525512345678).
 * - Sin "+", se le antepone la lada DEL NEGOCIO (la de su país, ADR 0103), salvo que ya
 *   empiece con ella y sea más largo que un número nacional de su país (más de 10
 *   dígitos en casi todos). Sin lada se usa la de la plataforma
 *   (`agendauno.whatsapp.lada`) como último respaldo. Un número nacional mide de 7 a
 *   12 dígitos (sin el 0 de marcación nacional); en México, 10.
 * - El prefijo "1" que México usaba para celulares (521…) ya no va.
 */
final class TelefonoWhatsApp
{
    /** La lada de México: sus números nacionales miden 10 dígitos y antes llevaban 521. */
    private const LADA_MEXICO = '52';

    /** Largo máximo de un número nacional (sin el 0 de marcación nacional). */
    private const LARGO_NACIONAL = 10;

    /**
     * Países cuyos números nacionales pasan de 10 dígitos (sus celulares, sobre todo):
     * Brasil, China, Alemania, Argentina (el 9 de los celulares), Italia, Indonesia y
     * Austria. Uno así que empiece con la lada no se toma como si ya la trajera
     * (p. ej. un celular de Brasil de la zona 55).
     */
    private const LARGO_NACIONAL_POR_LADA = ['55' => 11, '86' => 11, '49' => 11, '54' => 11, '39' => 11, '62' => 12, '43' => 13];

    /**
     * Ladas cuyo número lleva el 0 inicial también con la lada (Italia y el Vaticano,
     * San Marino, Costa de Marfil y Congo): ahí el 0 no es de marcación nacional.
     */
    private const CONSERVAN_CERO = ['39', '378', '225', '242'];

    public static function normalizar(?string $celular, ?string $lada = null): ?string
    {
        $celular = trim((string) $celular);
        if ($celular === '') {
            return null;
        }

        if (str_starts_with($celular, '+')) {
            $digitos = self::internacional($celular);
        } else {
            $digitos = self::conLada((string) preg_replace('/\D/', '', $celular), self::lada($lada));
            if ($digitos === null) {
                return null;
            }
        }
        if (strlen($digitos) === 13 && str_starts_with($digitos, self::LADA_MEXICO.'1')) {
            $digitos = self::LADA_MEXICO.substr($digitos, 3);
        }

        return strlen($digitos) >= 8 && strlen($digitos) <= 15 ? $digitos : null;
    }

    /**
     * Huella del número (HMAC con la llave de la app) para reconocerlo cuando contesta
     * sin guardarlo (ADR 0083). Acepta el número como lo manda Meta (`from`, solo
     * dígitos con lada, a veces 521… en México) o ya normalizado.
     */
    public static function huella(string $telefono): string
    {
        $digitos = (string) preg_replace('/\D/', '', $telefono);

        return hash_hmac('sha256', self::normalizar('+'.$digitos) ?? $digitos, (string) config('app.key'));
    }

    /**
     * Un número con "+". Con la lada aparte del número («+57 300…», «+44 (0) 7911…»),
     * el número se limpia como uno nacional de esa lada; si no, los dígitos tal cual.
     */
    private static function internacional(string $celular): string
    {
        $digitos = (string) preg_replace('/\D/', '', $celular);
        if (preg_match('/^\+\s*(\d{1,4})[\s\-.()]+(.*)$/', $celular, $partes) !== 1 || ! CatalogoPaises::esLada($partes[1])) {
            return $digitos;
        }
        $resto = trim($partes[2]);
        // El número ya traía su propio "+" (p. ej. «+52 +57 300…»): ese manda.
        if (str_starts_with($resto, '+')) {
            return self::internacional($resto);
        }
        $numero = (string) preg_replace('/\D/', '', $resto);
        // Con "00" (salida internacional) escrito en el número, ya trae su lada.
        if (str_starts_with($numero, '00')) {
            return substr($numero, 2);
        }

        return $partes[1].self::nacional($numero, $partes[1]);
    }

    /**
     * Un número capturado sin "+": con "00" (salida internacional) ya trae su lada; si
     * empieza con la lada y es más largo que uno nacional, también; si no, es nacional
     * y se le antepone.
     */
    private static function conLada(string $digitos, string $lada): ?string
    {
        if (str_starts_with($digitos, '00')) {
            return substr($digitos, 2);
        }
        if (self::repiteLada($digitos, $lada)) {
            return $digitos;
        }

        $digitos = self::sinCeroNacional($digitos, $lada);
        $largo = strlen($digitos);
        $valido = $lada === self::LADA_MEXICO ? $largo === 10 : $largo >= 7 && $largo <= 12;

        return $valido ? $lada.$digitos : null;
    }

    /**
     * El número nacional de una lada, como se escribió junto a ella: sin la lada si la
     * repite y sin el 0 de marcación nacional.
     */
    private static function nacional(string $numero, string $lada): string
    {
        if (self::repiteLada($numero, $lada)) {
            $numero = substr($numero, strlen($lada));
        }

        return self::sinCeroNacional($numero, $lada);
    }

    /**
     * ¿El número empieza con la lada y es más largo que uno nacional de ese país? Entonces
     * ya la trae (lo que queda mide al menos 7 dígitos).
     */
    private static function repiteLada(string $numero, string $lada): bool
    {
        return str_starts_with($numero, $lada)
            && strlen($numero) > (self::LARGO_NACIONAL_POR_LADA[$lada] ?? self::LARGO_NACIONAL)
            && strlen($numero) - strlen($lada) >= 7;
    }

    /** Sin el 0 de marcación nacional (p. ej. 011… o 07…), salvo donde el 0 es del número. */
    private static function sinCeroNacional(string $numero, string $lada): string
    {
        return str_starts_with($numero, '0') && ! in_array($lada, self::CONSERVAN_CERO, true)
            ? substr($numero, 1)
            : $numero;
    }

    /** La lada que se da (solo dígitos) o, sin ella, la de la plataforma. */
    private static function lada(?string $lada): string
    {
        $lada = (string) preg_replace('/\D/', '', (string) $lada);
        if ($lada === '') {
            $lada = (string) preg_replace('/\D/', '', (string) config('agendauno.whatsapp.lada', self::LADA_MEXICO));
        }

        return $lada !== '' ? $lada : self::LADA_MEXICO;
    }
}
