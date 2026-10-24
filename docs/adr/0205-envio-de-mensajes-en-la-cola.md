# ADR 0205 — Envío de mensajes en la cola, con avisos y campañas separados

Estado: Aceptado (2026-10-24).

## Contexto

Una revisión encontró un riesgo en el código, no un incidente medido:

- `agendauno:enviar-mensajes` corría cada minuto en el programador.
- Recorría los negocios uno tras otro y mandaba el correo dentro de ese proceso, hasta
  500 mensajes por negocio.
- El programador corre sus tareas en secuencia. Un proveedor de correo lento o una
  campaña grande podían retrasar las demás tareas del minuto: expirar reservas sin
  pagar, despachar el outbox, ofertas de la lista de espera…
- Una campaña también competía con los avisos de operación (recordatorios,
  confirmaciones).

La infraestructura de colas ya existía; faltaba usarla en este recorrido.

## Decisión

- **El programador solo encola.** `agendauno:enviar-mensajes` encola, por cada negocio
  operativo, dos trabajos `EnviarMensajesDelNegocio`: uno de avisos (cola `mensajes`)
  y otro de campañas (cola `campanas`). Termina en segundos.
- **Avisos y campañas separados.**
  - Avisos: mensajes sin difusión (operación, recordatorios, transaccionales).
  - Campañas: mensajes de una difusión; una campaña en pausa no cuenta.
  - El trabajador atiende `--queue=default,mensajes,campanas`: primero el latido y los
    correos de la plataforma, luego los avisos y al final las campañas.
  - Si un día no alcanza, se agrega un trabajador propio para `campanas` sin cambiar el
    código.
- **Tiempos máximos.**
  - Cada pasada tiene un tope de mensajes (avisos 200, campañas 100) y de segundos
    (avisos 45, campañas 30). Lo que no alcanza sale en la pasada siguiente.
  - El trabajo dura como máximo 75 s, menos que el `retry_after` de la cola (90 s).
  - Hay una sola pasada a la vez por negocio y tipo (`ShouldBeUnique`).
  - El SMTP tiene un tiempo límite (`MAIL_TIMEOUT`, 15 s por omisión).
- **Reintentos.** El trabajo no se reintenta: cada mensaje lleva sus intentos (6, como
  antes) y la pasada del minuto siguiente lo vuelve a tomar.
- **Medición.**
  - Cada pasada anota en `cola_mensajes` (control plane, migración 000414) cuántos
    mensajes le quedan al negocio y desde cuándo espera el más antiguo, por tipo.
  - La verificación de producción marca **falta** si un aviso lleva más de 15 minutos
    esperando y **aviso** si una campaña lleva más de 2 horas.
  - El retraso del programador ya lo mide su latido («Programador: atrasado»).

## Consecuencias

- El trabajador de producción cambia su comando (`docker-compose.yml`) y el de
  desarrollo también (`composer run dev`). Con otro comando, los avisos y las campañas
  se quedarían en su cola sin enviarse; la verificación lo detecta.
- Con muchos negocios, cada minuto se encolan dos trabajos por negocio. La mayoría
  terminan al instante porque no tienen pendientes.
