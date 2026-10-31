# ADR 0234 — Landings y legales para producción

Estado: Aceptado (2026-10-31). Ajusta la ADR 0110 (la página se instala como app), la 0143
(medición) y la 0206 (barra de cookies), tras la revisión de las páginas públicas antes de abrir.

## Contexto

Las landings vendían apps que no se pueden descargar e integraciones sin validar con su proveedor;
se pedían datos sin aviso de privacidad del negocio; los términos prometían 30 días de aviso de
precios que el sistema no cumplía; el aviso no declaraba varios proveedores, y las preguntas
frecuentes quedaban fuera de los buscadores.

## Decisión

Decisiones del dueño (2026-10-31):

- **Solo se anuncia lo comprobado.** `marketing/integraciones.ts`: una bandera pública por
  integración (`VITE_INTEGRACION_*`, apagadas) y los enlaces de cada tienda por producto
  (`VITE_{PRODUCTO}_PLAY_URL`, `VITE_{PRODUCTO}_APP_STORE_URL`, vacíos). Son de compilación porque las
  landings se pre-generan: encender una exige volver a publicar la imagen. Cada tarjeta de «Lo nuevo»
  sin comprobar se cambia por una de respaldo, para no romper la rejilla.
- **Aviso base al dar de alta.** `generarAvisoBase` guarda la versión 0 sin activar, con los datos
  del negocio: nadie la ve ni la firma, y publicar crea la versión 1. En producción, agendar sin cuenta
  sin aviso publicado responde 422; `reservas.aviso_version` guarda la versión que tuvo a la vista.
- **Subidas de tarifa.** `SubidaDeTarifa` compara con la vigente en cada escalón, moneda, IVA y meses
  del anual: una subida rige a 30 días o más y manda correo a los dueños de esa modalidad; bajar puede
  ser inmediato. Mover una función a un plan mayor no cuenta como subida.
- **`negocios.dias_borrar_baja` con mínimo de 30 días**, y los legales lo dicen, junto con los 12 meses
  de las copias de respaldo (ADR 0232).
- **«Solo necesarias» siempre**, con el mismo estilo que «Aceptar».
- **Sin correos de AgendaUno en TurnoUno**: cotiza con el suyo o `ventas@{su dominio}`; los documentos
  legales cambian `@agendauno.mx` por su soporte. `agendauno:verificar-produccion` avisa si falta
  `TURNOUNO_VENTAS_CORREO` (no crítico).
- **TurnoUno abre con su logo provisional**; `apps/web/MARKETING-SEO.md` dice dónde va el definitivo.
- **Soporte gratuito = orientar** (guías, importadores, dudas); configurar y migrar es el arranque de pago.

Del informe de revisión: `/preguntas-frecuentes` indexable y pre-generada; el aviso del negocio informa
su medición y se exige publicado para encenderla; `gclid` y `fbclid` solo con permiso de publicidad; el
origen de la visita dura 90 días; `VITE_ANALYTICS_ENDPOINT` solo con permiso de analítica; GA4 y Meta
reales del SaaS solo en producción; `page_location` sin parámetros; reCAPTCHA con tiempo límite de 8 s; el
servidor agrega el enlace de la cita a la plantilla editada que lo perdió.

## Consecuencias

- Hay que publicar los legales nuevos en el superadmin y volver a compilar las imágenes para encender
  una bandera o un enlace.
- Un negocio anterior a esta versión no tiene aviso base: Documentos sigue ofreciéndole la plantilla.
- Los textos legales siguen duplicados en `apps/api/resources/legales` y `apps/web/src/marketing/legales`
  (`documentoLegal.spec.ts`), ahora también el del negocio: la API lo necesita para el aviso base.
