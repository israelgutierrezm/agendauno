# Producto

## Visión

AgendaUno es el sistema con el que un negocio de clases o de citas vende, agenda,
cobra y atiende a sus clientes: panel web para el equipo, portal y app para el
alumno o cliente, y cobro en línea. Un solo núcleo configurable, sin versiones por
industria.

## Dos modalidades

**Clases** (estudios de pilates, pole, yoga, danza; gimnasios; escuelas de natación;
academias). Debe cubrir:
- clases recurrentes con capacidad, niveles e instructores;
- membresías ilimitadas o limitadas por ciclo, paquetes, pases y clases extra;
- ventanas de reserva, cancelación con tolerancia, lista de espera;
- pase de lista, check-in y QR de acceso;
- varias sucursales y recursos (salas, carriles).

**Citas** (barberías, salones, estética, spa, salud). Debe cubrir:
- servicios con duración, márgenes y recursos;
- profesionales con su horario de atención y bloqueos;
- agendar en línea sin cuenta, con pago para reservar;
- reprogramar y cancelar desde la cuenta del cliente;
- recordatorios y avisos al profesional.

Cada negocio es **solo de clases o solo de citas** (ADR 0104): no hay negocios
mixtos. El giro propone la modalidad al registrarse y queda guardada; el negocio
cambia de giro solo dentro de ella, y pasar de una a otra lo hace AgendaUno antes de
que el negocio opere. La modalidad decide qué flujos existen (el servidor niega los de
la otra), cómo se ve el panel y cómo se cobra el SaaS.

Lo que comparten las dos es el núcleo: clientes, equipo, sucursales, recursos,
agenda, reservas, asistencia, cancelaciones y comercio. Las membresías y los bonos
también se venden en citas (ADR 0091).

## Cobro del SaaS

Por alumnos activos (clases) o por profesionales activos (citas), con tarifas
versionadas y mes vencido (ADR 0019). Prueba de 30 días.

## Principios

- Reglas explicables: si algo se niega, se dice por qué.
- Operación diaria rápida para recepción.
- Varias sucursales desde el diseño.
- Agenda consciente de recursos.
- Pagos independientes de la pasarela; el dinero siempre cuadra (ledger, bitácora,
  conciliación).
- Límites del negocio configurables, no fijos en código.
- Aislamiento fuerte entre negocios (una base por negocio).
- Experiencia móvil primero para alumnos y profesionales.
