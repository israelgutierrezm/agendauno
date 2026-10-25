import { createI18n } from "vue-i18n";

import { conMarcaProfunda, type Producto } from "@/lib/producto";

import {
  adaptarMensajes,
  fijarTerminosActuales,
  type TerminosNegocio,
} from "@/lib/terminologia";

import esMX from "./locales/es-MX";
import { validacion } from "./locales/gestion.es-MX";
// El panel de apariencia está montado en toda página (con su título).
import apariencia from "./locales/apariencia.es-MX";
import demoAgendable, { demosPlataforma } from "./locales/demoAgendable.es-MX";
// Medición con GA4 y Meta (ADR 0142 y 0143).
import medicion from "./locales/medicion.es-MX";

/**
 * Los textos de las páginas comerciales (landing prerenderizada, sus formularios, la
 * barra de cookies): van con la aplicación. Los del panel llegan con
 * {@link cargarMensajesPanel}.
 */
export const mensajesComerciales = {
  ...esMX,
  validacion,
  apariencia,
  demoAgendable,
  demosPlataforma,
  medicion,
};

type Mensajes = Record<string, unknown>;

// Textos base (sin adaptar a ningún negocio); los revisa vocabularioPanel.spec.ts. Con
// los del panel en cuanto se cargan.
export let mensajesBase: Mensajes = mensajesComerciales;

let panelCargado: Promise<void> | null = null;

/**
 * Carga (una vez) los textos del panel, el portal y el superadmin. El router la espera
 * antes de cualquier pantalla que no es comercial; en una comercial, un texto que falta
 * también la pide, y la página se vuelve a pintar al llegar.
 */
export function cargarMensajesPanel(): Promise<void> {
  panelCargado ??= import("./panel").then(({ mensajesPanel }) => {
    mensajesBase = { ...mensajesComerciales, ...mensajesPanel };
    pintarTerminologia();
  });
  return panelCargado;
}

export const i18n = createI18n({
  legacy: false,
  locale: "es-MX",
  fallbackLocale: "es-MX",
  messages: { "es-MX": mensajesComerciales },
  // Un texto del panel en una página que no lo esperaba: se piden y se repinta. En
  // desarrollo se avisa cuál, para llevarlo a los comerciales si es de una de ellas.
  missing: (_locale, clave) => {
    if (import.meta.env.DEV && panelCargado === null) {
      console.warn(`Texto del panel en una página comercial: ${clave}`);
    }
    void cargarMensajesPanel();
  },
});

/**
 * Secciones que no se adaptan a la terminología del negocio: las páginas públicas y
 * comerciales y el cobro del SaaS hablan de "clases y citas" en general.
 */
export const SIN_ADAPTAR: ReadonlySet<string> = new Set([
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
  // La demo de la landing (pública) y su lista en el superadmin (ADR 0204).
  "demoAgendable",
  "demosPlataforma",
  "terminologiaNegocio",
  "modalidadNegocio",
]);

/**
 * Secciones de pantallas que solo existen en los negocios de clases (ADR 0104): en un
 * negocio de citas no se adaptan. Si alguna llegara a verse, dice «clases», que es lo
 * cierto, y no inventa «citas grabadas». En uno de clases sí se adaptan («Sesiones
 * grabadas» si su término es «sesión»).
 */
export const SOLO_CLASES: ReadonlySet<string> = new Set([
  "videos",
  "importarClases",
  "importarReservas",
]);

/**
 * Pone los textos en la terminología del negocio en sesión (ADR 0049): en una
 * barbería, "Próximas clases" se lee "Próximas citas". Sin negocio, los textos base.
 */
export function aplicarTerminologia(
  terminos: TerminosNegocio | null,
  modalidad: "clases" | "citas" = "clases",
): void {
  terminosDeSesion = terminos;
  modalidadDeSesion = modalidad;
  pintarTerminologia();
}

// Fuera del panel (páginas públicas con sesión) rigen los textos base: la página de
// otro negocio no habla con las palabras del negocio en sesión.
let terminosDeSesion: TerminosNegocio | null = null;
let modalidadDeSesion: "clases" | "citas" = "clases";
let terminologiaEnPausa = false;
let marca: Producto = "agendauno";

function pintarTerminologia(): void {
  const terminos = terminologiaEnPausa ? null : terminosDeSesion;
  fijarTerminosActuales(terminos);
  const adaptados =
    terminos === null
      ? mensajesBase
      : adaptarMensajes(
          mensajesBase,
          terminos,
          modalidadDeSesion === "citas"
            ? new Set([...SIN_ADAPTAR, ...SOLO_CLASES])
            : SIN_ADAPTAR,
        );
  // Con los del panel, más secciones que las comerciales con que se creó.
  i18n.global.setLocaleMessage(
    "es-MX",
    conMarca(adaptados, marca) as typeof mensajesComerciales,
  );
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
