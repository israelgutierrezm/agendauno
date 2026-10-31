<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

/**
 * El proveedor no reconoce la llave de la organización (401). FacturAPI borra las llaves
 * que pasan un año sin usarse: la organización y sus facturas siguen ahí, y con la llave
 * de usuario de la plataforma se crea otra (ADR 0228).
 */
class LlaveCfdiRechazada extends TimbradoFallido {}
