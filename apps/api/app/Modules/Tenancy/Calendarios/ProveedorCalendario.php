<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Calendarios;

use Carbon\CarbonImmutable;

/**
 * Un proveedor de calendario (ADR 0199): Google Calendar u Outlook (Microsoft Graph).
 * Autoriza con OAuth (con el cliente de la plataforma), crea el calendario del negocio en
 * la cuenta de la persona, publica sus clases y citas ahí y lee lo ocupado de su
 * calendario principal. Todo por HTTP, sin paquetes.
 */
interface ProveedorCalendario
{
    public function configurado(): bool;

    public function urlAutorizacion(string $estado, string $vuelta): string;

    /**
     * @return array{refresco: string, acceso: string, vence_en: CarbonImmutable, cuenta: string|null}
     */
    public function canjearCodigo(string $codigo, string $vuelta): array;

    /**
     * @return array{acceso: string, vence_en: CarbonImmutable, refresco: string|null}
     */
    public function refrescar(string $refresco): array;

    public function crearCalendario(string $acceso, string $nombre, string $zona): string;

    /** Quita el calendario del negocio de su cuenta (al desconectar). */
    public function borrarCalendario(string $acceso, string $calendario): void;

    /**
     * Crea o actualiza un evento; devuelve su id.
     *
     * @param  array{titulo: string, inicio: CarbonImmutable, fin: CarbonImmutable, lugar: string, descripcion: string}  $evento
     */
    public function guardarEvento(string $acceso, string $calendario, ?string $eventoId, array $evento): string;

    public function borrarEvento(string $acceso, string $calendario, string $eventoId): void;

    /**
     * Lo ocupado en su calendario principal (UTC).
     *
     * @return list<array{desde: CarbonImmutable, hasta: CarbonImmutable}>
     */
    public function ocupado(string $acceso, CarbonImmutable $desde, CarbonImmutable $hasta): array;

    /**
     * Se suscribe a los cambios de su calendario principal: le avisa a `$direccion`
     * con `$secreto`. Con un aviso anterior, lo renueva o lo reemplaza.
     *
     * @return array{id: string, recurso: string|null, vence_en: CarbonImmutable}
     */
    public function vigilar(string $acceso, ?string $avisoId, ?string $recurso, string $direccion, string $secreto): array;

    /** Deja de recibir sus avisos (sin fallar si ya no existían). */
    public function dejarDeVigilar(string $acceso, string $avisoId, ?string $recurso): void;
}
