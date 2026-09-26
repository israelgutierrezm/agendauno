import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { miReprogramar } from "@/i18n/locales/gestion.es-MX";
import ReprogramarMiReserva from "./ReprogramarMiReserva.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => (e instanceof Error ? e.message : String(e)),
}));

function montar() {
  return mount(ReprogramarMiReserva, {
    props: {
      base: "/api/v1/app/a",
      reservaId: "r1",
      zona: "America/Mexico_City",
      iniciaEn: "2030-01-07T16:00:00Z",
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { miReprogramar } },
        }),
      ],
    },
  });
}

describe("cambiar el horario desde la cuenta", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("cita: elige otro horario libre y lo manda en la hora de la sede", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          puede: true,
          motivo: null,
          tipo: "cita",
          restantes: 1,
          hasta: "2030-01-06T22:00:00Z",
          slots: [
            { inicia: "2030-01-07T18:00:00Z", termina: "2030-01-07T19:00:00Z" },
          ],
        },
      },
    });
    api.post.mockResolvedValue({ data: { data: {} } });
    const w = montar();
    await flushPromises();

    // Pide los horarios del día de la reserva, en la zona de la sede.
    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/a/mi/reservas/r1/reprogramar",
      { params: { fecha: "2030-01-07" } },
    );
    await w
      .findAll("button")
      .find((b) => b.text() === "12:00")!
      .trigger("click");
    await w
      .findAll("button")
      .find((b) => b.text() === "Cambiar")!
      .trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/a/mi/reservas/r1/reprogramar",
      { inicia_en_local: "2030-01-07 12:00:00" },
    );
    expect(w.emitted("hecho")).toHaveLength(1);
  });

  it("si ya no se puede, dice por qué y no ofrece cambiar", async () => {
    api.get.mockResolvedValue({
      data: {
        data: {
          puede: false,
          motivo: "Ya no se puede cambiar: faltan menos de 12 h.",
          tipo: "cita",
          restantes: 1,
          hasta: "2030-01-06T22:00:00Z",
        },
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("faltan menos de 12 h");
    expect(w.findAll("button").map((b) => b.text())).toEqual(["Volver"]);
  });
});
