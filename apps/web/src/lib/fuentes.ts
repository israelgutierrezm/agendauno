/**
 * Tipos de letra de Apariencia (la lista la da el API: `CatalogoFuentes`). Casi todos
 * de Google Fonts con los pesos que usa la app; se carga solo el que hace falta.
 * Poppins, la predeterminada, ya viene en index.html.
 */
export const FUENTE_PREDETERMINADA = "Poppins";

/**
 * Las del sistema: no se descargan (Segoe UI viene con Windows; en otros equipos se
 * ve la letra del sistema).
 */
export const FUENTES_DEL_SISTEMA = new Set(["Segoe UI"]);

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
  return `"${familia}", ui-sans-serif, system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif`;
}
