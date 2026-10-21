# ADR 0187 — Requisito de nivel y práctica libre

Estado: Aceptado (2026-10-20).

## Contexto

Los estudios de pole, danza y natación separan sus clases por nivel («requiere Pole Fit
básico»), y los de gimnasio y pole ofrecen práctica libre (Open Pole, Open Gym): sin
instructor y con tantos lugares como equipo. Ya había niveles por actividad, pero ni las
clases los pedían ni los clientes los tenían.

## Decisión

- **Una clase puede pedir un nivel** de su actividad (`ofertas.nivel_id`, migración de
  negocio 000180). La reserva desde la cuenta o la app la valida el servidor: el cliente
  necesita ese nivel o uno más alto, según el orden de los niveles. Si no, responde 422
  `LEVEL_REQUIRED`, y «antes de reservar» lo avisa con el motivo `nivel`. El equipo puede
  reservarle de todos modos.
- **El nivel de cada cliente por actividad** (`personas_niveles`) lo asigna quien gestiona
  clientes, desde su ficha (`GET/PUT /miembros/{persona}/niveles`). El cambio queda en la
  bitácora (`persona.nivel`).
- **Práctica libre** (`ofertas.practica_libre`). No lleva instructor. Si a la fecha se le
  asigna un espacio con equipo (un recurso con capacidad), su cupo es ese equipo, salvo
  que se ponga otro.

## Consecuencias

- Las clases que no piden nivel funcionan igual que antes.
- Un cliente sin nivel asignado no reserva desde su cuenta las clases que piden uno: el
  negocio debe asignárselo.
