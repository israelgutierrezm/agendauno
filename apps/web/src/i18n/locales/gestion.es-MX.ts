/**
 * Textos de las secciones de gestión que completan lo que el API ya ofrecía:
 * consentimientos, requisitos editables, respuestas de formularios, etc.
 */

export const documentosTabs = {
  documentos: "Documentos",
  requisitos: "Requisitos",
  consentimientos: "Consentimientos",
  editar: "Editar",
  guardar: "Guardar",
  activo: "Se pide",
  inactivo: "Ya no se pide",
  guardado: "Requisito actualizado.",
};

export const consentimientos = {
  nuevo: "Nuevo consentimiento",
  vacio:
    "Aún no publicas consentimientos. Los alumnos los firman desde su cuenta antes de reservar.",
  version: "Versión {n}",
  publicado: "Publicado el {fecha}",
  firmas: "Sin firmas | 1 firma | {n} firmas",
  verTexto: "Ver texto",
  nuevaVersion: "Nueva versión",
  retirar: "Retirar",
  confirmarRetirar:
    "¿Dejar de pedir este consentimiento? Las firmas que ya se dieron se conservan.",
  retirado: "Consentimiento retirado.",
  publicadoOk: "Consentimiento publicado.",
  titulo: "Título",
  tituloPh: "Carta responsiva",
  contenido: "Texto que firmará el alumno",
  avisoVersion:
    "Al publicar una versión nueva, todos los alumnos tendrán que volver a firmarla.",
  publicar: "Publicar",
};

export const formulariosRespuestas = {
  respondio: "Respondió el {fecha}",
  abrirExpediente: "Abrir expediente",
  comoLlenar:
    "Para llenar o corregir las respuestas de alguien, ábrelo desde su expediente.",
  vacio: "Aún nadie responde esta ficha de datos.",
  cargarError: "No se pudieron cargar las respuestas.",
};

export const conexiones = {
  titulo: "Integraciones",
  bienestar: "Plataformas de bienestar",
  llaves: "Llaves de API",
  webhooks: "Webhooks",
  copiar: "Copiar",
  copiado: "Copiado.",
  unaVez: "Cópiala ahora: no se volverá a mostrar.",
  llave: {
    ayuda:
      "Conecta otros sistemas en modo lectura. Envía la llave en el encabezado X-API-Key.",
    nueva: "Nueva llave",
    nombre: "Nombre",
    nombrePh: "Mi sitio web",
    permisos: "Qué puede leer",
    crear: "Crear llave",
    creada: "Llave creada",
    revocar: "Revocar",
    confirmarRevocar:
      "¿Revocar esta llave? Los sistemas que la usan dejarán de tener acceso.",
    revocada: "Revocada",
    ultimoUso: "Último uso {fecha}",
    nuncaUsada: "Sin usar",
    vacio: "Aún no creas llaves de API.",
    // Anidados: la clave del permiso ("miembros.ver") se usa tal cual como ruta.
    scopes: {
      miembros: { ver: "Miembros" },
      agenda: { ver: "Agenda" },
      reservas: { ver: "Reservas" },
      derechos: { ver: "Membresías y créditos" },
      ordenes: { ver: "Ventas" },
    },
  },
  webhook: {
    ayuda:
      "Avisamos a tu sistema cuando algo pasa en tu negocio. Cada aviso va firmado con el secreto del endpoint.",
    nuevo: "Nuevo endpoint",
    url: "URL que recibe los avisos",
    urlPh: "https://tu-sistema.com/avisos",
    eventos: "Eventos",
    todos: "Todos los eventos",
    todosAyuda: "Si no eliges ninguno, recibirá todos.",
    crear: "Agregar endpoint",
    creado: "Endpoint agregado",
    secreto: "Secreto para verificar la firma",
    unaVez: "Cópialo ahora: no se volverá a mostrar.",
    eliminar: "Eliminar",
    confirmarEliminar: "¿Eliminar este endpoint? Dejará de recibir avisos.",
    entregas: "Entregas",
    ocultarEntregas: "Ocultar entregas",
    sinEntregas: "Aún no hay entregas.",
    intentos: "1 intento | {n} intentos",
    vacio: "Aún no registras endpoints.",
    estados: {
      pendiente: "Pendiente",
      entregado: "Entregado",
      fallido: "Falló",
    },
    // Anidados: el tipo del evento ("reserva.creada") se usa tal cual como ruta.
    tipos: {
      reserva: {
        creada: "Reserva creada",
        apartada: "Lugar apartado (por pagar)",
        confirmada: "Reserva confirmada",
        ofrecida: "Lugar ofrecido (lista de espera)",
        recordatorio_24h: "Recordatorio 24 h antes",
        recordatorio_2h: "Recordatorio 2 h antes",
        cancelada: "Reserva cancelada",
        sesion_cancelada: "Clase o cita cancelada por el negocio",
        reprogramada: "Cambio de horario",
      },
      asistencia: { marcada: "Asistencia marcada" },
      acceso: { registrado: "Acceso registrado" },
      orden: { pagada: "Venta pagada (recibo)" },
      pago: {
        reembolsado: "Pago reembolsado",
        tardio: "Pago tardío (apartado vencido)",
        duplicado: "Cobro doble",
      },
      cobro: { fallido: "Cobro recurrente fallido" },
      membresia: {
        suspendida: "Membresía suspendida",
        regularizada: "Membresía regularizada",
        pausada: "Membresía en pausa",
        reanudada: "Membresía reanudada",
        renovacion_proxima: "Renovación próxima (3 días antes)",
      },
      factura: { timbrada: "Factura timbrada" },
      cuenta: { creada: "Cuenta creada (bienvenida)" },
      privacidad: { baja_solicitada: "Baja de datos solicitada" },
      resena: { creada: "Reseña recibida" },
      pago_automatico: { solicitado: "Invitación a pago automático" },
    },
  },
};

