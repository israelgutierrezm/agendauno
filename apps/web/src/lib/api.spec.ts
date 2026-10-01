import type { AxiosAdapter, InternalAxiosRequestConfig } from "axios";
import { afterEach, describe, expect, it } from "vitest";

import { api, esDelNegocio, fijarBearer } from "./api";

/*
| El token de la sesión es de UN negocio: solo viaja a sus rutas. Nunca a las de otro
| negocio (sus páginas públicas) ni a la plataforma, que manda su propia credencial.
*/

async function cabecera(
  url: string,
  headers: Record<string, string> = {},
): Promise<string | undefined> {
  let vista: InternalAxiosRequestConfig | undefined;
  const capturar: AxiosAdapter = async (config) => {
    vista = config;
    return { data: {}, status: 200, statusText: "OK", headers: {}, config };
  };
  await api.get(url, { adapter: capturar, headers });
  const valor = vista?.headers.get("Authorization");
  return typeof valor === "string" ? valor : undefined;
}

afterEach(() => fijarBearer(null));

describe("token de la sesión", () => {
  it("va a las rutas de su negocio", async () => {
    fijarBearer("1|secreto", "demo");
    expect(await cabecera("/api/v1/app/demo/yo")).toBe("Bearer 1|secreto");
    expect(await cabecera("/api/v1/app/demo?x=1")).toBe("Bearer 1|secreto");
  });

  it("no va a otro negocio, aunque su nombre empiece igual", async () => {
    fijarBearer("1|secreto", "demo");
    expect(await cabecera("/api/v1/app/barberia/citas/opciones")).toBe(
      undefined,
    );
    expect(await cabecera("/api/v1/app/demo-dos/yo")).toBe(undefined);
    expect(await cabecera("/api/v1/registro")).toBe(undefined);
  });

  it("no pisa la credencial que la petición ya trae (la de la plataforma)", async () => {
    fijarBearer("1|secreto", "demo");
    expect(
      await cabecera("/api/v1/plataforma/estudios", {
        Authorization: "Bearer plataforma",
      }),
    ).toBe("Bearer plataforma");
    expect(
      await cabecera("/api/v1/app/demo/yo", {
        Authorization: "Bearer plataforma",
      }),
    ).toBe("Bearer plataforma");
  });

  it("sin negocio no se manda", async () => {
    fijarBearer("1|secreto");
    expect(await cabecera("/api/v1/app/demo/yo")).toBe(undefined);
  });

  it("reconoce la ruta del negocio también con la URL completa", () => {
    expect(
      esDelNegocio("http://localhost:8000/api/v1/app/demo/yo", "demo"),
    ).toBe(true);
    expect(esDelNegocio("/api/v1/app/demos/yo", "demo")).toBe(false);
  });
});
