/*
| Textos del panel, el portal y el superadmin: se cargan aparte ({@see cargarMensajesPanel}
| en ./index.ts) para que las páginas comerciales no los descarguen. Los de las páginas
| comerciales (es-MX, validación, apariencia, demo y medición) van con la aplicación.
*/
import agendaVisual from "./locales/agendaVisual.es-MX";
import adminUsabilidad from "./locales/adminUsabilidad.es-MX";
import importarClases from "./locales/importarClases.es-MX";
import cobro from "./locales/cobro.es-MX";
import suscripcion from "./locales/suscripcion.es-MX";
import {
  confirmarCorreo,
  expediente,
  listados,
  miPerfil,
  profesional,
} from "./locales/equipo.es-MX";
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
  cobranzaClases,
  cuotasVenta,
  invitarEquipo,
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
import vencimiento from "./locales/vencimiento.es-MX";
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
import paseLista from "./locales/paseLista.es-MX";
import fitpass from "./locales/fitpass.es-MX";
import datosFacturacion from "./locales/datosFacturacion.es-MX";
// Kiosco de llegada (ADR 0183).
import kiosco from "./locales/kiosco.es-MX";
// Videos y clases en línea (ADR 0184).
import videos from "./locales/videos.es-MX";
// WhatsApp por mensaje (ADR 0186).
import whatsappRenta from "./locales/whatsappRenta.es-MX";
import plantillasWhatsApp from "./locales/plantillasWhatsApp.es-MX";
import apartados from "./locales/apartados.es-MX";
import paseWallet from "./locales/paseWallet.es-MX";
import calendariosExternos from "./locales/calendariosExternos.es-MX";
import bandejaMensajes from "./locales/bandejaMensajes.es-MX";
import asistenteIA from "./locales/asistenteIA.es-MX";
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
import eliminarCuenta from "./locales/eliminarCuenta.es-MX";
import cfdi from "./locales/cfdi.es-MX";
import bajaNegocio from "./locales/bajaNegocio.es-MX";
import datosNegocio from "./locales/datosNegocio.es-MX";

export const mensajesPanel = {
  adminUsabilidad,
  presupuestos,
  importarClases,
  agendaVisual,
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
  instructorClase,
  plataformaAdmin,
  recuperarContrasena,
  pagoEnLinea,
  pausaMembresia,
  bajaMembresia,
  cobranzaClases,
  cuotasVenta,
  invitarEquipo,
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
  vencimiento,
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
  fitpass,
  datosFacturacion,
  kiosco,
  videos,
  whatsappRenta,
  plantillasWhatsApp,
  apartados,
  paseWallet,
  calendariosExternos,
  bandejaMensajes,
  asistenteIA,
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
  comunicacionPantalla,
  datosFiscalesSat,
  eliminarCuenta,
  cfdi,
  bajaNegocio,
  datosNegocio,
};
