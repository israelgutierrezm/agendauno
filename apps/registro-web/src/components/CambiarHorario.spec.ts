import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { reprogramar } from "@/i18n/locales/gestion.es-MX";
import CambiarHorario from "./CambiarHorario.vue";

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => (e instanceof Error ? e.message : String(e)),
}));

function montar() {
  return mount(CambiarHorario, {
    props: {
      url: "/api/v1/app/a/reservas/r1/reprogramar",
      zona: "America/Mexico_City",
      iniciaEn: "2030-01-07T16:00:00Z",
      profesionales: [
        { id: "p1", nombre: "Ana" },
        { id: "p2", nombre: "Beto" },
      ],
      profesionalId: "p1",
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { reprogramar } },
        }),
      ],
    },
  });
}

describe("cambiar horario", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("propone el horario actual y manda el nuevo con otro profesional", async () => {
    api.post.mockResolvedValue({
      data: {
        data: { antes: "2030-01-07T16:00:00Z", ahora: "2030-01-08T18:00:00Z" },
      },
    });
    const w = montar();
    expect((w.get("#rp-fecha").element as HTMLInputElement).value).toBe(
      "2030-01-07",
    );
    expect((w.get("#rp-hora").element as HTMLInputElement).value).toBe("10:00");

    await w.get("#rp-fecha").setValue("2030-01-08");
    await w.get("#rp-hora").setValue("12:00");
    await w.get("#rp-pro").setValue("p2");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/a/reservas/r1/reprogramar",
      { inicia_en_local: "2030-01-08 12:00:00", instructor_id: "p2" },
    );
    expect(w.emitted("hecho")?.[0]).toEqual([
      { antes: "2030-01-07T16:00:00Z", ahora: "2030-01-08T18:00:00Z" },
    ]);
  });

  it("si el horario ya no está libre, lo dice y no cierra", async () => {
    api.post.mockRejectedValue(
      new Error("Esa persona ya atiende Nivel 1 a las 15:00."),
    );
    const w = montar();
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(w.text()).toContain("Esa persona ya atiende Nivel 1 a las 15:00.");
    expect(w.emitted("hecho")).toBeUndefined();
  });
});