export const comunicacionesAuto = {
  difusion: "Difusión",
  automaticos: "Automáticos",
  salida: "Bandeja de salida",
  ayuda:
    "Un mensaje automático se envía solo: cuando pasa algo (una reserva, una asistencia, una compra…) o antes de una clase o cita, como recordatorio.",
  configurar: "Configurar",
  activo: "Activo",
  sinConfigurar: "Sin mensaje",
  editor: "Mensaje automático",
  canal: "Canal",
  para: "Para",
  paraPersona: "Alumno o cliente",
  paraProfesional: "Profesional de la cita",
  ayudaProfesional:
    "Le llega a quien atiende la cita, por correo o en la app. En clases grupales no se avisa al instructor por cada reserva.",
  alProfesional: "Profesional",
  paraEquipo: "Equipo del negocio",
  ayudaEquipo:
    "Le llega a quien puede atenderlo: por ejemplo, una solicitud de baja de datos a quien gestiona alumnos y una venta a quien ve la facturación.",
  alEquipo: "Equipo",
  canalPush: "Notificación en la app",
  ayudaPush:
    "Llega al teléfono de quien tiene la app con sesión. Usa un título corto y una sola línea.",
  canalWhatsApp: "WhatsApp",
  textoWhatsApp: "Texto del mensaje",
  ayudaWhatsApp:
    "Es el texto aprobado por WhatsApp; no se puede cambiar, solo encender o apagar. Le llega a quien aceptó recibir avisos por WhatsApp y tiene su celular registrado.",
  asunto: "Asunto",
  asuntoPush: "Título",
  cuerpo: "Mensaje",
  marcadores:
    "Puedes usar {a}, {b} y {d}, además de los datos del evento (por ejemplo {c}).",
  marcadoresLista: "Puedes usar {lista}.",
  guardar: "Guardar mensaje",
  guardado: "Mensaje automático guardado.",
  eliminar: "Quitar mensaje",
  confirmarEliminar: "¿Quitar este mensaje automático?",
  eliminado: "Mensaje automático quitado.",
  salidaVacia: "Aún no se envían mensajes.",
  todos: "Todos",
  estados: {
    encolado: "En cola",
    enviado: "Enviado",
    entregado: "Entregado",
    leido: "Leído",
    fallido: "Falló",
    descartado: "Descartado",
  },
};

// Consentimiento de avisos por WhatsApp que registra el equipo (ADR 0069).
export const avisosWhatsApp = {
  fila: "Avisos por WhatsApp",
  acepta: "Aceptó",
  sinCelular: "Falta su celular",
  aceptaCliente: "Acepta recibir avisos por WhatsApp",
  ayudaCliente:
    "Márcalo solo si el cliente te lo pidió. Le llegan la confirmación y los recordatorios de sus citas.",
};

export const reglasAgenda = {
  titulo: "Reglas de la agenda",
  // Mismos nombres que en el menú de Configuración.
  pestanas: {
    politicas: "Políticas",
    cierres: "Días de cierre",
    programacion: "Programación recurrente",
    parametros: "Límites y tiempos",
  },
  cancelaciones: "Cancelaciones",
  cancelacionesAyuda:
    "Cancelar con esta antelación devuelve el crédito. Después, se cobra según lo que marques.",
  horas: "Horas de anticipación",
  penalizaTarde: "Cobrar cancelaciones tardías",
  penalizaNoShow: "Cobrar inasistencias",
  tolerancia: "Faltas toleradas sin cobrar",
  ventana: "En los últimos (días)",
  toleraN: "tolera {n} en {d} días",
  guardar: "Guardar",
  guardada: "Política guardada.",
  porActividad: "Excepciones por actividad",
  agregarActividad: "Agregar excepción para…",
  resumen: "{h} h antes",
  cobraTarde: "cobra tardías",
  cobraNoShow: "cobra inasistencias",
  soloLectura: "Solo el dueño o un administrador cambian esta política.",
  cerrados: "Días de cierre",
  cerradosAyuda:
    "Feriados y cierres: esos días no se generan clases ni se ofrecen citas.",
  fecha: "Fecha",
  motivo: "Motivo (opcional)",
  motivoPh: "Día festivo",
  agregarDia: "Agregar día",
  sinCerrados: "No hay días cerrados próximos.",
  quitar: "Quitar",
  series: "Clases que se repiten",
  seriesAyuda:
    "Al dejar de repetir una clase ya no se generan fechas nuevas; las que ya están en la agenda se conservan.",
  sinSeries: "Aún no hay clases que se repitan.",
  dejarDeRepetir: "Dejar de repetir",
  confirmarDejar:
    "¿Dejar de repetir esta clase? Las fechas ya agendadas se conservan.",
  desde: "desde {fecha}",
  hasta: "hasta {fecha}",
  filtroActividad: "Actividad",
  filtroInstructor: "Instructor",
  filtroSucursal: "Sucursal",
  todasActividades: "Todas las actividades",
  todosInstructores: "Todos los instructores",
  todasSucursales: "Todas las sucursales",
  sinInstructor: "Sin instructor",
  quitarFiltros: "Quitar filtros",
  clasesPorSemana:
    "Sin clases a la semana | 1 clase a la semana | {n} clases a la semana",
  sinSeriesFiltro: "Ninguna clase que se repite coincide con los filtros.",
  yaNoSeRepiten: "Ya no se repiten ({n})",
  detalle: {
    actividad: "Actividad",
    dias: "Días",
    horario: "Horario",
    minutos: "{n} min",
    instructor: "Instructor",
    sucursal: "Sucursal",
    lugares: "Lugares",
    vigencia: "Vigencia",
    cambiar:
      "Para cambiar días, hora o instructor, abre una de sus clases en la agenda y elige «Mover esta y las siguientes».",
  },
};

export const creditosFicha = {
  movimientos: "Movimientos",
  ocultar: "Ocultar",
  agregar: "Agregar créditos",
  creditos: "Créditos",
  motivo: "Motivo",
  motivoPh: "Cortesía por clase cancelada",
  confirmar: "Agregar",
  agregados: "Créditos agregados.",
  sinMovimientos: "Sin movimientos.",
  saldo: "Saldo {n}",
  por: "por {actor}",
  tipos: {
    concesion: "Alta",
    consumo: "Consumo",
    ajuste: "Ajuste",
    add_on: "Créditos extra",
    reverso: "Devolución",
    expiracion: "Vencimiento",
  },
};

export const inventarioExtra = {
  editar: "Editar",
  activo: "A la venta",
  inactivo: "Fuera de venta",
  guardado: "Artículo actualizado.",
  movimiento: "Movimiento",
  entrada: "Entrada",
  ajuste: "Ajuste (+/−)",
  ajusteAyuda: "Usa un número negativo para mermas o conteos menores.",
};

