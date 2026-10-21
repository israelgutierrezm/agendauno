import { createI18n } from "vue-i18n";

import { conMarcaProfunda, type Producto } from "@/lib/producto";

import {
  adaptarMensajes,
  fijarTerminosActuales,
  type TerminosNegocio,
} from "@/lib/terminologia";

import agendaVisual from "./locales/agendaVisual.es-MX";
import adminUsabilidad from "./locales/adminUsabilidad.es-MX";
import importarClases from "./locales/importarClases.es-MX";
import apariencia from "./locales/apariencia.es-MX";
import cobro from "./locales/cobro.es-MX";
import suscripcion from "./locales/suscripcion.es-MX";
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
  corteCaja,
  pausaMembresia,
  bajaMembresia,
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
import zonaArchivo from "./locales/zonaArchivo.es-MX";
import detalleMiembro from "./locales/detalleMiembro.es-MX";
import datosPersonales from "./locales/datosPersonales.es-MX";
import region from "./locales/region.es-MX";
import interesados from "./locales/interesados.es-MX";
import familias from "./locales/familias.es-MX";
import pwa from "./locales/pwa.es-MX";
import sitioWeb from "./locales/sitioWeb.es-MX";
import captacion from "./locales/captacion.es-MX";
import confirmaciones from "./locales/confirmaciones.es-MX";
import corregirCobro from "./locales/corregirCobro.es-MX";
import caja from "./locales/caja.es-MX";
import importarReservas from "./locales/importarReservas.es-MX";
import formasDePago from "./locales/formasDePago.es-MX";
import devolverVenta from "./locales/devolverVenta.es-MX";
import facturarVenta from "./locales/facturarVenta.es-MX";
import cancelarFactura from "./locales/cancelarFactura.es-MX";
import cobroCita from "./locales/cobroCita.es-MX";
import importarCitas from "./locales/importarCitas.es-MX";
import nominaVisual from "./locales/nominaVisual.es-MX";
import horariosVisual from "./locales/horariosVisual.es-MX";
import detalleCita from "./locales/detalleCita.es-MX";
import configuracionInicial from "./locales/configuracionInicial.es-MX";
import modalidadNegocio from "./locales/modalidad.es-MX";
import detalleClase from "./locales/detalleClase.es-MX";
// Medición con GA4 y Meta (ADR 0142 y 0143).
import medicion from "./locales/medicion.es-MX";
import paseLista from "./locales/paseLista.es-MX";
// Kiosco de llegada (ADR 0183).
import kiosco from "./locales/kiosco.es-MX";
// Videos y clases en línea (ADR 0184).
import videos from "./locales/videos.es-MX";
// WhatsApp por mensaje (ADR 0186).
import whatsappRenta from "./locales/whatsappRenta.es-MX";
import plantillasWhatsApp from "./locales/plantillasWhatsApp.es-MX";
import demoAgendable, { demosPlataforma } from "./locales/demoAgendable.es-MX";
// Niveles por actividad y práctica libre (ADR 0187).
import nivelesMiembro from "./locales/nivelesMiembro.es-MX";
import listadosVisual from "./locales/listadosVisual.es-MX";
import sucursalOperativa from "./locales/sucursalOperativa.es-MX";
import {
  inventarioVisual,
  mostradorVisual,
  ventasVisual,
  planesVisual,
  cobranzaVisual,
  equipoVisual,
  promocionesVisual,
  recursosVisual,
  oportunidadesVisual,
  gruposVisual,
  lealtadVisual,
  documentosVisual,
  sucursalesVisual,
  comunicacionesVisual,
} from "./locales/mostradorInventario.es-MX";
import planes from "./locales/planes.es-MX";
import presupuestos from "./locales/presupuestos.es-MX";
import portal from "./locales/portal.es-MX";
import recepcionVisual from "./locales/recepcionVisual.es-MX";
import comunicacionPantalla from "./locales/comunicacionPantalla.es-MX";
import datosFiscalesSat from "./locales/datosFiscalesSat.es-MX";

// Textos base (sin adaptar a ningún negocio).
const mensajesBase = {
  ...esMX,
  adminUsabilidad,
  presupuestos,
  importarClases,
  agendaVisual,
  apariencia,
  cobro,
  suscripcion,
  captacion,
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
  bajaMembresia,
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
  zonaArchivo,
  detalleMiembro,
  datosPersonales,
  region,
  interesados,
  familias,
  pwa,
  sitioWeb,
  confirmaciones,
  corregirCobro,
  caja,
  importarReservas,
  formasDePago,
  devolverVenta,
  facturarVenta,
  cancelarFactura,
  cobroCita,
  importarCitas,
  nominaVisual,
  horariosVisual,
  detalleCita,
  configuracionInicial,
  modalidadNegocio,
  detalleClase,
  paseLista,
  kiosco,
  videos,
  whatsappRenta,
  plantillasWhatsApp,
  demoAgendable,
  demosPlataforma,
  nivelesMiembro,
  listadosVisual,
  sucursalOperativa,
  inventarioVisual,
  mostradorVisual,
  ventasVisual,
  planesVisual,
  cobranzaVisual,
  equipoVisual,
  promocionesVisual,
  recursosVisual,
  oportunidadesVisual,
  gruposVisual,
  lealtadVisual,
  documentosVisual,
  sucursalesVisual,
  comunicacionesVisual,
  medicion,
  comunicacionPantalla,
  datosFiscalesSat,
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
  "modalidadNegocio",
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
let marca: Producto = "agendauno";

function pintarTerminologia(): void {
  const terminos = terminologiaEnPausa ? null : terminosDeSesion;
  fijarTerminosActuales(terminos);
  const adaptados =
    terminos === null
      ? mensajesBase
      : adaptarMensajes(mensajesBase, terminos, SIN_ADAPTAR);
  i18n.global.setLocaleMessage("es-MX", conMarca(adaptados, marca));
}

/**
 * Los textos hablan con la marca del producto (ADR 0108): en TurnoUno, «AgendaUno» se
 * lee «TurnoUno» y «agendauno.mx», su dominio. Los textos base están en AgendaUno.
 */
export function aplicarMarca(producto: Producto): void {
  if (producto !== marca) {
    marca = producto;
    pintarTerminologia();
  }
}

export function conMarca<T>(mensajes: T, producto: Producto): T {
  return conMarcaProfunda(mensajes, producto);
}

export function pausarTerminologia(pausa: boolean): void {
  if (pausa !== terminologiaEnPausa) {
    terminologiaEnPausa = pausa;
    pintarTerminologia();
  }
}
