import {
  AxiosError,
  type AxiosAdapter,
  type InternalAxiosRequestConfig,
} from "axios";
import { afterEach, describe, expect, it, vi } from "vitest";

import { api, fijarBearer, fijarParticipante, participanteEnUso } from "./api";

/*
| La persona a cargo por la que actúa la cuenta (ADR 0123) viaja como `X-Participante`
| solo a la cuenta del alumno (`/mi/…`) de su negocio. Si el servidor dice que ya no
| está autorizada, la cuenta vuelve a la suya y se avisa.
*/

async function participanteDe(url: string): Promise<string | undefined> {
  let vista: InternalAxiosRequestConfig | undefined;
  const capturar: AxiosAdapter = async (config) => {
    vista = config;
    return { data: {}, status: 200, statusText: "OK", headers: {}, config };
  };
  await api.get(url, { adapter: capturar });
  const valor = vista?.headers.get("X-Participante");
  return typeof valor === "string" ? valor : undefined;
}

afterEach(() => {
  fijarBearer(null);
  fijarParticipante(null);
});

describe("cuenta de una persona a cargo", () => {
  it("solo va a la cuenta del alumno de su negocio", async () => {
    fijarBearer("1|secreto", "demo");
    fijarParticipante("p-sofia");

    expect(await participanteDe("/api/v1/app/demo/mi/perfil")).toBe("p-sofia");
    expect(await participanteDe("/api/v1/app/demo/miembros")).toBe(undefined);
    expect(await participanteDe("/api/v1/app/otro/mi/perfil")).toBe(undefined);

    fijarParticipante(null);
    expect(await participanteDe("/api/v1/app/demo/mi/perfil")).toBe(undefined);
  });

  it("si ya no está autorizada, vuelve a la cuenta propia y lo avisa", async () => {
    fijarBearer("1|secreto", "demo");
    fijarParticipante("p-sofia");
    const aviso = vi.fn();
    window.addEventListener("agendauno:participante-invalido", aviso);
    const negar: AxiosAdapter = async (config) => {
      throw new AxiosError("403", "ERR_BAD_REQUEST", config, null, {
        data: { code: "GUARDIAN_NOT_AUTHORIZED" },
        status: 403,
        statusText: "Forbidden",
        headers: {},
        config,
      });
    };

    await expect(
      api.get("/api/v1/app/demo/mi/perfil", { adapter: negar }),
    ).rejects.toBeInstanceOf(AxiosError);
    expect(participanteEnUso()).toBeNull();
    expect(aviso).toHaveBeenCalledOnce();
    window.removeEventListener("agendauno:participante-invalido", aviso);
  });
});