export const accesoRecepcion = {
  registrar: "Registrar entrada",
  permitido: "Entrada permitida",
  denegado: "Entrada denegada",
  codigos: {
    ACCESS_BY_BOOKING: "Tiene reserva para ahora.",
    ACCESS_OPEN: "Su membresía le da acceso libre.",
    NO_ACCESS: "No tiene reserva ni membresía con acceso libre.",
  },
};

export const bitacora = {
  titulo: "Bitácora",
  cambios: "Cambios",
  accesos: "Accesos",
  sinCambios: "Sin cambios registrados.",
  sinAccesos: "Sin accesos registrados.",
  por: "por {actor}",
  sistema: "Sistema",
  antes: "Antes",
  despues: "Después",
  detalle: "Detalle",
  desde: "Desde",
  hasta: "Hasta",
  quien: "Quién",
  categoria: "Tipo",
  buscar: "Buscar",
  todos: "Todos",
  descargar: "Descargar CSV",
  anterior: "Anterior",
  siguiente: "Siguiente",
  pagina: "Página {page} de {total}",
  categorias: {
    equipo: "Equipo",
    alumnos: "Alumnos",
    pagos: "Pagos",
    membresias: "Membresías",
    catalogo: "Catálogo y agenda",
    privacidad: "Privacidad",
    configuracion: "Configuración",
  },
  acciones: {
    credito: { top_up: "Agregó créditos" },
    miembro: { actualizado: "Editó un miembro" },
    pago: { reembolso: "Reembolsó un pago" },
    producto: { actualizado: "Editó un producto" },
  },
  metodos: {
    qr: "QR",
    pin: "PIN",
    nfc: "Tarjeta",
    manual: "Manual",
  },
};

export const citaCuenta = {
  // Servicio que se toma con su bono o membresía (ADR 0091).
  conTuBono: "con tu bono",
  conTuBonoAyuda:
    "Se descuenta una sesión de tu bono o membresía; no pagas al agendar.",
  servicio: "Servicio",
  profesional: "Profesional",
  sede: "Sede",
  dia: "Día",
  hora: "Hora",
  elegir: "Elige…",
  sinHorarios:
    "No hay horarios libres ese día. Prueba otro día u otro profesional.",
  sinServicios: "Este negocio aún no tiene servicios para agendar en línea.",
  agendar: "Agendar",
  agendada: "¡Listo! Tu cita quedó agendada.",
  apartada: "Tu cita quedó apartada. Págala para confirmarla.",
  // Confirmación que se queda a la vista.
  cuando: "Cuándo",
  estado: "Estado",
  confirmada: "Confirmada",
  pendientePago: "Pendiente de pago",
  pendientePagoAyuda:
    "La encuentras en Mis reservas para pagarla. Si no se paga a tiempo, el horario se libera.",
  otra: "Agendar otra",
  listo: "Listo",
};

export const miCuentaExtra = {
  pagar: "Pagar en línea",
  pagando: "Abriendo pago…",
  pagoEnProceso:
    "Tu pago está en proceso. Se confirmará en cuanto la pasarela lo apruebe.",
};

export const reembolsosPago = {
  ver: "Ver reembolsos",
  ocultar: "Ocultar reembolsos",
  por: "por {actor}",
  creditosRevertidos: "créditos revertidos",
  estados: {
    solicitado: "Solicitado",
    pendiente: "En proceso",
    incierto: "Sin confirmar",
    aprobado: "Reembolsado",
    fallido: "Falló",
  },
  via: {
    pasarela: "devuelto en línea",
    manual: "devuelto por otro medio",
    caja: "devuelto en caja",
  },
  manual: "Ya devolví el dinero por otro medio",
  manualAyuda:
    "Transferencia o efectivo. Si no la marcas, la devolución se pide a la pasarela de pago.",
  enProceso:
    "La devolución quedó en proceso: se confirma cuando la pasarela de pago responda.",
  sinConfirmar:
    "La pasarela no respondió. La consultamos de nuevo automáticamente; mientras, aparece en Por conciliar.",
};

// Cobranza: lo del dinero que alguien debe revisar (devoluciones sin confirmar…).
export const porConciliar = {
  titulo: "Por conciliar",
  ayuda:
    "Movimientos que la pasarela no confirmó. Revisa en su panel qué pasó y márcalo aquí.",
  devolucion: "Devolución de {monto}",
  pagoTardio: "Pago tardío de {monto}",
  pagoDuplicado: "Cobro doble de {monto}",
  devolverPago: "Devolver el pago",
  resolver: "Resolver",
  nota: "Qué revisaste",
  notaEjemplo: "Ej.: en el panel de Stripe aparece devuelta el 25/09",
  faltaNota: "Escribe qué revisaste.",
  siSeDevolvio: "Sí se devolvió",
  noSeDevolvio: "No se devolvió",
  marcarResuelta: "Marcar como resuelta",
};

export const validacion = {
  contrasenasNoCoinciden: "Las contraseñas no coinciden.",
  sesion: {
    entrar: "No se pudo iniciar sesión.",
    google: "No se pudo iniciar sesión con Google.",
    activar: "No se pudo activar la cuenta.",
    registrar: "No se pudo crear la cuenta.",
    terminada: "Tu sesión terminó. Vuelve a entrar para seguir.",
    // Hay sesión guardada pero no se pudo confirmar (la sesión NO se borra).
    sinConfirmar: {
      sinConexion:
        "No pudimos confirmar tu sesión: no hay conexión o el servidor no respondió. Tu sesión sigue guardada; reintenta en un momento.",
      mantenimiento:
        "Estamos actualizando AgendaUno. Tu sesión sigue guardada; reintenta en unos minutos.",
      servidor:
        "El servidor no pudo confirmar tu sesión en este momento. Tu sesión sigue guardada; reintenta en un momento.",
    },
  },
  recursoTipoPh: "Sala, cancha, carril…",
  // Un precio escrito a mano que no se puede leer con los separadores del país.
  precioIlegible: "No entendimos este precio. Escríbelo así: {ejemplo}.",
};

export const instructorClase = {
  titulo: "Instructor de la clase",
  ninguno: "Sin instructor",
};

