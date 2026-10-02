/**
 * Textos de "Mi perfil", del expediente, del perfil de profesionales y de las
 * vistas de listado (lista / cuadrícula). Se montan en es-MX bajo `miPerfil`,
 * `expediente`, `profesional` y `listados`.
 */
export const miPerfil = {
  titulo: "Mi perfil",
  foto: "Foto",
  fotoAyuda:
    "Así te ubican en la agenda y en el equipo. JPG, PNG o WebP de hasta 4 MB.",
  cambiarFoto: "Cambiar foto",
  subirFoto: "Subir foto",
  quitarFoto: "Quitar",
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
    "Tus clases y citas en Google Calendar, Apple u Outlook. Se actualiza solo.",
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
