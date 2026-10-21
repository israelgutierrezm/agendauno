/**
 * Widgets para la web del negocio (ADR 0141): su calendario de clases, sus servicios
 * para agendar (citas) o sus planes, dentro de su propio sitio, con un iframe que avisa
 * su altura (como el formulario de captación embebido, ADR 0117).
 */
import { productoDelHost } from "@/lib/producto";
import { urlCanonicaEstudio } from "@/lib/tenant";

export type TipoWidget = "calendario" | "agendar" | "planes";

/** Los widgets de cada modalidad: clases (calendario) o citas (agendar), y planes. */
export function tiposDeWidget(esCitas: boolean): TipoWidget[] {
  return esCitas ? ["agendar", "planes"] : ["calendario", "planes"];
}
export type TemaWidget = "claro" | "oscuro" | "auto";

export interface Widget {
  id: string;
  tipo: TipoWidget;
  nombre: string;
  filtros: { sucursal: string | null; actividad: string | null };
  tema: TemaWidget;
  acento: string | null;
  dominios: string[];
  activo: boolean;
}

/** Lo que trae la configuración pública del widget. */
export interface WidgetPublico extends Widget {
  negocio: string;
  modalidad: "clases" | "citas";
}

/** Ruta del widget dentro de la aplicación. */
export function rutaWidget(slug: string, id: string): string {
  return `/w/${encodeURIComponent(slug)}/${encodeURIComponent(id)}`;
}

/** Dirección del widget: en el dominio del negocio en producción; en desarrollo, aquí. */
export function urlWidget(
  slug: string,
  id: string,
  host: string = window.location.hostname,
  origen: string = window.location.origin,
): string {
  const ruta = rutaWidget(slug, id);
  return productoDelHost(host) === null
    ? `${origen}${ruta}`
    : urlCanonicaEstudio(slug, ruta);
}

/**
 * El dominio de la web que muestra el widget: el de quien lo inserta (si el navegador
 * lo dice) o null si se abrió directo.
 */
export function hostDeQuienInserta(
  ventana: Window = window,
  documento: Document = document,
): string | null {
  if (ventana.parent === ventana) {
    return null;
  }
  const ancestros = ventana.location.ancestorOrigins;
  const origen =
    ancestros && ancestros.length > 0 ? ancestros[0] : documento.referrer;
  if (!origen) {
    return null;
  }
  try {
    return new URL(origen).hostname.toLowerCase();
  } catch {
    return null;
  }
}

/**
 * ¿Se puede mostrar en esa web? Sin webs registradas, en cualquiera; con webs, solo en
 * ellas o sus subdominios, y siempre en la propia plataforma (la vista previa). No es
 * seguridad (lo que muestra ya es público): evita que aparezca donde el negocio no lo
 * puso.
 */
export function permitidoEn(
  host: string | null,
  dominios: string[],
  propio: string = window.location.hostname,
): boolean {
  if (host === null || dominios.length === 0 || host === propio) {
    return true;
  }
  return dominios.some((d) => host === d || host.endsWith(`.${d}`));
}

/** Texto legible sobre el color de acento: oscuro sobre colores claros, blanco si no. */
export function contrasteDe(hex: string): string {
  const n = hex.replace("#", "");
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(n.slice(i, i + 2), 16) / 255);
  const lineal = (c: number) =>
    c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  const luminancia =
    0.2126 * lineal(r!) + 0.7152 * lineal(g!) + 0.0722 * lineal(b!);
  return luminancia > 0.4 ? "#111111" : "#ffffff";
}