export const plataformaAdmin = {
  pestanas: {
    estudios: "Estudios",
    cobros: "Cobros",
    tarifas: "Tarifas",
    parametros: "Parámetros",
    configuracion: "Configuración",
    operacion: "Operación",
    errores: "Errores",
  },
  // Monitoreo de errores (ADR 0080).
  errores: {
    titulo: "Errores",
    subtitulo:
      "Lo que falló en la API, la web y la app, agrupado. Los datos sensibles llegan tachados.",
    actualizar: "Actualizar",
    estados: {
      abierto: "Abiertos",
      resuelto: "Resueltos",
      ignorado: "Ignorados",
    },
    estado: {
      abierto: "Abierto",
      resuelto: "Resuelto",
      ignorado: "Ignorado",
    },
    origen: "Origen",
    todos: "Todos los orígenes",
    origenes: { api: "API", web: "Web", app: "App" },
    vacio: {
      abierto: "No hay errores abiertos.",
      resuelto: "No hay errores resueltos.",
      ignorado: "No hay errores ignorados.",
    },
    veces: "1 vez | {n} veces",
    volvio:
      "Volvió después de resolverse | Volvió {n} veces después de resolverse",
    verMas: "Ver más",
    clase: "Tipo",
    lugar: "Dónde",
    cuantas: "Cuántas",
    primera: "Primera vez",
    ultima: "Última vez",
    regresiones: "Regresiones",
    contexto: "Contexto de la última vez",
    traza: "Traza",
    resolver: "Resuelto",
    ignorar: "Ignorar",
    reabrir: "Reabrir",
    marcado: {
      abierto: "Error reabierto.",
      resuelto:
        "Marcado como resuelto. Si vuelve en otra versión, se reabre solo.",
      ignorado: "Ignorado: se sigue contando, pero ya no avisa.",
    },
  },
  operacion: {
    actualizar: "Actualizar",
    revisado: "Revisado a las {hora}",
    cargando: "Revisando la operación…",
    version: "Versión",
    entorno: "Entorno",
    servicio: "Servicio",
    abierto: "Abierto",
    mantenimiento: "En mantenimiento",
    programador: "Programador",
    cola: "Cola",
    procesos: {
      ok: "Al día",
      atrasado: "Atrasado",
      sin_datos: "Sin señal",
      otra_version: "Otra versión",
    },
    ultimoLatido: "Último latido: {cuando}",
    nunca: "nunca",
    verificacion: "Verificación de producción",
    todoEnOrden: "Todo en orden.",
    porResolver: "{n} punto por resolver | {n} puntos por resolver",
    avisos: "{n} aviso | {n} avisos",
    estados: {
      ok: "Listo",
      aviso: "Aviso",
      falta: "Falta",
    },
    respaldos: "Respaldos",
    baseCentral: "Base central",
    archivos: "Documentos, fotos y logos",
    sinRespaldo: "Sin respaldo",
    simulacro: "Último simulacro de restauración",
    sinSimulacro: "Aún no se ha hecho ningún simulacro.",
    simulacroOk: "Pasó",
    simulacroFallo: "Falló",
    alertas: "Alertas de los últimos 30 días",
    sinAlertas: "Sin alertas.",
    colAlerta: "Qué pasó",
    colNegocio: "Negocio",
    colVeces: "Veces",
    colUltima: "Última vez",
    colAviso: "Correo",
    avisada: "Enviado",
    porAvisar: "Por enviar",
    tipos: {
      error: "Error",
      cobro_fallido: "Cobro",
      incidencia_cobro: "Incidencia de cobro",
      aviso_de_pago_perdido: "Aviso de pago perdido",
      correo_fallido: "Correo que no salió",
      whatsapp_fallido: "WhatsApp que no salió",
      renta_vencida: "Renta vencida",
      suspension_por_renta: "Suspensión por renta",
      webhook_saliente_fallido: "Webhook de un negocio",
      respaldo_fallido: "Respaldo",
      simulacro_fallido: "Simulacro",
      cola_detenida: "Cola detenida",
      trabajos_fallidos: "Trabajos fallidos",
      trabajo_fallido: "Trabajo de la cola",
      error_web: "Error en la web",
      error_app: "Error en la app",
      error_regresion: "Error que volvió",
      errores_clientes_tope: "Tope de errores de la web y la app",
    },
  },
  estudios: {
    colEstudio: "Estudio",
    colEstado: "Estado",
    colCobro: "Cobro",
    colUso: "Uso",
    colAdeudo: "Adeudo",
    ver: "Ver",
    sinUso: "Sin medición",
    alCorriente: "Al corriente",
  },
  estados: {
    provisioning: "Creándose",
    trialing: "En prueba",
    active: "Activo",
    suspended: "Suspendido",
    cancelled: "Cancelado",
  },
  pruebaHasta: "Prueba hasta el {fecha}",
  ficha: {
    whatsappVerificado: "WhatsApp verificado",
    avisos: "Avisos al dueño",
    sinAvisos: "Aún no se le ha avisado nada.",
    tiposAviso: {
      prueba_por_terminar: "Prueba por terminar",
      renta_emitida: "Renta lista",
      renta_vencida: "Renta vencida",
      suspension_proxima: "Suspensión próxima",
      cuenta_suspendida: "Negocio suspendido",
      pago_recibido: "Pago recibido",
    },
    canalesAviso: { email: "Correo", whatsapp: "WhatsApp" },
    negocio: "Negocio",
    tipo: "Tipo",
    ciudad: "Ciudad",
    alta: "Alta",
    prueba: "Prueba gratis",
    directorio: "Directorio",
    enDirectorio: "Visible",
    fueraDirectorio: "No visible",
    configuracionInicial: "Configuración inicial",
    completa: "Completa",
    pendiente: "Pendiente",
    contacto: "Contacto",
    uso: "Uso por mes",
    cargos: "Cargos de renta",
    sinCargos: "Aún no tiene cargos.",
    vence: "vence el {fecha}",
    pagado: "pagado el {fecha}",
    factura: "Factura",
    facturacion: "Facturación",
    acciones: "Cuenta",
    extender: "Extender prueba",
    dias: "{n} días",
    extendida: "Prueba extendida.",
    suspender: "Suspender",
    confirmarSuspender:
      "¿Suspender {estudio}? Nadie podrá entrar a su cuenta ni a su página hasta que la reactives.",
    motivo: "Motivo (opcional)",
    suspendido: "Estudio suspendido.",
    reactivar: "Reactivar",
    reactivado: "Estudio reactivado.",
    guardado: "Cobro actualizado.",
    abrirPagina: "Ver su página",
    whatsappClientes: "WhatsApp con sus clientes",
    whatsappActivo: "Activo",
    whatsappSinPlataforma: "Activado; apagado en la plataforma",
    whatsappInactivo: "Desactivado",
    whatsappActivar: "Activar",
    whatsappDesactivar: "Desactivar",
    whatsappAyuda:
      "Cada aviso por WhatsApp a sus clientes lo paga la plataforma. El negocio no puede activarlo; ya activado, elige qué avisos manda.",
    whatsappAyudaPlataforma:
      "Para que salgan, enciende también «De los negocios a sus clientes» en Configuración → WhatsApp.",
    confirmarWhatsApp:
      "¿Activar WhatsApp para {estudio}? Cada aviso que mande a sus clientes lo paga la plataforma.",
    whatsappActivado: "WhatsApp activado en el negocio.",
    whatsappDesactivado: "WhatsApp desactivado en el negocio.",
  },
  cargos: {
    pendiente: "Pendiente",
    pagado: "Pagado",
    sin_cargo: "Sin cargo",
    vencido: "Vencido",
  },
  facturas: {
    timbrada: "Timbrada",
    error: "Con error",
  },
  cobros: {
    porCobrar: "Por cobrar",
    vencido: "Vencido",
    vencidos: "Vencidos",
    suspendido: "Negocio suspendido",
    suspendidoPorRenta: "Suspendido por renta; se reactiva al pagar",
    motivoRenta: "Renta de {periodo} vencida sin pagar.",
    cobradoMes: "Cobrado este mes",
    conAdeudo: "Estudios con adeudo",
    todos: "Todos los estados",
    periodo: "Periodo",
    vacio: "No hay cargos con estos filtros.",
    colEstado: "Estado",
  },
  llaves: {
    secret_key: "Llave secreta",
    webhook_secret: "Secreto del webhook",
    access_token: "Access token",
    merchant_id: "ID de comercio",
    private_key: "Llave privada",
    webhook_user: "Usuario del webhook",
    webhook_password: "Contraseña del webhook",
  },
  correoAlertas: {
    titulo: "Correo del superadministrador",
    ayuda:
      "Aquí llegan las alertas de la operación y las rentas vencidas. Vacío, se usa el de ALERTAS_CORREO.",
    correo: "Correo",
    guardar: "Guardar correo",
    guardado: "Correo guardado.",
  },
  whatsapp: {
    titulo: "WhatsApp",
    subtitulo:
      "Con la API de WhatsApp de Meta, un solo número para toda la plataforma. Cada mensaje tiene costo: enciende solo lo que convenga.",
    conectado: "Conectado",
    sinConectar: "Sin conectar",
    encendido: "Encendido",
    apagado: "Apagado",
    numero: "Identificador del número (Phone number ID)",
    token: "Token de acceso",
    tokenGuardado: "Guardado; escribe uno nuevo para reemplazarlo",
    tokenAyuda:
      "Usa un token permanente de un usuario del sistema. Se guarda cifrado y nunca se vuelve a mostrar.",
    webhook: "Webhook de estados",
    webhookAyuda:
      "En la app de Meta, en WhatsApp → Configuración, pega esta dirección y el token de verificación, y suscríbete a «messages». Así se sabe si cada mensaje se entregó, se leyó o falló, y a quien contesta se le responde solo con el contacto del negocio (si escribe BAJA, deja de recibir los avisos). El App Secret firma los avisos; sin él, en producción se rechazan.",
    webhookUrl: "Dirección (callback URL)",
    webhookToken: "Token de verificación",
    appSecret: "App Secret de la app de Meta",
    duenos: "Con los dueños",
    duenosActivar: "Verificar el número de los dueños y mandarles avisos",
    duenosAyuda:
      "Al crear su negocio, el dueño puede confirmar su WhatsApp con un código y aceptar avisos de AgendaUno: prueba por terminar, renta lista, renta vencida y pago recibido. El correo le llega siempre.",
    negocios: "De los negocios a sus clientes",
    negociosActivar:
      "Los negocios que actives pueden mandar avisos a sus clientes",
    negociosAyuda:
      "Se activa negocio por negocio desde su ficha en Estudios; ninguno lo tiene al crearse ni puede activarlo. Apagado aquí, ningún negocio lo usa y sus avisos siguen por correo y notificación en la app.",
    negociosHabilitados:
      "Ningún negocio lo tiene activado | Activado en 1 negocio | Activado en {n} negocios",
    guardar: "Guardar",
    guardado: "WhatsApp guardado.",
    prueba: "Mandar una prueba",
    pruebaTelefono: "Celular de prueba",
    pruebaAyuda:
      "Manda la plantilla de muestra hello_world (en inglés) para comprobar el número y el token.",
    pruebaEnviada: "Prueba enviada. Revisa ese WhatsApp.",
    plantillas: "Plantillas a registrar en Meta ({n})",
    plantillasAyuda:
      "Regístralas en WhatsApp Manager con este nombre exacto e idioma español (MEX). Un mensaje sale solo cuando Meta la aprobó.",
    categorias: {
      UTILITY: "Utilidad",
      AUTHENTICATION:
        "Autenticación, con botón «Copiar código» y vigencia de 10 minutos",
    },
  },
};

