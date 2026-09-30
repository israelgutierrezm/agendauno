# Autorización

## Modelo

RBAC dentro de cada negocio + alcance. La frontera del negocio la da su base de
datos (`docs/TENANCY.md`); dentro de ella decide el rol activo de la sesión.

## Roles

**De sistema** (en código, `CatalogoDePermisosTenant::roles()`):

| Rol | Faceta | Qué puede |
|---|---|---|
| `propietario` | equipo | Todo (`*`). Solo otro dueño cambia a un dueño. |
| `admin` | equipo | Todo lo operativo y la configuración, menos pasarelas de pago, integraciones y roles |
| `recepcionista` | equipo | Alumnos (sin darlos de baja), ventas, reservas, pase de lista, punto de venta |
| `instructor` | instructor | Su agenda, sus clases o citas y pase de lista (alcance propio) |
| `miembro` | miembro | Su cuenta: reservar, comprar, sus documentos |

**Propios del negocio** (tabla `roles`, ADR 0057): el negocio los arma con los
permisos del catálogo, p. ej. «Coordinación». En V1 son de la faceta equipo.

La faceta decide qué parte de la app ve la sesión: el panel (equipo), el portal de
quien imparte (instructor) o la cuenta del alumno (miembro).

## Varios roles y rol activo

Una persona puede tener varios roles en el mismo negocio (p. ej. instructora y
alumna). Entra con uno: la web y la app preguntan al entrar, con el de la última vez
marcado, y hay un botón para cambiar de rol. El token guarda el rol activo y el
servidor solo concede lo de ese rol (ADR 0055).

- `$usuario->puede('permiso')` y el middleware `puede:permiso` usan el rol activo.
- `rolesEfectivos()` dice lo que la persona ES (se le agenda como profesional, reglas
  del dueño); no se usa para conceder permisos.

## Permisos

Formato `area.accion`. El catálogo completo, agrupado por área, está en
`CatalogoDePermisosTenant::catalogo()`:

- agenda: `agenda.ver`, `agenda.gestionar`, `reservas.ver`, `reservas.gestionar`,
  `asistencia.marcar`, `checkins.registrar`
- clientes: `miembros.ver`, `miembros.gestionar`, `derechos.ver`, documentos y
  formularios
- membresías: catálogo, productos, `membresias.gestionar`, `creditos.gestionar`,
  `promociones.gestionar`
- cobros: `ordenes.ver`, `ordenes.gestionar`, `pagos.reembolsar`, `facturacion.ver`
- punto de venta: `pos.vender`, inventario
- equipo: `usuarios.invitar`, `usuarios.gestionar`, `roles.gestionar`, tareas
- marketing: comunicaciones, automatizaciones, lealtad
- negocio: `estudio.gestionar`, sucursales, organizaciones,
  `integraciones.configurar`, `pagos.configurar`, `auditoria.ver`

Una prueba exige que todo permiso usado en una ruta esté en el catálogo.

**Eliminar aparte.** Donde se borra o se da de baja hay un permiso `*.eliminar` que
pide el `*.gestionar` de su área: alumnos, equipo, agenda, planes (archivar),
promociones, plantillas de mensajes y automatizaciones (ADR 0077). Reactivar y
cancelar siguen en «gestionar».

**Requisitos.** Algunos permisos necesitan otros para que sus pantallas sirvan: por
ejemplo, «gestionar agenda» arma clases con el catálogo y las sucursales
(`CatalogoDePermisosTenant::requisitos()`). Un rol propio debe traerlos: la API rechaza
uno incompleto y el editor lo explica; no se agregan solos (ADR 0057). Las pantallas
funcionan con su permiso mínimo y el menú muestra cada una solo con lo que su carga pide.

## Alcance

- **Por sucursal** (`ResolverAccesoTenant`): un rol asignado en una sucursal
  (`asignaciones_personal`) concede sus permisos solo ahí.
- **Por profesional** (`AccesoSesionTenant`): el instructor ve y opera solo sus
  clases y citas.
- **El alumno** solo ve lo suyo (sus reservas, compras y documentos).
- **Llaves de API**: alcances propios (`alcance:miembros.ver`), solo lectura.

## Nadie da más de lo que tiene

- Un rol propio solo lleva permisos que tiene quien lo arma.
- No se edita ni se borra un rol con permisos que uno no tiene, ni uno que uno mismo
  tiene; no se borra un rol asignado.
- Al invitar o cambiar los roles de alguien, cada rol que se da o se quita debe caber
  en los permisos del rol activo de quien lo hace.

## Regla

Lo que la web y la app ocultan es solo comodidad. La API decide siempre. No se
autoriza por nombre de rol en el dominio cuando existe un permiso.
