import type { Router } from "vue-router";

import { reportarError } from "@/lib/errores";

/**
 * Recuperación tras publicar una versión nueva. Cada pantalla se carga aparte
 * (`() => import(...)`) y sus archivos llevan hash; la imagen nueva ya no trae los de
 * la anterior, así que una pestaña abierta desde antes recibe 404 al entrar a una
 * pantalla que no había cargado. Entonces se recarga la página UNA vez, directo en
 * la ruta a la que iba, y se toma la versión nueva.
 *
 * Una marca en sessionStorage evita el ciclo: si en el último minuto ya se recargó
 * por esto y vuelve a fallar, no se recarga otra vez; se avisa.
 */
const CLAVE = "tu.recarga-por-version";
const VENTANA_MS = 60_000;

// Lo que dice cada navegador cuando no puede traer un módulo dinámico.
const PATRONES = [
  /Failed to fetch dynamically imported module/i, // Chrome, Edge
  /error loading dynamically imported module/i, // Firefox
  /Importing a module script failed/i, // Safari
  /Unable to preload CSS/i, // Vite (la hoja de estilos de la pantalla)
];

export function esErrorDeCargaDeModulo(error: unknown): boolean {
  const mensaje =
    error instanceof Error
      ? error.message
      : typeof error === "string"
        ? error
        : "";
  return PATRONES.some((patron) => patron.test(mensaje));
}

function leerMarca(): number | null {
  try {
    const valor = Number(sessionStorage.getItem(CLAVE));
    return Number.isFinite(valor) && valor > 0 ? valor : null;
  } catch {
    return null;
  }
}

function guardarMarca(momento: number): boolean {
  try {
    sessionStorage.setItem(CLAVE, String(momento));
    return true;
  } catch {
    return false;
  }
}

function borrarMarca(): void {
  try {
    sessionStorage.removeItem(CLAVE);
  } catch {
    // Sin sessionStorage no hay marca que borrar.
  }
}

let recargando = false;

type Ir = (destino: string) => void;
const irA: Ir = (destino) => window.location.assign(destino);

/** Solo para las pruebas: empieza de cero. */
export function reiniciarRecargaPorVersion(): void {
  recargando = false;
  borrarMarca();
}

/**
 * Recarga la página en `destino` si no se hizo ya en el último minuto. ¿Recargó (o
 * ya iba a recargar)? Sin sessionStorage no se recarga: no habría cómo cortar el ciclo.
 */
export function recargarUnaVez(
  destino: string,
  ir: Ir = irA,
  ahora = Date.now(),
): boolean {
  if (recargando) {
    return true;
  }
  const previa = leerMarca();
  if (previa !== null && ahora - previa < VENTANA_MS) {
    return false;
  }
  if (!guardarMarca(ahora)) {
    return false;
  }
  recargando = true;
  ir(destino);
  return true;
}

/**
 * Escucha los fallos de carga de pantallas (los del router y `vite:preloadError`) y
 * recarga una vez en la ruta destino. `avisar` se llama si no se pudo (ya se había
 * recargado): la persona debe recargar a mano. Devuelve cómo dejar de escuchar el
 * aviso de Vite (para las pruebas).
 */
export function instalarRecargaPorVersion(
  router: Router,
  avisar: () => void,
  ir: Ir = irA,
): () => void {
  let avisado = false;
  const avisarUnaVez = (): void => {
    if (!avisado) {
      avisado = true;
      avisar();
    }
  };
  // El router atiende el fallo de la pantalla a la que se iba; el aviso de Vite
  // solo actúa si ninguna navegación lo atendió en ese momento.
  let atendidoPorRouter = 0;

  router.onError((error, to) => {
    if (!esErrorDeCargaDeModulo(error)) {
      // Sin este manejador el router solo lo escribía en la consola: que llegue al
      // monitoreo (RouterLink se traga el rechazo de la navegación).
      reportarError(error);
      console.error(error);
      return;
    }
    atendidoPorRouter = Date.now();
    if (!recargarUnaVez(to.fullPath, ir)) {
      reportarError(error);
      avisarUnaVez();
    }
  });

  // Ya se navegó bien con la versión nueva: una publicación futura puede recargar.
  router.afterEach((_to, _from, falla) => {
    if (!falla) {
      borrarMarca();
    }
  });

  const alFallarPrecarga = (): void => {
    // El rechazo llega al router en esta misma vuelta (microtareas); el temporizador
    // corre después y solo recarga si nadie lo atendió.
    window.setTimeout(() => {
      if (Date.now() - atendidoPorRouter < 1_000) {
        return;
      }
      if (!recargarUnaVez(window.location.href, ir)) {
        avisarUnaVez();
      }
    }, 0);
  };
  window.addEventListener("vite:preloadError", alFallarPrecarga);
  return () =>
    window.removeEventListener("vite:preloadError", alFallarPrecarga);
}