export const recuperarContrasena = {
  enlace: "¿Olvidaste tu contraseña?",
  titulo: "Recupera tu contraseña",
  subtitulo:
    "Escribe el correo con el que entras y te enviaremos un enlace para elegir una nueva.",
  negocio: "Dirección de tu negocio",
  negocioPh: "mi-negocio",
  email: "Correo",
  enviar: "Enviar enlace",
  enviando: "Enviando…",
  enviado:
    "Si ese correo tiene cuenta, te llegará un enlace en unos minutos. Revisa también la carpeta de spam.",
  volver: "Volver a iniciar sesión",
  nuevaTitulo: "Elige una contraseña nueva",
  nuevaSubtitulo: "Para {email}. Al guardarla se cerrarán tus otras sesiones.",
  nueva: "Contraseña nueva",
  confirmar: "Confirma la contraseña",
  guardar: "Guardar y entrar",
  guardando: "Guardando…",
  pedirOtro: "Pedir un enlace nuevo",
  sinEnlace:
    "Este enlace está incompleto. Pide uno nuevo desde la pantalla de inicio de sesión.",
};

export const pagoEnLinea = {
  exito: "¡Gracias! Recibimos tu pago. Se confirma en unos segundos.",
  cancelado: "El pago no se completó. Puedes intentarlo de nuevo.",
  citaExito: "¡Listo! Recibimos tu pago. Tu cita se confirma en unos segundos.",
  rentaExito:
    "¡Gracias! Recibimos el pago de tu renta. Se confirma en unos segundos.",
};

