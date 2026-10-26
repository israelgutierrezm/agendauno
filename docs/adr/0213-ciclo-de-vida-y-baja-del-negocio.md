# ADR 0213 — Ciclo de vida del negocio: fin de la prueba, estado de facturación y baja

Estado: Aceptado (2026-10-26). Completa el ADR 0073 (suspensión por renta) y el 0102
(altas sin activar).

## Contexto

La revisión para la V1 encontró tres huecos en el ciclo de vida de un negocio:

- Un negocio en prueba seguía «en prueba» para siempre: nada lo pasaba a activo al
  terminar (la renta sí corría desde ese día).
- El estado de facturación (`estado_facturacion`) lo capturaba a mano el superadmin y no
  decía la verdad: un negocio con la renta vencida podía verse «al corriente».
- No había baja. La landing dice «cancela cuando quieras» y la ley pide borrar los datos
  de quien se va, pero ni el dueño ni la plataforma podían dar de baja un negocio; el
  dueño que quería eliminar su cuenta recibía «primero da de baja el negocio», sin forma
  de hacerlo.

## Decisión

- **Fin de la prueba.** `agendauno:ciclo-de-negocios` (a diario) pasa a `active` al
  negocio cuya prueba ya terminó (`trial_termina_en` antes de hoy).
- **Estado de facturación derivado** (`Estudio::estadoDeFacturacion`): en prueba,
  activo, con renta vencida (`past_due`: un cargo pendiente con monto cuyo vencimiento
  pasó), suspendido o cancelado. El superadmin ya no lo captura.
- **Baja** (`BajaDeNegocio`): la da el dueño desde Mi suscripción (con su contraseña y
  escribiendo la dirección del negocio) o la plataforma desde la ficha (con motivo). El
  negocio queda `cancelled` con `dado_de_baja_en`, `baja_por` (`dueno` o `plataforma`),
  `baja_motivo` y `datos_borrar_en`; al dueño le llega un correo con esa fecha.
  - En cola (`CerrarNegocioDadoDeBaja`) se quitan los pagos automáticos de sus clientes
    (las suscripciones se cancelan en la pasarela) y se cierran las sesiones de todos
    menos sus dueños.
  - Durante el plazo (parámetro de la plataforma `negocios.dias_borrar_baja`, 30 días
    por omisión, de 7 a 365) solo entra su dueño, y solo a la pantalla de la baja y a su
    suscripción: paga lo pendiente y, si la pidió él, la revierte. La que hizo la
    plataforma solo la revierte la plataforma. Su página, su app y su directorio dejan
    de verse; sus avisos y tareas dejan de correr.
  - Siguen atendiéndose el dinero en curso (avisos de las pasarelas, devoluciones,
    cancelaciones y su conciliación) y sus respaldos (`Estudio::conPagosEnCurso`).
  - Su último mes de renta se cobra: los cargos se emiten hasta el mes de la baja y un
    plan por adelantado ya no se renueva.
  - Vencido el plazo, `agendauno:ciclo-de-negocios` borra su base, sus archivos
    (públicos y privados) y sus respaldos, y anota `datos_borrados_en`. Se quedan su
    registro, sus cargos y sus facturas de la renta: la ley obliga a guardarlos. Ya no
    se puede revertir.
- **Revertir** vuelve a prueba si aún no termina, si no a activo, y aplica lo de
  reabrir tras una suspensión (ADR 0166). Los pagos automáticos quitados no vuelven
  solos.
- El borrado de la base es uno solo (`GestorDeConexionTenant::borrarBaseDeDatos`), con la
  misma guarda del nombre de la base que ya tenía la limpieza de altas sin activar.
- **Descargar todos los datos** (`ExportacionDeDatosNegocio`): el dueño la pide desde Mi
  suscripción (o desde la pantalla de la baja). Se arma en cola un ZIP con un CSV por
  tabla de su base (UTF-8 con BOM, celdas a salvo de fórmulas) y sus archivos, más un
  LEEME. No salen contraseñas, tokens, llaves, credenciales ni huellas (por el nombre de
  la columna) ni las tablas técnicas o de terceros. Queda en el disco privado 7 días
  (`exportaciones_negocio`, en el control plane), se avisa por correo y solo la descarga
  el dueño con su sesión. Pedir otra reemplaza la anterior; las vencidas las borra
  `agendauno:ciclo-de-negocios`, y la baja borra todas.

## Consecuencias

- «Cancela cuando quieras» y «tus datos son tuyos» son verdad, y el dueño puede eliminar
  su cuenta después de dar de baja el negocio.
- La columna `estado_facturacion` se conserva por compatibilidad, pero ya no se lee.
