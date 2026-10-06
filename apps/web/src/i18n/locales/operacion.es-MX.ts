/**
 * Textos de los ajustes del panel (revisión del admin): reportes, horarios,
 * renovaciones, recepción y comunicación. Aparte de es-MX.ts (la landing en curso).
 */
export default {
  admin: {
    jornadaCitas: "Citas y atención a clientes",
    jornadaClases: "Clases y asistencia",
    suscripcion: "Tu suscripción a AgendaUno",
    buscarJornada: "Buscar en las citas de este día…",
    buscarDirectorio: "Buscar otro cliente en el directorio",
    filtrarAtencion: "Filtrar por atención",
    atencion: {
      todas: "Todas",
      pendientes: "Por atender",
      llegaron: "Llegaron",
      canceladas: "Canceladas",
    },
    verDetalle: "Ver detalle",
    sinResultados: "No hay citas que coincidan con estos filtros.",
    limpiar: "Limpiar filtros",
    progresoAsistencia: "Asistencia registrada",
    ocupacion: "Ocupación",
    clientesCitas:
      "Encuentra a tus clientes, consulta sus visitas y revisa su próxima cita.",
    clientesClases:
      "Consulta planes, créditos y próximas clases de tus alumnos.",
    cobros: {
      "por-cobrar":
        "Revisa los adeudos y registra los pagos que recibe tu negocio.",
      movimientos: "Consulta los pagos recibidos, sus ajustes y reembolsos.",
      conciliacion:
        "Revisa los pagos en línea y resuelve lo que requiere validación.",
      caja: "Consulta los ingresos de caja por fecha y por quien los registró.",
    },
  },
  // Roles propios del negocio (ADR 0057): nadie da permisos que no tiene.
  rolesPropios: {
    titulo: "Roles y permisos",
    ayuda:
      "Arma roles para tu equipo con los permisos que necesitan. Solo puedes dar permisos que tú tienes; los roles del sistema no se editan.",
    nuevo: "Nuevo rol",
    sistema: "Del sistema",
    propio: "Propio del negocio",
    personas: "Nadie lo tiene | 1 persona | {n} personas",
    cuantosPermisos: "{n} permiso | {n} permisos",
    todos: "Todos los permisos",
    ver: "Ver permisos",
    ocultar: "Ocultar permisos",
    editar: "Editar",
    eliminar: "Eliminar",
    confirmarEliminar: "¿Eliminar el rol «{nombre}»? No se puede deshacer.",
    noCambiar:
      "Tiene permisos que tú no tienes, o es tu propio rol: no puedes cambiarlo.",
    editorNuevo: "Nuevo rol",
    editorEditar: "Editar rol",
    nombre: "Nombre del rol",
    nombrePh: "Por ejemplo: Coordinación",
    permisos: "Permisos",
    sinPermiso: "No lo tienes, así que no puedes darlo.",
    necesita: "Para funcionar necesita también: {lista}.",
    agregarNecesarios: "Agregar lo que necesita",
    incompleto:
      "Algunos permisos necesitan otros para que sus pantallas funcionen. Agrégalos o quita esos permisos para guardar.",
    facetaTitulo: "Para quién es",
    facetas: {
      equipo: "Del equipo",
      instructor: "De quien imparte",
    },
    facetasAyuda: {
      equipo: "Trabaja en el panel del negocio con los permisos que elijas.",
      instructor:
        "Se le agenda como instructor y solo ve sus propias clases, con los permisos que elijas.",
    },
    facetaFija:
      "Es un rol de quien imparte: se le agenda como instructor y solo ve sus propias clases.",
    deInstructor: "De quien imparte",
    instructorAgenda:
      "Quien imparte necesita «Ver la agenda» para ver sus clases.",
    guardar: "Guardar rol",
    guardado: "Rol guardado.",
    eliminado: "Rol eliminado.",
    vacio: "Aún no hay roles propios. Crea el primero con «Nuevo rol».",
    grupos: {
      agenda: "Agenda",
      clientes: "Alumnos",
      membresias: "Membresías y catálogo",
      cobros: "Cobros",
      punto_venta: "Punto de venta",
      equipo: "Equipo",
      marketing: "Marketing",
      negocio: "Negocio",
    },
    permiso: {
      agenda: {
        ver: "Ver la agenda",
        gestionar: "Programar y editar la agenda",
        eliminar: "Borrar horarios, bloqueos y recursos",
      },
      reservas: {
        ver: "Ver reservas",
        gestionar: "Reservar, cancelar y mover reservas",
      },
      asistencia: { marcar: "Pasar asistencia" },
      checkins: { registrar: "Registrar entradas" },
      miembros: {
        ver: "Ver alumnos y su ficha",
        gestionar: "Dar de alta y editar alumnos",
        eliminar: "Dar de baja alumnos",
      },
      derechos: { ver: "Ver paquetes y créditos de cada alumno" },
      documentos: {
        subir: "Subir documentos",
        gestionar: "Administrar documentos y plantillas",
      },
      formularios: {
        responder: "Llenar fichas de datos",
        gestionar: "Crear y editar fichas de datos",
      },
      catalogo: {
        ver: "Ver clases y servicios",
        gestionar: "Crear y editar clases y servicios",
      },
      productos: {
        ver: "Ver planes y paquetes",
        gestionar: "Crear y editar planes y paquetes",
        eliminar: "Archivar planes y paquetes",
      },
      membresias: { gestionar: "Vender y administrar membresías" },
      creditos: { gestionar: "Ajustar créditos" },
      promociones: {
        gestionar: "Crear promociones",
        eliminar: "Borrar promociones",
      },
      ordenes: {
        ver: "Ver ventas",
        gestionar: "Registrar ventas y cobros",
      },
      pagos: {
        reembolsar: "Hacer reembolsos",
        configurar: "Configurar pasarelas de pago",
      },
      facturacion: { ver: "Ver facturación e ingresos" },
      pos: { vender: "Vender en mostrador" },
      inventario: {
        ver: "Ver inventario",
        gestionar: "Administrar inventario",
      },
      usuarios: {
        invitar: "Invitar al equipo",
        gestionar: "Administrar al equipo y sus roles",
        eliminar: "Dar de baja al equipo",
      },
      roles: { gestionar: "Crear y editar roles" },
      tareas: {
        ver: "Ver tareas",
        gestionar: "Crear y asignar tareas",
        equipo: "Ver y atender las tareas de todo el equipo",
      },
      comunicaciones: {
        ver: "Ver comunicaciones",
        gestionar: "Enviar avisos y campañas",
        eliminar: "Borrar plantillas de mensajes",
      },
      automatizaciones: {
        gestionar: "Configurar automatizaciones",
        eliminar: "Borrar automatizaciones",
      },
      lealtad: {
        ver: "Ver el programa de lealtad",
        gestionar: "Administrar el programa de lealtad",
      },
      estudio: { gestionar: "Configurar el negocio" },
      sucursales: {
        ver: "Ver sucursales",
        gestionar: "Administrar sucursales",
      },
      organizaciones: { ver: "Ver marcas", gestionar: "Administrar marcas" },
      integraciones: { configurar: "Configurar integraciones" },
      auditoria: { ver: "Ver la bitácora" },
    },
  },
  // Rol activo: quien tiene varios roles elige con cuál entra y lo cambia arriba.
  rolActivo: {
    titulo: "¿Cómo quieres entrar?",
    subtitulo:
      "Tienes más de un rol en {estudio}. Cada uno muestra sus propias opciones; puedes cambiarlo cuando quieras desde la barra superior.",
    cambiar: "Cambiar de rol",
    panelSubtitulo: "Cada rol muestra sus propias opciones y permisos.",
    activo: "Activo",
    ultimo: "La última vez",
    cambiado: "Ahora estás como {rol}.",
    error: "No se pudo cambiar de rol.",
    // Lo que verá con cada rol, con las palabras del negocio (Barbero, citas,
    // clientes…). Se arma aquí porque al entrar aún rigen los textos base.
    detalle: {
      equipo:
        "El negocio: agenda, {miembros}, cobros y reportes, según tus permisos.",
      instructorClases:
        "Tus {sesiones}, tu agenda y la asistencia de tus {miembros}.",
      instructorCitas: "Tus {sesiones}, tu agenda y tus {miembros}.",
      miembroClases: "Tus reservas, tus pagos y tu expediente.",
      miembroCitas: "Tus {sesiones}, tus pagos y tu expediente.",
    },
  },
  confirmar: {
    titulo: "Confirmar",
    aceptar: "Confirmar",
    cancelar: "Cancelar",
  },
  // Inicio del negocio: el día de hoy.
  hoy: {
    titulo: "Hoy",
    saludo: "Esto es lo que pasa hoy en {estudio}, {fecha}.",
    enCurso: "En curso",
    loQueSigue: "Lo que sigue hoy",
    diaTerminado: "No quedan clases por comenzar hoy",
    buscar: "Buscar en la agenda de hoy…",
    filtrar: "Filtrar la jornada",
    filtros: {
      todas: "Todas",
      proximas: "Por comenzar",
      canceladas: "Canceladas",
    },
    sinResultados: "No hay actividad que coincida con tu búsqueda.",
    limpiar: "Limpiar búsqueda y filtros",
    abrirAgenda: "Abrir agenda",
    irRecepcion: "Ir a recepción →",
    agenda: "Agenda de hoy",
    verAgenda: "Ver agenda →",
    sinSesiones: "No hay clases hoy.",
    cupo: "{n} de {total}",
    pasarLista:
      "Falta pasar lista a 1 persona | Falta pasar lista a {n} personas",
    momento: {
      proxima: "Próxima",
      en_curso: "En curso",
      termino: "Terminó",
      cancelada: "Cancelada",
    },
    pendientes: "Pendientes",
    alDia: "Todo al día: nada por cobrar ni renovaciones por atender.",
    porCobrar: "1 orden por cobrar | {n} órdenes por cobrar",
    enMora: "1 cuenta en mora | {n} cuentas en mora",
    porVencer:
      "1 membresía vence en {dias} días o menos | {n} membresías vencen en {dias} días o menos",
    vencidas:
      "1 membresía vencida por recuperar | {n} membresías vencidas por recuperar",
    // Negocio de citas: quién viene, quién llegó, qué falta y dónde hay espacio.
    citas: {
      vieneDespues: "Quién viene después",
      diaTerminado: "No quedan citas por comenzar hoy",
      revisarCierre:
        "Revisa las llegadas y los cobros pendientes para cerrar la jornada.",
      sinCitas: "No hay citas hoy",
      marcarLlegada: "Falta marcar si llegó",
      porCobrar: "Por cobrar",
      llego: "Llegó",
      libres: "Espacios libres hoy",
      agendar: "Agendar →",
      nadieAtiende: "Hoy nadie tiene horario de atención.",
      huecos: "1 espacio | {n} espacios",
      sinHuecos: "Sin espacios",
      desde: "desde las {hora}",
      kpi: {
        citas: "Citas hoy",
        llegaron: "Ya llegaron",
        porAtender: "Por atender",
        porCobrar: "Por cobrar",
      },
    },
    // Negocio de clases: ocupación, listas, espera y planes por vencer.
    clases: {
      revisarCierre:
        "Revisa la asistencia y los pendientes para cerrar la jornada.",
      enEspera: "1 en lista de espera | {n} en lista de espera",
      kpi: {
        clases: "Clases hoy",
        ocupados: "Lugares ocupados",
        listas: "Listas por registrar",
        enEspera: "En lista de espera",
        porVencer: "Planes por vencer",
      },
    },
  },
  // Columnas de la lista de alumnos o clientes: un dato por columna.
  clientes: {
    registro: "Registro",
    app: "App",
    conApp: "Con acceso",
    sinApp: "Sin cuenta",
    // Invitar a su cuenta: el correo para activarla y ver sus citas y pagos.
    invitar: "Invitar a su cuenta",
    invitarAyuda:
      "Le enviamos un correo para que active su cuenta y vea sus citas, pagos y membresía.",
    invitacionPendiente: "Invitación enviada",
    reenviar: "Reenviar invitación",
    reenviando: "Reenviando…",
    reenviada: "Invitación reenviada a {email}.",
    invitarCorto: "Invitar",
    reenviarCorto: "Reenviar",
    otrosPlanes: "También: {lista}",
    estadoAyuda:
      "Si es cliente activo del negocio (no se refiere a su cuenta).",
    plan: "Plan vigente",
    saldo: "Saldo",
    proxima: "Próxima reserva",
    estado: "Estado",
  },
  // Documentos legales de la plataforma (superadmin): borrador y publicar.
  legales: {
    ayuda:
      "Edita el borrador y publícalo cuando esté listo: los usuarios solo ven lo publicado, con su número de versión y fecha, y cada negocio acepta la versión vigente al registrarse.",
    responsable: "Responsable del tratamiento (aparece en el aviso)",
    nombre: "Nombre o razón social",
    domicilio: "Domicilio completo",
    contacto: "Correo para privacidad y derechos ARCO",
    area: "Persona o área designada",
    marcadores:
      "Usa {'{'}responsable{'}'}, {'{'}domicilio{'}'}, {'{'}contacto{'}'} y {'{'}area{'}'} en el texto: se llenan con estos datos al publicar. No se publica mientras queden campos entre corchetes del borrador.",
    nombreDoc: {
      aviso_privacidad: "Aviso de privacidad",
      terminos: "Términos y condiciones",
    },
    version: "publicado, versión {version} desde el {fecha}",
    sinPublicar:
      "sin publicar (el registro de negocios queda cerrado en producción)",
    guardarBorrador: "Guardar borrador",
    borradorGuardado:
      "Borrador guardado. Los usuarios siguen viendo lo publicado.",
    publicarAviso: "Publicar aviso",
    publicarTerminos: "Publicar términos",
    confirmar: {
      aviso_privacidad:
        "¿Publicar el aviso de privacidad? Será la versión vigente para todos y los negocios nuevos la aceptarán al registrarse.",
      terminos:
        "¿Publicar los términos y condiciones? Serán la versión vigente para todos y los negocios nuevos la aceptarán al registrarse.",
    },
    publicado: "Publicado: versión {version}.",
    versionPublica: "Versión {version} · vigente desde el {fecha}",
  },
  // Grupos y rótulos del menú lateral que no son el título de una pantalla.
  menu: {
    directorio: "Directorio",
    equipo: "Equipo",
    marketing: "Marketing",
    negocio: "Datos del negocio",
  },
  sedes: {
    ubicacion: "Ubicación (para el clima de tus alumnos)",
    ubicacionPh: "19.4194, -99.1617",
    ubicacionAyuda:
      "Pega las coordenadas desde Google Maps (clic derecho sobre el lugar y copia los números) o usa tu ubicación si estás en la sucursal.",
    usarMiUbicacion: "Usar mi ubicación actual",
    ubicando: "Ubicando…",
    verMapa: "Ver en el mapa →",
    invalida:
      "Escribe la latitud y la longitud separadas por una coma (p. ej. 19.4194, -99.1617).",
    sinPermiso: "No pudimos obtener tu ubicación desde este dispositivo.",
  },
  reportes: {
    // Cómo conocieron al negocio los clientes nuevos (ADR 0067).
    origenes: {
      titulo: "Cómo nos conocieron",
      subtitulo:
        "Clientes nuevos de los últimos {n} meses que lo dijeron al agendar en línea. Sin dato: {sinDato}.",
    },
    sinSucursal: "Sin sucursal asignada",
    total: "Total",
    estadoActual: "Estado actual: no depende del periodo elegido.",
    periodo: "Cifras del {desde} al {hasta}.",
    sinClientes:
      "Aún no hay altas suficientes para medir conversión y permanencia.",
    pestanas: {
      resumen: "Resumen",
      ingresos: "Ingresos",
      ocupacion: "Ocupación",
      clientes: "Clientes",
      equipo: "Equipo y sucursales",
    },
  },
  horarios: {
    noSeCargo:
      "No se pudo cargar el horario de esta persona. Reintenta antes de editarlo.",
    descartar:
      "Tienes cambios sin guardar en este horario. ¿Descartarlos y cambiar de selección?",
  },
  renovaciones: {
    titulo: "Renovaciones",
    subtitulo:
      "Membresías y paquetes por vencer o recién vencidos, para contactar a tiempo.",
    vacio: "No hay membresías por vencer ni recién vencidas.",
    errorCarga:
      "No pudimos consultar las renovaciones. Intenta de nuevo en un momento.",
  },
  recepcion: {
    sinActividad: "No hay clases ni citas programadas este día.",
    sinActividadFiltros:
      "No hay clases ni citas este día en la sucursal elegida. Prueba con todas las sucursales.",
    irAgenda: "Ir a la agenda →",
  },
  comunicacion: {
    revisar: "Revisar y enviar",
    confirmarTitulo: "Confirmar envío",
    destinatarios: "Se enviará a 1 persona | Se enviará a {n} personas",
    canal: "Canal: {canal}",
    vistaPrevia: "Vista previa (con datos de ejemplo)",
    enviar: "Enviar ahora",
    cancelar: "Cancelar",
    sinDestinatarios: "Nadie cumple con este segmento: no hay a quién enviar.",
  },
};