export const pausaMembresia = {
  pausar: "Pausar",
  reanudar: "Reanudar",
  hasta: "En pausa hasta (incluido)",
  motivo: "Motivo",
  motivoPh: "Vacaciones, lesión…",
  confirmar: "Pausar membresía",
  ayuda:
    "Mientras esté en pausa no podrá reservar ni se le cobrará. Al volver, su próximo cobro y su vencimiento se recorren los días que duró la pausa. Las reservas que ya tenía se conservan.",
  enPausa: "En pausa hasta el {fecha}",
  pausada: "Membresía en pausa hasta el {fecha}.",
  reanudada: "Membresía reanudada.",
};

export const paseEntrada = {
  titulo: "Mi pase de entrada",
  ayuda:
    "Muéstralo en recepción para registrar tu entrada. Se renueva solo cada minuto.",
  mostrar: "Mostrar mi pase",
  ocultar: "Ocultar",
  alt: "Código QR de tu pase de entrada",
  error: "No se pudo generar tu pase.",
  escanear: "Escanear pase",
  apunta: "Apunta la cámara al código QR del pase.",
  sinCamara:
    "No se pudo abrir la cámara en este navegador. Usa un lector de códigos o busca al alumno por su nombre.",
  pistaLector:
    "Con un lector de códigos, escanea el pase aquí mismo y se registra la entrada.",
  entrada: "{nombre}: {resultado}",
};

export const pasarelasEstado = {
  proximamente: "Próximamente",
  noDisponible: "Aún no está disponible.",
  faltaLlave: "Faltan llaves: sin ellas no se cobra en línea.",
  lista: "Lista para cobrar",
  webhook: "URL para avisos (webhook)",
  copiar: "Copiar",
  copiada: "Copiada",
  ayudaWebhook: {
    stripe:
      "Regístrala en Stripe → Desarrolladores → Webhooks con los eventos checkout.session.*, payment_intent.* y refund.*, y pega aquí su clave de firma (webhook_secret).",
    mercadopago:
      "Regístrala en Mercado Pago → Tus integraciones → Webhooks con los temas Pagos y Planes y suscripciones, y pega aquí la clave secreta (webhook_secret).",
    openpay:
      "Regístrala en el tablero de OpenPay → Webhooks con autenticación HTTP Basic: el usuario y la contraseña son los que guardes aquí (webhook_user y webhook_password).",
  },
  codigoVerificacion:
    "Código de verificación que envió OpenPay: {codigo}. Captúralo en el tablero de OpenPay para activar el webhook.",
};

export const pagoTienda = {
  pagarOxxo: "o paga en efectivo en OXXO",
  titulo: "Paga en tienda",
  referencia: "Referencia: {referencia}",
  recibo: "Ver recibo para imprimir",
  ayuda:
    "Presenta la referencia en la caja. Tu compra se confirma sola cuando la tienda reporta el pago.",
};

export const misDocumentos = {
  titulo: "Mis documentos",
  ayuda:
    "Los que te pide el negocio. Lo que subas queda en revisión hasta que el equipo lo valide.",
  obligatorio: "obligatorio",
  falta: "Falta subirlo",
  estados: {
    pendiente: "En revisión",
    aprobado: "Aprobado",
    rechazado: "Rechazado",
  },
  subir: "Subir",
  reemplazar: "Subir otro",
  subiendo: "Subiendo…",
  ver: "Ver",
  subido: "Documento enviado. Queda en revisión.",
  error: "No se pudo subir el documento.",
};

export const miPrivacidad = {
  titulo: "Privacidad",
  ayuda: "Elige qué avisos recibes y administra tus datos en este negocio.",
  errorCarga: "No pudimos cargar tus opciones de privacidad.",
  reintentar: "Volver a intentar",
  cargando: "Cargando tus opciones de privacidad…",
  promociones: "Recibir promociones",
  promocionesAyuda:
    "Ofertas y novedades del negocio. Los avisos de tus reservas y pagos te siguen llegando.",
  whatsapp: "Avisos por WhatsApp",
  whatsappAyuda:
    "Confirmaciones y recordatorios de tus reservas a tu celular. Sin esto te llegan por correo o en la app.",
  descargar: "Descargar mis datos",
  baja: "Pedir la baja de mis datos",
  bajaExplica:
    "El negocio borrará tus datos personales y cerrará tu cuenta. Tus compras y pagos se conservan sin tu nombre, como pide la ley.",
  motivo: "Motivo (opcional)",
  confirmarBaja: "Enviar solicitud",
  // Confirmar con la contraseña antes de descargar o pedir la baja.
  confirmarTitulo: "Confirma que eres tú",
  confirmarDescargaTexto:
    "Para descargar tus datos, escribe la contraseña con la que entras.",
  confirmarBajaTexto:
    "Para enviar la solicitud de baja de tus datos, escribe la contraseña con la que entras.",
  contrasena: "Contraseña",
  cancelar: "Cancelar",
  bajaEnviada: "Solicitud enviada. El negocio te responderá.",
  bajaPendiente: "Tu solicitud de baja está en revisión.",
  bajaRechazada: "El negocio no pudo darte de baja: {respuesta}",
};

export const privacidadNegocio = {
  titulo: "Solicitudes de privacidad",
  ayuda:
    "Alumnos que pidieron la baja de sus datos. Al atenderla se borran sus datos personales (nombre, correo, celular, documentos) y su cuenta; sus compras y pagos se conservan sin nombre. La ley pide responder en 20 días hábiles.",
  vacio: "No hay solicitudes.",
  solicitada: "Pedida el {fecha}",
  responderAntes: "responder antes del {fecha}",
  atender: "Dar de baja",
  rechazar: "Rechazar",
  motivoRechazo: "Motivo del rechazo",
  confirmarRechazo: "Rechazar solicitud",
  confirmarAtender:
    "Se borrarán los datos personales de esta persona y su cuenta. No se puede deshacer. ¿Continuar?",
  atendida: "Baja aplicada.",
  estados: {
    pendiente: "Pendiente",
    atendida: "Dada de baja",
    rechazada: "Rechazada",
  },
};

