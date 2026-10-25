import { cargarMensajesPanel } from "@/i18n";

// Las pruebas montan pantallas del panel sin pasar por el router: con todos los textos
// (ADR 0210). La guardia de la landing (textosComerciales.spec.ts) prueba sin ellos.
await cargarMensajesPanel();
