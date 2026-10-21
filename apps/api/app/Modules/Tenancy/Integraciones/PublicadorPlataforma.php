<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use App\Modules\Tenancy\Application\PublicarEnPlataformasTenant;
use App\Modules\Tenancy\Models\HorarioPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\Models\PublicacionPlataformaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\ProveedorPartner;

/**
 * Cómo se publican las clases en una plataforma para reservarse desde su app (ADR 0149).
 * Qué publicar, cuándo y con cuántos lugares lo decide
 * {@see PublicarEnPlataformasTenant}; cada plataforma
 * dice cómo se lo manda.
 */
interface PublicadorPlataforma
{
    public function proveedor(): ProveedorPartner;

    /**
     * Registra con la plataforma la dirección de sus avisos de reserva (y de check-in, si
     * van juntos). Null si quedó; si no, por qué.
     */
    public function registrarAvisos(IntegracionTenant $integracion, string $url): ?string;

    /**
     * Deja de publicar sus clases: la plataforma deja de mandar avisos de reserva (si se
     * puede quitar la dirección) y la próxima vez se vuelven a registrar.
     */
    public function dejarDeRecibir(IntegracionTenant $integracion): void;

    /**
     * Deja lista la clase de la publicación en la plataforma (Wellhub la crea una vez).
     * Null si quedó; si no, por qué.
     *
     * @param  array<string, string>  $credenciales
     */
    public function prepararClase(PublicacionPlataformaTenant $publicacion, array $credenciales): ?string;

    /**
     * Cambia el producto o plan de una clase ya publicada. Null si quedó; si no, por qué.
     *
     * @param  array<string, string>  $credenciales
     */
    public function cambiarProducto(PublicacionPlataformaTenant $publicacion, array $credenciales): ?string;

    /**
     * Lo que se publica del horario, sin los lugares.
     *
     * @return array<string, mixed>
     */
    public function horario(SesionTenant $sesion, PublicacionPlataformaTenant $publicacion): array;

    /**
     * La huella de lo que la plataforma no deja cambiar en su lugar (si cambia, se retira
     * y se publica de nuevo); null si todo se cambia en su lugar.
     *
     * @param  array<string, mixed>  $horario
     */
    public function firmaFija(array $horario): ?string;

    /**
     * Los dos números de lugares que se le mandan, para saber si cambiaron.
     *
     * @return array{0: int, 1: int}
     */
    public function numeros(LugaresDeHorario $lugares): array;

    /**
     * @param  array<string, mixed>  $horario
     * @param  array<string, string>  $credenciales
     */
    public function crear(PublicacionPlataformaTenant $publicacion, array $horario, LugaresDeHorario $lugares, array $credenciales): ResultadoPlataforma;

    /**
     * @param  array<string, mixed>  $horario
     * @param  array<string, string>  $credenciales
     */
    public function actualizar(HorarioPlataformaTenant $publicado, PublicacionPlataformaTenant $publicacion, array $horario, LugaresDeHorario $lugares, array $credenciales): ResultadoPlataforma;

    /**
     * @param  array<string, string>  $credenciales
     */
    public function actualizarLugares(HorarioPlataformaTenant $publicado, PublicacionPlataformaTenant $publicacion, LugaresDeHorario $lugares, array $credenciales): ResultadoPlataforma;

    /**
     * @param  array<string, string>  $credenciales
     */
    public function retirar(HorarioPlataformaTenant $publicado, PublicacionPlataformaTenant $publicacion, array $credenciales): ResultadoPlataforma;
}
