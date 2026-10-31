<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

/**
 * El alta de cada negocio como organización de FacturAPI (ADR 0212), con la llave de
 * usuario de la plataforma: su organización, sus datos fiscales, su sello (CSD) y su
 * llave en vivo, con la que timbra a sus clientes. Y enviar un CFDI por correo.
 */
interface OrganizacionesCfdi
{
    /** Crea la organización y devuelve su id. */
    public function crearOrganizacion(string $llaveUsuario, string $nombre): string;

    /**
     * @param  array{nombre: string, razon_social: string, regimen_fiscal: string, codigo_postal: string}  $datos
     */
    public function actualizarDatosLegales(string $llaveUsuario, string $organizacion, array $datos): void;

    /**
     * Sube el certificado y la llave del sello (CSD) con su contraseña. Devuelve el RFC
     * que trae el certificado, si el proveedor lo dice (ADR 0228): si no es el capturado,
     * no se timbra.
     */
    public function subirSello(string $llaveUsuario, string $organizacion, string $certificado, string $llave, string $contrasena): ?string;

    /** Crea una llave en vivo de la organización (la anterior sigue sirviendo). */
    public function crearLlaveEnVivo(string $llaveUsuario, string $organizacion): string;

    /**
     * Si ya puede timbrar, lo que le falta (p. ej. firmar la carta manifiesto) y el RFC de
     * su sello, si el proveedor lo dice (ADR 0228).
     *
     * @return array{lista: bool, pendientes: list<string>, rfc: string|null}
     */
    public function estadoOrganizacion(string $llaveUsuario, string $organizacion): array;

    /** Pide a FacturAPI que mande el CFDI (PDF y XML) por correo. */
    public function enviarPorCorreo(string $llaveOrganizacion, string $facturaId, string $correo): void;
}
