# ADR 0198 — Reserva con Google (integración «redirect»)

Estado: Aceptado (2026-10-20). Sin validar con Google: requiere que la plataforma sea
aprobada como socia del Actions Center.

## Contexto

Fitune y Atom ofrecen Reserva con Google: el botón «Reservar» en la ficha del negocio en
Búsqueda y Maps. La integración completa (Google reserva en nuestro servidor) pide un
servidor de reservas certificado. La integración «redirect» solo manda a la página del
negocio, pero también pide la aprobación de Google:

- feeds diarios por SFTP con las entidades (sedes) y sus acciones (el enlace);
- el aviso de conversión cuando una visita que llegó desde Google termina en reserva,
  con el `rwg_token` que Google agrega al enlace.

## Decisión

- **Integración «redirect»**, con el id de socio de la plataforma
  (`RESERVA_GOOGLE_PARTNER_ID`). Sin él, no se ofrece ni se genera nada.
- **El negocio decide aparecer** (`estudios.en_reserva_google_desde`, desde Sitio web →
  Compartir). Necesita su página publicada y al menos una sede con dirección y ubicación
  (latitud y longitud). Cada sede así es una entidad de Google.
- **Los feeds** (entidades y acciones, en JSON, `PROCESS_AS_COMPLETE`) los genera
  `agendauno:reserva-con-google` cada día en el disco configurado. La acción es
  `appointment_info` con el enlace a la página del negocio (citas: agendar; clases: su
  página), en su dominio propio si lo tiene (ADR 0189). Subirlos al SFTP de Google es un
  paso de operación: la imagen no trae un cliente SFTP.
- **Conversión**: la web guarda el `rwg_token` 30 días por negocio en el navegador. Al
  reservar (agendar una cita o reservar una clase) se lo manda a la API, que avisa a
  Google con el id de socio (`merchant_changed = 2`) y la web lo olvida. Solo el token:
  ningún dato de la persona.
- **Bitácora**: `google.reserva_activada` y `google.reserva_desactivada`.

## Consecuencias

- Mientras Google no apruebe a la plataforma, nada cambia para los negocios.
- La categoría de cada entidad y los campos extra que pida Google se ajustan en la
  incorporación: el formato se revisa con su validador de feeds.
