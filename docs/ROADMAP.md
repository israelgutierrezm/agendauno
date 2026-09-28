# Roadmap

## Hecho

- **Base**: monolito modular, una base por negocio, identidad por negocio, RBAC con
  alcance, bitácora, outbox.
- **Núcleo comercial**: catálogo, membresías, derechos, ledger de créditos, órdenes,
  pagos y reembolsos idempotentes, tres pasarelas, pago automático, conciliación.
- **Agenda y reservas**: clases y citas, series, recursos, bloqueos, lista de
  espera, reprogramar, cancelaciones, pase de lista, QR.
- **Fase 1 (operación)** y **fase 2 (agenda cotidiana)** cerradas (ADR 0035–0046).
- **Plataforma**: registro, onboarding, directorio, subdominios, superadmin, cobro
  del SaaS, documentos legales, respaldos, alertas, despliegue con Docker.
- **Experiencia**: web única (sitio, panel, portal, superadmin) y app Flutter;
  roles propios y rol activo por sesión (ADR 0055, 0057); nombre AgendaUno
  (ADR 0056).

## Antes de abrir

- Pruebas de punta a punta con llaves de prueba de cada pasarela
  (`docs/VERIFICACION-V1.md`).
- Instalar en un servidor real y correr `actualizar.sh` / `volver.sh`.
- Proyecto de Firebase para push; firma de release y publicación de la app.

## Después

- Separar «editar» y «eliminar» en el catálogo de permisos.
- Roles propios con faceta de instructor.
- Monitoreo de errores más completo y despliegue automático desde el CI.
- Analítica: ocupación, rentabilidad, demanda.
- Marketplace a partir del directorio.
- Opciones empresariales: marca blanca, OAuth para terceros.
