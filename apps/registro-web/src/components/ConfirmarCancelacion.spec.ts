import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { cancelacion } from "@/i18n/locales/gestion.es-MX";
import ConfirmarCancelacion from "./ConfirmarCancelacion.vue";

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));

function montar(conQuien = false) {
  return mount(ConfirmarCancelacion, {
    props: { url: "/api/v1/app/a/reservas/r1/cancelacion", conQuien },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { cancelacion } },
        }),
      ],
    },
  });
}

function boton(w: ReturnType<typeof montar>, texto: string) {
  return w.findAll("button").find((b) => b.text() === texto);
}

describe("confirmar cancelación", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("muestra qué pasará con el crédito antes de cancelar", async () => {
    api.get.mockResolvedValue({
      data: { data: { cancelable: true, mensaje: "Se devolverá 1 crédito." } },
    });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Se devolverá 1 crédito.");
    await boton(w, "Sí, cancelar")?.trigger("click");
    expect(w.emitted("confirmar")).toEqual([[null]]);
  });

  it("en recepción pregunta quién cancela y recalcula el efecto", async () => {
    api.get
      .mockResolvedValueOnce({
        data: {
          data: {
            cancelable: true,
            mensaje:
              "Se devolverá 1 crédito. Cancela el negocio: sin penalización.",
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            cancelable: true,
            mensaje:
              "Se cobrará 1 crédito: se cancela con menos de 6 h de anticipación.",
          },
        },
      });
    const w = montar(true);
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(expect.any(String), {
      params: { por: "negocio" },
    });

    await boton(w, "Lo pidió el cliente")?.trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(expect.any(String), {
      params: { por: "cliente" },
    });
    expect(w.text()).toContain("Se cobrará 1 crédito");

    await boton(w, "Sí, cancelar")?.trigger("click");
    expect(w.emitted("confirmar")).toEqual([["cliente"]]);
  });

  it("si ya no se puede cancelar, lo dice y no ofrece confirmar", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          cancelable: false,
          mensaje:
            "Ya se registró la asistencia de esta reserva; no se puede cancelar.",
        },
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Ya se registró la asistencia");
    expect(boton(w, "Sí, cancelar")).toBeUndefined();
  });
});
