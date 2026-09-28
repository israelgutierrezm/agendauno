# Landing, captación y SEO

## Cambios y comprobación

- Paleta pública aislada del panel operativo: CTA azul tinta `#223B4B`, énfasis grande verde Bootstrap `#198754` y éxito en texto pequeño `#146C43` por contraste. Fondos `#F6F8FC` y superficies `#FFFFFF` / `#E9EDF5`; en oscuro, `#020D24` y superficies `#071A3A` / `#0B2854`. En oscuro, CTA claro `#DCE5E5` y énfasis `#75B798`. El progreso conserva el azul petróleo `#24566B` (`#376D82` en oscuro). No se modifica ningún logo.
- `src/marketing/public-ui.css`, cargado desde `main.ts`, comparte tipografía, controles de 44 px como mínimo, radios y espaciados solo en el marco público. Las páginas operativas no reciben estas reglas. El header conserva logo, posición fija y tema; las rutas de tarea no repiten el CTA de registro ni el menú comercial.
- La demo aparece antes de las modalidades y la operación móvil tiene su propia banda blanca. Las secciones alternan blanco y gris azulado del hero, con espaciado compacto y uniforme, evitando bloques consecutivos del mismo fondo. Se preservan los textos comerciales y H1/H2. Acceso mantiene recientes y búsquedas, pero sin duplicar el logo ni mostrar resultados vacíos antes de una búsqueda explícita. El aviso legal se presenta por apartados, sin reescribir su contenido.
- Los titulares conservan su texto y nivel H1/H2. En la portada solo se enfatizan «Más» y «crecer», mediante spans de texto real y peso moderado; no se colorea una palabra en cada título.
- `/aviso-de-privacidad` está enlazado desde el footer y antes de los campos de registro. Comparte el documento editable de `/api/v1/legales` con el modal de registro. El borrador `src/marketing/aviso-privacidad.borrador.txt` solo se muestra en desarrollo si no existe documento publicado. No debe publicarse sin completar los datos del responsable y validar prácticas, proveedores y controles. La ruta legal queda fuera del sitemap y sin indexación; no altera el SEO comercial.
- Seis páginas enlazadas desde la landing: `/software-para-pilates`, `/software-para-pole-dance`, `/software-para-academias`, `/software-para-barberias`, `/software-para-spas`, `/software-para-terapeutas`.
- Cada página tiene contenido propio, fotos existentes, CTA al registro, título, descripción y canonical. No se inventan testimonios, calificaciones ni precios.
- `npm run build` genera HTML completo reutilizando los componentes Vue de marketing. La aplicación de gestión sigue siendo SPA; no se consultan cuentas ni datos de negocios durante la compilación.
- Validar con `npm run typecheck`, `npm test`, `npm run build` y `npm run test:marketing`. `npm run preview` sirve las rutas generadas y el fallback de acceso.
- Las animaciones no ocultan el texto cuando JavaScript no está disponible. Se respeta movimiento reducido.

## Configuración necesaria al publicar (no se despliega desde este cambio)

Publicar `dist`, nunca `dist-ssr`. El servidor debe distinguir dominio comercial y subdominios de negocios:

1. En `agendauno.mx` y `www.agendauno.mx`, servir `/` desde `dist/index.html`, y cada ruta `/software-para-*` existente desde su propio `index.html`.
2. Servir archivos reales, CSS, JS, imágenes, robots y sitemap normalmente. Un asset inexistente debe responder 404, no HTML.
3. Acceso, registro, activación, pantallas privadas y rutas dinámicas usan `dist/app.html` como fallback. **No usar el HTML de la landing como fallback universal.** Las rutas estáticas de registro/acceso también tienen HTML propio sin indexación.
4. En `{negocio}.agendauno.mx` y hosts de aplicación, servir `app.html` para rutas de aplicación, incluida `/`; nunca la landing comercial. Mantener la resolución de tenant existente.
5. Redirigir HTTP a HTTPS y www al dominio canónico en producción. Comprobar códigos HTTP y encabezados antes de enviar el sitemap. El `noindex` no sustituye autenticación.

El directorio queda como herramienta de acceso, fuera del sitemap comercial. Los escaparates dinámicos conservan sus metadatos al cargar, pero no se prerenderizan con datos de negocios; si se quiere posicionar cada negocio, requiere un trabajo separado de renderizado y respuestas HTTP 404 reales. El catch-all histórico de Vue también debe revisarse antes de una estrategia SEO de escaparates.

## Search Console

No hay una propiedad conectada automáticamente. Si se utiliza verificación por etiqueta HTML, colocar el **token público** en `GOOGLE_SITE_VERIFICATION` y recompilar. Para una propiedad de dominio, verificar por DNS desde el proveedor correspondiente. Publicar y enviar `https://agendauno.mx/sitemap.xml`; inspeccionar portada y una página por giro. La configuración técnica facilita indexar, no garantiza posiciones.

## Embudo y medición

La integración existente emite eventos a `window.dataLayer`, al evento local `agendauno:analytics` y, si se configura, a `VITE_ANALYTICS_ENDPOINT`. Sin un receptor o un gestor de etiquetas configurado no existe un panel de métricas ni almacenamiento persistente. No se instala GA4 ni se envían datos a una cuenta externa nueva.

| Etapa            | Evento                                  | Qué significa                                                      |
| ---------------- | --------------------------------------- | ------------------------------------------------------------------ |
| Visita comercial | `page_view`                             | Navegación completada a landing, solución o registro               |
| Interés          | `marketing_cta_clicked`                 | CTA, ubicación y solución seleccionada                             |
| Inicio           | `studio_registration_started`           | Se abre el formulario                                              |
| Avance           | `studio_registration_step_completed`    | Paso validado                                                      |
| Registro         | `tenant_created`                        | API confirma creación                                              |
| Activación       | `tenant_activated`                      | API confirma activación                                            |
| Pago             | Pendiente de conectar del lado servidor | Confirmación real de la pasarela, no clic ni retorno del navegador |

Las rutas de activación y operación se agrupan, sin slugs, IDs ni parámetros de URL en `page_path`. No incluir emails, teléfonos, nombres ni datos sensibles en eventos o campañas UTM. El endpoint debe aplicar validación, límites, retención y los controles de privacidad correspondientes; revisar consentimiento antes de activar un proveedor externo.

Para completar el embudo hasta pago: instrumentar la confirmación de suscripción del backend con deduplicación por ID de transacción y primera suscripción pagada por negocio. Usar identificadores internos en un almacén autorizado; no enviarlos a terceros sin revisión. Las cuentas únicas y cohortes requieren esa integración: no calcularlas contando clics. Separar pagos de la suscripción SaaS de cobros que cada negocio recibe de sus propios clientes.

## Validación comercial posterior

Comparar conversión a registro y activación por página de origen y campaña. Probar una sola variante de propuesta o CTA a la vez cuando haya tráfico suficiente. No atribuir aumentos de conversión al color por sí solo. Revisar con producto la disponibilidad real del cobro por profesional antes de retirar los avisos de preparación.
