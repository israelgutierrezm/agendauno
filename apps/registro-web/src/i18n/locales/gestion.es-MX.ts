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
        confirmada: "Reserva confirmada",
        ofrecida: "Lugar ofrecido (lista de espera)",
        recordatorio_24h: "Recordatorio 24 h antes",
        recordatorio_2h: "Recordatorio 2 h antes",
        cancelada: "Reserva cancelada",
        sesion_cancelada: "Clase o cita cancelada por el negocio",
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
  },
  recursoTipoPh: "Sala, cancha, carril…",
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
    configuracion: "Configuración",
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
    sinContacto: "Sin datos de contacto.",
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
    cobradoMes: "Cobrado este mes",
    conAdeudo: "Estudios con adeudo",
    todos: "Todos los estados",
    periodo: "Periodo",
    vacio: "No hay cargos con estos filtros.",
    colPeriodo: "Periodo",
    colMonto: "Monto",
    colEstado: "Estado",
    colVence: "Vence",
    colFactura: "Factura",
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
  vence: "Vence el {fecha}",
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
  promociones: "Recibir promociones",
  promocionesAyuda:
    "Ofertas y novedades del negocio. Los avisos de tus reservas y pagos te siguen llegando.",
  descargar: "Descargar mis datos",
  baja: "Pedir la baja de mis datos",
  bajaExplica:
    "El negocio borrará tus datos personales y cerrará tu cuenta. Tus compras y pagos se conservan sin tu nombre, como pide la ley.",
  motivo: "Motivo (opcional)",
  confirmarBaja: "Enviar solicitud",
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

// Registro con un correo que ya es de alguien en el negocio: se confirma por correo.
export const confirmarRegistro = {
  enviado:
    "Te enviamos un correo a {email}. Ábrelo y confirma que es tuyo para entrar con tu historial.",
  titulo: "Confirma tu registro",
  confirmando: "Confirmando tu correo…",
  sinEnlace:
    "Falta información del enlace. Ábrelo directamente desde tu correo.",
  volverARegistrarte: "Volver a la página del negocio",
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
