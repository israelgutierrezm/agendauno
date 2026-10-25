// La sucursal con la que se trabaja (barra superior y filtros del panel).
export default {
  etiqueta: "Sucursal con la que trabajas",
  etiquetaCorta: "Sucursal",
  operandoEn: "Operando en",
  todas: "Todas las sucursales",
  // Leyenda en los formularios: dónde queda lo que se registra.
  seRegistraEn: "Se registra en {sucursal}",
  // Con varias sucursales y «Todas» en la barra, un cobro no sabe a qué caja va.
  eligeParaCobrar:
    "Elige la sucursal en la barra de arriba para cobrar: el cobro entra en su caja.",
  ayuda:
    "Elige una sucursal y el panel la usa en la agenda, el mostrador y los formularios.",
  // Personal sin sucursal asignada (ADR 0098).
  sinSucursal: {
    titulo: "Aún no tienes una sucursal asignada",
    detalle:
      "Mientras tanto no verás agenda, alumnos ni cobros. Pide al administrador del negocio que te asigne una.",
  },
  // En Usuarios: el personal necesita al menos una sucursal.
  sinSucursalUsuario: "Sin sucursal: no ve nada hasta que le asignes una.",
  ultimaSucursal: "Debe tener al menos una sucursal.",
};
