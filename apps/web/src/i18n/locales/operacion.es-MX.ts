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
      sinRegistrar: "Sin registrar",
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
      presupuestos: {
        ver: "Ver presupuestos",
        gestionar: "Hacer, enviar y convertir presupuestos",
      },
      checkins: {
        registrar: "Registrar entradas",
        excepcion: "Autorizar entradas que la plataforma no pudo verificar",
      },
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
      cuotas: { exentar: "Exentar inscripción, anualidad o penalización" },
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
    // Abre directo lo que sigue (la clase o la cita), con el término del negocio.
    verSesion: "Ver {sesion}",
    irRecepcion: "Ir a recepción",
    agenda: "Agenda de hoy",
    verAgenda: "Ver agenda",
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
    // Citas: bonos, no membresías (ADR 0130).
    porVencerCitas:
      "1 bono vence en {dias} días o menos | {n} bonos vencen en {dias} días o menos",
    vencidasCitas:
      "1 bono vencido por recuperar | {n} bonos vencidos por recuperar",
    // Lo que espera al equipo (M08).
    solicitudes:
      "1 solicitud de acceso sin atender | {n} solicitudes de acceso sin atender",
    seguimientos: "1 seguimiento por hacer | {n} seguimientos por hacer",
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
      noAsistio: "No asistió",
      // Ya terminó y nadie registró si vino.
      sinRegistrar: "Sin registrar",
      duracion: "{n} min",
      libres: "Espacios libres hoy",
      agendar: "Agendar",
      nadieAtiende: "Hoy nadie tiene horario de atención.",
      huecos: "1 espacio | {n} espacios",
      sinHuecos: "Sin espacios",
      desde: "desde las {hora}",
      kpi: {
        citas: "Citas hoy",
        llegaron: "Ya llegaron",
        // Aún no terminan y nadie registró la llegada.
        porAtender: "Por atender",
        // Ya terminaron y falta registrar si vino o no.
        sinRegistrar: "Sin registrar",
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
      "Le enviamos un correo para que active su cuenta y vea sus citas, pagos y bonos.",
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
    verMapa: "Ver en el mapa",
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
      embudo: "Embudo",
      equipo: "Equipo y sucursales",
    },
    // El embudo del negocio (plan de mejoras C08): cantidad y denominador.
    embudo: {
      titulo: "Del interés a la renovación",
      subtitulo:
        "Cada paso con su cantidad y de cuántos sale. Las visitas a tu página se cuentan una por persona al día, sin guardar quién.",
      minutos: "{n} min",
      horas: "{n} h",
      pasos: {
        sitio: {
          titulo: "Visitas → solicitudes",
          cifra: "{n} de {de}",
          ayuda:
            "Quienes llenaron un formulario de interés del total de visitas a tu página.",
          ayudaCitas:
            "Quienes llenaron un formulario de interés del total de visitas a tu página.",
        },
        compra: {
          titulo: "Solicitud → primera compra",
          cifra: "{n} de {de}",
          ayuda: "Solicitudes del periodo que ya pagaron un plan.",
          ayudaCitas: "Solicitudes del periodo que ya pagaron un plan.",
        },
        asistencia: {
          titulo: "Compra → primera asistencia",
          cifra: "{n} de {de}",
          ayuda: "Quienes compraron por primera vez y ya vinieron a clase.",
          ayudaCitas:
            "Quienes compraron por primera vez y ya vinieron a su cita.",
        },
        renovacion: {
          titulo: "Renovación",
          cifra: "{n} de {de}",
          ayuda: "Renovaciones que tocaron en el periodo y se pagaron.",
          ayudaCitas: "Renovaciones que tocaron en el periodo y se pagaron.",
        },
      },
      respuesta: {
        titulo: "Tiempo de respuesta",
        mediana: "Mediana de la solicitud al primer contacto",
        sinAtender: "Solicitudes sin atender, de {de}",
      },
      leyenda:
        "Sin base para comparar, el porcentaje se muestra como «—». Cada persona cuenta una vez por paso. Las visitas se cuentan desde que se activó esta medición.",
    },
  },
  // Seguimiento útil en Tareas (plan de mejoras M09).
  // Wellhub y TotalPass (ADR 0148): sus visitantes en Reportes y el check-in automático.
  plataformas: {
    tab: "Plataformas",
    titulo: "Wellhub y TotalPass",
    subtitulo:
      "Quién vino por cada plataforma y sus visitas. Son clientes de la plataforma, no de tu negocio: no aparecen en tu directorio hasta que los registres.",
    periodo: "Periodo",
    desde: "Desde",
    hasta: "Hasta",
    todas: "Todas",
    proveedores: { wellhub: "Wellhub", totalpass: "TotalPass" },
    indicadores: {
      visitas: "Visitas",
      visitantes: "Visitantes",
      nuevos: "Nuevos en el periodo",
      sinVerificar: "Sin verificar",
      sinClase: "Sin clase asignada",
      automaticas: "{n} automáticas",
    },
    porPlataforma: "Por plataforma",
    porClase: "Clases con más visitas",
    porDia: "Visitas por día",
    visitasDe: "{n} visitas · {v} visitantes",
    sinVisitas: "Sin visitas en el periodo.",
    lista: {
      titulo: "Visitantes",
      buscar: "Buscar por nombre, correo, teléfono o ID…",
      nombre: "Visitante",
      plataforma: "Plataforma",
      visitas: "Visitas",
      ultima: "Última visita",
      sinNombre: "Sin nombre",
      esAlumno: "Ya es alumno",
      vacio: "Aún no llega nadie por Wellhub o TotalPass.",
      sinResultados: "Ningún visitante coincide con tu búsqueda.",
      ver: "Ver visitas de {nombre}",
    },
    detalle: {
      idPlataforma: "ID en {plataforma}",
      correo: "Correo",
      telefono: "Teléfono",
      producto: "Plan",
      primera: "Primera visita",
      historial: "Visitas",
      sinClase: "Sin clase asignada",
      esAlumnoAyuda:
        "Ya lo registraste como alumno con el mismo correo. Aquí siguen sus visitas por la plataforma.",
    },
    estados: {
      validado: "Validada",
      no_verificable: "Sin verificar",
      excepcion: "Excepción",
    },
    origen: {
      automatico: "Automática",
      manual: "Registrada en recepción",
    },
    automatico: {
      titulo: "Check-in automático",
      listo: "Activo",
      pendiente: "Por activar",
      copiar: "Copiar dirección",
      copiada: "Dirección copiada",
      direccion: "Dirección de avisos",
      sinDireccion:
        "Guarda la integración para generar la dirección donde la plataforma avisa de cada check-in.",
      wellhubListo:
        "Cuando la persona hace check-in en la app de Wellhub, la visita se valida sola y queda en su clase.",
      wellhubPendiente:
        "Pide a Wellhub que mande sus avisos de check-in a esta dirección y captura la llave secreta con que los firma.",
      totalpassListo:
        "Registrado con TotalPass: cuando la persona hace check-in en su app, la visita se valida sola.",
      totalpassPendiente:
        "Captura la llave de API de tu sede (la generas en el portal de TotalPass, en Integraciones) y guarda: AgendaUno registra su aviso.",
    },
  },
  // La bandeja de tareas: por páginas, con búsqueda, filtros y grupos por vencimiento.
  bandeja: {
    tab: "Bandeja",
    estado: "Qué tareas ver",
    pendientes: "Pendientes",
    completadas: "Completadas",
    completada: "Completada",
    completadaEl: "Completada el {fecha}",
    conteos: {
      vencidas: "Vencidas",
      hoy: "Para hoy",
      semana: "Próximos 7 días",
      sin_fecha: "Sin fecha",
    },
    grupos: {
      vencidas: "Vencidas",
      hoy: "Hoy",
      semana: "Próximos 7 días",
      despues: "Más adelante",
      sin_fecha: "Sin fecha",
    },
    vence: {
      vencio: "Venció el {fecha}",
      ayer: "Venció ayer",
      hoy: "Vence hoy",
      manana: "Vence mañana",
      el: "Vence el {fecha}",
    },
    buscar: "Buscar por tarea, detalle o persona…",
    filtros: {
      tipo: "Tipo",
      vencimiento: "Vencimiento",
      mias: "Solo las mías",
    },
    tipos: {
      seguimiento: "Seguimientos",
      automatica: "Automáticas",
      manual: "Manuales",
    },
    rangoVence: "Vencen",
    rangoCompletada: "Completadas",
    desde: "Desde",
    hasta: "Hasta",
    asignada: "Asignada a {nombre}",
    marcarCompletada: "Marcar «{titulo}» como completada",
    reabrirTarea: "Reabrir «{titulo}»",
    cargando: "Cargando tareas…",
    todoAlDia: "Todo al día",
    sinPendientesTexto:
      "No hay tareas pendientes. Crea una o deja que la automatización las genere.",
    sinResultados: "Ninguna tarea coincide con tu búsqueda o filtros.",
    quitarFiltros: "Quitar filtros",
  },
  seguimiento: {
    soloSeguimiento: "Ver solo seguimiento",
    todas: "Ver todas las tareas",
    etiqueta: "Seguimiento",
    registrar: "Registrar resultado",
    panelTitulo: "¿Cómo terminó el seguimiento?",
    resultado: "Resultado",
    nota: "Nota (opcional)",
    guardar: "Guardar resultado",
    registrado: "Resultado guardado",
    copiar: "Copiar mensaje",
    // Sin celular propio, se le escribe a su responsable (familias).
    via: "Se le escribe a {nombre}, su responsable",
    copiado: "Mensaje copiado",
    noSeCopio: "No se pudo copiar; selecciona el texto y cópialo.",
    whatsapp: "Escribir por WhatsApp",
    verFicha: "Ver ficha",
    resultados: {
      contactado: "Contactado",
      renovo: "Renovó",
      no_respondio: "No respondió",
      no_desea_contacto: "No desea contacto",
      requiere_atencion: "Requiere atención",
    },
    ayudaNoDesea:
      "Deja de recibir promociones y seguimientos comerciales. Puedes cambiarlo en su ficha.",
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
    subtituloCitas:
      "Bonos por vencer o recién vencidos, para contactar a tiempo.",
    vacioCitas: "No hay bonos por vencer ni recién vencidos.",
    errorCarga:
      "No pudimos consultar las renovaciones. Intenta de nuevo en un momento.",
  },
  recepcion: {
    sinActividad: "No hay clases programadas este día.",
    sinActividadFiltros:
      "No hay clases este día en la sucursal elegida. Prueba con todas las sucursales.",
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
