import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api";
import {
  avisarConversionGoogle,
  capturarRwgToken,
} from "@/lib/reservaConGoogle";

/*
| Reserva con Google (ADR 0198): el token con que se llega se guarda por negocio y, al
| reservar ahí, se avisa una vez y se olvida.
*/
afterEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
  vi.useRealTimers();
});

describe("reserva con Google", () => {
  it("guarda el token del negocio y lo avisa una sola vez al reservar", () => {
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: {} });
    capturarRwgToken("Fluo", "AJKvS9W-token");

    avisarConversionGoogle("otro");
    expect(post).not.toHaveBeenCalled();

    avisarConversionGoogle("fluo");
    expect(post).toHaveBeenCalledWith(
      "/api/v1/app/fluo/reserva-con-google/conversion",
      { rwg_token: "AJKvS9W-token" },
    );
    avisarConversionGoogle("fluo");
    expect(post).toHaveBeenCalledTimes(1);
  });

  it("después de 30 días ya no cuenta", () => {
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: {} });
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-10-01T12:00:00Z"));
    capturarRwgToken("fluo", "tok");
    vi.setSystemTime(new Date("2026-11-02T12:00:00Z"));

    avisarConversionGoogle("fluo");
    expect(post).not.toHaveBeenCalled();
  });
});
