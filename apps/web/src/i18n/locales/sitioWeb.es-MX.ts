// El constructor del sitio de cada negocio (ADR 0114): views/SitioWebView.vue y la
// página pública (views/EstudioPublicoView.vue).
export default {
  titulo: "Sitio web",
  descripcion:
    "Cómo se ve tu página: la plantilla, qué secciones muestra y en qué orden, tus textos, fotos y banners. Horarios, precios y servicios salen de tu negocio y se actualizan solos.",
  estado: {
    cambios: "Cambios sin publicar",
    alDia: "Publicado",
    deSiempre: "Sin cambios por publicar",
    sinGuardar: "Cambios sin guardar",
    publicadoEl: "Última publicación: {fecha}",
    nuncaPublicado:
      "Aún no publicas cambios: tus clientes ven la página de siempre.",
    paginaOculta:
      "Tu página no está abierta al público. Ábrela en «Página pública» para que se vea.",
    abrirPagina: "Ir a Página pública",
  },
  acciones: {
    guardar: "Guardar borrador",
    publicar: "Publicar",
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
      "¿Publicar estos cambios? Tus clientes los verán en tu página de inmediato.",
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
      "Sube una portada en «Página pública»: sin ella se ve como la esencial.",
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
      textoInicioAyuda: "Vacío: tu descripción de «Página pública».",
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
  publico: {
    avisoVistaPrevia:
      "Vista previa: así se verá tu página cuando publiques este borrador.",
    nosotros: "Nosotros",
  },
};
