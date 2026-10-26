// Mostrador (views/PosView.vue) e Inventario (views/InventarioView.vue).
export const mostradorVisual = {
  titulo: "Mostrador",
  errorCarga: "No se pudieron cargar los productos y las sucursales.",
  subtitulo: "Vende productos de tu inventario y cobra en caja.",
  buscar: "Buscar producto o SKU",
  sinResultados: "Ningún producto coincide con la búsqueda.",
  enExistencia: "{n} en existencia",
  ventaActual: "Venta actual",
  vacia: "Toca un producto para agregarlo.",
  // Lo de hoy en una línea (teléfono) y la barra que lleva a la venta.
  hoyResumen:
    "Hoy: sin ventas | Hoy: 1 venta · {total} | Hoy: {n} ventas · {total}",
  verVenta: "Ver venta y cobrar",
  // La sede en que se vende; cambiarla a media venta la descarta.
  sucursal: {
    etiqueta: "Sucursal de la venta",
    ventaEn: "Venta en {sucursal}",
    cambiar: "Cambiar de sucursal",
    confirmarCambio:
      "Cambiar a {sucursal} vacía la venta actual (1 pieza): cada sucursal tiene su propio stock. | Cambiar a {sucursal} vacía la venta actual ({n} piezas): cada sucursal tiene su propio stock.",
  },
  piezas: "1 pieza | {n} piezas",
  menos: "Quitar uno",
  mas: "Agregar uno",
  quitar: "Quitar de la venta",
  // Extras de un producto (ADR 0139): p. ej. a una proteína, fresas o leche de soya.
  extras: {
    titulo: "¿Con algo extra?",
    ayuda: "Toca lo que lleva. Cada extra se cobra con su precio.",
    sinExtras: "Sin extras",
    agregar: "Agregar a la venta",
    con: "con {extras}",
  },
  // Propina (aparte de lo vendido: no es ingreso del negocio) y el cambio.
  propina: {
    titulo: "Propina",
    sin: "Sin propina",
    otra: "Otra",
    monto: "Monto de la propina",
    ayuda: "Va aparte de la venta: no cuenta como ingreso del negocio.",
  },
  // A quién se le vende (ADR 0145): una persona del negocio o público en general.
  comprador: {
    titulo: "Venta a",
    buscar: "Busca alumno, instructor o alguien del equipo",
    publico: "Público en general",
    publicoAyuda:
      "Si no eliges a nadie, se registra como venta a público en general.",
    enviarTicket: "Enviar su ticket por correo",
    aCorreo: "A {correo}",
    sinCorreo: "No tiene correo registrado: no se le puede mandar.",
    confirmarA: "Se registra como venta a {nombre}.",
    confirmarPublico: "Se registra como venta a público en general.",
    ticketEnviadoA: "Le mandamos su ticket a {correo}.",
    tipos: {
      miembro: "Alumno",
      instructor: "Instructor",
      staff: "Equipo",
    },
  },
  // Ventas recientes: por páginas y con fechas.
  ventas: {
    resumen: "{n} venta · {total} | {n} ventas · {total}",
    desde: "Desde",
    hasta: "Hasta",
    quitarFiltro: "Quitar filtro",
    sinEnFechas: "No hubo ventas en esas fechas.",
    ticketEnviado: "Ticket enviado",
    enFicha: "Compras en mostrador",
  },
  subtotal: "Productos",
  totalCobrar: "Total a cobrar",
  recibido: "Recibe",
  recibidoAyuda: "Lo que te entrega en efectivo, para darle su cambio.",
  cambio: "Cambio",
  falta: "Falta {monto}",
  vendidoCambio: "Venta registrada. Cambio: {cambio}.",
  conPropina: "+ {monto} de propina",
  kpi: {
    ventasHoy: "Ventas de hoy",
    vendidoHoy: "Vendido hoy",
    ticket: "Ticket promedio",
    sinStock: "Productos sin stock",
  },
  col: {
    cuando: "Cuándo",
    ventaA: "Venta a",
    productos: "Productos",
    acciones: "Acciones",
  },
};

