import { reactive } from "vue";

import { api } from "@/lib/api";
import {
  atribucionParaEnviar,
  moverAtribucionSegunPreferencias,
  type AtribucionParaEnviar,
  valorDeCampana,
  identificadorDeClic,
  type Ambito,
} from "@/lib/atribucion";
import {
  guardarPreferencias,
  leerPreferencias,
  visitanteDe,
  type Preferencias,
} from "@/lib/consentimiento";

/**
 * Las etiquetas de Google Analytics 4 y del píxel de Meta (ADR 0143), por ámbito.
 *
 * - Cada producto (la promoción del SaaS) y cada negocio tienen sus propios destinos;
 *   aquí solo llegan sus IDs públicos. Sin destinos no se pregunta ni se carga nada.
 * - Nada se carga sin la autorización: GA4 con la de analítica y Meta con la de
 *   publicidad. Consent Mode empieza todo en «denied» y se actualiza con lo elegido.
 * - Solo en las páginas públicas (las comerciales y el registro del producto; la
 *   página del negocio y su agenda). En el panel, la cuenta del cliente, el superadmin,
 *   un widget o una vista previa no se mide: GA4 se desactiva ahí.
 * - Una sola vista de página por navegación, desde el router (`send_page_view: false`
 *   en GA4 y `disablePushState` en Meta).
 * - En los negocios, GA4 usa cookies con un prefijo propio y solo en su dominio: no se
 *   comparten entre negocios ni con la plataforma. El píxel de Meta no se carga en los
 *   negocios (guarda su cookie en el dominio padre, compartido): Meta recibe sus
 *   conversiones desde el servidor.
 * - Sin JavaScript arbitrario: solo estos dos scripts oficiales, con los IDs validados.
 */

export interface DestinosPublicos {
  ga4: string | null;
  meta: string | null;
}

type Gtag = (...args: unknown[]) => void;
interface Fbq {
  (...args: unknown[]): void;
  callMethod?: (...args: unknown[]) => void;
  queue: unknown[][];
  push: Fbq;
  loaded: boolean;
  version: string;
  disablePushState?: boolean;
}

declare global {
  interface Window {
    gtag?: Gtag;
    fbq?: Fbq;
    _fbq?: Fbq;
  }
}

const GA4 = /^G-[A-Z0-9]{4,20}$/;
const PIXEL = /^\d{6,20}$/;

/** Lo que ve el aviso de privacidad (AvisoMedicion). */
export const medicion = reactive<{
  ambito: Ambito | null;
  destinos: DestinosPublicos | null;
  preferencias: Preferencias | null;
  /** Esta pantalla no se mide (panel, cuenta, widget, vista previa). */
  pausada: boolean;
  /** El panel de preferencias abierto a pedido («Privacidad»). */
  eligiendo: boolean;
}>({
  ambito: null,
  destinos: null,
  preferencias: null,
  pausada: true,
  eligiendo: false,
});

const consultas = new Map<Ambito, Promise<DestinosPublicos>>();
const cargados = { ga4: new Map<string, Ambito>(), meta: new Set<string>() };

function urlDestinos(ambito: Ambito): string {
  const [tipo, clave] = ambito.split(":") as [string, string];
  return tipo === "plataforma"
    ? `/api/v1/medicion/${encodeURIComponent(clave)}`
    : `/api/v1/app/${encodeURIComponent(clave)}/medicion`;
}

/** Los IDs públicos de los destinos encendidos del ámbito (sin ninguno, nada). */
export function destinosDe(ambito: Ambito): Promise<DestinosPublicos> {
  let consulta = consultas.get(ambito);
  if (consulta === undefined) {
    consulta = api
      .get<{ data: Partial<DestinosPublicos> }>(urlDestinos(ambito))
      .then(({ data }) => ({
        ga4:
          typeof data.data.ga4 === "string" && GA4.test(data.data.ga4)
            ? data.data.ga4
            : null,
        meta:
          typeof data.data.meta === "string" &&
          PIXEL.test(data.data.meta) &&
          ambito.startsWith("plataforma:")
            ? data.data.meta
            : null,
      }))
      .catch(() => ({ ga4: null, meta: null }));
    consultas.set(ambito, consulta);
  }
  return consulta;
}

