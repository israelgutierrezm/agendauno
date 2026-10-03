// Mostrador (views/PosView.vue) e Inventario (views/InventarioView.vue).
export const mostradorVisual = {
  titulo: "Mostrador",
  subtitulo: "Vende productos de tu inventario y cobra en caja.",
  buscar: "Buscar producto o SKU",
  sinResultados: "Ningún producto coincide con la búsqueda.",
  enExistencia: "{n} en existencia",
  ventaActual: "Venta actual",
  vacia: "Toca un producto para agregarlo.",
  piezas: "1 pieza | {n} piezas",
  menos: "Quitar uno",
  mas: "Agregar uno",
  quitar: "Quitar de la venta",
  kpi: {
    ventasHoy: "Ventas de hoy",
    vendidoHoy: "Vendido hoy",
    ticket: "Ticket promedio",
    sinStock: "Productos sin stock",
  },
  col: {
    cuando: "Cuándo",
    productos: "Productos",
    acciones: "Acciones",
  },
};

export const inventarioVisual = {
  titulo: "Inventario",
  subtitulo:
    "Tus productos de mostrador, su precio y cuántos hay en cada sucursal.",
  nuevo: "Nuevo producto",
  editarTitulo: "Editar producto",
  creado: "Producto agregado.",
  movido: "Movimiento registrado.",
  altaAyuda:
    "Después registra cuántos tienes con «Movimiento» (una entrada de stock).",
  buscar: "Buscar por nombre o SKU",
  filtroVenta: "Venta",
  filtroStock: "Stock",
  todos: "Todos",
  sinResultados: "Ningún producto coincide con los filtros.",
  limpiar: "Limpiar filtros",
  movimiento: "Movimiento",
  registrar: "Registrar movimiento",
  entradaAyuda:
    "Lo que llega a la sucursal (una compra o un reabastecimiento).",
  stockBajoAyuda:
    "«Stock bajo» es cuando quedan {n} piezas o menos; se ajusta en Configuración → Límites y tiempos.",
  tipo: {
    entrada: "Entrada",
    ajuste: "Ajuste",
  },
  estado: {
    con_stock: "Con stock",
    bajo: "Stock bajo",
    sin_stock: "Sin stock",
  },
  kpi: {
    productos: "Productos a la venta",
  },
  col: {
    producto: "Producto",
    sku: "SKU",
    precio: "Precio",
    stock: "Stock",
    estado: "Estado",
    acciones: "Acciones",
  },
};

// Membresías y paquetes (views/VentasView.vue): vender un plan.
export const ventasVisual = {
  titulo: "Membresías y paquetes",
  tituloCitas: "Bonos y membresías",
  ventaActual: "Venta",
  eligePlan: "Elige un plan de la lista.",
  verTodas: "Ver todas en Cobros",
  kpi: {
    ventasHoy: "Ventas de hoy",
    vendidoHoy: "Vendido hoy",
    porCobrar: "Órdenes por cobrar",
    planes: "Planes a la venta",
  },
  col: {
    cuando: "Cuándo",
    plan: "Plan",
  },
};

// Planes y paquetes (views/PlanesView.vue) con el patrón de los listados.
export const planesVisual = {
  buscar: "Buscar plan",
  todos: "Todos",
  aLaVenta: "A la venta",
  mientrasPague: "Mientras se pague",
  sinResultados: "Ningún plan coincide con la búsqueda.",
  kpi: {
    aLaVenta: "Planes a la venta",
    archivados: "Archivados",
  },
  col: {
    plan: "Plan",
    incluye: "Incluye",
    dura: "Cuánto dura",
    sirve: "Para qué sirve",
    precio: "Precio",
    estado: "Estado",
    acciones: "Acciones",
  },
};

// Cobros (views/CobranzaView.vue) con el patrón de los listados.
export const cobranzaVisual = {
  buscar: "Buscar por cliente",
  sinResultados: "Ningún movimiento coincide con los filtros.",
  deTotal: "{n} de {total}",
  filtro: {
    todos: "Todos",
    aprobado: "Aprobados",
    otros: "Con algún cambio",
  },
  kpi: {
    cobros: "Cobros aprobados",
    cobrado: "Cobrado",
    reembolsado: "Reembolsado",
    sinAprobar: "Con algún cambio",
    enMora: "En mora",
    suspendidos: "Suspendidos",
    renovaciones: "Renovaciones próximas",
    automatico: "Con pago automático",
  },
};

// Equipo (views/InstructoresView.vue) con el patrón de los listados.
export const equipoVisual = {
  subtitulo: "Quién atiende, dónde y cómo viene su semana.",
  deTotal: "{n} de {total}",
  kpi: {
    conAgenda: "Con agenda esta semana",
    clasesSemana: "Clases esta semana",
    citasSemana: "Citas esta semana",
    calificacion: "Calificación promedio",
  },
  col: {
    persona: "Persona",
    sedes: "Sedes",
    acciones: "Acciones",
  },
};

// Promociones (views/PromocionesView.vue) con el patrón de los listados.
export const promocionesVisual = {
  subtitulo:
    "Códigos de descuento: cuánto descuentan, cuántas veces y hasta cuándo.",
  buscar: "Buscar código",
  sinResultados: "Ninguna promoción coincide con los filtros.",
  desde: "En compras desde {monto}",
  filtro: {
    todas: "Todas",
    vigentes: "Vigentes",
    no_vigentes: "No vigentes",
  },
  kpi: {
    vigentes: "Vigentes",
    usos: "Veces usadas",
    porVencer: "Vencen en 7 días",
    noVigentes: "No vigentes",
  },
  col: {
    codigo: "Código",
    descuento: "Descuento",
    estado: "Estado",
    acciones: "Acciones",
  },
};

// Salas y equipos (views/RecursosView.vue) con el patrón de los listados.
export const recursosVisual = {
  subtitulo:
    "Las salas, cabinas o equipos que usa cada servicio, por sucursal.",
  buscar: "Buscar por nombre o tipo",
  sinResultados: "Ningún recurso coincide con la búsqueda.",
  acciones: "Acciones",
  pool: "Varios iguales · {n}",
  unidad: "Uno a la vez",
  kpi: {
    unidades: "Únicos",
    pools: "En grupos (piezas)",
    sedes: "Sucursales con recursos",
  },
};
