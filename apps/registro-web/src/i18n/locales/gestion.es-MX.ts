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
  vacio: "Aún nadie responde este formulario.",
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
        ofrecida: "Lugar ofrecido (lista de espera)",
      },
      asistencia: { marcada: "Asistencia marcada" },
      acceso: { registrado: "Acceso registrado" },
      orden: { pagada: "Venta pagada" },
      pago: { reembolsado: "Pago reembolsado" },
      cobro: { fallido: "Cobro recurrente fallido" },
      membresia: {
        suspendida: "Membresía suspendida",
        regularizada: "Membresía regularizada",
      },
      factura: { timbrada: "Factura timbrada" },
    },
  },
};

export const comunicacionesAuto = {
  difusion: "Difusión",
  automaticos: "Automáticos",
  salida: "Bandeja de salida",
  ayuda:
    "Un mensaje automático se envía solo cuando pasa algo: una reserva, una asistencia, una compra…",
  configurar: "Configurar",
  activo: "Activo",
  sinConfigurar: "Sin mensaje",
  editor: "Mensaje automático",
  canal: "Canal",
  asunto: "Asunto",
  cuerpo: "Mensaje",
  marcadores:
    "Puedes usar {a} y {b}, además de los datos del evento (por ejemplo {c}).",
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
    fallido: "Falló",
  },
};

export const reglasAgenda = {
  titulo: "Reglas de la agenda",
  cancelaciones: "Cancelaciones",
  cancelacionesAyuda:
    "Cancelar con esta antelación devuelve el crédito. Después, se cobra según lo que marques.",
  horas: "Horas de anticipación",
  penalizaTarde: "Cobrar cancelaciones tardías",
  penalizaNoShow: "Cobrar inasistencias",
  guardar: "Guardar",
  guardada: "Política guardada.",
  general: "Para todas las actividades",
  porActividad: "Excepciones por actividad",
  agregarActividad: "Agregar excepción para…",
  elegir: "Elige una actividad",
  resumen: "{h} h antes",
  cobraTarde: "cobra tardías",
  cobraNoShow: "cobra inasistencias",
  soloLectura: "Solo el dueño o un administrador cambian esta política.",
  cerrados: "Días cerrados",
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
  titulo: "Agendar una cita",
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
};

export const miCuentaExtra = {
  formularios: "Mis formularios",
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
    pendiente: "En proceso",
    aprobado: "Reembolsado",
    fallido: "Falló",
  },
};

export const validacion = {
  contrasenasNoCoinciden: "Las contraseñas no coinciden.",
  sesion: {
    entrar: "No se pudo iniciar sesión.",
    google: "No se pudo iniciar sesión con Google.",
    activar: "No se pudo activar la cuenta.",
    registrar: "No se pudo crear la cuenta.",
  },
  recursoTipoPh: "Sala, cancha, carril…",
};

export const instructorClase = {
  titulo: "Instructor de la clase",
  ninguno: "Sin instructor",
};
