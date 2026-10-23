/**
 * Medición con GA4 y Meta (ADR 0142 y 0143): el aviso de privacidad de las páginas
 * públicas, los destinos del SaaS en el superadmin y los de cada negocio.
 */
export default {
  aviso: {
    titulo: "Tu privacidad",
    textoPlataforma:
      "Usamos medición opcional para saber cómo nos encuentras y mejorar nuestras campañas. Solo se activa si la aceptas y puedes cambiarla cuando quieras.",
    textoNegocio:
      "{negocio} usa medición opcional para saber cómo lo encuentras y mejorar sus campañas. Solo se activa si la aceptas y puedes cambiarla cuando quieras.",
    esteNegocio: "Este negocio",
    aceptar: "Aceptar",
    rechazar: "Solo lo necesario",
    elegir: "Elegir",
    guardar: "Guardar mi elección",
    analitica: "Analítica",
    analiticaTexto:
      "Google Analytics: qué páginas se visitan y cómo se llega a ellas.",
    publicidad: "Publicidad",
    publicidadTexto:
      "Medir los resultados de los anuncios (Meta y Google) y mejorar las campañas.",
    necesarias:
      "Lo necesario para que la página funcione siempre está activo y no mide nada.",
    avisoPrivacidad: "Aviso de privacidad",
    privacidad: "Privacidad",
    cerrar: "Cerrar",
  },
  destinos: {
    ga4: {
      nombre: "Google Analytics 4",
      descripcion:
        "Visitas y conversiones en tu propiedad de GA4: la etiqueta en el navegador (con permiso de analítica) y los resultados confirmados desde el servidor (Measurement Protocol).",
      identificador: "ID de medición",
      ayudaIdentificador:
        "Empieza con «G-». Está en Administrar → Flujos de datos → tu flujo web.",
      secreto: "API secret de Measurement Protocol",
      ayudaSecreto:
        "En el mismo flujo: Secretos de la API de Measurement Protocol → Crear.",
    },
    meta: {
      nombre: "Meta (Facebook e Instagram)",
      descripcion:
        "Resultados de tus anuncios en Meta: el píxel en el navegador (con permiso de publicidad) y las conversiones confirmadas desde el servidor (Conversions API), deduplicadas con el mismo identificador.",
      descripcionNegocio:
        "Resultados de tus anuncios en Meta, enviados desde el servidor (Conversions API) solo con permiso de publicidad. El píxel no se carga en tu página: su cookie se compartiría con otros negocios.",
      identificador: "ID del píxel (dataset)",
      ayudaIdentificador: "Solo números. Está en el Administrador de eventos.",
      secreto: "Token de acceso de la Conversions API",
      ayudaSecreto:
        "Administrador de eventos → tu píxel → Configuración → Generar token de acceso.",
      codigoPrueba: "Código de eventos de prueba (opcional)",
      ayudaCodigoPrueba:
        "Mientras esté escrito, lo que mandes aparece en «Eventos de prueba» y no cuenta en tus resultados. Bórralo al terminar de probar.",
    },
    secretoGuardado: "Guardado. Escribe uno nuevo solo para cambiarlo.",
    encendido: "Encendido",
    aviso:
      "El aviso de privacidad vigente ya informa esta medición (qué se mide, con quién se comparte y cómo retirar el permiso).",
    estado: {
      apagado: "Apagado",
      activo: "Enviando",
      con_error: "Con error",
    },
    ultimoEnvio: "Último envío: {fecha}",
    sinEnvios: "Aún no envía nada.",
    ultimoError: "Último error ({fecha}): {error}",
    guardar: "Guardar",
    probar: "Probar conexión",
    desconectar: "Desconectar",
    confirmarDesconectar:
      "¿Desconectar {destino}? Se borran su ID y su secreto, y lo pendiente ya no se manda.",
    guardado: "Guardado",
    desconectado: "Desconectado",
    guia: "Cómo configurarlo",
  },
  envios: {
    titulo: "Últimos envíos",
    vacio: "Todavía no hay conversiones para enviar.",
    estado: {
      pendiente: "Pendiente",
      enviado: "Aceptado por el proveedor",
      error: "Con error",
      omitido: "Omitido",
    },
    motivo: {
      preferencias: "la persona no lo autorizó",
      sin_identificador: "sin identificador de su navegador",
      sin_contexto: "sin datos de su navegador",
      fuera_de_ventana: "pasó el plazo del proveedor",
      entorno_de_pruebas: "entorno de pruebas",
      negocio_demo: "negocio de demostración",
      destino_apagado: "destino apagado",
      destino_desconectado: "destino desconectado",
      credenciales: "el token o el secreto no sirve",
      sin_conexion: "sin conexión",
      incierto: "sin respuesta (no se reintenta para no duplicar)",
      rechazado: "el proveedor lo rechazó",
      no_aplica: "no aplica",
    },
    reintento: "se reintenta {fecha}",
    aceptadoNoAtribuido:
      "«Aceptado» quiere decir que el proveedor lo recibió, no que lo atribuyó a un anuncio.",
  },
  plataforma: {
    pestana: "Medición",
    titulo: "Medición del SaaS",
    descripcion:
      "Los destinos de GA4 y Meta de cada producto, para medir cómo llegan los negocios que contratan. Son distintos de los que configura cada negocio para su propia página.",
    embudo:
      "Se envía: negocio creado, prueba iniciada, activación operativa (su primera operación real) y primera suscripción pagada. Una prueba gratuita no es una venta.",
  },
  negocio: {
    pestana: "Medición",
    titulo: "Medición de tus campañas",
    descripcion:
      "Conecta tu propia cuenta de Google Analytics 4 y tu píxel de Meta para medir cómo llegan tus alumnos. Son tuyas: distintas de las de la plataforma y de las de otros negocios.",
    embudo:
      "Se envía: visita a tu página (GA4), solicitud, cuenta activada, compra pagada, devolución y primera reserva confirmada, solo con el permiso de cada persona. Nunca nombres, correos, teléfonos ni respuestas de formularios.",
    extras: "Eventos adicionales",
    extrasAyuda:
      "Siempre quedan en tu seguimiento interno. Enciéndelos solo si también quieres mandarlos a este destino.",
    extra: {
      asistencia: "Primera asistencia",
      renovacion: "Renovaciones pagadas",
    },
  },
  reporte: {
    titulo: "Por campaña",
    ayuda:
      "La campaña que se acredita es la del último toque vigente (su enlace con UTM, el anuncio o el sitio de donde llegó). Es la atribución de este sistema, no la de Google ni la de Meta.",
    vacio: "Aún no hay solicitudes, compras ni reservas en este periodo.",
    sinAtribucion: "Sin atribución",
    columnas: {
      solicitudes: "Solicitudes",
      nuevos: "Compradores nuevos",
      compras: "Compras",
      devoluciones: "Devoluciones",
      reservas: "Primeras reservas",
      renovaciones: "Renovaciones (aparte)",
    },
    leyenda:
      "Las renovaciones van aparte para no mezclarlas con la adquisición. Sin gasto publicitario capturado, no se calcula costo por resultado.",
    tituloPlataforma: "Por campaña",
    ayudaPlataforma:
      "De los negocios registrados en el periodo (sin demos), por la campaña de su registro. Es la atribución de este sistema, no la de Google ni la de Meta.",
    vacioPlataforma: "Aún no hay registros en este periodo.",
    cifrasPlataforma:
      "{registros} registros · {operacion} operando · {pago} con suscripción pagada",
  },
  guiaGa4: [
    "En GA4, crea (o elige) una propiedad y un flujo web para el dominio.",
    "Copia el ID de medición (G-…) y crea un API secret de Measurement Protocol.",
    "En el flujo → Medición mejorada → Vistas de página → Configuración avanzada, apaga «Cambios de página según el historial del navegador»: la vista la manda esta página, una por navegación.",
    "Apaga también «Búsqueda en el sitio» e «Interacciones con formularios» si no los necesitas.",
    "Guarda, confirma el aviso de privacidad, enciéndelo y usa «Probar conexión»: el evento «prueba_conexion» debe aparecer en DebugView.",
  ],
  guiaMeta: [
    "En el Administrador de eventos, crea (o elige) el píxel o dataset.",
    "En Configuración, genera un token de acceso de la Conversions API.",
    "Deja apagada la correspondencia avanzada automática: aquí no se mandan correos ni teléfonos.",
    "Para probar, copia el código de «Eventos de prueba» en «Código de eventos de prueba» y usa «Probar conexión».",
    "Guarda, confirma el aviso de privacidad y enciéndelo. Borra el código de prueba al terminar.",
  ],
};
