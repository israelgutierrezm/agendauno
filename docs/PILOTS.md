# Validación con pilotos

Cada piloto se arma solo con configuración del mismo núcleo. Criterio de paso en
todos: ninguna rama de código por industria.

## Estudio de clases (pole, pilates, yoga)

Configuración:
- varias sucursales;
- actividades con niveles (principiante, intermedio, avanzado), talleres;
- varios instructores por clase;
- membresías limitadas e ilimitadas, paquetes, clases extra;
- ventana de reserva, cancelación con tolerancia, lista de espera;
- pase de lista.

## Escuela de natación

Configuración:
- alberca y carriles como recursos;
- niveles por actividad;
- grupos fijos (inscripción recurrente);
- capacidad por clase;
- pase de lista.

Pendiente de evaluar con el piloto: restricciones por edad y clases de reposición.

Sin cuentas de tutor: el pago y la reserva los hace la cuenta del alumno o el equipo
del negocio (ADR 0059).

## Gimnasio

Configuración:
- acceso libre con membresía (check-in y QR, sin reservar);
- clases grupales;
- entrenamiento personal como cita;
- membresía válida en varias sucursales.

## Negocio de citas (barbería, salón, estética)

Configuración:
- servicios con duración y márgenes;
- profesionales con horario de atención y bloqueos;
- agendar en línea sin cuenta, con pago para reservar;
- recordatorios y reprogramación desde la cuenta.

## Regla de arquitectura

Cada requisito nuevo de un piloto se clasifica como:
1. configuración del núcleo;
2. capacidad reutilizable del núcleo;
3. extensión justificada por perfil (terminología, flags, valores por defecto);
4. personalización del negocio (parámetros).

El código específico de un negocio está prohibido.