export function hayDestinos(d: DestinosPublicos | null): boolean {
  return d !== null && (d.ga4 !== null || d.meta !== null);
}

/** Prefijo de las cookies de GA4 de un negocio: las suyas, no las de otro. */
export function prefijoCookies(ambito: Ambito): string | null {
  if (!ambito.startsWith("negocio:")) return null;
  return `n_${ambito.slice("negocio:".length).replace(/[^a-z0-9]/g, "_")}`;
}

function cargarScript(src: string): void {
  if (document.querySelector(`script[src="${src}"]`) !== null) return;
  const s = document.createElement("script");
  s.async = true;
  s.src = src;
  document.head.appendChild(s);
}

function gtag(...args: unknown[]): void {
  window.gtag?.(...args);
}

function asegurarGtag(id: string): void {
  if (typeof window.gtag !== "function") {
    window.dataLayer = window.dataLayer ?? [];
    window.gtag = function () {
      // gtag.js lee el objeto `arguments`, no un arreglo.
      // eslint-disable-next-line prefer-rest-params
      window.dataLayer!.push(arguments as unknown as Record<string, unknown>);
    };
    // Todo empieza negado; lo elegido se aplica justo después.
    gtag("consent", "default", {
      ad_storage: "denied",
      ad_user_data: "denied",
      ad_personalization: "denied",
      analytics_storage: "denied",
    });
    gtag("js", new Date());
  }
  cargarScript(
    `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`,
  );
}

function consentimientoGoogle(p: Preferencias): void {
  gtag("consent", "update", {
    analytics_storage: p.analitica ? "granted" : "denied",
    ad_storage: p.publicidad ? "granted" : "denied",
    ad_user_data: p.publicidad ? "granted" : "denied",
    ad_personalization: p.publicidad ? "granted" : "denied",
  });
  gtag("set", "ads_data_redaction", !p.publicidad);
}

function enDesarrollo(): boolean {
  const h = window.location.hostname;
  return h === "localhost" || h.endsWith(".localhost") || h === "127.0.0.1";
}

function cargarGa4(ambito: Ambito, id: string, p: Preferencias): void {
  asegurarGtag(id);
  consentimientoGoogle(p);
  desactivarGa4(id, false);
  if (cargados.ga4.has(id)) return;
  const prefijo = prefijoCookies(ambito);
  gtag("config", id, {
    send_page_view: false,
    ...(prefijo !== null
      ? { cookie_prefix: prefijo, cookie_domain: "none" }
      : {}),
    ...(enDesarrollo() ? { debug_mode: true } : {}),
  });
  cargados.ga4.set(id, ambito);
}

function desactivarGa4(id: string, desactivar: boolean): void {
  (window as unknown as Record<string, boolean>)[`ga-disable-${id}`] =
    desactivar;
}

function cargarMeta(id: string): void {
  if (typeof window.fbq !== "function") {
    const n = function (...args: unknown[]) {
      if (n.callMethod) n.callMethod(...args);
      else n.queue.push(args);
    } as Fbq;
    n.push = n;
    n.loaded = true;
    n.version = "2.0";
    n.queue = [];
    // La vista de página la manda el router, una por navegación.
    n.disablePushState = true;
    window.fbq = n;
    window._fbq ??= n;
    cargarScript("https://connect.facebook.net/en_US/fbevents.js");
  }
  window.fbq!("consent", "grant");
  if (cargados.meta.has(id)) return;
  // Sin eventos automáticos (clics, datos de la página) ni correspondencia avanzada.
  window.fbq!("set", "autoConfig", false, id);
  window.fbq!("init", id);
  cargados.meta.add(id);
}

/** Borra las cookies de un nombre en este host y en sus dominios padre. */
function borrarCookies(patron: RegExp): void {
  const host = window.location.hostname;
  const partes = host.split(".");
  const dominios = [""];
  for (let i = 0; i < partes.length - 1; i++) {
    dominios.push(`; domain=.${partes.slice(i).join(".")}`);
  }
  for (const par of document.cookie.split(";")) {
    const nombre = par.split("=")[0]?.trim() ?? "";
    if (nombre === "" || !patron.test(nombre)) continue;
    for (const dominio of dominios) {
      document.cookie = `${nombre}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/${dominio}`;
    }
  }
}