export const inventarioVisual = {
  errorCarga: "No se pudieron cargar los artículos.",
  // Extras del producto (ADR 0139): lo que se le puede agregar, con su precio.
  extras: {
    titulo: "Extras",
    ayuda:
      "Lo que se le puede agregar al venderlo, con su precio: a una proteína, fresas o leche de soya.",
    nombre: "Extra",
    precio: "Precio",
    agregar: "Agregar extra",
    quitar: "Quitar extra",
  },
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
  // Por qué se ajusta a mano: queda en el historial con quién lo hizo.
  motivo: "Motivo del ajuste",
  motivoElige: "Elige el motivo",
  motivos: {
    conteo: "Conteo físico",
    merma: "Merma o daño",
    caducidad: "Caducidad",
    uso_interno: "Uso interno",
    otro: "Otro",
  },
  detalle: "Detalle (opcional)",
  detalleObligatorio: "¿Cuál es el motivo?",
  detalleEjemplo: "p. ej. se rompieron 2 frascos",
  nota: "Nota (opcional)",
  notaEjemplo: "p. ej. proveedor o número de factura",
  errores: {
    revisa: "Revisa los campos marcados.",
    fotoNoSubio:
      "El producto se guardó, pero la foto no se subió ({error}). Inténtalo de nuevo desde aquí.",
  },
  historial: {
    ver: "Historial",
    titulo: "Historial de {producto}",
    vacio: "Sin movimientos todavía.",
    venta: "Venta en mostrador",
    ventaAnulada: "Venta anulada (regresó al stock)",
    tipos: {
      entrada: "Entrada",
      salida: "Salida",
      ajuste: "Ajuste a mano",
    },
    filtros: {
      entrada: "Entradas",
      ajuste: "Ajustes",
      salida: "Ventas",
    },
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
  tituloCitas: "Bonos",
  ventaActual: "Venta",
  eligePlan: "Elige un plan de la lista.",
  eligePlanCitas: "Elige un bono de la lista.",
  verTodas: "Ver todas en Cobros",
  kpi: {
    ventasHoy: "Ventas de hoy",
    vendidoHoy: "Vendido hoy",
    porCobrar: "Órdenes por cobrar",
    planes: "Planes a la venta",
    planesCitas: "Bonos a la venta",
  },
  col: {
    cuando: "Cuándo",
    plan: "Plan",
    planCitas: "Bono",
  },
};

// Planes y paquetes (views/PlanesView.vue) con el patrón de los listados.
export const planesVisual = {
  truncado:
    "Se muestran los {n} planes más recientes (primero los que están a la venta). Archiva o elimina los que ya no uses para ver el resto.",
  buscar: "Buscar plan",
  buscarCitas: "Buscar bono",
  todos: "Todos",
  aLaVenta: "A la venta",
  mientrasPague: "Mientras se pague",
  // Con muchas clases compatibles: cuántos tipos de clase acepta (no «incluidas»).
  validoPara: "Válido para 1 tipo de clase | Válido para {n} tipos de clase",
  renovacion: {
    no: "Sin renovación automática",
    cadaMes: "Se renueva cada mes",
    acumula: "acumula lo no usado",
    acumulaHasta: "acumula hasta 1 clase | acumula hasta {n} clases",
    noAcumula: "lo no usado no se acumula",
  },
  restricciones: {
    primeraVez: "Solo para la primera visita",
    permanencia: "Permanencia de 1 mes | Permanencia de {n} meses",
  },
  sinResultados: "Ningún plan coincide con la búsqueda.",
  sinResultadosCitas: "Ningún bono coincide con la búsqueda.",
  kpi: {
    aLaVenta: "Planes a la venta",
    aLaVentaCitas: "Bonos a la venta",
    archivados: "Archivados",
  },
  col: {
    plan: "Plan",
    planCitas: "Bono",
    incluye: "Incluye",
    dura: "Vigencia y renovación",
    sirve: "Válido para",
    precio: "Precio",
    estado: "Estado",
    acciones: "Acciones",
  },
};

// Cobros (views/CobranzaView.vue) con el patrón de los listados.
export const cobranzaVisual = {
  buscar: "Buscar por alumno",
  sinResultados: "Ningún movimiento coincide con los filtros.",
  fechas: "Fechas del cobro",
  desde: "Desde",
  hasta: "Hasta",
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
    porCobrar: "Por cobrar",
    enMora: "En mora",
    suspendidos: "Suspendidos",
    renovaciones: "Renovaciones próximas",
    vencidas: "Vencidas sin cobrar",
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
  // Filtros de la lista, como en Miembros.
  filtros: {
    sede: "Sede",
    agenda: "Esta semana",
    conAgenda: "Con agenda",
    sinAgenda: "Sin agenda",
    resenas: "Reseñas",
    cuatroOMas: "4 estrellas o más",
    conResenas: "Con reseñas",
    sinResenas: "Sin reseñas",
    nadie: "Nadie coincide con la búsqueda o los filtros.",
  },
};

// Promociones (views/PromocionesView.vue) con el patrón de los listados.
export const promocionesVisual = {
  subtitulo:
    "Códigos de descuento: cuánto descuentan, cuántas veces y hasta cuándo.",
  editarTitulo: "Editar promoción {codigo}",
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
  subtituloClases: "Las salas o equipos que usa cada clase, por sucursal.",
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

// Lugares disponibles (views/OportunidadesView.vue) con el patrón de los listados.
export const oportunidadesVisual = {
  subtitulo:
    "Clases próximas con lugares libres y su lista de espera, para llenarlas.",
  kpi: {
    clases: "Clases con lugar",
    libres: "Lugares libres",
    espera: "En lista de espera",
    ocupacion: "Ocupación promedio",
  },
  col: {
    clase: "Clase",
    ocupacion: "Ocupación",
    libres: "Libres",
    espera: "En espera",
    acciones: "Acciones",
  },
  filtros: {
    buscar: "Buscar clase, sucursal o instructor",
    buscarEtiqueta: "Buscar lugares disponibles",
    sucursal: "Filtrar por sucursal",
    instructor: "Filtrar por instructor",
    todosInstructores: "Todos los instructores",
    soloEspera: "Con lista de espera",
    soloPoca: "A menos de la mitad",
  },
};

// Grupos (views/GruposView.vue) con el patrón de los listados.
export const gruposVisual = {
  subtitulo: "Grupos fijos de una clase y quiénes están inscritos.",
  elige: "Elige un grupo para ver e inscribir a sus integrantes.",
  activo: "Activo",
  cerrado: "Cerrado",
  kpi: {
    grupos: "Grupos activos",
    inscritos: "Inscritos",
    promedio: "Inscritos por grupo",
  },
  col: {
    grupo: "Grupo",
    inscritos: "Inscritos",
    estado: "Estado",
  },
};

// Lealtad (views/LealtadView.vue) con el patrón de los listados.
export const lealtadVisual = {
  errorCarga: "No se pudo cargar el programa de lealtad.",
  subtitulo:
    "Puntos por asistir y por comprar, y las recompensas que se canjean.",
  inactivo: "Apagado",
  kpi: {
    programa: "Programa",
    recompensas: "Recompensas activas",
    pendientes: "Canjes por entregar",
    canjeados: "Puntos canjeados",
  },
  // Configurar el programa, aparte de operar los canjes.
  secciones: {
    programa: "Configuración del programa",
    programaAyuda:
      "Cómo se ganan puntos y qué recompensas se pueden canjear. Lo que cambies aplica desde ahora.",
    canjes: "Canjes y puntos",
    canjesAyuda:
      "Consulta o ajusta los puntos de un miembro, canjea recompensas y marca lo que ya recibió.",
  },
  recompensas: {
    editar: "Editar",
    editarTitulo: "Editar recompensa: {nombre}",
    descripcion: "Descripción (opcional)",
    descripcionPh: "Ej. Válida en cualquier clase grupal",
    seCanjea: "Se puede canjear",
    activar: "Activar recompensa",
    desactivar: "Desactivar recompensa",
    guardada: "Recompensa «{nombre}» guardada.",
    historial:
      "Los canjes ya hechos conservan el nombre y los puntos con que se canjearon.",
  },
};

// Documentos (views/DocumentosView.vue) con el patrón de los listados.
export const documentosVisual = {
  col: {
    persona: "Persona",
    documento: "Documento",
    subido: "Subido",
    estado: "Estado",
    acciones: "Acciones",
  },
};

// Sucursales y Comunicaciones con el patrón de los listados.
export const sucursalesVisual = {
  col: {
    sucursal: "Sucursal",
    zona: "Zona horaria",
    moneda: "Moneda",
    iva: "IVA",
    acciones: "Acciones",
  },
};
export const comunicacionesVisual = {
  subtitulo:
    "Avisos a tus alumnos por correo, WhatsApp o en su cuenta, y lo que ya se envió.",
};
