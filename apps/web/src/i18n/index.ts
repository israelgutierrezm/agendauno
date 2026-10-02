import { createI18n } from "vue-i18n";

import {
  adaptarMensajes,
  fijarTerminosActuales,
  type TerminosNegocio,
} from "@/lib/terminologia";

import agendaVisual from "./locales/agendaVisual.es-MX";
import apariencia from "./locales/apariencia.es-MX";
import cobro from "./locales/cobro.es-MX";
import {
  confirmarCorreo,
  expediente,
  listados,
  miPerfil,
  profesional,
} from "./locales/equipo.es-MX";
import esMX from "./locales/es-MX";
import {
  accesoRecepcion,
  agendaOperacion,
  avisosWhatsApp,
  bitacora,
  citaCuenta,
  comunicacionesAuto,
  conexiones,
  consentimientos,
  creditosFicha,
  documentosTabs,
  formulariosRespuestas,
  instructorClase,
  inventarioExtra,
  margenesServicio,
  miCuentaExtra,
  miReprogramar,
  pagoEnLinea,
  parametrosConfig,
  paseEntrada,
  miPrivacidad,
  misDocumentos,
  pasarelasEstado,
  privacidadNegocio,
  resenas,
  pagoAutomatico,
  pagoTienda,
  bajas,
  bloqueosAgenda,
  cambiarSerie,
  cancelacion,
  confirmarRegistro,
  corteCaja,
  pausaMembresia,
  plataformaAdmin,
  porConciliar,
  recuperarContrasena,
  recursosServicio,
  reembolsosPago,
  reprogramar,
  nuevaClase,
  reglasAgenda,
  tarjetas,
  terminologiaNegocio,
  validacion,
} from "./locales/gestion.es-MX";
import asistente from "./locales/asistente.es-MX";
import operacion from "./locales/operacion.es-MX";
import perfilPublico from "./locales/perfilPublico.es-MX";
import buscarPersona from "./locales/buscarPersona.es-MX";
import confirmaciones from "./locales/confirmaciones.es-MX";
import planes from "./locales/planes.es-MX";
import portal from "./locales/portal.es-MX";
import recepcionVisual from "./locales/recepcionVisual.es-MX";

// Textos base (sin adaptar a ningún negocio).
const mensajesBase = {
  ...esMX,
  agendaVisual,
  apariencia,
  cobro,
  recepcionVisual,
  miPerfil,
  confirmarCorreo,
  expediente,
  profesional,
  listados,
  consentimientos,
  documentosTabs,
  formulariosRespuestas,
  conexiones,
  comunicacionesAuto,
  avisosWhatsApp,
  reglasAgenda,
  creditosFicha,
  inventarioExtra,
  accesoRecepcion,
  bitacora,
  citaCuenta,
  miCuentaExtra,
  reembolsosPago,
  validacion,
  instructorClase,
  plataformaAdmin,
  recuperarContrasena,
  pagoEnLinea,
  pausaMembresia,
  paseEntrada,
  pasarelasEstado,
  misDocumentos,
  miPrivacidad,
  privacidadNegocio,
  resenas,
  pagoAutomatico,
  pagoTienda,
  bajas,
  porConciliar,
  confirmarRegistro,
  corteCaja,
  cancelacion,
  margenesServicio,
  bloqueosAgenda,
  reprogramar,
  recursosServicio,
  cambiarSerie,
  agendaOperacion,
  parametrosConfig,
  miReprogramar,
  terminologiaNegocio,
  tarjetas,
  nuevaClase,
  portal,
  planes,
  asistente,
  operacion,
  perfilPublico,
  buscarPersona,
  confirmaciones,
};

export const i18n = createI18n({
  legacy: false,
  locale: "es-MX",
  fallbackLocale: "es-MX",
  messages: { "es-MX": mensajesBase },
});

/**
 * Secciones que no se adaptan a la terminología del negocio: las páginas públicas y
 * comerciales y el cobro del SaaS hablan de "clases y citas" en general.
 */
const SIN_ADAPTAR = new Set([
  "landing",
  "registro",
  "activacion",
  "directorio",
  "escaparate",
  "entrar",
  "tema",
  "cobro",
  "renta",
  "plataforma",
  "plataformaAdmin",
  "terminologiaNegocio",
]);

/**
 * Pone los textos en la terminología del negocio en sesión (ADR 0049): en una
 * barbería, "Próximas clases" se lee "Próximas citas". Sin negocio, los textos base.
 */
export function aplicarTerminologia(terminos: TerminosNegocio | null): void {
  terminosDeSesion = terminos;
  pintarTerminologia();
}

// Fuera del panel (páginas públicas con sesión) rigen los textos base: la página de
// otro negocio no habla con las palabras del negocio en sesión.
let terminosDeSesion: TerminosNegocio | null = null;
let terminologiaEnPausa = false;

function pintarTerminologia(): void {
  const terminos = terminologiaEnPausa ? null : terminosDeSesion;
  fijarTerminosActuales(terminos);
  i18n.global.setLocaleMessage(
    "es-MX",
    terminos === null
      ? mensajesBase
      : adaptarMensajes(mensajesBase, terminos, SIN_ADAPTAR),
  );
}

export function pausarTerminologia(pausa: boolean): void {
  if (pausa !== terminologiaEnPausa) {
    terminologiaEnPausa = pausa;
    pintarTerminologia();
  }
}
