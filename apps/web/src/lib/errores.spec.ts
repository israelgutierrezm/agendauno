import { AxiosError } from "axios";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  describirError,
  reiniciarReporteDeErrores,
  reportarError,
} from "./errores";

const fetchFalso = vi.fn(() => Promise.resolve(new Response(null)));

beforeEach(() => {
  reiniciarReporteDeErrores();
  fetchFalso.mockClear();
  vi.stubGlobal("fetch", fetchFalso);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

function enviado(n = 0): Record<string, unknown> {
  const llamada = fetchFalso.mock.calls[n] as unknown as [string, RequestInit];
  return JSON.parse(String(llamada[1].body)) as Record<string, unknown>;
}

describe("reporte de errores de la web", () => {
  it("manda el error con su lugar en nuestro código, sin el dominio", () => {
    const error = new TypeError("No se pudo leer «total»");
    error.stack = `TypeError: No se pudo leer «total»\n    at f (${window.location.origin}/assets/index-abc.js:1:234)`;

    reportarError(error);

    expect(fetchFalso).toHaveBeenCalledTimes(1);
    const [url, opciones] = fetchFalso.mock.calls[0] as unknown as [
      string,
      RequestInit,
    ];
    expect(url).toMatch(/\/api\/v1\/errores$/);
    expect(opciones.keepalive).toBe(true);
    expect(enviado()).toMatchObject({
      origen: "web",
      tipo: "TypeError",
      mensaje: "No se pudo leer «total»",
      lugar: "assets/index-abc.js:1:234",
    });
  });

  it("no manda errores de la API, de otros orígenes ni el mismo dos veces", () => {
    reportarError(new AxiosError("Request failed with status code 500"));
    reportarError(new Error("De una extensión"), "chrome-extension://abc/x.js");
    reportarError(new Error("ResizeObserver loop limit exceeded"));
    expect(fetchFalso).not.toHaveBeenCalled();

    reportarError(new Error("Uno"));
    reportarError(new Error("Uno"));
    expect(fetchFalso).toHaveBeenCalledTimes(1);
  });

  it("manda como mucho 10 por página", () => {
    for (let n = 0; n < 15; n++) {
      reportarError(new Error(`Error ${n}`));
    }
    expect(fetchFalso).toHaveBeenCalledTimes(10);
  });

  it("describe un texto y descarta lo que no es un error", () => {
    expect(describirError("Algo falló")).toEqual({
      tipo: "Error",
      mensaje: "Algo falló",
    });
    expect(describirError(undefined)).toBeNull();
  });
});
