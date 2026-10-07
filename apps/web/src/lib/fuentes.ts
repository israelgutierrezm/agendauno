/**
 * Tipos de letra de Apariencia (la lista la da el API: `CatalogoFuentes`). Tres del
 * equipo, que no se descargan: Segoe UI (la predeterminada), la del sistema operativo
 * y Century Gothic; y tres de Google Fonts con los pesos que usa la app, que se cargan
 * solo si hacen falta. Poppins ya viene en index.html (la usa la página comercial).
 */
export const FUENTE_PREDETERMINADA = "Segoe UI";

/** La del sistema operativo (San Francisco en Apple, Segoe UI en Windows, Roboto…). */
export const FUENTE_SISTEMA = "Sistema";

/** Del equipo: no se descargan. */
const DEL_EQUIPO = new Set([
  FUENTE_PREDETERMINADA,
  FUENTE_SISTEMA,
  "Century Gothic",
]);

/** Ya vienen en la página. */
const YA_CARGADAS = new Set(["Poppins"]);

/** Parecidas, por si el equipo no tiene la elegida. */
const PARECIDAS: Record<string, string> = {
  "Century Gothic": '"URW Gothic", "Avant Garde", Futura, ',
};

const DEL_SISTEMA =
  'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

/** Agrega la hoja de Google Fonts de esa familia (una sola vez). */
export function cargarFuente(familia: string): void {
  if (
    DEL_EQUIPO.has(familia) ||
    YA_CARGADAS.has(familia) ||
    typeof document === "undefined"
  ) {
    return;
  }
  const id = `fuente-${familia.toLowerCase().replaceAll(" ", "-")}`;
  if (document.getElementById(id) !== null) {
    return;
  }
  const enlace = document.createElement("link");
  enlace.id = id;
  enlace.rel = "stylesheet";
  enlace.href = `https://fonts.googleapis.com/css2?family=${familia.replaceAll(" ", "+")}:wght@300;400;500;600;700&display=swap`;
  document.head.appendChild(enlace);
}

/** El valor de `font-family` con esa familia primero y las del sistema de respaldo. */
export function pilaDeFuente(familia: string): string {
  if (familia === FUENTE_SISTEMA) {
    return DEL_SISTEMA;
  }
  return `"${familia}", ${PARECIDAS[familia] ?? ""}ui-sans-serif, ${DEL_SISTEMA}`;
}

const instaladas = new Map<string, boolean>();

/**
 * ¿Se puede ofrecer en este equipo? Las de Google Fonts y la del sistema, siempre;
 * una del equipo (Century Gothic), solo si está instalada: se mide un texto con ella
 * y con las genéricas, y si mide igual con todas es que no está. Sin forma de medir,
 * se ofrece.
 */
export function fuenteDisponible(familia: string): boolean {
  if (
    !DEL_EQUIPO.has(familia) ||
    familia === FUENTE_SISTEMA ||
    familia === FUENTE_PREDETERMINADA
  ) {
    return true;
  }
  const guardada = instaladas.get(familia);
  if (guardada !== undefined) {
    return guardada;
  }
  if (typeof OffscreenCanvas === "undefined") {
    return true;
  }
  const lienzo = new OffscreenCanvas(1, 1).getContext("2d");
  if (lienzo === null) {
    return true;
  }
  const muestra = "mmmmmmmmmmlli1WQ@#";
  const instalada = ["monospace", "serif", "sans-serif"].some((generica) => {
    lienzo.font = `72px ${generica}`;
    const sinElla = lienzo.measureText(muestra).width;
    lienzo.font = `72px "${familia}", ${generica}`;
    return lienzo.measureText(muestra).width !== sinElla;
  });
  instaladas.set(familia, instalada);
  return instalada;
}
