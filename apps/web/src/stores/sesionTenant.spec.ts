import { AxiosError, type InternalAxiosRequestConfig } from "axios";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useSesionTenantStore } from "./sesionTenant";

/*
| Al abrir o recargar, la sesión guardada se confirma con /yo. Solo se borra cuando
| el servidor dice que ya no sirve (401; 404: el negocio ya no existe). Sin red, en
| mantenimiento (503 al publicar), con 429 o 5xx el token se conserva, se deja
| seguir y se puede reintentar.
*/

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", async (importOriginal) => {
  const real = await importOriginal<typeof import("@/lib/api")>();
  return { ...real, api: { get: mocks.get, post: mocks.post } };
});
vi.mock("@/stores/apariencia", () => ({
  useAparienciaStore: () => ({ activar: vi.fn(), desactivar: vi.fn() }),
}));

const BEARER = "tu.tenant.bearer";

function respuestaConEstado(status: number): AxiosError {
  const config = { headers: {} } as InternalAxiosRequestConfig;
  return new AxiosError("Falla", "ERR_BAD_RESPONSE", config, undefined, {
    status,
    statusText: "",
    headers: {},
    config,
    data: {},
  });
}
const sinRed = (): AxiosError => new AxiosError("Network Error", "ERR_NETWORK");
const tiempoAgotado = (): AxiosError =>
  new AxiosError("timeout of 30000ms exceeded", "ECONNABORTED");

const yo = {
  data: {
    data: {
      usuario: { ulid: "u1", nombre: "Ana", email: "ana@x.test", rol: "admin" },
      estudio: { slug: "demo", nombre: "Demo", estado: "active" },
    },
  },
};

function conSesionGuardada(): ReturnType<typeof useSesionTenantStore> {
  localStorage.setItem(BEARER, "1|secreto");
  localStorage.setItem("tu.tenant.slug", "demo");
  return useSesionTenantStore();
}

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
  setActivePinia(createPinia());
});

describe("confirmar la sesión guardada al abrir", () => {
  it.each([
    ["sin red", sinRed, "sin-conexion"],
    ["con el tiempo agotado", tiempoAgotado, "sin-conexion"],
    ["en mantenimiento (503)", () => respuestaConEstado(503), "mantenimiento"],
    [
      "con demasiadas peticiones (429)",
      () => respuestaConEstado(429),
      "servidor",
    ],
    [
      "con un error del servidor (500)",
      () => respuestaConEstado(500),
      "servidor",
    ],
    ["con un 502 del proxy", () => respuestaConEstado(502), "servidor"],
  ])("%s conserva el token y avisa", async (_caso, error, motivo) => {
    const sesion = conSesionGuardada();
    mocks.get.mockRejectedValueOnce(error());

    await sesion.verificarSesion();

    expect(localStorage.getItem(BEARER)).toBe("1|secreto");
    expect(sesion.bearer).toBe("1|secreto");
    expect(sesion.autenticado).toBe(false);
    expect(sesion.validando).toBe(false);
    expect(sesion.sinConfirmar).toBe(motivo);
    expect(sesion.avisoSinConfirmar).toContain("sigue guardada");
  });

  it.each([401, 404])(
    "con %s la sesión ya no sirve y se borra",
    async (estado) => {
      const sesion = conSesionGuardada();
      mocks.get.mockRejectedValueOnce(respuestaConEstado(estado));

      await sesion.verificarSesion();

      expect(localStorage.getItem(BEARER)).toBeNull();
      expect(sesion.bearer).toBeNull();
      expect(sesion.sinConfirmar).toBeNull();
    },
  );

  it("el mantenimiento lo dice como tal", async () => {
    const sesion = conSesionGuardada();
    mocks.get.mockRejectedValueOnce(respuestaConEstado(503));

    await sesion.verificarSesion();

    expect(sesion.avisoSinConfirmar).toContain("actualizando");
  });

  it("al reintentar con la API de vuelta, la sesión queda confirmada", async () => {
    const sesion = conSesionGuardada();
    mocks.get.mockRejectedValueOnce(respuestaConEstado(503));
    await sesion.verificarSesion();

    mocks.get.mockResolvedValueOnce(yo);
    const confirmada = await sesion.reintentarSesion();

    expect(confirmada).toBe(true);
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/yo");
    expect(sesion.autenticado).toBe(true);
    expect(sesion.sinConfirmar).toBeNull();
  });

  it("si al reintentar sigue sin red, conserva el token", async () => {
    const sesion = conSesionGuardada();
    mocks.get.mockRejectedValueOnce(sinRed());
    await sesion.verificarSesion();

    mocks.get.mockRejectedValueOnce(sinRed());
    const confirmada = await sesion.reintentarSesion();

    expect(confirmada).toBe(false);
    expect(localStorage.getItem(BEARER)).toBe("1|secreto");
    expect(sesion.sinConfirmar).toBe("sin-conexion");
  });

  it("recuerda la dirección que se abrió para volver a ella", async () => {
    window.history.replaceState(null, "", "/recepcion?vista=hoy");
    const sesion = conSesionGuardada();
    mocks.get.mockRejectedValueOnce(sinRed());

    await sesion.verificarSesion();

    expect(sesion.volverTrasConfirmar).toBe("/recepcion?vista=hoy");
    window.history.replaceState(null, "", "/");
  });

  it("olvidar la sesión (401 en plena sesión) borra el token sin llamar al servidor", async () => {
    const sesion = conSesionGuardada();
    mocks.get.mockResolvedValueOnce(yo);
    await sesion.verificarSesion();

    sesion.olvidarSesion();

    expect(localStorage.getItem(BEARER)).toBeNull();
    expect(sesion.autenticado).toBe(false);
    expect(mocks.post).not.toHaveBeenCalled();
  });
});
