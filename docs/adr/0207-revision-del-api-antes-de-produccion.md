# ADR 0207 — Revisión del API antes de producción: alcance, permisos y dinero

Estado: Aceptado (2026-10-24).

## Contexto

Una revisión del API antes de producción encontró huecos que no son incidentes medidos,
sino caminos concretos en el código:

- Atajos que no pedían el permiso que pide su pantalla (saldos de apertura sin
  `membresias.cortesia`; atender una baja ARCO, que borra datos, con solo
  `miembros.gestionar`).
- Pantallas o búsquedas por ULID sin el alcance por sede (R19, ADR 0098) que sí tiene
  su listado: solicitudes ARCO, retención por segmentos, bandeja, reseñas, eventos de
  prospecto, canjes y tareas; y el asistente al generar la agenda.
- Acciones que se podían repetir: aplicar dos veces una propuesta del asistente
  (campaña doble), o seguir enviando una campaña ya cancelada.
- Dinero: devolver todo el pago de una cita que sigue en pie (la cita quedaba sin cobro
  ocupando el horario) y un cargo automático cobrado con otro importe que se descartaba
  en silencio y se volvía a cobrar.
- Abuso desde fuera: el cupo del webhook de Wellhub/TotalPass lo agotaba cualquiera con
  el slug; el login y la recuperación no tenían tope por cuenta; abrir el enlace de baja
  de promociones daba de baja (los filtros de correo lo abren solos); renovar el pase de
  la cartera no dejaba fuera al teléfono perdido.

## Decisión

**Permisos.**
- Cargar saldos de apertura pide además `membresias.cortesia` (es dar un plan sin
  cobrarlo). El corte es de hoy o de hasta un año atrás y lo cargado vence a lo más
  24 meses después del corte, por omisión: son parámetros de la plataforma que ajusta
  el superadmin (`importaciones.dias_maximos_corte_saldos`,
  `importaciones.meses_maximos_vigencia_saldos`; ADR 0042).
- Atender o rechazar una solicitud ARCO pide `miembros.eliminar`.

**Alcance por sede (mismo que la ficha, `AlcancePersonaTenant`).**
- Saldos de apertura por fila: quien no es de las sedes de quien carga se trata como si
  no existiera (no se revela que su correo está en otra sede).
- Solicitudes ARCO, retención por segmentos (listas, cuentas y seguimientos), reseñas,
  eventos de prospecto, canjes y tareas: lo que se busca por ULID tiene el alcance de su
  listado.
- Bandeja: lo que no está ligado a nadie es de todo el equipo; lo ligado a un cliente,
  con el alcance de su ficha; personal sin sede (con varias) no ve ninguna. Ligar una
  conversación pide poder ver a esa persona.
- El asistente genera la agenda solo de las series que quien aplica puede operar.

**Una sola vez.**
- Una campaña sale por un solo camino (`DifundirComunicacionTenant::unaVez`): candado
  del negocio (la llave lleva su ULID) y la regla de no repetirla en 24 horas, también
  desde el asistente. Cada propuesta del asistente se aplica una vez.
- El envío reclama cada mensaje antes de entregarlo (mismo estado e intentos, y su
  campaña no en pausa ni cancelada): pausar o cancelar frena la tanda en curso.

**Dinero.**
- La devolución que deja todo un pago devuelto y cancelaría su venta se rechaza si es de
  una cita (o clase de pago suelto) confirmada o apartada que no ha empezado: «Cancela la
  cita primero; después devuelve el pago». Las parciales siguen igual.
- Un cargo automático cobrado con otro importe u otra moneda queda registrado con lo que
  cobró y por revisar (incidencia `MONTO_DISTINTO`), sin pagar la compra por sí solo; ya
  cobrado, la renovación no se vuelve a cobrar.

**Desde fuera.**
- El cupo del webhook de bienestar es por negocio y por token de su dirección
  (`agendauno.integraciones.avisos_bienestar_por_minuto`).
- Login y recuperación: además de los topes por IP, uno por cuenta (negocio y correo) en
  la hora (`AppServiceProvider::INTENTOS_POR_CUENTA_HORA`,
  `RECUPERACIONES_POR_CUENTA_HORA`).
- Baja de promociones: GET muestra la confirmación; POST a la misma dirección firmada da
  de baja; las campañas llevan `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
  (RFC 8058).
- Renovar el pase de la cartera rota su número de serie (y con él el token de Apple
  Wallet), olvida los teléfonos registrados y deja inactivo el objeto anterior de
  Google Wallet.

## Consecuencias

- Quien antes cargaba saldos o atendía bajas ARCO con un rol de recepción ahora necesita
  el permiso correspondiente.
- La web debe confirmar la baja de promociones con un botón (ya lo hace la página que
  sirve el API) y explicar el error de devolver una cita vigente.
- Sin migraciones.