export const resenas = {
  titulo: "Reseñas",
  califica: "Califica tus clases",
  // Filtro de fechas de lo que hay por calificar.
  ventana: "Puedes calificar las de los últimos {n} días.",
  desde: "Desde",
  hasta: "Hasta",
  quitarFiltro: "Quitar fechas",
  sinEnFechas: "No hay clases por calificar en esas fechas.",
  calificacion: "Calificación",
  estrellas: "{n} de 5",
  comentarioPh: "¿Cómo te fue? (opcional)",
  enviar: "Enviar",
  gracias: "¡Gracias por tu calificación!",
  total: "{n} reseñas",
  porProfesional: "Por profesional",
  vacio: "Aún no hay reseñas.",
  oculta: "oculta del público",
  ocultar: "Ocultar del público",
  mostrar: "Mostrar al público",
};

export const pagoAutomatico = {
  titulo: "Pago automático",
  ayuda:
    "Tu membresía se renueva sola: se cobra a tu tarjeta en la fecha de renovación y te avisamos si algo falla. Tu tarjeta la autoriza directamente la pasarela de pago; AgendaUno nunca la ve ni la guarda.",
  tarjeta: "{marca} terminación {ultimos4}",
  vence: "vence {fecha}",
  cambiarTarjeta: "Cambiar tarjeta",
  renueva: "Se renueva el {fecha} · {monto}",
  automatico: "Cobro automático",
  manual: "Te avisamos para pagar",
  activar: "Activar",
  quitar: "Quitar",
  confirmarQuitar:
    "¿Quitar el pago automático? Cada renovación te avisaremos para que la pagues.",
  tarjetaExito:
    "Tarjeta autorizada. En unos segundos verás el pago automático activo.",
  tarjetaCancelado: "No se autorizó la tarjeta; puedes intentarlo de nuevo.",
  alPagar: "Cobrar automáticamente cada renovación",
  // Cobranza (negocio)
  colCobro: "Cobro",
  pagoManual: "Pago manual",
  invitar: "Invitar a activarlo",
  invitado: "Le enviamos el correo para activar el pago automático.",
  confirmarQuitarNegocio:
    "¿Quitar el pago automático de esta membresía? La renovación se le avisará para que la pague.",
  porAutorizar: "Falta autorizarlo en la pasarela",
  numeroTarjeta: "Número de tarjeta",
  titular: "Nombre del titular",
  mes: "Mes",
  anio: "Año",
  seguridadOpenPay:
    "Tu tarjeta se envía cifrada directamente a OpenPay; AgendaUno no la ve ni la guarda.",
  autorizar: "Autorizar tarjeta",
};

export const bajas = {
  darDeBaja: "Dar de baja",
  ayudaMiembro:
    "Deja de operar: se cancelan sus reservas por venir, sus membresías y sus pagos automáticos. Su historial (compras, pagos, asistencias) se conserva y se puede reactivar.",
  ayudaUsuario:
    "Pierde el acceso y se cierran sus sesiones. Su historial se conserva; si lo vuelves a invitar con su correo, se reactiva.",
  motivo: "Motivo (opcional)",
  dadosDeBaja: "Dados de baja",
  detalle: "Dado de baja el {fecha}",
  detallePor: "Dado de baja el {fecha} por {quien}",
  reactivar: "Reactivar",
  reactivado: "Reactivamos a {nombre} con su historial.",
  reactivarlo: "Reactivar a {nombre}",
  otraPersona: "Es otra persona",
  otraPersonaAyuda: "El teléfono se le quita a quien está dado de baja.",
  verBajas: "Ver dados de baja",
  verEquipo: "Ver equipo",
};

export const corteCaja = {
  titulo: "Corte de caja",
  descargar: "Descargar CSV",
  desde: "Desde",
  hasta: "Hasta",
  quien: "Quién",
  tipo: "Tipo",
  todos: "Todos",
  cobrado: "Cobrado",
  devuelto: "Devuelto",
  neto: "Neto",
  porCobrar: "Por cobrar",
  enMoneda: "En {moneda}",
  truncado:
    "Se muestran los {n} movimientos más recientes; los totales y el CSV incluyen todos los del rango.",
  porMetodo: "Por método",
  porPersona: "Por persona",
  vacio: "No hay movimientos en esas fechas.",
  fecha: "Fecha",
  movimiento: "Movimiento",
  monto: "Monto",
  registro: "Registró: {quien}",
  tipos: {
    cobro: "Cobro",
    devolucion: "Devolución",
    venta: "Venta de mostrador",
    cancelacion: "Cancelación",
  },
};

// Cancelar con el efecto a la vista (fase 1, punto 1.4).
export const cancelacion = {
  titulo: "Cancelar",
  porNegocio: "Cancela el negocio",
  porCliente: "Lo pidió el cliente",
  calculando: "Revisando qué pasa con el crédito…",
  confirmar: "Sí, cancelar",
  volver: "Volver",
  canceladaPor: {
    cliente: "la canceló el cliente",
    negocio: "la canceló el negocio",
    sistema: "venció sin pago",
  },
};

// Tiempos de preparación y limpieza de un servicio (fase 2, punto 2.3).
export const margenesServicio = {
  preparacion: "Preparación antes (min)",
  limpieza: "Limpieza después (min)",
  ayuda:
    "Ocupan la agenda del profesional y de la sala, pero no se le cobran ni se le muestran al cliente.",
  resumen: "+{n} min de preparación y limpieza",
  enAgenda: "Preparación o limpieza",
};

// Bloqueos de agenda (fase 2, punto 2.2).
export const bloqueosAgenda = {
  titulo: "Bloqueos",
  ayuda:
    "Comida, vacaciones, ausencias o cierres. Ese horario deja de ofrecerse y no se puede agendar; lo que ya estaba agendado no se cancela solo.",
  vacio: "Sin bloqueos próximos.",
  sede: "Toda la sede ({sede})",
  todaLaSede: "toda la sede",
  unaSala: "Una sala",
  sala: "Sala, cabina o equipo",
  fecha: "Día",
  desdeDia: "Desde el día",
  hastaDia: "Hasta el día",
  desde: "De",
  hasta: "A",
  todoElDia: "Días completos",
  motivo: "Motivo",
  motivoEjemplo: "Comida, vacaciones, mantenimiento…",
  bloquear: "Bloquear",
  bloquearIgual: "Bloquear de todos modos",
  quitar: "Quitar",
  afectadas:
    "Ya hay {n} citas o clases en ese horario. No se cancelan: revísalas después de bloquear.",
  conReservas: "{n} con reserva",
};

