# Plataforma multiproducto: AgendaUno y TurnoUno

Una sola plataforma (una API, una base central, una base por negocio, un superadmin)
vende dos productos (ADR 0108):

| | AgendaUno | TurnoUno |
|---|---|---|
| Negocios | De clases: estudios boutique (pilates, yoga, pole, danza, fitness), gimnasios, academias, natación | De citas: barberías, salones, uñas, pestañas, spas, estética, profesionales independientes |
| Dominio | agendauno.mx (`{slug}.agendauno.mx`) | turnouno.mx (`{slug}.turnouno.mx`) |
| Estado comercial | Se lanza primero: registro abierto | Prelanzamiento: registro cerrado, lista de interesados |
| Cobro SaaS | Por alumnos activos, mes vencido (ADR 0107) | Por plan (nivel y profesionales), por adelantado (ADR 0107) |

El producto de un negocio sale de su modalidad (clases → AgendaUno, citas → TurnoUno).

## Estado por componente

Estados: **Implementado y probado** · **Implementado, pendiente de credenciales** ·
**Implementado, pendiente de despliegue** · **Preparado para futuro** · **No
implementado** · **Requiere autorización**.

| Componente | Estado | Notas |
|---|---|---|
| Producto derivado de la modalidad (`ProductoComercial`) | Implementado y probado | `Estudio::producto()`, `/yo` y `/marca` lo dicen |
| Dominios por producto en la API (rutas, CORS, regreso de pagos) | Implementado y probado | `{slug}.agendauno.mx` y `{slug}.turnouno.mx`; cada negocio solo en el suyo |
| Enlaces y marca de correos y avisos por producto | Implementado y probado | Remitente por producto opcional (`AGENDAUNO_MAIL_FROM`, `TURNOUNO_MAIL_FROM`) |
| Registro por producto y cierre de TurnoUno | Implementado y probado | Parámetros `registro.abierto_agendauno` / `registro.abierto_turnouno` |
| Lista de interesados (API) | Implementado y probado | `POST /api/v1/interesados`, superadmin `GET /plataforma/interesados` |
| Directorio por producto, slugs reservados | Implementado y probado | |
| Web: un código, tres builds (aplicación, landing AgendaUno, landing TurnoUno) | Implementado y probado | ADR 0109; `npm run build`, revisión por producto y por HTTP |
| Landing AgendaUno (agendauno.mx) | Implementado, pendiente de despliegue | Portada de clases, páginas por giro de clases, su SEO, sitemap y robots |
| Landing TurnoUno de prelanzamiento (turnouno.mx) | Implementado, pendiente de despliegue | Portada de citas con «Quiero que me avisen» y lista de interesados; logotipo en texto (configurable) |
| Aplicación con la marca del dominio | Implementado y probado | Textos, logo y enlaces con la marca del producto; registro solo con sus giros |
| Superadmin: interesados | Implementado y probado | Pestaña «Interesados» |
| Imágenes web separadas por build (publicar una landing sin la aplicación) | No implementado | Fase 5 (infraestructura) |
| PWA por negocio (AgendaUno y TurnoUno) | No implementado | Fase 3 |
| App oficial AgendaUno (Android/iOS) | No implementado | Fase 4; la app actual es la base |
| App oficial TurnoUno (Android/iOS) | No implementado | Fase 4 |
| White-label (solo AgendaUno) | No implementado | Fase 4, preparado sin publicar |
| Traefik, dos dominios, certificados comodín | No implementado | Fase 5; DNS en Cloudflare |
| CI/CD por aplicación | No implementado | Fase 5 |
| Negocio con los dos productos | Preparado para futuro | Hoy un negocio es de una sola modalidad (ADR 0104) |
| Documentos legales por producto | Preparado para futuro | Hoy son de la plataforma |
| Autorregistro de clientes en los sitios | Requiere autorización | Se mantiene cerrado (ADR 0093, reafirmado 2026-10-10) |
| Publicar apps en tiendas | Requiere autorización | |
| Cambios en producción | Requiere autorización | |

## Variables

API (`infra/produccion/api.env.example`):

- `APP_TENANT_DOMAIN`, `APP_SPA_URL`: dominio y web de AgendaUno (como siempre).
- `TURNOUNO_DOMINIO`, `TURNOUNO_URL_WEB`: dominio y web de TurnoUno.
- `AGENDAUNO_MAIL_FROM`, `TURNOUNO_MAIL_FROM`: remitente de cada producto (opcional).
- `FRONTEND_URL`: orígenes extra para CORS (los dos dominios y sus subdominios ya se
  admiten).

Superadmin → Parámetros → «Registro de negocios»: abrir o cerrar el registro de cada
producto. Superadmin → Interesados (API): quién espera el lanzamiento de TurnoUno.
