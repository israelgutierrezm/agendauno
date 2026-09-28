# ADR 0054 — Actualizar con punto de corte, abrir solo si atiende y simulacro que comprueba la operación

Estado: Aceptado (2026-09-27). Amplía el ADR 0051. Corregido por el ADR 0060: en mantenimiento, el programador y el worker se comprueban con señales que no reactivan la operación.

## Contexto

Una revisión del proceso de operación encontró cinco problemas:

1. `actualizar.sh` quitaba el mantenimiento **antes** de verificar. Si la
   verificación fallaba, igual anotaba la versión como actual y terminaba en «Listo».
2. Respaldaba **antes** de poner mantenimiento, con la cola y el programador
   trabajando. Una reserva o un pago aceptados en ese intervalo podían quedar fuera
   del respaldo. Además, Docker mata a los 10 s por defecto: una tarea larga
   (un respaldo nocturno) se cortaba y su candado la bloqueaba 24 h.
3. El simulacro de restauración daba por buena una base con más de cero tablas y
   no recuperaba los archivos subidos.
4. `verificar-produccion` podía decir «Lista para producción» con Stripe de la
   plataforma en modo de prueba.
5. Dos pruebas de respaldos suponían la base de la suite en SQLite en memoria y
   fallaban con MySQL (la configuración de `phpunit.xml` y de CI).

Revisando el script apareció un sexto: `git checkout` cambiaba `actualizar.sh`
mientras corría, y `sh` lee el archivo conforme avanza.

## Decisiones

- **Punto de corte antes de respaldar.** Primero mantenimiento, luego detener la cola
  y el programador esperando a que terminen, y luego respaldar y migrar.
  - `stop_grace_period` de 5 min para la cola y 15 min para el programador.
  - `schedule:work` y `queue:work` atienden SIGTERM: dejan de tomar trabajo nuevo y
    terminan lo que tienen.
  - Después se corre `schedule:clear-cache`, por si alguna tarea se cortó al vencer
    la espera.
  - Si el respaldo falla, se reabre la versión anterior y no se migra.
- **Abrir solo si atiende.** La versión nueva se levanta en mantenimiento. Para
  quitarlo, espera hasta 5 minutos a que pasen dos comprobaciones:
  - `verificar-produccion --disponibilidad`: APP_KEY, base, caché, esquema de la
    plataforma y de cada negocio al día, y latidos del programador y de la cola. Los
    latidos se borran antes de levantar, así que solo cuentan los procesos nuevos.
  - Una petición real a `/api/v1/health?estricto=1` por nginx y PHP-FPM. Pasa el
    mantenimiento con la galleta de `artisan down --secret`, en una ruta `api/…` para
    que nginx la entregue a PHP.

  Si no pasan, la versión queda en mantenimiento, no se anota como actual y el script
  sale con error y con las opciones para corregir o volver. La base nunca se revierte
  sola: las migraciones son compatibles hacia atrás, y si alguna no lo fuera se
  restauran a mano los respaldos recién tomados.
- **Después de abrir, la verificación completa.** Si falta algo para operar en
  producción, el script lo dice y sale con código 2, sin «Listo».
  - Estos pendientes no cierran el sitio, porque no los causa la versión nueva: un
    simulacro vencido no debe tumbar el servicio en cada actualización.
  - Lo que sí depende de la versión (que atienda) es lo que bloquea la apertura.
- **`volver.sh` sigue el mismo corte** y solo reabre si la versión atiende. Solo usa la
  petición de salud, que existe en todas las versiones anteriores.
- **Los scripts se leen completos antes de correr**: el cuerpo va en una función que
  se llama al final.
- **Apertura comercial explícita** (`APERTURA_COMERCIAL`, o `--apertura`). Con ella,
  Stripe de la plataforma en modo live es un punto que falta. Sin ella, es un aviso y
  la verificación termina en «Lista como instalación sin cobro real de la renta».
- **Simulacro que comprueba la operación** (`ComprobacionRestauracion`). Restaura la
  base central, la de un negocio y los archivos en lugares temporales, y revisa:
  - las tablas esenciales;
  - las migraciones registradas;
  - que estén los negocios que existían al respaldar, contados por otra conexión,
    como los ve el volcado;
  - que haya un dueño con acceso;
  - que no haya registros huérfanos: sesión→oferta, reserva→sesión y persona,
    pago→orden, acuerdo→persona, derecho→acuerdo, movimiento→derecho, y cargos y
    mediciones→negocio;
  - que corran las consultas de operación: agenda con sus alumnos, saldos del libro y
    negocios con sus cargos;
  - que los documentos, fotos y logos en uso de antes del respaldo sean idénticos a
    los recuperados (sha256, hasta 200 archivos).
- **Pruebas según el motor de la suite.** El respaldo se comprueba como archivo SQLite
  o como SQL de mysqldump, según corresponda. En el entorno `testing`, restaurar la
  base central solo se permite sobre una base desechable (`tenant_simulacro_*`,
  `*_desechable` o un SQLite dentro de `storage/framework/testing`), nunca sobre la
  de la suite.

## Consecuencias

- Una actualización tarda más: espera a que terminen los trabajos en curso y a que la
  versión nueva lata. A cambio, el respaldo trae todo lo aceptado y nada se abre sin
  atender.
- Durante el mantenimiento, los avisos de las pasarelas reciben 503. Las pasarelas
  reintentan, y la conciliación de pagos (ADR 0053) recupera lo que se pierda.
- Las páginas estáticas de la versión nueva (el sitio comercial) se publican al
  levantar el contenedor web, antes de que la API abra.

## Actualización (2026-09-28): simulacro más estricto

- El respaldo de la plataforma guarda su inventario (`.inventario.json`): los negocios
  que trae, tomados antes del volcado y como los ve el volcado. El simulacro exige que
  estén todos; ya no basta con que venga alguno.
- «Dueño con acceso» exige un dueño activo, sin baja y con contraseña o Google (antes
  contaba cualquier cuenta con el rol). El simulacro prefiere probar un negocio que ya
  terminó su onboarding.
- La base temporal del simulacro en MySQL se crea y se borra por una conexión aparte:
  `CREATE/DROP DATABASE` confirmaban la transacción de quien lo llamaba.
