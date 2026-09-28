# API

REST con JSON, versionada bajo `/api/v1`. Documentación OpenAPI generada con
Scramble en `/docs/api`. Rutas en `apps/api/routes/api.php`.

## Grupos de rutas

| Prefijo | Quién | Autenticación |
|---|---|---|
| `/api/v1/health` | Monitores (`?estricto=1` falla con 503) | — |
| `/api/v1/registro`, `/directorio`, `/legales` | Alta pública de negocios y directorio | — |
| `/api/v1/webhooks/tenant/{slug}/{proveedor}` | Pasarelas de cada negocio | Verificación con la pasarela |
| `/api/v1/webhooks/plataforma/{proveedor}` | Pasarela de la plataforma (renta) | Verificación con la pasarela |
| `/api/v1/plataforma/*` | Superadmin | `PLATFORM_ADMIN_TOKEN` |
| `/api/v1/app/{slug}/*` | Un negocio: equipo, profesionales y alumnos | Bearer del negocio |
| `{slug}.agendauno.mx/api/v1/*` | Lo mismo por subdominio | Bearer del negocio |
| `/api/v1/app/{slug}/integracion/*` | Terceros (solo lectura) | Llave de API con alcances |

Dentro de un negocio hay rutas públicas (login, activar, recuperar contraseña,
marca, escaparate, registro de alumno, citas sin cuenta, calendario iCal) y el
resto exige sesión y un permiso (`puede:…`). Ver `docs/AUTHORIZATION.md`.

## Autenticación

- `POST /app/{slug}/login` o `/auth/google` devuelve un token `{id}|{secreto}`; la
  base del negocio guarda solo el hash. Se manda como `Authorization: Bearer …`.
- La web y la app móvil usan el mismo token (no hay cookies de sesión ni Sanctum).
- Quien tiene varios roles entra con uno; `GET /yo` trae `rol` y
  `roles_disponibles`, y `PUT /yo/rol-activo` cambia de rol (ADR 0055).
- `POST /logout` revoca el token.

## Convenciones

- Identificadores públicos: ULID. Nunca el `id` interno.
- Dinero: `*_minor` entero + `moneda`. Créditos: unidades enteras (1000 = 1).
- Fechas en ISO-8601 con zona; las horas se guardan en UTC y se muestran en la zona
  de la sucursal.
- Operaciones críticas (reservar, crear órdenes, pagar) aceptan `idempotency_key`
  en el cuerpo: repetir la petición devuelve el mismo resultado. Los webhooks son
  idempotentes por la referencia de la pasarela.
- Listas grandes paginan (`page`, `per_page`).
- Cada respuesta lleva `X-Correlation-ID`; se acepta uno entrante si es seguro.
- Límites de peticiones: `throttle:login`, `throttle:tenant`, `throttle:60,1`.

## Errores

Los errores de dominio tienen un código estable (`TenancyException::codigo()`) que
`ApiExceptionRenderer` convierte en:

```json
{
  "code": "CAPACITY_FULL",
  "message": "La clase ya no tiene lugares."
}
```

`meta` aparece solo cuando el error trae datos para resolverlo.

Con el mismo sobre: una validación responde 422 `VALIDATION_FAILED` con
`meta.errors` por campo; sin sesión, 401 `UNAUTHENTICATED`; sin permiso, 403
`FORBIDDEN`; lo que no existe (o un negocio no disponible), 404 `NOT_FOUND`.
