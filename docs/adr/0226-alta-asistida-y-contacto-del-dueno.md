# ADR 0226 — Alta asistida y contacto del dueño desde el superadmin

Estado: Aceptado (2026-10-26). Completa el soporte del superadmin (ADR 0215, 0216).

## Contexto

Soporte no podía corregir el contacto del dueño (un correo mal escrito al registrarse
dejaba la activación perdida) ni dar de alta un negocio por alguien que no puede
registrarse solo, también con el registro de un producto cerrado.

## Decisión

- **Alta asistida**: `POST /plataforma/estudios` (perfiles `total` y `soporte`) con los
  datos del registro (nombre, giro, país y el contacto del dueño). Su base se crea como en
  el registro (en la cola en producción, ADR 0222) y al dueño le llega su activación. No
  pasa por reCAPTCHA ni por «registro abierto». El dueño no aceptó el aviso ni los
  términos al registrarse: los acepta al entrar (ADR 0216).
- **Corregir el contacto**: `PUT /plataforma/estudios/{e}/contacto` (mismos perfiles).
  El nombre y el WhatsApp siempre; el correo solo mientras no haya activado su cuenta
  (es con el que entra; después lo cambia él desde su perfil): se cambia también en su
  negocio y le llega una activación nueva. La ficha trae el contacto por partes.
- Todo queda en la bitácora del superadmin.
