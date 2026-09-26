# ADR 0042 — Parámetros configurables: nada de límites de negocio fijos en el código

Estado: Aceptado (2026-09-26).

## Contexto

Regla del producto: cualquier límite o dato parametrizable debe poder configurarse
desde el administrador del negocio o desde el superadmin.

Había decenas de constantes de negocio en el código: tiempo para pagar una reserva
apartada (30 min), para aceptar un lugar de la lista de espera (30 min),
recordatorios (24 h y 2 h), ventana de acceso (30 min), gracias de cobro (3 y 7
días), días para calificar (30), duración de una cita sin definir (30 min), política
de cancelación de quien no tiene una (6 h) y vigencias de enlaces (24 h, 60 min).

## Decisiones

- **Un catálogo** (`CatalogoParametros`) define cada parámetro:
  - clave, grupo, etiqueta y ayuda;
  - tipo (entero o sí/no), mínimo, máximo, unidad y valor inicial;
  - si el negocio puede ajustarlo o solo la plataforma.
- **Tres niveles**, resueltos por `ParametrosTenant`: el valor del negocio (tabla
  tenant `parametros_negocio`), si no el de la plataforma (`configuracion_plataforma`,
  clave `parametros`), y si no el inicial.
  - Se leen una vez por negocio y por solicitud; lo leído no se arrastra a otra
    solicitud aunque la instancia se reutilice (controladores en caché, servidores de
    larga vida).
- **Solo de plataforma**:
  - la política de cancelación de los negocios que no definieron la suya (el negocio
    tiene la propia en Reglas de la agenda);
  - las vigencias de enlaces de seguridad.
- **Validación**: se rechaza lo que está fuera de rango, lo desconocido o lo que no es
  del negocio, con el nombre del dato en el mensaje. Vacío (null) vuelve al nivel de
  arriba.
- **API**:
  - negocio: `GET/PUT /parametros` (quien administra el negocio, con bitácora);
  - superadmin: `GET/PUT /plataforma/parametros`.
- **Pantallas**:
  - "Límites y tiempos" en Reglas de la agenda, con el valor de la plataforma como
    referencia;
  - pestaña "Parámetros" en el panel del superadmin.
- **Recordatorios**: el primero y el segundo usan las horas configuradas. El segundo
  se apaga con 0. Se conservan los nombres históricos de sus eventos (`_24h`/`_2h`).

## Consecuencias

- Todo parámetro nuevo entra al catálogo y se lee con `ParametrosTenant`. Ninguna
  constante de negocio nueva en el código.
- Quedan en código solo límites técnicos: paginación, tiempos de espera de red,
  reintentos, tamaños de lote.
- La prueba gratuita del SaaS no se tocó porque está en curso aparte.
