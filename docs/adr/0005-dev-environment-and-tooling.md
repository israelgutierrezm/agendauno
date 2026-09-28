# ADR 0005 — Entorno de Desarrollo y Tooling

Estado: Aceptado (Sprint 0)

## Contexto

El desarrollo local corre en Windows (WAMP: PHP 8.3.6, MySQL 8.3); CI y
producción en Linux. Redis no está instalado de forma nativa en Windows.

## Decisiones

- **MySQL**: esquemas dedicados `agendauno` / `agendauno_testing` en el WAMP local (3306).
- **Forzar InnoDB** como motor MySQL de Laravel (`DB_ENGINE=InnoDB`). WAMP usa MyISAM
  por defecto, cuyo límite de índice de 1000 bytes rompe los índices únicos utf8mb4 y
  carece de las transacciones y locks que exigirá el motor de reservas.
- **Redis vía Docker** (`infra/docker/docker-compose.yml`) con el cliente `predis`
  (PHP puro), sin extensión nativa en Windows.
- **Defaults arrancables**: cache/queue/sesión sobre base de datos para que la API
  funcione aunque Redis/Docker estén caídos. Cambiar a `redis` con el contenedor arriba.
- **Contrato de error**: JSON estable `{code, message, meta}` para `/api/*`; el `code`
  es un identificador técnico estable y el `message` está localizado en es-MX.
- **Correlation id**: middleware `X-Correlation-ID` en la respuesta y los logs;
  se propaga a los jobs.
- **Tooling**: Pest (+ arch), Larastan nivel 6, Pint (preset Laravel +
  `declare(strict_types=1)`), Scramble (OpenAPI en `/docs/api`), Laravel Boost (solo dev).
- **IDs públicos**: ULID nativo (`HasUlids`); sin paquete extra.

## Consecuencias

- Producción debe aprovisionar Redis y credenciales MySQL de mínimo privilegio (dev usa `root`).
- `composer.json` tiene `minimum-stability: dev` (heredado del skeleton) — revisar hacia `stable`.
- CI corre en Linux con servicios MySQL 8.4 + Redis 7.
