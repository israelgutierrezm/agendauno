import {
  AxiosError,
  type AxiosAdapter,
  type InternalAxiosRequestConfig,
} from "axios";
import { afterEach, describe, expect, it, vi } from "vitest";

import {
  alPerderSesion,
  api,
  esDelNegocio,
  fallaPasajera,
  fijarBearer,
  mensajeDeError,
  TIEMPO_LIMITE_MS,
} from "./api";
import { fijarTerminosActuales } from "./terminologia";

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

afterEach(() => {
  fijarBearer(null);
  alPerderSesion(null);
});

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

/*
| Un 401 con el token de la sesión: lo revocaron (baja, cambio de contraseña, cerró
| sesión en otro lado). Se avisa para limpiarla y llevar a Entrar; las demás
| respuestas, o las de peticiones que no usaron la sesión, no.
*/
function responde(status: number): AxiosAdapter {
  return async (config) => {
    const respuesta = {
      data: { code: "UNAUTHENTICATED", message: "No autenticado." },
      status,
      statusText: "",
      headers: {},
      config,
    };
    if (status >= 400) {
      throw new AxiosError(
        `Request failed with status code ${status}`,
        "ERR_BAD_REQUEST",
        config,
        null,
        respuesta,
      );
    }
    return respuesta;
  };
}

async function pedir(
  url: string,
  status: number,
  headers: Record<string, string> = {},
): Promise<void> {
  await api.get(url, { adapter: responde(status), headers }).catch(() => {
    // El error sigue su curso: lo atiende quien hizo la petición.
  });
}

describe("sesión revocada en plena sesión (401)", () => {
  it("un 401 de una ruta del negocio con su token avisa una vez", async () => {
    const perdida = vi.fn();
    alPerderSesion(perdida);
    fijarBearer("1|secreto", "demo");

    await pedir("/api/v1/app/demo/agenda", 401);

    expect(perdida).toHaveBeenCalledTimes(1);
  });

  it("el error sigue llegando a quien hizo la petición", async () => {
    alPerderSesion(vi.fn());
    fijarBearer("1|secreto", "demo");

    await expect(
      api.get("/api/v1/app/demo/yo", { adapter: responde(401) }),
    ).rejects.toBeInstanceOf(AxiosError);
  });

  it("no avisa con otros errores ni sin sesión", async () => {
    const perdida = vi.fn();
    alPerderSesion(perdida);

    await pedir("/api/v1/app/demo/agenda", 401);
    fijarBearer("1|secreto", "demo");
    await pedir("/api/v1/app/demo/agenda", 403);
    await pedir("/api/v1/app/demo/agenda", 422);
    await pedir("/api/v1/app/demo/agenda", 503);

    expect(perdida).not.toHaveBeenCalled();
  });

  it("no avisa por entrar, salir o activar la cuenta", async () => {
    const perdida = vi.fn();
    alPerderSesion(perdida);
    fijarBearer("1|secreto", "demo");

    for (const ruta of [
      "login",
      "logout",
      "auth/google",
      "activar",
      "restablecer-contrasena",
    ]) {
      await pedir(`/api/v1/app/demo/${ruta}`, 401);
    }

    expect(perdida).not.toHaveBeenCalled();
  });

  it("no avisa por otro negocio, la plataforma ni un token anterior", async () => {
    const perdida = vi.fn();
    alPerderSesion(perdida);
    fijarBearer("1|secreto", "demo");

    await pedir("/api/v1/app/barberia/yo", 401);
    await pedir("/api/v1/plataforma/estudios", 401, {
      Authorization: "Bearer plataforma",
    });
    // Se volvió a entrar mientras la petición viajaba con el token anterior.
    await pedir("/api/v1/app/demo/yo", 401, {
      Authorization: "Bearer 1|viejo",
    });

    expect(perdida).not.toHaveBeenCalled();
  });
});

