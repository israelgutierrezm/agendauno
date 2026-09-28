# TurnoUno — SaaS de Membresías

SaaS comercial multi-tenant para negocios basados en membresías, clases, reservas, recursos y
actividades (estudios de pole, escuelas de natación, gimnasios). Un núcleo único configurable —
sin forks por industria.

## Idioma del proyecto

El **dominio se programa en español** (clases, servicios, variables, comentarios, mensajes) y se
conserva el **inglés** para framework y estándares técnicos (Controller, Request, Migration, REST,
`Tenant`, `User`, `ulid`, ...). El glosario canónico está en `docs/DATABASE.md`.

## Estructura del monorepo

| Ruta | Stack | Propósito |
|------|-------|-----------|
| `apps/api` | Laravel 13 · PHP 8.3 · MySQL · Redis | API REST (modular monolith) |
| `apps/web` | Vue 3 · TS · Vite · Tailwind · Pinia · Router | Web de AgendaUno: sitio comercial, registro, panel de cada negocio, portal del alumno y superadmin |
| `apps/mobile` | Flutter · Riverpod · Dio | App de miembro / tutor / instructor |
| `docs/` | — | Arquitectura y producto (**fuente de verdad**): ADRs, despliegue, verificación |
| `infra/docker/` | Docker Compose | Redis local (MySQL desde WAMP) |
| `infra/produccion/` | Docker · nginx | Imágenes, compose, `actualizar.sh` / `volver.sh` (ver `docs/DESPLIEGUE.md`) |
| `.github/workflows/` | GitHub Actions | CI: un job por app y la verificación de concurrencia en MySQL |

## Requisitos

- PHP 8.3+, Composer 2
- Node 22+ (se recomienda 22.18+), npm
- MySQL 8 (WAMP local o Docker)
- Redis vía Docker (no se instala nativo en Windows)
- Flutter 3.44+ / Dart 3.12+
- Docker Desktop (para Redis)

## Inicio rápido

### 1. Redis (opcional en local)

```bash
docker compose -f infra/docker/docker-compose.yml up -d redis
```

La API arranca sin Redis usando drivers de cache/queue/sesión sobre base de datos. Levanta Redis
(y cambia `CACHE_STORE` / `QUEUE_CONNECTION` a `redis`) para un comportamiento como producción.

### 2. API

```bash
cd apps/api
php artisan key:generate      # el .env se crea durante el bootstrap
php artisan migrate --seed
php artisan serve             # http://localhost:8000
```

Deben existir los esquemas `turnouno` y `turnouno_testing` (utf8mb4).

```bash
composer lint       # Pint (revisión de formato)
composer analyse    # PHPStan / Larastan (nivel 6)
composer test       # Pest
```

- Salud: `GET http://localhost:8000/api/v1/health`
- OpenAPI (Scramble): `http://localhost:8000/docs/api`
- Estudios demo: `php artisan turnouno:sembrar-demo` (negocio `demo`, clases y citas) y
  `php artisan turnouno:sembrar-demo --slug=barberia --perfil=barberia --nombre="Barbería Demo"`
  (solo citas). Crean una cuenta por rol (dueño, administradora, recepción, profesionales,
  alumna); la contraseña es la opción `--password` del comando y lo imprime al terminar.

### 3. Web

```bash
cd apps/web
npm install
npm run dev               # :5175
npm run lint && npm run build && npm run test
```

### 4. Móvil

```bash
cd apps/mobile
flutter pub get
flutter run -d chrome     # o un emulador / dispositivo
# El emulador de Android alcanza el host en 10.0.2.2:
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
flutter analyze && flutter test
```

## Producción

Imágenes Docker (API con worker y scheduler, web con nginx), `docker-compose.yml` y
plantillas de variables en `infra/produccion/`. Paso a paso en `docs/DESPLIEGUE.md`
(ADR 0048).

## Estado

SaaS multi-tenant con una base de datos por negocio: clases y citas, membresías y
créditos con ledger, pagos en línea (Stripe, Mercado Pago, OpenPay), portal del
alumno, app móvil, recepción, reportes, comunicaciones, superadmin y cobro del SaaS.
Las fases 1 (operación) y 2 (agenda cotidiana) están cerradas; los límites de negocio
son parámetros configurables por negocio o por la plataforma (ADR 0042 y 0047).

Lee `docs/DEVELOPMENT_PLAN.md` para la hoja de ruta por slices, `docs/DATABASE.md` para el glosario
y esquema, y `docs/adr/` para las decisiones de arquitectura.
