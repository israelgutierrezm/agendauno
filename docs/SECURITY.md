# Seguridad

## Amenazas principales

- fuga de datos entre negocios
- IDOR / autorización rota a nivel de objeto
- escalamiento de privilegios (roles propios, cambio de rol)
- archivos subidos inseguros
- webhooks falsos o repetidos
- estado de pago falsificado
- secretos filtrados
- asignación masiva

## Controles

- Una base de datos por negocio; el negocio sale de la ruta o del subdominio, nunca
  del cuerpo (`docs/TENANCY.md`).
- Tokens propios por negocio: solo se guarda el hash; el token lleva el rol activo
  y se revoca al salir.
- Autorización en el servidor: permiso del rol activo + alcance por sucursal y por
  profesional (`docs/AUTHORIZATION.md`). Nadie da más permisos de los que tiene.
- ULIDs en la API; nunca IDs internos.
- Validación estricta de cada petición y `$fillable` explícito.
- Límites de peticiones en login, recuperación de contraseña, registro y rutas
  públicas.
- Webhooks: se verifican con la pasarela, son idempotentes y un cobro no confirmado
  se concilia consultando a la pasarela (ADR 0013, 0053).
- Llaves de pasarelas y de integraciones cifradas en la base; la web nunca las
  vuelve a mostrar.
- Webhooks salientes solo a destinos públicos (bloquea redes internas).
- Archivos del negocio en su propio espacio; los documentos privados se descargan
  solo por la API, con sesión y permiso.
- Bitácora (`auditorias`) de lo sensible: dinero, roles, bajas, cambios de
  configuración.
- Monitoreo de errores sin datos sensibles: trazas sin argumentos, SQL sin valores y
  correos, llaves, tokens y números largos tachados (ADR 0080).
- Registro público con verificación por correo (ADR 0028) y reCAPTCHA v3, obligatorio
  en producción (`agendauno:verificar-produccion` lo exige). Las altas que nadie
  activa se borran solas (ADR 0102).
- Derechos ARCO y aviso de privacidad versionado con aceptación registrada.
- HTTPS delante de nginx (certificado comodín) y encabezados de seguridad en nginx
  (`infra/produccion`, `docs/DESPLIEGUE.md`).

## Menores

Solo se guarda lo necesario para operar (reservas, asistencia, expediente). Las
responsivas y la aceptación de documentos quedan registradas. No hay cuentas de
tutores ni familias (ADR 0059).
