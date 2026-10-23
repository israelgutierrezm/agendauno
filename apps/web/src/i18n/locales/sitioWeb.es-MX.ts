// El constructor del sitio de cada negocio (ADR 0114): views/SitioWebView.vue y la
// página pública (views/EstudioPublicoView.vue).
export default {
  titulo: "Sitio web",
  // Su antiguo rubro en Configuración del negocio (ahora en Captación, ADR 0167).
  categoria: {
    titulo: "Sitio web",
    descripcion:
      "Tu página en internet: el constructor de páginas y lo que se ve en tu página pública.",
    constructor: "Constructor del sitio",
  },
  descripcion:
    "Cómo se ve tu página: la plantilla, qué secciones muestra y en qué orden, tus textos, fotos y banners. Horarios, precios y servicios salen de tu negocio y se actualizan solos.",
  estado: {
    cambios: "Cambios sin publicar",
    alDia: "Publicado",
    deSiempre: "Sin cambios por publicar",
    sinGuardar: "Cambios sin guardar",
    publicadoEl: "Última publicación: {fecha}",
    nuncaPublicado:
      "Aún no publicas cambios: quien visita tu página ve la de siempre.",
    paginaOculta: "Tu página no está abierta al público: nadie puede verla.",
    abrirPagina: "Abrirla en Dominio y SEO",
  },
  acciones: {
    guardar: "Guardar borrador",
    publicar: "Publicar cambios",
    descartar: "Descartar cambios",
    vistaPrevia: "Vista previa",
    abrirPestana: "Abrir en otra pestaña",
    actualizar: "Actualizar",
    telefono: "Teléfono",
    computadora: "Computadora",
  },
  avisos: {
    guardado: "Borrador guardado.",
    publicado: "Tu sitio está publicado.",
    descartado: "Volviste a lo publicado.",
  },
  confirmar: {
    publicar:
      "¿Publicar estos cambios? Quien visite tu página los verá de inmediato.",
    descartar:
      "¿Descartar los cambios sin publicar? Tu borrador vuelve a lo que está publicado.",
    plantilla:
      "¿Usar la plantilla «{plantilla}»? Cambia el orden de las secciones; tus textos y banners se conservan.",
    usar: "Usar plantilla",
  },
  plantillas: {
    titulo: "Plantilla",
    ayuda: "Un punto de partida: después mueve, oculta y edita lo que quieras.",
    esencial: "Esencial",
    esencialDesc: "Logo y nombre al centro; primero tu agenda.",
    portada: "Portada",
    portadaDesc:
      "Tu foto de portada a todo lo ancho con tu nombre encima; primero lo que ofreces.",
    portadaSinFoto:
      "Sube tu portada arriba, en «Perfil público»: sin ella se ve como la esencial.",
    compacta: "Compacta",
    compactaDesc: "Encabezado corto y directo a reservar.",
    elegida: "En uso",
  },
  secciones: {
    titulo: "Secciones",
    ayuda:
      "Ordénalas y elige cuáles se ven. La portada va siempre al inicio y el contacto al final.",
    visible: "Se ve",
    oculta: "Oculta",
    subir: "Subir {seccion}",
    bajar: "Bajar {seccion}",
    editar: "Editar",
    cerrar: "Listo",
    nombres: {
      inicio: "Portada",
      promociones: "Promociones",
      nosotros: "Nosotros",
      agendaClases: "Próximas clases",
      agendaCitas: "Agenda tu cita",
      serviciosClases: "Clases",
      serviciosCitas: "Servicios",
      horario: "Horarios",
      precios: "Membresías y paquetes",
      equipoClases: "Instructores",
      equipoCitas: "Profesionales",
      resenas: "Reseñas",
      sucursales: "Sucursales",
      contacto: "Contacto",
    },
    contenido: {
      inicio: "Tu logo, nombre, redes y los botones para reservar.",
      promociones: "Tus banners vigentes. Sin banners, no aparece.",
      nosotros: "Tu historia con una foto. Sin texto ni foto, no aparece.",
      agendaClases: "Tus próximas clases con su cupo, al día.",
      agendaCitas: "Cómo agendar en línea, paso a paso.",
      serviciosClases: "Tus clases del catálogo, con su descripción.",
      serviciosCitas: "Tus servicios del catálogo, con precio y duración.",
      horario: "Tu horario semanal de clases.",
      precios: "Tus planes y paquetes a la venta.",
      equipoClases: "Quién da tus clases, con su foto.",
      equipoCitas: "Tu equipo, con su foto.",
      resenas: "Las reseñas que dejas visibles.",
      sucursales: "Dirección, mapa, teléfono y horario de cada sede.",
      contacto:
        "Cómo escribirte, el botón para reservar y tu aviso de privacidad.",
    },
    campos: {
      titulo: "Título",
      tituloAyuda: "Vacío: el de siempre.",
      titular: "Titular de la portada",
      titularAyuda: "Vacío: el nombre de tu negocio.",
      texto: "Texto",
      textoInicio: "Texto de la portada",
      textoInicioAyuda: "Vacío: tu descripción de «Perfil público».",
      foto: "Foto",
      fotoArrastra: "Arrastra una foto aquí o",
    },
  },
  banners: {
    titulo: "Banners",
    ayuda:
      "Promociones o avisos en la sección «Promociones», solo en sus fechas.",
    agregar: "Agregar banner",
    quitar: "Quitar banner",
    vacio: "Aún no tienes banners.",
    tope: "Puedes tener hasta {n} banners.",
    nuevo: "Banner {n}",
    campos: {
      titulo: "Título",
      texto: "Texto",
      enlaceTexto: "Texto del botón",
      enlaceUrl: "Enlace del botón",
      enlaceAyuda:
        "Una página (https://…), una ruta de tu sitio (/…) o una sección (#precios).",
      desde: "Desde",
      hasta: "Hasta",
      vigenciaAyuda: "Vacío: sin fecha de inicio o de fin.",
      foto: "Foto (opcional)",
    },
  },
  // Varias páginas (ADR 0146, fase 2): Inicio y las propias, con su SEO y el pie.
  paginas: {
    titulo: "Páginas",
    ayuda:
      "Inicio y tus páginas propias. Elige una para editar sus secciones; las que van en el menú aparecen arriba de tu sitio.",
    inicio: "Inicio",
    seo: "SEO",
    configurar: "Configurar",
    enMenu: "En el menú",
    fueraMenu: "Fuera del menú",
    subir: "Subir {pagina}",
    bajar: "Bajar {pagina}",
    agregar: "Agregar página",
    nueva: "Nueva página",
    crear: "Crear página",
    ejemplo: "Ej.: Nosotros, Precios, Preguntas frecuentes",
    direccionAyuda: "Su dirección será /p/{direccion}; la cambias después.",
    tope: "Tu sitio puede tener hasta {n} páginas, además de Inicio.",
    quitar: "Quitar página",
    confirmarQuitar:
      "¿Quitar la página «{pagina}»? Sus secciones y bloques se pierden al guardar, y su enlace deja de funcionar al publicar.",
    seccionesDe: "Secciones de «{pagina}»",
    seccionesAyuda:
      "Agrega secciones de tu negocio (horarios, precios, equipo…) o bloques propios, y ordénalos.",
    vacia: "Esta página aún no tiene secciones: agrega la primera.",
    quitarSeccion: "Quitar de la página",
    delNegocio: "Secciones de tu negocio",
    propios: "Bloques propios",
    campos: {
      titulo: "Título de la página",
      direccion: "Dirección",
      enMenu: "Mostrarla en el menú",
    },
  },
  seo: {
    titulo: "Buscadores y redes",
    ayuda:
      "Cómo aparece en Google y al compartir el enlace. Vacío: el de siempre.",
    campoTitulo: "Título",
    campoDescripcion: "Descripción",
    campoImagen: "Imagen al compartir (opcional)",
    quedan: "Quedan {n} caracteres.",
    sinDescripcion: "La descripción de siempre.",
  },
  // Estilo de todo el sitio y su botón principal (ADR 0146, fase 3).
  estilo: {
    titulo: "Estilo del sitio",
    ayuda:
      "Cómo se ven tus páginas públicas: el color, la letra, las esquinas y si se ven claras u oscuras.",
    // El del sitio (estilo.color) no es el de la app del negocio (color_marca).
    color: "Color de tu página web",
    deTuMarca: "El mismo de tu app",
    usarMarca: "Usar el color de tu app",
    colorAyuda:
      "Solo cambia tus páginas públicas, las que se ven en internet. No cambia la app de tu negocio.",
    colorApp: "Color de la app de tu negocio",
    colorAppSin: "El de la plataforma",
    colorAppAyuda:
      "Lo usa la app que tus alumnos instalan en su teléfono; tu página lo toma si no eliges otro. Se cambia en",
    colorAppDonde: "Perfil público, arriba",
    tipografia: "Tipografía",
    tipografias: {
      predeterminada: "La de siempre",
      moderna: "Moderna",
      clasica: "Clásica",
      elegante: "Elegante",
      amigable: "Amigable",
      sistema: "Sencilla",
    },
    esquinas: "Esquinas",
    esquinasOpciones: {
      rectas: "Rectas",
      suaves: "Suaves",
      redondas: "Redondas",
    },
    tema: "Fondo",
    temas: {
      automatico: "Como lo tenga cada quien",
      claro: "Claro",
      oscuro: "Oscuro",
    },
    botonTexto: "Botón principal del encabezado",
    botonEjemplo: "Ej.: Agendar",
    botonEnlace: "Enlace del botón",
    botonAyuda:
      "Aparece arriba en todas tus páginas. Una sección (#agenda), una de tus páginas (/p/precios) o una página web (https://…).",
  },
  versiones: {
    titulo: "Versiones publicadas",
    ayuda:
      "Vuelve a una versión anterior: queda como borrador para que la revises antes de publicarla.",
    actual: "Publicada ahora",
    volver: "Volver a esta",
    por: "Por {nombre}",
    confirmar:
      "¿Volver a la versión del {fecha}? Tu borrador se reemplaza por ella; el público sigue viendo lo publicado hasta que publiques.",
    restaurada: "Listo: tu borrador es esa versión. Revísala y publica.",
  },
  // Editor visual (ADR 0146, fase 3).
  editor: {
    ayuda: "Haz clic en una sección de la vista previa para editarla.",
    enVivo:
      "En vivo: ves tus cambios aunque aún no los guardes. Haz clic en una sección para editarla.",
    incompleto:
      "Hay algo por completar en tu borrador: la vista previa muestra tu último cambio válido.",
    clicParaEditar: "Clic para editar: {seccion}",
  },
  pie: {
    titulo: "Pie de página",
    ayuda:
      "Aparece al final de todas tus páginas, con el menú y tu aviso de privacidad.",
    ejemplo: "Ej.: Abrimos de lunes a sábado de 7:00 a 21:00.",
  },
  // Bloques propios (ADR 0146): se agregan, se repiten y tienen su estilo.
  bloques: {
    agregar: "Agregar bloque",
    elegir: "Elige un bloque",
    elegirAyuda:
      "Se agrega antes del contacto. Después muévelo a donde quieras.",
    tope: "Tu sitio puede tener hasta {n} bloques propios.",
    duplicar: "Duplicar",
    quitar: "Quitar bloque",
    confirmarQuitar:
      "¿Quitar el bloque «{bloque}»? Sus textos y fotos se pierden al guardar.",
    propio: "Bloque propio",
    nombres: {
      texto_imagen: "Texto con imagen",
      galeria: "Galería",
      preguntas: "Preguntas frecuentes",
      testimonios: "Testimonios",
      llamado: "Llamado a la acción",
      video: "Video",
      beneficios: "Beneficios",
      formulario: "Formulario de contacto",
    },
    descripciones: {
      texto_imagen: "Un texto con una foto al lado y, si quieres, un botón.",
      galeria: "Hasta 12 fotos de tu espacio, tus clases o tus trabajos.",
      preguntas: "Las dudas de siempre, con su respuesta.",
      testimonios: "Lo que dicen tus alumnos, con su nombre.",
      llamado: "Una invitación con un botón: agendar, comprar, escribir.",
      video: "Un video de YouTube o Vimeo; se carga cuando le dan clic.",
      beneficios: "Por qué elegirte, en puntos cortos.",
      formulario: "Uno de tus formularios de captación, para dejar sus datos.",
    },
    campos: {
      titulo: "Título",
      texto: "Texto",
      foto: "Foto",
      lado: "La foto va a la",
      izquierda: "Izquierda",
      derecha: "Derecha",
      botonTexto: "Texto del botón",
      botonEnlace: "Enlace del botón",
      fotos: "Fotos",
      pie: "Pie de foto (opcional)",
      agregarFoto: "Agregar foto",
      quitarFoto: "Quitar foto",
      pregunta: "Pregunta",
      respuesta: "Respuesta",
      agregarPregunta: "Agregar pregunta",
      nombre: "Nombre",
      testimonio: "Lo que dijo",
      agregarTestimonio: "Agregar testimonio",
      beneficio: "Beneficio",
      detalle: "Detalle (opcional)",
      agregarBeneficio: "Agregar beneficio",
      quitar: "Quitar",
      video: "Enlace del video",
      videoAyuda:
        "Copia el enlace de YouTube o Vimeo. No se aceptan códigos ni otros sitios.",
      videoNoValido: "Ese enlace no es de un video de YouTube o Vimeo.",
      portadaVideo: "Portada (opcional)",
      formulario: "Formulario",
      sinFormularios:
        "Aún no tienes formularios de captación activos. Créalos en Captación.",
      elegirFormulario: "Elige un formulario",
      estilo: "Estilo",
      fondo: "Fondo",
      alineacion: "Alineación",
    },
    fondos: {
      normal: "Normal",
      suave: "Suave",
      marca: "Tu color",
      oscuro: "Oscuro",
    },
    alineaciones: {
      izquierda: "Izquierda",
      centro: "Centro",
    },
    vacio: "Sin contenido todavía: no se verá en tu página.",
  },
  publico: {
    avisoVistaPrevia:
      "Vista previa: así se verá tu página cuando publiques este borrador.",
    nosotros: "Nosotros",
    // Su menú de páginas y el pie (ADR 0146).
    menu: "Páginas del sitio",
    inicio: "Inicio",
    paginas: "Páginas",
    noExiste: "Esta página no existe o ya no está publicada.",
    irAInicio: "Ir a Inicio",
    // El video carga hasta que la persona le da clic (sin cookies hasta entonces).
    verVideo: "Ver video",
    // El equipo y los carruseles (ADR 0167).
    anterior: "Anterior",
    siguiente: "Siguiente",
    verMas: "Ver más",
    verPerfil: "Ver a {nombre}",
    agendarCon: "Agendar con {nombre}",
    susClases: "Sus próximas clases",
    videoAviso: "Al reproducirlo, el video se carga desde {proveedor}.",
  },
  // Cómo se muestra una sección o un bloque (ADR 0167, Plus).
  presentacion: {
    titulo: "Cómo se muestra",
    siempre: "Como siempre",
    tarjetas: "Tarjetas",
    mosaico: "Mosaico",
    carrusel: "Carrusel",
    ayuda: "El carrusel se desliza con el dedo o con sus flechas.",
    ayudaEquipo:
      "Al tocar a alguien se abre su ficha: su foto, su semblanza y, en citas, agendar con esa persona. La semblanza y la foto se cambian en su perfil del equipo.",
  },
  // En Captación (ADR 0167): sus pestañas y la barra que siempre está a la vista.
  pestanas: {
    etiqueta: "Partes del sitio",
    contenido: "Contenido",
    reservas: "Reservas y ventas",
    compartir: "Compartir",
    dominio: "Dominio y SEO",
  },
  barra: {
    verSitio: "Ver sitio",
    sinPublicar:
      "Puedes preparar y guardar cambios; los publica quien tiene permiso para publicar el sitio.",
  },
  // Lo avanzado es «Plus»: estilo propio, más páginas y bloques propios.
  plus: {
    etiqueta: "Plus",
    ayuda:
      "Tu estilo propio, más páginas y tus bloques son del sitio avanzado (Plus), desde el plan Premium. Lo que ya tienes se conserva.",
    paginas:
      "Más páginas son del sitio avanzado (Plus), desde el plan Premium.",
    bloques:
      "Tus bloques propios son del sitio avanzado (Plus), desde el plan Premium.",
    cambiarPlan: "Ver mi suscripción",
  },
  reservas: {
    ayuda:
      "Lo que se muestra sale de tu negocio: aquí decides qué se ve en tu sitio (lo que ocultes sigue a la venta y se agenda igual); cada cosa se edita en su lugar, así tu página y tu agenda dicen lo mismo. Se publica con tus demás cambios.",
    visitante: "Qué puede hacer quien visita tu página",
    agendar: "Agendar en línea",
    // Quien visita ve las clases y pide lugar; reservar es de quien ya es alumno.
    agendarClases: "Ver tus clases y pedir lugar en línea",
    agendarCitas: "Agendar su cita en línea",
    dejarDatos: "Dejar sus datos para que lo contactes",
    activo: "Activo",
    inactivo: "Apagado",
    irFormularios: "Formularios de captación",
    seMuestra: "Se ve en tu sitio",
    itemSeVe: "Se ve",
    vacio: "Aún no hay nada aquí.",
    servicios: "Servicios",
    serviciosClases: "Clases",
    serviciosCitas: "Servicios",
    editarServicios: "Editar servicios",
    editarServiciosClases: "Editar clases",
    editarServiciosCitas: "Editar servicios",
    precios: "Planes",
    preciosClases: "Planes y paquetes",
    preciosCitas: "Bonos",
    editarPrecios: "Editar planes",
    editarPreciosClases: "Editar planes y paquetes",
    editarPreciosCitas: "Editar bonos",
    horario: "Horario semanal",
    editarHorario: "Editar en la agenda",
    equipo: "Equipo",
    equipoClases: "Instructores",
    equipoCitas: "Equipo",
    editarEquipo: "Editar equipo",
    editarEquipoClases: "Editar instructores",
    editarEquipoCitas: "Editar equipo",
    sucursales: "Sucursales",
    editarSucursales: "Editar sucursales",
    minutos: "{n} min",
    dias: {
      1: "Lun",
      2: "Mar",
      3: "Mié",
      4: "Jue",
      5: "Vie",
      6: "Sáb",
      7: "Dom",
    },
  },
  compartir: {
    enlaces: "Página de enlaces",
    enlacesAyuda:
      "Para la biografía de tus redes: reservar, tu WhatsApp, tu página y tus redes en un solo enlace.",
    formularios: "Formularios de captación",
    formulariosAyuda:
      "Cada formulario tiene su enlace, su QR y su código para tu web.",
    irFormularios: "Ver formularios",
  },
  dominio: {
    direccion: "Dirección de tu página",
    comoSePublica: "¿Cómo se publica?",
    alMomento:
      "Abrir o cerrar tu página se aplica al guardar, aparte de los cambios del sitio.",
    guardarVisibilidad: "Guardar",
    seo: "En buscadores y redes",
    seoAyuda:
      "El título, la descripción y la imagen de Inicio cuando la buscan o la comparten. Se publica con el sitio.",
    seoPaginas: "Tus otras {n} páginas tienen el suyo en Contenido › Páginas.",
    irPaginas: "Ir a Páginas",
    propio: "Dominio propio",
    propioAyuda:
      "Tu página en tu dirección (p. ej. www.tunegocio.com) en lugar de la de la plataforma.",
    propioPlus:
      "Es del sitio avanzado (Plus), desde el plan Premium. Con él, tu página abre en tu propio dominio.",
    propioCampo: "Tu dominio",
    conectar: "Conectar",
    revisar: "Revisar ahora",
    quitar: "Quitar dominio",
    quitarConfirmar:
      "Tu página dejará de abrir en {dominio} y seguirá en la dirección de la plataforma.",
    estado: {
      activo: "Activo",
      pendiente: "Pendiente",
      sin_plan: "Sin el plan",
    },
    activoAyuda: "Tu página ya abre en",
    sinPlanAyuda:
      "Tu dominio está listo, pero se sirve con el sitio avanzado (Plus) de tu plan.",
    problema: {
      sin_txt: "Aún no vemos el registro TXT.",
      txt_distinto:
        "El registro TXT tiene otro valor: cópialo de nuevo, tal cual.",
      no_apunta:
        "El dominio ya es tuyo; falta que apunte aquí con el CNAME (o los registros A).",
    },
    registrosAyuda:
      "Crea estos registros en el panel de tu proveedor de dominio (donde lo compraste):",
    tipo: "Tipo",
    nombre: "Nombre",
    valor: "Valor",
    tardaAyuda:
      "Pueden tardar unas horas en verse. Lo revisamos solos cada 10 minutos; cuando quede activo, tu página abre ahí con candado (https).",
  },
};