function retirar(ambito: Ambito, d: DestinosPublicos, p: Preferencias): void {
  const prefijo = prefijoCookies(ambito);
  if (!p.analitica && d.ga4 !== null) {
    if (cargados.ga4.has(d.ga4)) {
      consentimientoGoogle(p);
      desactivarGa4(d.ga4, true);
    }
    borrarCookies(prefijo !== null ? new RegExp(`^${prefijo}_ga`) : /^_ga/);
  }
  if (!p.publicidad && d.meta !== null) {
    if (cargados.meta.has(d.meta)) window.fbq?.("consent", "revoke");
    borrarCookies(/^_fb[pc]$/);
  }
  if (!p.publicidad && d.ga4 !== null && cargados.ga4.has(d.ga4)) {
    consentimientoGoogle(p);
  }
}

/** Aplica las preferencias del ámbito: carga lo autorizado y retira lo que no. */
function aplicar(ambito: Ambito, d: DestinosPublicos, p: Preferencias): void {
  try {
    if (p.analitica && d.ga4 !== null) cargarGa4(ambito, d.ga4, p);
    if (p.publicidad && d.meta !== null) cargarMeta(d.meta);
    retirar(ambito, d, p);
  } catch {
    // Un bloqueador o un error del proveedor nunca rompen la página.
  }
}

// Una vista de página por navegación: la del router; si las etiquetas cargan después
// (al aceptar), se manda la de la página actual una sola vez.
let navegacion = 0;
let vistaEnviada = -1;
let vista: { ambito: Ambito; ruta: string; titulo: string } | null = null;

function enviarVista(): void {
  if (vista === null || vistaEnviada === navegacion || medicion.pausada) return;
  const { ambito, ruta, titulo } = vista;
  const p = leerPreferencias(ambito);
  const d = medicion.ambito === ambito ? medicion.destinos : null;
  if (p === null || d === null) return;
  let enviada = false;
  try {
    if (p.analitica && d.ga4 !== null && cargados.ga4.has(d.ga4)) {
      gtag("event", "page_view", {
        send_to: d.ga4,
        page_location: `${window.location.origin}${ruta}`,
        page_title: titulo.slice(0, 300),
      });
      enviada = true;
    }
    if (p.publicidad && d.meta !== null && cargados.meta.has(d.meta)) {
      window.fbq?.("trackSingle", d.meta, "PageView");
      enviada = true;
    }
  } catch {
    // Medir nunca rompe la página.
  }
  if (enviada) vistaEnviada = navegacion;
}

/**
 * La ruta como se manda: sin query, salvo la campaña (`utm_*` y `gclid`, validados),
 * que GA4 usa para atribuir la visita.
 */
export function rutaParaMedir(ruta: string): string {
  const url = new URL(ruta, "https://x.invalid");
  const query = new URLSearchParams();
  for (const [clave, valor] of url.searchParams) {
    if (/^utm_(source|medium|campaign|content|term)$/.test(clave)) {
      const v = valorDeCampana(valor);
      if (v !== null) query.set(clave, v);
    } else if (clave === "gclid" && identificadorDeClic(valor) !== null) {
      query.set(clave, valor);
    }
  }
  const q = query.toString();
  return `${url.pathname}${q !== "" ? `?${q}` : ""}`;
}

/**
 * Cada navegación: qué ámbito es, si se mide y su vista de página. `ruta` es la que se
 * manda (ya sin datos de nadie); null = esta pantalla no se mide.
 */
