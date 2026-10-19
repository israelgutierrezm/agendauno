export default {
  panel: {
    resumen: "Tu día, de un vistazo",
    ocupacion: "Ocupación de tus clases",
    proporcion: "{n} de {total}",
  },
  estadoActivo: "Activo",
  estadoInactivo: "Inactivo",
  configurarPrograma: "Configurar programa",
  documentos: {
    nuevo: "Nuevo requisito documental",
    editar: "Editar requisito: {nombre}",
    ayuda: "Define el documento que solicitarás y a quién se aplica.",
  },
  nomina: {
    definir: "Definir esquema",
    editar: "Esquema de pago de {nombre}",
    ayuda: "Configura cómo se calcula el pago de este integrante del equipo.",
  },
  recompensas: {
    nueva: "Nueva recompensa",
    nombre: "Nombre de la recompensa",
    costo: "Puntos necesarios",
    ejemplo: "Ejemplo: Una clase de regalo",
  },
  buscarMenu: "Buscar una opción…",
  resultadosMenu: "Opciones disponibles",
  sinOpciones: "No encontramos esa opción. Prueba con otro nombre.",
  limpiarBusqueda: "Limpiar búsqueda",
  filtrosAplicados: "Filtros aplicados",
  quitarFiltro: "Quitar filtro: {nombre}",
  registroManual: "Registro manual",
  prospectosAyuda:
    "Organiza contactos interesados, solicitudes y seguimientos para acompañarlos hasta su primera compra o reserva.",
  configurarConexion: "Configurar conexión",
  credenciales: {
    secret_key: "Clave secreta",
    publishable_key: "Clave pública",
    webhook_secret: "Secreto para verificar avisos de pago",
    merchant_id: "Identificador del comercio",
    private_key: "Clave privada",
    public_key: "Clave pública",
    webhook_user: "Usuario para verificar avisos de pago",
    webhook_password: "Contraseña para verificar avisos de pago",
    access_token: "Token de acceso",
  },
  fichas: {
    titulo: "Fichas de datos",
    ejemplo: "Ejemplo: Ficha médica",
    listadoAyuda:
      "Consulta tus fichas, sus destinatarios y sus preguntas. Elige una para comenzar a gestionarla.",
    gestionar: "Gestionar ficha",
    consultar: "Ver campos",
    volver: "Volver a las fichas",
    gestionando: "Gestionando: {nombre}",
    consultando: "Campos de {nombre}",
    lecturaAyuda: "Consulta las preguntas y los destinatarios de esta ficha.",
    editorAyuda:
      "Consulta las preguntas existentes y agrega nuevos campos a esta ficha.",
    campos: "Sin campos | 1 campo | {n} campos",
    activa: "Publicada",
    inactiva: "Inactiva",
    // Aún no se pide a nadie (ADR 0166).
    borrador: "Borrador",
    nuevaPregunta: "Agregar pregunta",
    preguntaAyuda: "Esta pregunta se agregará a «{nombre}».",
    pregunta: "Pregunta o nombre del campo",
    tipo: "Tipo de respuesta",
    opciones: "Opciones de respuesta",
    nombre: "Nombre de la ficha",
    destinatarios: "A quién se aplica",
    ayuda:
      "Define el nombre y los destinatarios. Después podrás agregar sus preguntas.",
  },
  ingresoPromedio: "Ingreso promedio por {persona}",
  equipoClases:
    "Consulta a tus instructores, sus clases y la actividad de esta semana.",
  agenda: {
    clase: "Clase",
    ayuda:
      "Elige la clase y define cuándo se imparte, con quién y con qué cupo.",
  },
  sucursales: {
    ayuda: "Ubicación, contacto y horarios de cada sucursal, en un solo lugar.",
    buscar: "Buscar sucursal, dirección o región…",
    sinDireccion: "Dirección por completar",
    zona: "Zona horaria",
    impuesto: "IVA",
    formulario:
      "Completa los datos de la sucursal y cómo la verán tus clientes.",
    sinResultados: "No hay sucursales que coincidan con tu búsqueda.",
  },
  integraciones: {
    ayuda:
      "Conecta herramientas externas y consulta el estado de tus conexiones.",
    llave:
      "Elige un nombre y los permisos de lectura que necesita la conexión.",
    webhook:
      "Indica dónde enviar los avisos y qué eventos debe recibir tu sistema.",
    // Wellhub y TotalPass, por lo que de verdad pasó (como las pasarelas): activarla
    // no la comprueba. Llave dinámica (`adminUsabilidad.integraciones.conexion.${estado}`).
    conexion: {
      sin_configurar: "Conexión: sin configurar. Captura sus llaves.",
      credenciales: "Conexión: llaves guardadas, sin activar.",
      activa_sin_comprobar:
        "Conexión: activada, aún sin comprobar. Se comprueba con el primer check-in que la plataforma valide o con su primer aviso.",
      comprobadaCheckin:
        "Conexión comprobada: último check-in validado el {fecha}.",
      comprobadaAviso:
        "Conexión comprobada: último aviso de la plataforma recibido el {fecha}.",
      // Lo que llegó con llaves anteriores: historial, no prueba (R13).
      anterior:
        " Con las llaves anteriores respondió por última vez el {fecha}; con las de ahora aún no.",
    },
  },
};
