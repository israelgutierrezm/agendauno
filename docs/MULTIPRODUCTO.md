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
| Imágenes web separadas (aplicación, landing AgendaUno, landing TurnoUno) | Implementado, pendiente de despliegue | ADR 0112: `landing.Dockerfile`; nginx de `web` pasa a cada landing sus rutas; enrutamiento probado con contenedores |
| Publicar y volver por componente | Implementado, pendiente de despliegue | `./actualizar.sh --solo …`, `./volver.sh --solo …`, versión por componente |
| PWA por negocio (AgendaUno y TurnoUno) | Implementado, pendiente de despliegue | ADR 0110: manifiesto dinámico en el subdominio, service worker que no guarda la API, invitación a instalar; probado en unidades y contra el API local |
| Color de marca del negocio | Implementado y probado | Configuración → Perfil público; barra de la app instalada |
| Íconos cuadrados del logo (192/512) | Preparado para futuro | Requiere GD en la imagen del API (cambio de imagen) |
| Notificaciones push web | No implementado | La app oficial sí tiene push (FCM) |
| Constructor del sitio (plantillas, secciones, banners) | No implementado | Hoy: escaparate con logo, portada, descripción, redes y color |
| App oficial AgendaUno (Android) | Implementado y probado | ADR 0111: sabor `agendauno`, `com.agendauno.app`; APK compilado y revisado |
| App oficial TurnoUno (Android) | Implementado y probado | Sabor `turnouno`, `com.turnouno.app`, API `turnouno.mx`; APK compilado y revisado; ícono provisional |
| Apps oficiales en iOS | Preparado para futuro | Producto en Dart listo; esquemas de Xcode en una Mac (docs/MOBILE.md) |
| La API no abre negocios del otro producto en cada app | Implementado y probado | `X-App-Producto`; la app dice qué app descargar |
| Marca blanca (solo AgendaUno) | Preparado para futuro | Archivo por negocio, `X-App-Negocio`, Gradle la impide en TurnoUno; sin alta en superadmin |
| Publicar las apps en tiendas | Requiere autorización | Cuentas de desarrollador, llaves de firma, fichas |
| Traefik existente, dos dominios, certificados comodín | Implementado, pendiente de despliegue | `docker-compose.traefik.yml`; el resolvedor DNS-01 de Cloudflare en el Traefik y el token requieren autorización |
| IP real detrás de Cloudflare | Implementado, pendiente de despliegue | `docker-compose.cloudflare.yml`: solo IP de Cloudflare y `CF-Connecting-IP` |
| DNS en Cloudflare (`@`, `www`, `*` de cada dominio) | Requiere autorización | Cambio en producción |
| CI/CD por aplicación | No implementado | Fase de CI/CD |
| Negocio con los dos productos | Preparado para futuro | Hoy un negocio es de una sola modalidad (ADR 0104) |
| Documentos legales por producto | Preparado para futuro | Hoy son de la plataforma |
| Autorregistro de clientes en los sitios | Requiere autorización | Se mantiene cerrado (ADR 0093, reafirmado 2026-10-10) |
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