// Reprogramar sin cancelar (fase 2, punto 2.1).
export const reprogramar = {
  titulo: "Reprogramar",
  cambiarHorario: "Cambiar horario",
  fecha: "Nuevo día",
  hora: "Hora",
  profesional: "Con",
  ayuda:
    "Se conserva todo: cliente, pagos e historial. Si el nuevo horario ya no está libre, no cambia nada.",
  guardar: "Cambiar",
  volver: "Volver",
  hecho: "Movida: antes {antes}, ahora {ahora}.",
  moverTitulo: "Mover a otra fecha",
  mover: "Mover",
  otraFecha: "A otra fecha de esta clase",
  elegir: "Elige la fecha",
  sinFechas:
    "No hay otras fechas de esta clase con lugar en los próximos 30 días.",
  movida: "Se movió a otra fecha.",
};

// Espacios o equipos que requiere un servicio (fase 2, punto 2.4).
export const recursosServicio = {
  titulo: "Espacios que usa",
  ayuda:
    "Si eliges alguno, cada cita toma uno libre de su sede (cabina, consultorio, sillón…). Sin marcar, el servicio no pide espacio.",
  resumen: "usa {lista}",
};

// "Esta y las siguientes" en una clase recurrente (fase 2, punto 2.5).
export const cambiarSerie = {
  titulo: "Esta y las siguientes",
  soloEsta: "Solo esta sesión",
  desde: "Desde el {fecha} en adelante. Las fechas anteriores no cambian.",
  hora: "Hora",
  duracion: "Duración (min)",
  profesional: "Con",
  sinAsignar: "Sin asignar",
  revisar: "Revisar",
  aplicar: "Aplicar el cambio",
  movidas: "Se moverán {n} fechas.",
  conservadas: "{n} se quedan como están:",
  dias: "Días",
  nombresDias: "lunes,martes,miércoles,jueves,viernes,sábado,domingo",
  quitadas: "Se quitan {n} fechas de los días que ya no van.",
  creadas: "Se crean {n} fechas en los días nuevos.",
  omitidas: "{n} fechas nuevas no se pueden crear:",
};

// Agenda como centro de operación (fase 2, punto 2.6).
export const agendaOperacion = {
  todosServicios: "Todos los servicios",
  servicio: "Servicio",
  // Negocio de clases: se filtra por clase, no por servicio.
  todasClases: "Todas las clases",
  clase: "Clase",
  pago: {
    pagada: "Pagada",
    por_cobrar: "Por cobrar",
    por_pagar: "Falta pagar en línea",
  },
};

// Parámetros configurables del negocio y de la plataforma (ADR 0042).
export const parametrosConfig = {
  tituloNegocio: "Límites y tiempos",
  ayudaNegocio:
    "Ajusta los tiempos y límites de tu negocio. Lo que dejes vacío usa el valor de la plataforma.",
  tituloPlataforma: "Parámetros de la plataforma",
  ayudaPlataforma:
    "Valor que aplica a todos los negocios que no ajustaron el suyo. Vacío = el valor inicial.",
  dePlataforma: "Plataforma: {valor}.",
  inicial: "Inicial: {valor}.",
  usarReferencia: "El de referencia",
  si: "Sí",
  no: "No",
  guardar: "Guardar",
  descartar: "Descartar cambios",
  guardado: "Parámetros guardados.",
};

// Cambiar el horario desde la cuenta del cliente (ADR 0044).
export const miReprogramar = {
  titulo: "Cambiar horario",
  boton: "Cambiar horario",
  hasta: "Puedes cambiarlo hasta el {fecha} (te quedan {n} cambios).",
  dia: "Nuevo día",
  sinHorarios: "No hay horarios libres ese día. Prueba otro.",
  sinFechas: "No hay otras fechas de esta clase con lugar por ahora.",
  otraFecha: "Elige la nueva fecha",
  cambiar: "Cambiar",
  volver: "Volver",
  hecho: "Listo: cambiamos tu horario.",
};

// Cómo se llaman las cosas en el negocio (ADR 0049). No se adapta a sí misma: la
// sección "terminologiaNegocio" nombra los términos genéricos.
export const terminologiaNegocio = {
  titulo: "Cómo se llaman las cosas",
  ayuda:
    "Así se nombran en tus pantallas y en la app. Parte de lo de tu giro; elige otro si tu negocio usa otras palabras.",
  terminos: {
    sesion: "Lo que se reserva",
    miembro: "Quien lo toma",
    instructor: "Quien lo imparte",
  },
  delGiro: "Como en tu giro ({termino})",
  guardar: "Guardar",
  guardado: "Listo: tus pantallas ya usan estas palabras.",
};

// Tarjetas de los listados de Miembros e Instructores.
export const tarjetas = {
  ilimitado: "Ilimitado",
  // Lo que puede usar (lo ya reservado no cuenta), como en la ficha.
  creditos: "1 disponible | {n} disponibles",
  vence: "vence el {fecha}",
  vencio: "venció el {fecha}",
  enPausa: "en pausa hasta el {fecha}",
  sinMembresia: "Sin membresía",
  adeudo: "Tiene un pago pendiente",
  ultimaVisita: "Última visita",
  sinVisitas: "Aún no asiste",
  proxima: "Próxima",
  sinReservas: "Sin reservas",
  sede: "Sede",
  estaSemana: "Próximos 7 días",
  clases: "1 clase | {n} clases",
  citas: "1 cita | {n} citas",
  nadaAgendado: "Nada agendado",
  resenas: "Reseñas",
  promedio: "{promedio} de 5 ({n})",
};

// Nueva clase: tres formas de cargar horarios (fecha y hora por separado).
export const nuevaClase = {
  modos: {
    una: "Una sola clase",
    misma: "Misma hora, varios días",
    porDia: "Horario por día",
  },
  ayuda: {
    una: "Una fecha y una hora.",
    misma: "Se repite cada semana a la misma hora en los días que elijas.",
    porDia: "Se repite cada semana; cada día con su propia hora.",
  },
  fecha: "Fecha",
  desde: "Empieza el",
  hora: "Hora",
  horarios: "Días y horas",
  dia: "Día",
  agregarDia: "Agregar día",
  quitar: "Quitar",
  repetido: "Hay un día repetido con la misma hora.",
  nombresDias: "Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo",
};