export async function navegar(
  ambito: Ambito | null,
  ruta: string | null,
  titulo: string,
): Promise<void> {
  navegacion++;
  const pausada = ambito === null || ruta === null;
  medicion.pausada = pausada;
  // GA4 queda desactivado mientras no se esté en una página que se mide.
  for (const id of cargados.ga4.keys()) {
    desactivarGa4(id, pausada || cargados.ga4.get(id) !== ambito);
  }
  if (pausada) {
    vista = null;
    return;
  }
  vista = { ambito, ruta: rutaParaMedir(ruta), titulo };
  if (medicion.ambito !== ambito) {
    medicion.ambito = ambito;
    medicion.destinos = null;
    medicion.eligiendo = false;
  }
  medicion.preferencias = leerPreferencias(ambito);
  const d = await destinosDe(ambito);
  if (medicion.ambito !== ambito) return;
  medicion.destinos = d;
  if (medicion.preferencias !== null && hayDestinos(d)) {
    aplicar(ambito, d, medicion.preferencias);
    enviarVista();
  }
}

/** Lo que eligió en el aviso: se guarda, se aplica y se avisa al servidor. */
export function elegir(eleccion: {
  analitica: boolean;
  publicidad: boolean;
}): void {
  const ambito = medicion.ambito;
  if (ambito === null) return;
  const { preferencias, visitante } = guardarPreferencias(ambito, eleccion);
  medicion.preferencias = preferencias;
  medicion.eligiendo = false;
  moverAtribucionSegunPreferencias(ambito);
  if (medicion.destinos !== null) {
    aplicar(ambito, medicion.destinos, preferencias);
    enviarVista();
  }
  if (visitante !== null) {
    const [tipo, clave] = ambito.split(":") as [string, string];
    const url =
      tipo === "plataforma"
        ? "/api/v1/medicion/consentimiento"
        : `/api/v1/app/${encodeURIComponent(clave)}/medicion/consentimiento`;
    void api.post(url, { visitante, ...eleccion }).catch(() => undefined);
  }
}

/**
 * Un evento del navegador para el ámbito actual, solo con lo autorizado. GA4 recibe
 * los de navegación e intención; Meta, además, los resultados que pasan en la página
 * con el mismo `eventID` que manda el servidor (Meta los deduplica).
 */
export function eventoNavegador(evento: {
  ga4?: { nombre: string; parametros?: Record<string, unknown> };
  meta?: {
    nombre: string;
    personalizado?: boolean;
    datos?: Record<string, unknown>;
    eventId?: string;
  };
}): void {
  const ambito = medicion.ambito;
  if (ambito === null || medicion.pausada) return;
  const p = leerPreferencias(ambito);
  const d = medicion.destinos;
  if (p === null || d === null) return;
  try {
    if (
      evento.ga4 &&
      p.analitica &&
      d.ga4 !== null &&
      cargados.ga4.has(d.ga4)
    ) {
      gtag("event", evento.ga4.nombre, {
        send_to: d.ga4,
        ...(evento.ga4.parametros ?? {}),
      });
    }
    if (
      evento.meta &&
      p.publicidad &&
      d.meta !== null &&
      cargados.meta.has(d.meta)
    ) {
      window.fbq?.(
        evento.meta.personalizado ? "trackSingleCustom" : "trackSingle",
        d.meta,
        evento.meta.nombre,
        evento.meta.datos ?? {},
        ...(evento.meta.eventId ? [{ eventID: evento.meta.eventId }] : []),
      );
    }
  } catch {
    // Medir nunca rompe la página.
  }
}

/**
 * Los eventos internos que también van a GA4 o a Meta (ADR 0143). Solo intención y
 * resultados que pasan en la página: lo confirmado (una solicitud registrada, un pago)
 * lo manda el servidor.
 *
 * - `studio_registration_started` → `inicio_registro` / `InicioRegistro`.
 * - `tenant_created` → `CompleteRegistration` y `StartTrial` en el píxel, con los
 *   identificadores del servidor (GA4 los recibe del servidor).
 * - `student_access_requested` por WhatsApp o teléfono → `contacto` / `Contact`: un
 *   clic de WhatsApp no es un prospecto.
 */
