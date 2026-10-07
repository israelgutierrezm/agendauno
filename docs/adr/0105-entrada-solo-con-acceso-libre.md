# ADR 0105 — La entrada solo se registra en negocios de acceso libre

Estado: Aceptado (2026-10-07). Precisa el control de acceso (R12) y el pase QR del
alumno (ADR 0047).

## Contexto

En un estudio como Grecon (pole, pilates, yoga, danza…) no existe la entrada al lugar
como tal: entrar es tomar una clase reservada, con lugar. Lo importante son las clases,
y los créditos ya se descuentan solo por ellas (al reservar y al pasar lista).

Aun así, en esos negocios se ofrecían «Registrar entrada» (ficha y panel del alumno),
el escáner del pase en Recepción, la pestaña «Accesos» de la bitácora y el pase QR en
la cuenta del alumno. Registrar la entrada no tenía ningún efecto (solo una línea en la
bitácora), y a quien tenía una membresía ilimitada le respondía «Entrada permitida ·
acceso libre» aunque no tuviera clase: confundía a recepción.

Se consideró un ajuste de «modo de entrada» por negocio (llegada a la clase al escanear,
reservar y registrar llegada en un paso). Se descartó por ahora: agrega pasos y ajustes
que un estudio no necesita.

## Decisión

- La entrada solo existe en los negocios de **acceso libre**: los giros con la bandera
  `acceso_abierto` (gimnasio, CrossFit y HYROX). La decide el giro; no hay ajuste nuevo.
- En los demás (estudios y negocios de citas) la web y la app no muestran «Registrar
  entrada», el escáner del pase ni la pista del lector en Recepción, la pestaña
  «Accesos» de la bitácora ni el pase QR del alumno (`portal.pase` del API sale solo de
  la bandera, ya no de que existan entradas registradas).
- En los de acceso libre todo sigue igual: el pase QR, el escáner, «Registrar entrada»
  y la regla de acceso (reserva vigente o membresía ilimitada).
- La asistencia y los créditos no cambian: se registran por clase o cita, como antes.

## Consecuencias

- Un estudio ya no ve nada de entradas. Si algún día quiere controlar la puerta, se
  retoma el «modo de entrada» (llegada a clase al escanear) como cambio propio.
- El servidor sigue aceptando `POST /accesos` en cualquier negocio (no tiene efecto
  sobre reservas ni créditos); lo que cambia es lo que se ofrece.
- La demo de Grecon deja de sembrar entradas con QR.
