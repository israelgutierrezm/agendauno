# ADR 0007 — Mecánica de aislamiento de tenant

Estado: Reemplazado por el ADR 0058 (una base por negocio; sin `tenant_id` ni `TenantContext`).

## Contexto

Tenant es la frontera de seguridad. El aislamiento debe sostenerse en el route-model
binding, en las consultas Eloquent, en la autorización y en los jobs encolados.

## Decisiones

- **TenantContext** es un servicio con scope de request/job. El middleware
  `ResolveTenantContext` resuelve el tenant activo desde el encabezado `X-Tenant-ID`
  (ULID), validado contra las pertenencias activas del usuario (o la única pertenencia).
  Seleccionar un tenant del que no se es miembro devuelve `403 TENANT_FORBIDDEN`. La
  entrada del cliente nunca se toma como autoridad.
- **Prioridad de middleware**: `ResolveTenantContext` corre antes de `SubstituteBindings`,
  de modo que todo modelo tenant-owned enlazado por ruta queda filtrado al tenant activo
  y los ids de otro tenant resuelven `404 NOT_FOUND`.
- **BelongsToTenant** agrega un global scope que filtra por el tenant activo y fuerza
  `tenant_id` al crear dentro de un request de tenant.
- **Jobs encolados**: un `Queue::createPayloadUsing` global captura el tenant activo en el
  payload de cada job; `JobProcessing` restaura el TenantContext y el team de spatie, y se
  limpia en `JobProcessed`/`JobFailed`. Sin opt-in por job.
- **RBAC** tenant-scoped vía teams de spatie (team = tenant, ADR-0006).
- **User** permanece en `app/Models` (acoplamiento con auth/Sanctum). Los demás modelos de
  dominio (Persona, Organizacion, Sucursal, ...) viven en sus módulos y son tenant-scoped;
  User es global.

## Consecuencias

- Los endpoints tenant-scoped aplican el middleware `tenant.require`. `/me` tolera un tenant
  sin resolver y devuelve las pertenencias para que el cliente elija con `X-Tenant-ID`.
- Cualquier modelo tenant-owned futuro obtiene el aislamiento gratis usando
  `BelongsToTenant` + `HasPublicId`.
