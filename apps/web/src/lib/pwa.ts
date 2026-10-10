import { reactive } from "vue";

import { productoActual } from "@/lib/producto";
import { enSubdominioDeEstudio } from "@/lib/tenant";

/**
 * La app instalable (PWA) de cada negocio (ADR 0110). Solo en el subdominio del
 * negocio ({slug}.agendauno.mx, {slug}.turnouno.mx): ahí se enlaza su manifiesto (lo
 * sirve la API con su nombre, logo y color), se registra el service worker (`/sw.js`,
 * que nunca guarda la API) y se ofrece instalarla. Cada negocio es un origen propio:
 * su app abre directo en él, sin elegir el negocio.
 */

interface AvisoInstalacion extends Event {
  prompt(): Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
}

export const instalacion = reactive({
  /** El navegador ofrece instalarla (Android, Chrome y Edge de escritorio). */
  disponible: false,
  /** Ya se abre instalada (pantalla completa, sin barra del navegador). */
  instalada: false,
});

let aviso: AvisoInstalacion | null = null;
let instalado = false;

function enHead(
  etiqueta: "link" | "meta",
  atributos: Record<string, string>,
): HTMLElement {
  const clave = etiqueta === "link" ? "rel" : "name";
  let elemento = document.head.querySelector<HTMLElement>(
    `${etiqueta}[${clave}="${atributos[clave]}"]`,
  );
  if (elemento === null) {
    elemento = document.createElement(etiqueta);
    document.head.appendChild(elemento);
  }
  for (const [nombre, valor] of Object.entries(atributos)) {
    elemento.setAttribute(nombre, valor);
  }
  return elemento;
}

/** ¿Se abrió como app instalada? */
export function abiertaComoApp(): boolean {
  return (
    window.matchMedia?.("(display-mode: standalone)").matches === true ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  );
}

/** iPhone o iPad (Safari no ofrece instalar: se explica cómo). */
export function esIos(): boolean {
  return (
    /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1)
  );
}

/** Una vez al arrancar la web. Fuera del subdominio de un negocio, no hace nada. */
export function instalarPwa(): void {
  if (instalado || typeof window === "undefined" || !enSubdominioDeEstudio()) {
    return;
  }
  instalado = true;
  enHead("link", { rel: "manifest", href: "/api/v1/pwa/manifest.webmanifest" });
  enHead("meta", { name: "mobile-web-app-capable", content: "yes" });
  enHead("meta", { name: "apple-mobile-web-app-capable", content: "yes" });
  enHead("meta", {
    name: "apple-mobile-web-app-status-bar-style",
    content: "default",
  });
  instalacion.instalada = abiertaComoApp();

  window.addEventListener("beforeinstallprompt", (evento) => {
    evento.preventDefault();
    aviso = evento as AvisoInstalacion;
    instalacion.disponible = true;
  });
  window.addEventListener("appinstalled", () => {
    instalacion.instalada = true;
    instalacion.disponible = false;
    aviso = null;
  });

  // En desarrollo no: el service worker se queda con versiones viejas de los archivos.
  if (import.meta.env.PROD && "serviceWorker" in navigator) {
    void navigator.serviceWorker.register("/sw.js").catch(() => undefined);
  }
}

/** Muestra el aviso de instalar del navegador; true si la persona aceptó. */
export async function pedirInstalacion(): Promise<boolean> {
  if (aviso === null) {
    return false;
  }
  const pendiente = aviso;
  aviso = null;
  instalacion.disponible = false;
  await pendiente.prompt();
  return (await pendiente.userChoice).outcome === "accepted";
}

/**
 * Con los datos del negocio: el nombre bajo el ícono en iOS, su logo como ícono de
 * inicio y el color de la barra. Solo en su subdominio.
 */
export function marcarPwa(negocio: {
  nombre: string;
  logo_url?: string | null;
  color_marca?: string | null;
}): void {
  if (!instalado) {
    return;
  }
  enHead("meta", {
    name: "apple-mobile-web-app-title",
    content: negocio.nombre,
  });
  // Su logo desde su propio origen (la API sirve /storage en cada dominio); sin logo,
  // el ícono de su producto (no el de la plataforma).
  enHead("link", {
    rel: "apple-touch-icon",
    href: negocio.logo_url
      ? new URL(negocio.logo_url, window.location.href).pathname
      : `/assets/pwa/${productoActual()}-192.png`,
  });
  if (negocio.color_marca) {
    enHead("meta", { name: "theme-color", content: negocio.color_marca });
  }
}
