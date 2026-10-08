# ADR 0106 — Revisión previa a producción: dinero, sesiones y tareas programadas

Estado: Aceptado (2026-10-07). Complementa la ADR 0102 (endurecimiento para
producción).

## Contexto

Antes de salir se revisó todo el sistema en las dos modalidades (web, app y API). Casi
todo lo que apareció eran ajustes de pantalla, pero hubo huecos del servidor que en
producción cuestan dinero o seguridad:

- Con un negocio suspendido, el aviso de la pasarela respondía 404 y la conciliación
  solo revisaba negocios en prueba o activos: un pago en tienda o una devolución que se
  completaban durante la suspensión no se registraban nunca.
- La renta del SaaS no tenía conciliación: si el aviso de Stripe se perdía, el cargo se
  quedaba pendiente y, tras la gracia, el negocio se suspendía aunque hubiera pagado.
- Las tareas programadas recorren los negocios uno por uno sin aislar errores: un
  negocio con un dato roto detenía la tarea (cobros, outbox, mensajes, recordatorios)
  para todos los que seguían, en cada corrida.
- Las sesiones (tokens) no vencían.
- La firma de los avisos de Stripe no revisaba la antigüedad ni varias firmas.
- Las plataformas de bienestar (Wellhub, TotalPass) aceptaban cualquier dirección del
  proveedor (incluida la red interna) y no llevaban `modalidad:clases`.

## Decisión

- **Avisos con el negocio suspendido**: el aviso de una pasarela
  (`webhooks/tenant/{estudio}/{proveedor}`) pasa aunque el negocio esté suspendido;
  `conciliar-pagos` y `conciliar-reembolsos` también recorren los suspendidos. El resto
  del negocio sigue cerrado.
- **Conciliación de la renta**: `agendauno:conciliar-renta` (cada 15 minutos) pregunta
  a Stripe por los cargos pendientes con un intento en curso (tras 10 minutos de
  gracia) y aplica lo que habría aplicado el aviso: pagado o intento terminado. Al
  volver a «Pagar», si el intento anterior ya se pagó, se confirma y no se abre otro.
- **Tareas aisladas por negocio**: `GestorDeConexionTenant::ejecutarAislado` corre la
  tarea en un negocio y, si falla, la reporta (con el negocio) y sigue con los demás.
  Lo usan todas las tareas programadas que recorren negocios.
- **Sesiones que vencen**: una sesión sin usarse en `sesion.dias_inactividad` días (60
  por omisión; el negocio puede bajarlo) ya no sirve y se borra; usarla la renueva.
  `limpiar-registros` borra las vencidas de cada negocio.
- **Firma de Stripe**: se acepta cualquiera de sus `v1` (rotación del secreto) y solo
  si se firmó hace 5 minutos o menos.
- **Wellhub / TotalPass**: sus rutas llevan `modalidad:clases` (como las clasifica la
  ADR 0104); ver abajo cómo se validan.
- **Topes**: `/health` y los avisos de las pasarelas tienen tope de peticiones por IP;
  el programador suelta al arrancar los candados que dejó una caída.
- **Cobro de una cita**: recepción cobra y ve el total de la orden de la cita (fijo
  desde que se agendó), no el precio actual del catálogo.
- **Documentos legales de AgendaUno**: el aviso de privacidad y los términos (con
  una parte para los negocios y otra para sus clientes) tienen su texto base en
  `resources/legales`; el superadmin los ve precargados, con hola@agendauno.mx, y al
  publicarlos se llenan el responsable y su domicilio (obligatorios) en los dos. Los
  términos tienen su página pública (`/terminos`).
- **Aviso de privacidad de cada negocio** para sus clientes: es el documento con la
  clave reservada `aviso-privacidad` (se firma en el portal como cualquier otro), con
  una plantilla en Documentos que no se publica con campos por llenar. Se consulta
  sin sesión (`GET /aviso-privacidad`, página `/estudio/{slug}/aviso-de-privacidad`) y
  se enlaza en su página y antes de agendar sin cuenta.
- **Wellhub y TotalPass**: solo para negocios en México (`INTEGRATION_ONLY_MEXICO`).
  Se validan con las API que documentan: Wellhub con su Access Control API
  (`POST /access/v1/validate`, `Authorization: Bearer` y `X-Gym-Id`, Wellhub ID de 13
  dígitos; una visita por día) y TotalPass con el uso del token
  (`POST /service/v1/track_usages`, `x-api-key`). Sin dirección propia del proveedor
  (no hay SSRF posible); las URL salen de la configuración. Pendiente para certificar
  con Wellhub: su aviso automático de check-in (webhook) y darse de alta como sistema.

## Consecuencias

- Quien no usa la app o la web en el plazo vuelve a poner su contraseña; las pruebas
  del API fijan un plazo muy largo (hay pruebas que viajan meses con la misma sesión) y
  el vencimiento se prueba aparte.
- Un error en un negocio ya no se ve como una tarea caída: llega al monitoreo con el
  negocio, y la tarea termina bien para los demás.
- Las integraciones de bienestar siguen la documentación pública de cada proveedor;
  falta probarlas con credenciales reales (sandbox de Wellhub y TotalPass).
