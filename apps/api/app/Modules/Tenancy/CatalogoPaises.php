<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Los países donde puede estar un negocio (ADR 0103): todos los códigos ISO 3166-1
 * alfa-2 con su lada internacional (UIT), solo dígitos y sin «+». Los territorios sin
 * lada propia usan la del país que les da servicio (p. ej. Bouvet, la de Noruega).
 * Incluye Kosovo (XK), de uso común aunque ISO no lo asigna.
 *
 * No depende de la extensión intl (la imagen de producción no la trae): los nombres
 * los pone quien muestra la lista (la web, con Intl.DisplayNames).
 */
final class CatalogoPaises
{
    /** El país de los negocios que no dijeron otro. */
    public const PREDETERMINADO = 'MX';

    /**
     * Código ISO 3166-1 alfa-2 => lada.
     *
     * @var array<string, string>
     */
    private const LADAS = [
        'AD' => '376', // Andorra
        'AE' => '971', // Emiratos Árabes Unidos
        'AF' => '93', // Afganistán
        'AG' => '1', // Antigua y Barbuda
        'AI' => '1', // Anguila
        'AL' => '355', // Albania
        'AM' => '374', // Armenia
        'AO' => '244', // Angola
        'AQ' => '672', // Antártida
        'AR' => '54', // Argentina
        'AS' => '1', // Samoa Americana
        'AT' => '43', // Austria
        'AU' => '61', // Australia
        'AW' => '297', // Aruba
        'AX' => '358', // Islas Åland
        'AZ' => '994', // Azerbaiyán
        'BA' => '387', // Bosnia y Herzegovina
        'BB' => '1', // Barbados
        'BD' => '880', // Bangladés
        'BE' => '32', // Bélgica
        'BF' => '226', // Burkina Faso
        'BG' => '359', // Bulgaria
        'BH' => '973', // Baréin
        'BI' => '257', // Burundi
        'BJ' => '229', // Benín
        'BL' => '590', // San Bartolomé
        'BM' => '1', // Bermudas
        'BN' => '673', // Brunéi
        'BO' => '591', // Bolivia
        'BQ' => '599', // Caribe neerlandés
        'BR' => '55', // Brasil
        'BS' => '1', // Bahamas
        'BT' => '975', // Bután
        'BV' => '47', // Isla Bouvet
        'BW' => '267', // Botsuana
        'BY' => '375', // Bielorrusia
        'BZ' => '501', // Belice
        'CA' => '1', // Canadá
        'CC' => '61', // Islas Cocos
        'CD' => '243', // República Democrática del Congo
        'CF' => '236', // República Centroafricana
        'CG' => '242', // Congo
        'CH' => '41', // Suiza
        'CI' => '225', // Côte d'Ivoire
        'CK' => '682', // Islas Cook
        'CL' => '56', // Chile
        'CM' => '237', // Camerún
        'CN' => '86', // China
        'CO' => '57', // Colombia
        'CR' => '506', // Costa Rica
        'CU' => '53', // Cuba
        'CV' => '238', // Cabo Verde
        'CW' => '599', // Curazao
        'CX' => '61', // Isla de Navidad
        'CY' => '357', // Chipre
        'CZ' => '420', // Chequia
        'DE' => '49', // Alemania
        'DJ' => '253', // Yibuti
        'DK' => '45', // Dinamarca
        'DM' => '1', // Dominica
        'DO' => '1', // República Dominicana
        'DZ' => '213', // Argelia
        'EC' => '593', // Ecuador
        'EE' => '372', // Estonia
        'EG' => '20', // Egipto
        'EH' => '212', // Sáhara Occidental
        'ER' => '291', // Eritrea
        'ES' => '34', // España
        'ET' => '251', // Etiopía
        'FI' => '358', // Finlandia
        'FJ' => '679', // Fiyi
        'FK' => '500', // Islas Malvinas
        'FM' => '691', // Micronesia
        'FO' => '298', // Islas Feroe
        'FR' => '33', // Francia
        'GA' => '241', // Gabón
        'GB' => '44', // Reino Unido
        'GD' => '1', // Granada
        'GE' => '995', // Georgia
        'GF' => '594', // Guayana Francesa
        'GG' => '44', // Guernsey
        'GH' => '233', // Ghana
        'GI' => '350', // Gibraltar
        'GL' => '299', // Groenlandia
        'GM' => '220', // Gambia
        'GN' => '224', // Guinea
        'GP' => '590', // Guadalupe
        'GQ' => '240', // Guinea Ecuatorial
        'GR' => '30', // Grecia
        'GS' => '500', // Islas Georgia del Sur y Sandwich del Sur
        'GT' => '502', // Guatemala
        'GU' => '1', // Guam
        'GW' => '245', // Guinea-Bisáu
        'GY' => '592', // Guyana
        'HK' => '852', // Hong Kong
        'HM' => '672', // Islas Heard y McDonald
        'HN' => '504', // Honduras
        'HR' => '385', // Croacia
        'HT' => '509', // Haití
        'HU' => '36', // Hungría
        'ID' => '62', // Indonesia
        'IE' => '353', // Irlanda
        'IL' => '972', // Israel
        'IM' => '44', // Isla de Man
        'IN' => '91', // India
        'IO' => '246', // Territorio Británico del Océano Índico
        'IQ' => '964', // Irak
        'IR' => '98', // Irán
        'IS' => '354', // Islandia
        'IT' => '39', // Italia
        'JE' => '44', // Jersey
        'JM' => '1', // Jamaica
        'JO' => '962', // Jordania
        'JP' => '81', // Japón
        'KE' => '254', // Kenia
        'KG' => '996', // Kirguistán
        'KH' => '855', // Camboya
        'KI' => '686', // Kiribati
        'KM' => '269', // Comoras
        'KN' => '1', // San Cristóbal y Nieves
        'KP' => '850', // Corea del Norte
        'KR' => '82', // Corea del Sur
        'KW' => '965', // Kuwait
        'KY' => '1', // Islas Caimán
        'KZ' => '7', // Kazajistán
        'LA' => '856', // Laos
        'LB' => '961', // Líbano
        'LC' => '1', // Santa Lucía
        'LI' => '423', // Liechtenstein
        'LK' => '94', // Sri Lanka
        'LR' => '231', // Liberia
        'LS' => '266', // Lesoto
        'LT' => '370', // Lituania
        'LU' => '352', // Luxemburgo
        'LV' => '371', // Letonia
        'LY' => '218', // Libia
        'MA' => '212', // Marruecos
        'MC' => '377', // Mónaco
        'MD' => '373', // Moldavia
        'ME' => '382', // Montenegro
        'MF' => '590', // San Martín (Francia)
        'MG' => '261', // Madagascar
        'MH' => '692', // Islas Marshall
        'MK' => '389', // Macedonia del Norte
        'ML' => '223', // Mali
        'MM' => '95', // Myanmar
        'MN' => '976', // Mongolia
        'MO' => '853', // Macao
        'MP' => '1', // Islas Marianas del Norte
        'MQ' => '596', // Martinica
        'MR' => '222', // Mauritania
        'MS' => '1', // Montserrat
        'MT' => '356', // Malta
        'MU' => '230', // Mauricio
        'MV' => '960', // Maldivas
        'MW' => '265', // Malaui
        'MX' => '52', // México
        'MY' => '60', // Malasia
        'MZ' => '258', // Mozambique
        'NA' => '264', // Namibia
        'NC' => '687', // Nueva Caledonia
        'NE' => '227', // Níger
        'NF' => '672', // Isla Norfolk
        'NG' => '234', // Nigeria
        'NI' => '505', // Nicaragua
        'NL' => '31', // Países Bajos
        'NO' => '47', // Noruega
        'NP' => '977', // Nepal
        'NR' => '674', // Nauru
        'NU' => '683', // Niue
        'NZ' => '64', // Nueva Zelanda
        'OM' => '968', // Omán
        'PA' => '507', // Panamá
        'PE' => '51', // Perú
        'PF' => '689', // Polinesia Francesa
        'PG' => '675', // Papúa Nueva Guinea
        'PH' => '63', // Filipinas
        'PK' => '92', // Pakistán
        'PL' => '48', // Polonia
        'PM' => '508', // San Pedro y Miquelón
        'PN' => '64', // Islas Pitcairn
        'PR' => '1', // Puerto Rico
        'PS' => '970', // Palestina
        'PT' => '351', // Portugal
        'PW' => '680', // Palaos
        'PY' => '595', // Paraguay
        'QA' => '974', // Catar
        'RE' => '262', // Reunión
        'RO' => '40', // Rumania
        'RS' => '381', // Serbia
        'RU' => '7', // Rusia
        'RW' => '250', // Ruanda
        'SA' => '966', // Arabia Saudita
        'SB' => '677', // Islas Salomón
        'SC' => '248', // Seychelles
        'SD' => '249', // Sudán
        'SE' => '46', // Suecia
        'SG' => '65', // Singapur
        'SH' => '290', // Santa Elena
        'SI' => '386', // Eslovenia
        'SJ' => '47', // Svalbard y Jan Mayen
        'SK' => '421', // Eslovaquia
        'SL' => '232', // Sierra Leona
        'SM' => '378', // San Marino
        'SN' => '221', // Senegal
        'SO' => '252', // Somalia
        'SR' => '597', // Surinam
        'SS' => '211', // Sudán del Sur
        'ST' => '239', // Santo Tomé y Príncipe
        'SV' => '503', // El Salvador
        'SX' => '1', // Sint Maarten
        'SY' => '963', // Siria
        'SZ' => '268', // Esuatini
        'TC' => '1', // Islas Turcas y Caicos
        'TD' => '235', // Chad
        'TF' => '262', // Territorios Australes Franceses
        'TG' => '228', // Togo
        'TH' => '66', // Tailandia
        'TJ' => '992', // Tayikistán
        'TK' => '690', // Tokelau
        'TL' => '670', // Timor-Leste
        'TM' => '993', // Turkmenistán
        'TN' => '216', // Túnez
        'TO' => '676', // Tonga
        'TR' => '90', // Turquía
        'TT' => '1', // Trinidad y Tobago
        'TV' => '688', // Tuvalu
        'TW' => '886', // Taiwán
        'TZ' => '255', // Tanzania
        'UA' => '380', // Ucrania
        'UG' => '256', // Uganda
        'UM' => '1', // Islas menores alejadas de EE. UU.
        'US' => '1', // Estados Unidos
        'UY' => '598', // Uruguay
        'UZ' => '998', // Uzbekistán
        'VA' => '39', // Ciudad del Vaticano
        'VC' => '1', // San Vicente y las Granadinas
        'VE' => '58', // Venezuela
        'VG' => '1', // Islas Vírgenes Británicas
        'VI' => '1', // Islas Vírgenes de EE. UU.
        'VN' => '84', // Vietnam
        'VU' => '678', // Vanuatu
        'WF' => '681', // Wallis y Futuna
        'WS' => '685', // Samoa
        'XK' => '383', // Kosovo
        'YE' => '967', // Yemen
        'YT' => '262', // Mayotte
        'ZA' => '27', // Sudáfrica
        'ZM' => '260', // Zambia
        'ZW' => '263', // Zimbabue
    ];

