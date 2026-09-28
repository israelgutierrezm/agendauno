# ADR 0051 — Operación en producción: latidos, alertas, respaldos fuera del servidor y versiones

Estado: Aceptado (2026-09-27). Guía de operación: `docs/DESPLIEGUE.md`.

## Contexto

Que la página abra no prueba que la instalación opere. En la revisión previa al
VPS faltaba comprobar:

- que la cola y las tareas programadas siguen vivas;
- que alguien se entera cuando fallan pagos, correos o respaldos;
- que hay copias fuera del propio servidor y que se pueden restaurar;
- cómo actualizar y volver a una versión anterior.

## Decisiones

- **Latidos.**
  - `agendauno:latido` corre cada minuto desde el programador. Marca el latido del
    programador y encola un trabajo mínimo que marca el de la cola; ambos quedan en
    la caché (Redis).
  - Docker usa `--verificar=programador|cola` como chequeo de salud del scheduler y
    del worker.
  - `/api/v1/health` informa los latidos. `?estricto=1` responde 503 si faltan; es
    para un monitor externo, porque un programador detenido no puede avisar solo.
- **Alertas por correo al superadmin (`ALERTAS_CORREO`), sin dependencias nuevas.**
  - La tabla `alertas_plataforma` agrupa por tipo y clave, con un contador.
  - Fuentes: toda excepción reportada (un gancho en el manejador), trabajos
    fallidos (`JobFailed`), correos y webhooks que agotan sus intentos, incidencias
    de cobro nuevas, respaldos y simulacros fallidos, y la cola sin latido.
  - `agendauno:enviar-alertas` manda un resumen cada 10 minutos, en el acto y no por
    la cola, que podría ser lo que falla.
  - Lo ya avisado se vuelve a avisar a las 6 h, si sigue pasando.
  - Sentry u otra herramienta queda como opción futura.
- **Respaldos fuera del servidor.**
  - Disco compatible con S3 (`league/flysystem-aws-s3-v3`: R2, B2, S3…).
  - Además de cada negocio, se respaldan la base central y los archivos subidos.
  - Cada archivo lleva su sha256 al lado y se comprueba antes de restaurar.
  - `agendauno:simulacro-restauracion` corre cada semana. Restaura el último
    respaldo de la plataforma y el de un negocio en bases temporales (`tenant_simulacro_*`,
    dentro del permiso `tenant\_%`), cuenta sus tablas y las borra. El resultado
    queda en la configuración de la plataforma.
  - `agendauno:verificar-produccion` pide respaldos de menos de 26 h y un simulacro
    exitoso de menos de 8 días.
- **Versiones.**
  - Las imágenes se etiquetan con el commit (`VERSION`), no `latest`.
  - `actualizar.sh`:
    1. construye;
    2. respalda la plataforma y los negocios antes de migrar (sin respaldo no
       sigue);
    3. pone mantenimiento;
    4. migra;
    5. levanta;
    6. verifica;
    7. anota en `.historial-versiones`.
  - `volver.sh` regresa a la versión anterior sin reconstruir.
  - Las migraciones siguen el patrón expandir y contraer (lo que se quita, en
    otra versión), para que la versión anterior funcione con el esquema nuevo.
    Si no, se restaura el respaldo tomado antes de actualizar.
- **`agendauno:verificar-produccion`** reúne todo lo anterior junto con el entorno,
  el correo, el aviso de privacidad y la pasarela de la plataforma. Sale con error
  si falta algo crítico.

## Consecuencias

- Operar exige `ALERTAS_CORREO`, un bucket externo y un monitor externo del
  chequeo estricto. La verificación los marca si faltan.
- Los respaldos de archivos pueden crecer. El paquete diario es completo, no
  incremental; si pesa demasiado, se cambia a una copia incremental del volumen.
- Lo que solo se prueba con dinero o correos reales (cobros de extremo a extremo,
  entrega en bandejas) queda en `docs/VERIFICACION-V1.md`.
