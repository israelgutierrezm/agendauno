# ADR 0102 — Endurecimiento para producción

Estado: Aceptado (2026-10-06). Complementa el ADR 0048 (despliegue), el ADR 0051
(operación para producción) y el ADR 0080 (monitoreo de errores).

## Contexto

Antes de abrir la primera versión estable se revisó la web, la app y la API con la
mirada de producción: qué pasa con muchos negocios a la vez, con bots en el registro
público, con la red caída o una versión nueva publicada a media sesión, y qué deja
cada falla en el log. Salieron unos veinte hallazgos; este ADR recoge las decisiones
que cambian cómo se comporta la plataforma.

## Decisión

**Registro público**

- reCAPTCHA v3 es obligatorio en producción: `agendauno:verificar-produccion` marca
  FALTA sin `RECAPTCHA_SECRET`. Cada alta crea una base completa y manda un correo.
- Las altas que nadie activa se borran (`agendauno:limpiar-altas-sin-activar`, diario):
  solo negocios en prueba o a medio aprovisionar, registrados hace más de
  `registro.dias_sin_activar` días (parámetro de plataforma, 14 por omisión, de 3 a
  365) y sin nada propio (un solo usuario sin activar, sin sesiones, pagos, ventas ni
  clientes, sin cargos ni facturas de la renta). Ante la duda se conserva. Se borran
  sus respaldos, su base y su registro; el nombre queda libre.
- La dirección (slug) mide a lo más 40 caracteres y el nombre de la base en MySQL a lo
  más 64: un nombre largo se recorta, sin guion al final.
- La web espera hasta 120 s la respuesta del alta (crear la base tarda): si se rindiera
  antes que el servidor (60 s), el negocio quedaría creado y el dueño lo intentaría de
  nuevo.

**Límites de peticiones**

- En las rutas de un negocio, el límite va después de resolver el negocio y la sesión
  (o la llave de API): cuenta por usuario de cada negocio, no por IP. Toda una oficina
  detrás de una IP ya no comparte cupo. Un slug inexistente (404) o un token inválido
  (401) ya no gastan cupo; los tokens son aleatorios de 40 a 48 caracteres.
- El superadmin conserva el orden contrario a propósito: su límite es por IP y va antes
  de validar el token, para frenar a quien intenta adivinarlo.
- Limitadores con nombre por uso (lista en `docs/API.md`): la página pública se cuenta
  por negocio e IP, el calendario iCal por enlace y el clima con su propio tope.

**Errores y trazas**

- Las reglas del negocio que la API responde con un 4xx y su código (cupo lleno,
  enlace vencido, saldo insuficiente…) no se reportan: no van al log, al monitoreo ni a
  las alertas (`ReporteDeErrores::esReglaDelNegocio`). Los 5xx y lo inesperado sí.
- Lo que se atrapa y no llega a ninguna respuesta (cobros en segundo plano,
  conciliación, cancelar en la pasarela) usa `ReporteDeErrores::reportarAtrapado()` y
  se reporta siempre, aunque sea una regla del negocio: ahí nadie más lo ve.
- Las trazas no llevan argumentos (`zend.exception_ignore_args = On`) y los métodos que
  reciben contraseñas, tokens, códigos o llaves marcan esos parámetros con
  `#[\SensitiveParameter]`.

**Sesión y versiones en la web y la app**

- Un 401 con el token vigente en una ruta del negocio cierra la sesión en ese momento
  (baja, cambio de contraseña, cerró sesión en otro lado) y lleva a Entrar para volver
  a la misma pantalla. Sin red, mantenimiento (503) o un 5xx, la sesión se conserva y
  se ofrece reintentar. Las peticiones se rinden a los 30 s (subir o descargar archivos,
  a los 300 s).
- Tras publicar, una pestaña abierta desde antes recarga una vez la pantalla a la que
  iba si un archivo de la versión vieja ya no existe; nginx sirve el HTML con
  `Cache-Control: no-cache` y los `/assets/` con hash por un año.
- En Entrar, solo un 404 dice que el negocio ya no está disponible; sin conexión se
  dice eso y el negocio se queda elegido.
- Entrar con Google solo desde el dominio principal (Google no acepta comodines en los
  orígenes): en el subdominio de un negocio la web lo explica y enlaza allá.

**Negocio y zona horaria**

- Los horarios de citas se ofrecen y se agendan con la hora de la sede (`inicia_local`
  y `zona_horaria`), no con la del teléfono o la computadora de quien agenda.
- Al pagar en línea, la pasarela regresa al sitio desde el que se pagó si es el del
  negocio (`Origin`/`Referer` validados, solo `https` en producción); si no, a la app.
- Nadie da de baja ni reactiva a alguien con más permisos que los suyos; a un dueño
  solo otro dueño.

**Infraestructura**

- PHP-FPM con pool propio (24 procesos para 4 GB) y corte a los 65 s; nginx resuelve la
  dirección de la API cada 10 s (sin 502 permanentes tras recrearla) y manda HSTS con
  `includeSubDomains`, `X-Frame-Options: DENY` y `frame-ancestors 'none'`.
- Los respaldos de MySQL admiten TLS (`RESPALDOS_MYSQL_SSL_*`) y su carpeta de trabajo
  es solo de `www-data`; todo `artisan` con `exec` corre con `-u www-data`.
- La imagen de la API trae el plugin `caching_sha2_password` del cliente (con el que
  entra el usuario normal de MySQL 8.4; `mysql_native_password` ya viene apagado).
- CI construye las dos imágenes de producción, valida `nginx -t` y `php-fpm -t`, y
  vuelca y carga una base con el cliente de la imagen contra MySQL 8.4.

## Consecuencias

- Una instalación sin llaves de reCAPTCHA no pasa `verificar-produccion`.
- Por `includeSubDomains`, todo subdominio del dominio comercial debe tener HTTPS
  durante un año (también los de servicios externos, como el seguimiento de clics del
  correo).
- Quedan para el dueño: medir la memoria de PHP-FPM bajo carga, correr un respaldo y
  el simulacro contra el MySQL real (con su TLS), la llave APNs y la compilación de iOS
  en una Mac, y los íconos de las tiendas desde el isotipo en vector.
- No se adoptó una CSP completa (exige inventariar Google, reCAPTCHA, pasarelas,
  analítica y clima y probarla en el navegador) ni mover el aprovisionamiento del alta
  a la cola; quedan como mejoras posteriores.
