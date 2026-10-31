# ADR 0232 — Despliegue y operación para producción

Estado: Aceptado (2026-10-31). Ajusta la ADR 0135 (el despliegue vuelve solo) y la 0113
(imágenes del CI), tras la revisión de operación antes de abrir.

## Contexto

La revisión encontró que el primer despliegue posterior al borrado de datos de un negocio
dado de baja dejaría a toda la plataforma en mantenimiento: `agendauno:migrar-estudios`
intentaba conectarse a una base que ya no existe. También que una migración que falla en un
solo negocio dejaba fuera a todos sin alertar a nadie, que el respaldo previo crece con cada
negocio y cada archivo, y que lo publicado podía no ser lo que pasó por el CI.

## Decisión

Decisiones del dueño (2026-10-31):

- **Una migración que falla reabre sola la versión anterior y avisa**, también si falla en
  un solo negocio. Se apoya en la regla de la ADR 0135: las migraciones se escriben para que
  la versión anterior funcione con el esquema nuevo.
- **Respaldo previo reducido.** La base central siempre, sin los archivos subidos (no los
  toca una migración y van en el respaldo diario). Las bases de los negocios, solo si la
  versión trae migraciones de negocio; si no se puede saber, se respaldan.
- **`REGISTRO` es obligatorio en producción**: se publican las imágenes por commit del CI.
  Construir en el servidor hay que pedirlo con `CONSTRUIR_EN_SERVIDOR=1`, solo en staging.
- **Retención de respaldos**: bloqueo de 30 días en el bucket más copias mensuales durante
  12 meses; la retención la hace el bucket y la aplicación deja de borrar.

Correcciones sin decisión:

- Un negocio con `datos_borrados_en` cuenta como sin base (`baseDeDatosExiste`): ningún
  comando intenta conectarse a ella.
- «Desplegar» publica exactamente el commit cuyo CI revisó, no lo que `origin/main` tenga un
  minuto después.
- Un solo despliegue a la vez en cada servidor (`flock`).
- Al terminar bien se conservan las 5 versiones más recientes de cada imagen y se borran las
  demás.

Segunda parte, resto de la revisión:

- **Trabajos largos aparte.** `AprovisionarNegocioRegistrado` y `ArmarExportacionNegocio` van
  en la cola `pesados`, con conexión Redis propia (`retry_after` 2000) y su `worker-pesados`
  (`--timeout=1900`). El worker general lleva `--timeout=80` y atiende primero `latido`, así
  que una fila cargada ya no da una falsa «cola detenida» ni una vuelta en falso al
  publicar. Con sync o database (pruebas, desarrollo) siguen en la cola de la instalación.
  El despliegue espera a `worker-pesados` antes del mantenimiento.
- **Sin negocio, `tenant` falla.** Al desconectar apunta a una base centinela que no existe;
  un trabajo cuyo `estudio_id` ya no existe falla en vez de correr.
- **El programador no espera a terceros.** Las tareas con llamadas externas que se pueden
  retomar corren con `runInBackground()` y los webhooks salientes van en la cola (como la
  ADR 0224). Cobrar, facturar, emitir cargos y respaldar siguen en primer plano: al detener
  el programador nadie espera a lo que va en segundo plano, y eso no se debe cortar a la mitad.
- **Límite `accion-publica`**, por `negocio|IP` (30 por minuto), para lo que se hace desde un
  enlace, sin correo. `throttle:login` queda para entrar y registrarse. Detrás de una IP
  compartida, los clientes de un negocio ya no gastan el cupo de otro.
- **Retención en el bucket** (`RESPALDOS_RETENCION_BUCKET`): la aplicación no borra respaldos
  y copia el primero de cada mes a `respaldos-mensuales/AAAAMM/`. Una copia mensual fallida no
  cuesta el respaldo diario y se reintenta al día siguiente.
- **`agendauno:revisar-pendientes`** mide el outbox y los avisos de cada negocio cada 10
  minutos, sin contadores nuevos en el control plane: la verificación lee esa medición, así
  que abrir el superadmin no abre una conexión por negocio.
- **Telegram como segundo canal de alertas** (decisión del dueño): solo tipos y cuentas,
  nunca el mensaje de la alerta, que puede traer datos de un cliente. Su registro tampoco
  lleva el error, porque la URL trae el token.
- **`agendauno:rotar-app-key`** (decisión del dueño): las columnas salen de los casts
  `encrypted` de los modelos, para que una nueva entre sola; compara antes de escribir para
  no pisar un cambio. Lo derivado de la llave con HMAC no se migra a propósito: si se filtró,
  esos enlaces deben dejar de valer.
- Presupuesto de 45 s para toda una pregunta al asistente; `RestaurarEstudio` anota el esquema
  real y avisa lo que falta; `composer migrar` corre las dos migraciones en desarrollo; Redis con `maxmemory` y `noeviction`, porque un desalojo borraría
  trabajos; `log_format` sin query ni referer y con el token del calendario tachado;
  acciones de terceros fijadas por SHA.

No se hizo: la guardia contra migraciones destructivas en el CI (O23), el ensayo de
`actualizar.sh` en staging y migrar sobre una copia de un respaldo real antes de abrir: son
tarea del dueño.

## Consecuencias

- Una migración que falla deja el esquema a medias: la versión anterior sigue atendiendo,
  pero hay que corregir el negocio afectado antes de volver a publicar. Si una migración no
  fuera compatible con la versión anterior, se restaura con los respaldos recién tomados.
- Sin respaldo de las bases de los negocios antes de una versión sin migraciones de negocio,
  el punto de regreso de esas bases es el respaldo diario.
- Un despliegue puede tardar hasta 33 minutos más en empezar si hay un trabajo largo en
  curso; el servicio sigue abierto mientras espera.
- Un código que consultaba `tenant` sin negocio y funcionaba de casualidad ahora falla.
- Con Redis en su tope, escribir falla en vez de desalojar: el aviso es que todo empieza a
  fallar. Vigila `redis-cli info memory`.
- Con la retención en el bucket, los datos de un negocio dado de baja viven en las copias hasta
  12 meses más: el aviso de privacidad y los términos deben decirlo.
- Las tareas en segundo plano no se esperan al detener el programador: un corte las
  interrumpe. Se retoman en su siguiente vuelta y sus candados se limpian al arrancar y al
  desplegar.
