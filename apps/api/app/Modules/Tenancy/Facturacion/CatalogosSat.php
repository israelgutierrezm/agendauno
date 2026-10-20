<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

/**
 * Las claves de los catálogos del SAT (CFDI 4.0) que el negocio elige al facturar: un
 * uso del CFDI o una forma de pago fuera del catálogo se rechaza aquí, antes de
 * mandarla a timbrar. Las descripciones viven en la web (`lib/catalogosSat.ts`).
 */
final class CatalogosSat
{
    /**
     * El concepto de un CFDI de anticipo (Anexo 20, apéndice de anticipos recibidos):
     * clave, unidad y descripción que fija el SAT.
     */
    public const ANTICIPO = [
        'descripcion' => 'Anticipo del bien o servicio',
        'clave_prod_serv' => '84111506',
        'clave_unidad' => 'ACT',
    ];

    /**
     * El concepto de una nota de crédito (CFDI de egreso, ADR 0180): la clave de
     * servicios de facturación y la unidad «actividad», como en el anticipo.
     */
    public const NOTA_DE_CREDITO = [
        'clave_prod_serv' => '84111506',
        'clave_unidad' => 'ACT',
    ];

    /** El uso del CFDI de una nota de crédito: devoluciones, descuentos o bonificaciones. */
    public const USO_NOTA_DE_CREDITO = 'G02';

    /** Motivos de cancelación que se piden (el 04 es de la factura global, que no hay). */
    public const MOTIVOS_CANCELACION = ['01', '02', '03'];

    /** La leyenda del CFDI por el resto de una operación con anticipo (remanente). */
    public const LEYENDA_REMANENTE = 'CFDI por remanente de un anticipo';

    /** Tipo de relación del CFDI por remanente con el de su anticipo. */
    public const RELACION_ANTICIPO = '07';

    /** c_UsoCFDI; los de personas físicas solamente, aparte. */
    public const USOS_CFDI = [
        'G01', 'G02', 'G03', 'I01', 'I02', 'I03', 'I04', 'I05', 'I06', 'I07', 'I08',
        'D01', 'D02', 'D03', 'D04', 'D05', 'D06', 'D07', 'D08', 'D09', 'D10',
        'S01', 'CP01', 'CN01',
    ];

    /** Usos que solo puede usar una persona física (RFC de 13 caracteres). */
    public const USOS_SOLO_FISICAS = ['D01', 'D02', 'D03', 'D04', 'D05', 'D06', 'D07', 'D08', 'D09', 'D10', 'CN01'];

    /** c_FormaPago. */
    public const FORMAS_PAGO = [
        '01', '02', '03', '04', '05', '06', '08', '12', '13', '14', '15', '17',
        '23', '24', '25', '26', '27', '28', '29', '30', '31', '99',
    ];
}
