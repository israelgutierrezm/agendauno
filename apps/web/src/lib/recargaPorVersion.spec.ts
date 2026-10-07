import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Router } from "vue-router";

import {
  esErrorDeCargaDeModulo,
  instalarRecargaPorVersion,
  recargarUnaVez,
  reiniciarRecargaPorVersion,
} from "./recargaPorVersion";

/*
| Tras publicar, una pestaña abierta desde antes pide pantallas cuyos archivos ya no
| existen (404). Se recarga UNA vez, en la ruta a la que iba; si vuelve a fallar en
| el mismo minuto, se avisa en lugar de recargar en ciclo.
*/

vi.mock("./errores", () => ({ reportarError: vi.fn() }));

type ManejadorError = (error: unknown, to: { fullPath: string }) => void;
type ManejadorDespues = (to: unknown, from: unknown, falla?: unknown) => void;

function routerFalso() {
  const manejadores: { error?: ManejadorError; despues?: ManejadorDespues } =
    {};
  const router = {
    onError: (fn: ManejadorError) => {
      manejadores.error = fn;
    },
    afterEach: (fn: ManejadorDespues) => {
      manejadores.despues = fn;
    },
  } as unknown as Router;
  return { router, manejadores };
}

const chunkPerdido = (): Error =>
  new TypeError(
    "Failed to fetch dynamically imported module: https://agendauno.mx/assets/AgendaView-abc123.js",
  );

const instalaciones: (() => void)[] = [];
function instalar(
  router: Router,
  avisar: () => void,
  ir: (destino: string) => void,
): void {
  instalaciones.push(instalarRecargaPorVersion(router, avisar, ir));
}

beforeEach(() => {
  reiniciarRecargaPorVersion();
  sessionStorage.clear();
});
afterEach(() => {
  instalaciones.splice(0).forEach((quitar) => quitar());
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("reconoce el fallo de carga de una pantalla", () => {
  it.each([
    "Failed to fetch dynamically imported module: /assets/x.js",
    "error loading dynamically imported module: /assets/x.js",
    "Importing a module script failed.",
    "Unable to preload CSS for /assets/x.css",
  ])("%s", (mensaje) => {
    expect(esErrorDeCargaDeModulo(new TypeError(mensaje))).toBe(true);
  });

  it("los demás errores no", () => {
    expect(esErrorDeCargaDeModulo(new Error("No se pudo leer «total»"))).toBe(
      false,
    );
    expect(esErrorDeCargaDeModulo(undefined)).toBe(false);
  });
});

describe("recargar una sola vez", () => {
  it("recarga y no vuelve a recargar en el mismo minuto", () => {
    const ir = vi.fn();
    expect(recargarUnaVez("/agenda", ir, 1_000)).toBe(true);
    expect(ir).toHaveBeenCalledWith("/agenda");

    // La página nueva (otra carga del módulo) vuelve a fallar enseguida.
    reiniciarRecargaPorVersion();
    sessionStorage.setItem("tu.recarga-por-version", "1000");
    expect(recargarUnaVez("/agenda", ir, 30_000)).toBe(false);
    expect(ir).toHaveBeenCalledTimes(1);

    // Pasado el minuto, una publicación nueva puede volver a recargar.
    expect(recargarUnaVez("/agenda", ir, 62_000)).toBe(true);
    expect(ir).toHaveBeenCalledTimes(2);
  });

  it("sin sessionStorage no recarga: no habría cómo cortar el ciclo", () => {
    vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
      throw new Error("bloqueado");
    });
    const ir = vi.fn();
    expect(recargarUnaVez("/agenda", ir)).toBe(false);
    expect(ir).not.toHaveBeenCalled();
  });
});

describe("al navegar con la versión vieja", () => {
  it("recarga directo en la ruta a la que iba", () => {
    const { router, manejadores } = routerFalso();
    const ir = vi.fn();
    const avisar = vi.fn();
    instalar(router, avisar, ir);

    manejadores.error?.(chunkPerdido(), { fullPath: "/agenda?dia=2026-10-06" });

    expect(ir).toHaveBeenCalledWith("/agenda?dia=2026-10-06");
    expect(avisar).not.toHaveBeenCalled();
  });

  it("si ya recargó y vuelve a fallar, avisa una vez y no recarga en ciclo", () => {
    sessionStorage.setItem("tu.recarga-por-version", String(Date.now()));
    const { router, manejadores } = routerFalso();
    const ir = vi.fn();
    const avisar = vi.fn();
    instalar(router, avisar, ir);

    manejadores.error?.(chunkPerdido(), { fullPath: "/agenda" });
    manejadores.error?.(chunkPerdido(), { fullPath: "/ventas" });

    expect(ir).not.toHaveBeenCalled();
    expect(avisar).toHaveBeenCalledTimes(1);
  });

  it("una navegación que sí termina borra la marca", () => {
    sessionStorage.setItem("tu.recarga-por-version", String(Date.now()));
    const { router, manejadores } = routerFalso();
    instalar(router, vi.fn(), vi.fn());

    manejadores.despues?.({}, {}, { type: 4 });
    expect(sessionStorage.getItem("tu.recarga-por-version")).not.toBeNull();
    manejadores.despues?.({}, {}, undefined);
    expect(sessionStorage.getItem("tu.recarga-por-version")).toBeNull();
  });

  it("otros errores de navegación no recargan", () => {
    vi.spyOn(console, "error").mockImplementation(() => undefined);
    const { router, manejadores } = routerFalso();
    const ir = vi.fn();
    instalar(router, vi.fn(), ir);

    manejadores.error?.(new Error("falló un guard"), { fullPath: "/agenda" });

    expect(ir).not.toHaveBeenCalled();
  });

  it("el aviso de Vite que ya atendió el router no recarga otra vez", () => {
    vi.useFakeTimers();
    const { router, manejadores } = routerFalso();
    const ir = vi.fn();
    instalar(router, vi.fn(), ir);

    window.dispatchEvent(new Event("vite:preloadError"));
    manejadores.error?.(chunkPerdido(), { fullPath: "/agenda" });
    vi.runAllTimers();

    expect(ir).toHaveBeenCalledTimes(1);
    expect(ir).toHaveBeenCalledWith("/agenda");
  });

  it("un fallo de carga fuera del router recarga la página actual", () => {
    vi.useFakeTimers();
    const { router } = routerFalso();
    const ir = vi.fn();
    instalar(router, vi.fn(), ir);

    window.dispatchEvent(new Event("vite:preloadError"));
    vi.runAllTimers();

    expect(ir).toHaveBeenCalledWith(window.location.href);
  });
});
