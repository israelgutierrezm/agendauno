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

## Consecuencias

- Una migración que falla deja el esquema a medias: la versión anterior sigue atendiendo,
  pero hay que corregir el negocio afectado antes de volver a publicar. Si una migración no
  fuera compatible con la versión anterior, se restaura con los respaldos recién tomados.
- Sin respaldo de las bases de los negocios antes de una versión sin migraciones de negocio,
  el punto de regreso de esas bases es el respaldo diario.