describe("tiempo límite de las peticiones", () => {
  async function tiempoDe(
    datos?: unknown,
    responseType?: "blob",
  ): Promise<number | undefined> {
    let vista: InternalAxiosRequestConfig | undefined;
    const capturar: AxiosAdapter = async (config) => {
      vista = config;
      return { data: {}, status: 200, statusText: "OK", headers: {}, config };
    };
    await api.post("/api/v1/app/demo/x", datos, {
      adapter: capturar,
      responseType,
    });
    return vista?.timeout;
  }

  it("una API colgada no deja esperando sin fin", async () => {
    expect(TIEMPO_LIMITE_MS).toBe(30_000);
    expect(await tiempoDe({ a: 1 })).toBe(30_000);
  });

  it("subir o descargar archivos tiene más margen", async () => {
    expect(await tiempoDe(new FormData())).toBeGreaterThan(30_000);
    expect(await tiempoDe(undefined, "blob")).toBeGreaterThan(30_000);
  });
});

describe("fallas que no dicen nada de la sesión", () => {
  function conEstado(status: number): AxiosError {
    const config = { headers: {} } as InternalAxiosRequestConfig;
    return new AxiosError("Falla", "ERR_BAD_RESPONSE", config, null, {
      status,
      statusText: "",
      headers: {},
      config,
      data: {},
    });
  }

  it("clasifica red, mantenimiento y servidor; 401 y 404 sí invalidan", () => {
    expect(fallaPasajera(new AxiosError("Network Error", "ERR_NETWORK"))).toBe(
      "sin-conexion",
    );
    expect(fallaPasajera(conEstado(503))).toBe("mantenimiento");
    expect(fallaPasajera(conEstado(429))).toBe("servidor");
    expect(fallaPasajera(conEstado(500))).toBe("servidor");
    expect(fallaPasajera(conEstado(401))).toBeNull();
    expect(fallaPasajera(conEstado(404))).toBeNull();
  });
});

describe("mensaje de error", () => {
  it("sin respuesta del servidor dice eso, no el mensaje genérico", () => {
    expect(
      mensajeDeError(
        new AxiosError("Network Error", "ERR_NETWORK"),
        "No se pudo",
      ),
    ).toBe(
      "No hubo respuesta del servidor. Revisa tu conexión e inténtalo de nuevo.",
    );
    expect(
      mensajeDeError(new AxiosError("timeout", "ECONNABORTED"), "No se pudo"),
    ).toBe(
      "No hubo respuesta del servidor. Revisa tu conexión e inténtalo de nuevo.",
    );
  });

  it("una petición cancelada usa el mensaje de quien la hizo", () => {
    expect(
      mensajeDeError(new AxiosError("canceled", "ERR_CANCELED"), "No se pudo"),
    ).toBe("No se pudo");
  });

  describe("en una barbería (sus citas, sus clientes)", () => {
    function respuesta(status: number, data: object): AxiosError {
      const config = { headers: {} } as InternalAxiosRequestConfig;
      return new AxiosError("Falla", "ERR_BAD_REQUEST", config, null, {
        status,
        statusText: "",
        headers: {},
        config,
        data,
      });
    }
    afterEach(() => fijarTerminosActuales(null));

    it("los mensajes del negocio hablan como el negocio", () => {
      fijarTerminosActuales({
        sesion: "Cita",
        miembro: "Cliente",
        instructor: "Barbero",
      });
      expect(
        mensajeDeError(
          respuesta(422, {
            code: "SESSION_FULL",
            message: "La clase está llena.",
          }),
        ),
      ).toBe("La cita está llena.");
    });

    it("los de la modalidad se muestran tal cual dice el servidor (ADR 0104)", () => {
      fijarTerminosActuales({
        sesion: "Cita",
        miembro: "Cliente",
        instructor: "Barbero",
      });
      expect(
        mensajeDeError(
          respuesta(422, {
            code: "MODALITY_LOCKED",
            message:
              "Este negocio trabaja con citas; cambiar a clases lo hace AgendaUno.",
          }),
        ),
      ).toBe(
        "Este negocio trabaja con citas; cambiar a clases lo hace AgendaUno.",
      );
      expect(
        mensajeDeError(
          respuesta(403, {
            code: "MODALITY_NOT_AVAILABLE",
            message: "Esta función es de negocios con clases.",
          }),
        ),
      ).toBe("Esta función es de negocios con clases.");
    });
  });
});
