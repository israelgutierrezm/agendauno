# ADR 0183 — Kiosco de llegada

Estado: Aceptado (2026-10-20).

## Contexto

Los competidores venden una tableta en recepción donde el cliente marca su llegada
(Fitune la cobra a $449 al mes). Ya teníamos el pase de entrada (un QR firmado y con
vencimiento) y su lectura en recepción. Faltaba una pantalla que funcione sola, sin
que alguien del equipo tenga la sesión abierta.

## Decisión

- **Cada tableta tiene su propia llave** (`kioscos`, migración de negocio 000176). La
  activa y la da de baja quien administra el negocio (`estudio.gestionar`), desde
  Integraciones → Kiosco de llegada. La llave se muestra una sola vez, dentro del enlace
  de activación. Solo se guarda su hash. La llave solo sirve para registrar llegadas
  (`X-Kiosco`, middleware `kiosco`) en la sede de la tableta.
- **La tableta** (`/estudio/{slug}/kiosco`) guarda la llave al abrir el enlace. La llave
  va en el fragmento de la dirección, así que no llega al servidor ni queda en el
  historial. El cliente muestra el QR de su pase a la cámara (con el detector de códigos
  del navegador) o a un lector USB.
- **Registrar una llegada** reutiliza lo de recepción. Se registra el acceso
  (`RegistrarAccesoTenant`, con su bitácora y su evento). Si el cliente tiene una clase a
  esta hora, también su asistencia como presente; si la clase ya empezó, con retardo. Las
  respuestas son:
  - `llegada`: se registró la asistencia.
  - `ya_registrada`: no se duplica.
  - `temprano`: la lista todavía no abre; se dice desde qué hora.
  - `sin_clase`: no tiene clase a esta hora.
  - `bienvenida`: acceso abierto de un gimnasio.
  - `pase_invalido`.
- **La pantalla solo dice el primer nombre** y la clase (nombre, hora y lugar). Nunca
  muestra datos de otras personas.
- Dada de baja, la tableta responde 401 y vuelve a pedir que la activen.

## Consecuencias

- No hace falta una app aparte: cualquier tableta con navegador sirve.
- En iOS, Safari no tiene el detector de códigos: ahí se usa un lector USB o Bluetooth.
