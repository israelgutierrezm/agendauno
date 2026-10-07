/**
 * Tipos de letra de Apariencia (la lista la da el API: `CatalogoFuentes`). Casi todos
 * de Google Fonts con los pesos que usa la app; se carga solo el que hace falta.
 * Poppins, la predeterminada, ya viene en index.html.
 */
export const FUENTE_PREDETERMINADA = "Poppins";

/**
 * Las del equipo: no se descargan. Segoe UI viene con Windows; Century Gothic, si el
 * equipo la tiene (Windows con Office, por ejemplo). Si no, se ve la parecida que
 * haya o la del sistema. El valor es la llave del texto que lo explica en Apariencia.
 */
export const FUENTES_DEL_SISTEMA = new Map<string, string>([
  ["Segoe UI", "apariencia.fuenteWindows"],
  ["Century Gothic", "apariencia.fuenteEquipo"],
]);

/** Parecidas, por si el equipo no tiene la elegida. */
const PARECIDAS: Record<string, string> = {
  "Century Gothic": '"URW Gothic", "Avant Garde", Futura, ',
};

/** Agrega la hoja de Google Fonts de esa familia (una sola vez). */
export function cargarFuente(familia: string): void {
  if (
    familia === FUENTE_PREDETERMINADA ||
    FUENTES_DEL_SISTEMA.has(familia) ||
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
  return `"${familia}", ${PARECIDAS[familia] ?? ""}ui-sans-serif, system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif`;
}
