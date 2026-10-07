/**
 * Textos de "Mi perfil", del expediente, del perfil de profesionales y de las
 * vistas de listado (lista / cuadrícula). Se montan en es-MX bajo `miPerfil`,
 * `expediente`, `profesional` y `listados`.
 */
export const miPerfil = {
  // Entrar con Google: se conecta desde aquí (ADR 0093).
  google: {
    titulo: "Entrar con Google",
    ayuda:
      "Conecta tu cuenta de Google para entrar sin contraseña. Puedes usar un Gmail distinto a tu correo de acceso.",
    conectado: "Listo: ya puedes entrar con Google.",
    conectadoEstado: "Google conectado",
    desconectar: "Quitar Google",
    desconectado: "Quitaste Google: entra con tu correo y contraseña.",
    // En el subdominio del negocio, Google se conecta desde el dominio principal.
    enRaiz:
      "Google se conecta desde {host}: entra ahí con tu correo y contraseña y conéctalo en Mi perfil.",
    conectarEn: "Conectar en {host}",
  },
  titulo: "Mi perfil",
  subtitulo: "Tu información, tu acceso y tus preferencias, en un solo lugar.",
  navegacion: "Secciones de Mi perfil",
  datosDescripcion: "Mantén al día la información con la que te identificas.",
  accesoDescripcion: "Administra cómo entras a tu cuenta.",
  preferencias: "Preferencias",
  preferenciasDescripcion: "Ajusta tu experiencia y lleva tu agenda contigo.",
  calendarioDispositivos: "Google Calendar, Apple Calendar y Outlook",
  // Las tres partes de Mi perfil.
  seccionDatos: "Datos personales",
  seccionAcceso: "Acceso",
  seccionPreferencias: "Preferencias y privacidad",
  celular: "Celular",
  celularAyuda:
    "Para avisarte de tus clases (también por WhatsApp, si lo activas).",
  // Zona de la foto: arrastrar y soltar o clic.
  fotoArrastra: "Arrastra tu foto aquí o haz clic para elegirla",
  fotoSuelta: "Suelta la imagen para subirla",
  fotoZonaTexto: "Arrastra tu foto aquí o",
  fotoZonaElige: "elígela",
  fotoSubiendo: "Subiendo tu foto…",
  fotoFormatos: "JPG, PNG o WebP de hasta 4 MB",
  fotoTipo: "Elige una imagen JPG, PNG o WebP.",
  fotoPeso: "La imagen pesa más de 4 MB.",
  // El botón que despliega la zona para arrastrar o elegir la foto.
  subirFoto: "Subir foto",
  cambiarFoto: "Cambiar foto",
  quitarFoto: "Quitar foto",
  datos: "Tus datos",
  datosAyuda: "Tu nombre como lo verá el equipo.",
  nombre: "Nombre(s)",
  primerApellido: "Apellido paterno",
  segundoApellido: "Apellido materno",
  correo: "Correo de acceso",
  correoAyuda: "Es con el que entras y donde te llegan los avisos.",
  cambiarCorreo: "Cambiar correo",
  correoNuevo: "Correo nuevo",
  tuContrasena: "Tu contraseña",
  enviarEnlace: "Enviar enlace",
  cancelar: "Cancelar",
  correoEnviado:
    "Te enviamos un enlace a {email}. El cambio se aplica al abrirlo.",
  correoPendiente:
    "Falta confirmar {email}: abre el enlace que te enviamos (vence en 24 horas).",
  cancelarCambio: "Cancelar cambio",
  cambioCancelado: "Cancelamos el cambio de correo.",
  guardar: "Guardar",
  guardando: "Guardando…",
  guardado: "Tus datos se guardaron.",
  contrasena: "Contraseña",
  contrasenaAyuda:
    "Al cambiarla se cierran tus otras sesiones; esta sigue abierta.",
  actual: "Contraseña actual",
  nueva: "Nueva contraseña",
  confirmar: "Confirma la nueva contraseña",
  cambiar: "Cambiar contraseña",
  cambiada: "Contraseña actualizada. Cerramos tus otras sesiones.",
  calendario: "Calendario",
  calendarioAyuda:
    "Tus clases en Google Calendar, Apple u Outlook. Se actualiza solo.",
  calendarioObtener: "Conectar mi calendario",
  calendarioAbrir: "Abrir en mi calendario",
  calendarioCopiar: "Copiar enlace",
  calendarioCopiado: "Enlace copiado.",
  calendarioNuevo: "Generar un enlace nuevo",
  calendarioNuevoAyuda:
    "Es privado: no lo compartas. Si lo compartiste, genera uno nuevo y el anterior dejará de funcionar.",
  calendarioGoogle:
    "En Google Calendar: Otros calendarios → Desde URL, y pega el enlace.",
  apariencia: "Apariencia",
  aparienciaAyuda: "Tema, colores y tamaño de letra.",
  abrirApariencia: "Ajustar apariencia",
  error: "No se pudo guardar.",
};

export const expediente = {
  titulo: "Expediente",
  actividad: "Actividad",
  documentos: "Documentos",
  sinDocumentos: "Sin documentos cargados.",
  subir: "Subir documento",
  tipo: "Tipo",
  sinTipo: "Sin tipo",
  archivo: "Archivo",
  archivoAyuda: "PDF, JPG o PNG de hasta 8 MB.",
  subiendo: "Subiendo…",
  subido: "Documento cargado.",
  ver: "Ver",
  aprobar: "Aprobar",
  rechazar: "Rechazar",
  estados: {
    pendiente: "Por revisar",
    aprobado: "Aprobado",
    rechazado: "Rechazado",
  },
  consentimientos: "Consentimientos",
  sinConsentimientos: "El estudio no tiene consentimientos publicados.",
  version: "versión {n}",
  firmado: "Firmado el {fecha}",
  pendiente: "Sin firmar",
  formularios: "Ficha de datos",
  sinFormularios: "No hay fichas de datos que le apliquen.",
  respondido: "Respondido el {fecha}",
  sinResponder: "Sin responder",
  verRespuestas: "Ver respuestas",
  ocultarRespuestas: "Ocultar",
  sinValor: "—",
  si: "Sí",
  no: "No",
  llenar: "Llenar",
  editarRespuestas: "Editar respuestas",
  guardarRespuestas: "Guardar respuestas",
  formularioGuardado: "Respuestas guardadas.",
  elegir: "Elige…",
  error: "No se pudo cargar el expediente.",
};

export const profesional = {
  volver: "Volver a {grupo}",
  desde: "En el equipo desde {fecha}",
  inactivo: "Aún no activa su cuenta",
  verPerfil: "Ver perfil",
};

export const listados = {
  verLista: "Ver como lista",
  verCuadricula: "Ver como cuadrícula",
  agregar: "Agregar",
};

export const confirmarCorreo = {
  titulo: "Confirma tu correo",
  confirmando: "Confirmando tu correo…",
  listo: "Listo. Desde ahora entras con {email}.",
  entrar: "Ir a iniciar sesión",
  volver: "Volver a mi perfil",
  sinEnlace:
    "Este enlace está incompleto. Pide el cambio otra vez desde Mi perfil.",
};