export function reenviarEvento(
  nombre: string,
  props: Record<string, unknown>,
): void {
  switch (nombre) {
    case "studio_registration_started":
      eventoNavegador({
        ga4: { nombre: "inicio_registro" },
        meta: { nombre: "InicioRegistro", personalizado: true },
      });
      break;
    case "tenant_created":
      if (typeof props.evento_negocio_creado === "string") {
        eventoNavegador({
          meta: {
            nombre: "CompleteRegistration",
            eventId: props.evento_negocio_creado,
          },
        });
      }
      if (typeof props.evento_prueba_iniciada === "string") {
        eventoNavegador({
          meta: { nombre: "StartTrial", eventId: props.evento_prueba_iniciada },
        });
      }
      break;
    case "student_access_requested":
      if (props.channel === "whatsapp" || props.channel === "telefono") {
        eventoNavegador({
          ga4: { nombre: "contacto", parametros: { method: props.channel } },
          meta: { nombre: "Contact" },
        });
      }
      break;
  }
}

function cookie(nombre: string): string | null {
  for (const par of document.cookie.split(";")) {
    const [k, ...v] = par.trim().split("=");
    if (k === nombre) return decodeURIComponent(v.join("="));
  }
  return null;
}

function idDeGa4(
  id: string,
  campo: "client_id" | "session_id",
): Promise<string | null> {
  return new Promise((resolve) => {
    const espera = window.setTimeout(() => resolve(null), 500);
    try {
      gtag("get", id, campo, (valor: unknown) => {
        window.clearTimeout(espera);
        resolve(valor === undefined || valor === null ? null : String(valor));
      });
    } catch {
      window.clearTimeout(espera);
      resolve(null);
    }
  });
}

/**
 * Lo que viaja con un registro (el negocio, una solicitud, una cita, una compra): sus
 * preferencias, su id de visitante y los identificadores de su navegador que permitió.
 * Sin una elección, nada.
 */
export async function medicionPara(
  ambito: Ambito,
): Promise<Record<string, unknown> | null> {
  const p = leerPreferencias(ambito);
  if (p === null) return null;
  const datos: Record<string, unknown> = {
    visitante: visitanteDe(ambito),
    analitica: p.analitica,
    publicidad: p.publicidad,
    url: `${window.location.origin}${window.location.pathname}`,
  };
  try {
    const d = await destinosDe(ambito);
    if (p.analitica && d.ga4 !== null) {
      if (cargados.ga4.has(d.ga4)) {
        datos.ga_client_id = await idDeGa4(d.ga4, "client_id");
        datos.ga_session_id = await idDeGa4(d.ga4, "session_id");
      } else {
        // En la cuenta del cliente no se cargan las etiquetas: la cookie que dejó la
        // página del negocio (con su prefijo) dice su client_id.
        const prefijo = prefijoCookies(ambito);
        const ga = cookie(prefijo !== null ? `${prefijo}_ga` : "_ga");
        const partes = ga?.split(".") ?? [];
        if (partes.length >= 4) datos.ga_client_id = partes.slice(-2).join(".");
      }
    }
    if (p.publicidad && ambito.startsWith("plataforma:")) {
      datos.fbp = cookie("_fbp");
      datos.fbc = cookie("_fbc");
    }
  } catch {
    // Sin identificadores: el servidor manda lo que pueda.
  }
  return Object.fromEntries(
    Object.entries(datos).filter(([, v]) => v !== null && v !== undefined),
  );
}

/**
 * Lo que se agrega a un registro (el negocio, una solicitud, una cita, una compra): de
 * dónde llegó (ADR 0142) y sus preferencias con sus identificadores (ADR 0143). Sin
 * nada que mandar, no agrega nada.
 */
export async function camposDeMedicion(
  ambito: Ambito,
): Promise<{
  atribucion?: AtribucionParaEnviar;
  medicion?: Record<string, unknown>;
}> {
  const atribucion = atribucionParaEnviar(ambito);
  const datos = await medicionPara(ambito);
  return {
    ...(atribucion !== null ? { atribucion } : {}),
    ...(datos !== null ? { medicion: datos } : {}),
  };
}

/** Para las pruebas: olvida lo cargado. */
export function reiniciarEtiquetas(): void {
  consultas.clear();
  cargados.ga4.clear();
  cargados.meta.clear();
  navegacion = 0;
  vistaEnviada = -1;
  vista = null;
  Object.assign(medicion, {
    ambito: null,
    destinos: null,
    preferencias: null,
    pausada: true,
    eligiendo: false,
  });
}