    /** ¿Es un país del catálogo? (sin importar mayúsculas ni espacios). */
    public static function existe(?string $codigo): bool
    {
        return array_key_exists(self::codigo($codigo), self::LADAS);
    }

    /** La lada de un país (solo dígitos), o null si no está en el catálogo. */
    public static function lada(?string $codigo): ?string
    {
        return self::LADAS[self::codigo($codigo)] ?? null;
    }

    /** ¿Es la lada de algún país? (solo dígitos: «57», «1»). */
    public static function esLada(string $lada): bool
    {
        return in_array($lada, self::LADAS, true);
    }

    /**
     * Los códigos, para validar.
     *
     * @return list<string>
     */
    public static function codigos(): array
    {
        return array_keys(self::LADAS);
    }

    /**
     * Para elegir un país: código y lada, por código.
     *
     * @return list<array{codigo: string, lada: string}>
     */
    public static function lista(): array
    {
        $lista = [];
        foreach (self::LADAS as $codigo => $lada) {
            $lista[] = ['codigo' => $codigo, 'lada' => $lada];
        }

        return $lista;
    }

    /** El código como se guarda: dos letras en mayúsculas. */
    public static function codigo(?string $codigo): string
    {
        return mb_strtoupper(trim((string) $codigo));
    }
}
