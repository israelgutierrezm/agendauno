import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { cancelacion } from "@/i18n/locales/gestion.es-MX";
import portal from "@/i18n/locales/portal.es-MX";
import CambiarMiLugar from "./CambiarMiLugar.vue";

/*
| Cambiar su lugar numerado (B06): se guarda el elegido; si alguien lo tomó antes, se
| dice y se vuelven a cargar los libres, sin cambiarlo a otro en silencio.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "El lugar elegido no esta disponible.",
}));

function montar() {
  return mount(CambiarMiLugar, {
    props: { base: "/api/v1/app/demo", reservaId: "r1", sesionId: "s1" },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, portal, cancelacion } },
        }),
      ],
    },
  });
}

beforeEach(() => {
  api.get.mockReset();
  api.put.mockReset();
});

describe("cambiar mi lugar", () => {
  it("parte del suyo y guarda el nuevo", async () => {
    api.get.mockResolvedValue({
      data: { data: { total: 5, ocupados: [1, 2], mio: 1 } },
    });
    api.put.mockResolvedValue({ data: { data: {} } });
    const w = montar();
    await flushPromises();

    // El suyo no cuenta como ocupado: está elegido.
    expect(w.get('[data-lugar="1"]').attributes("aria-pressed")).toBe("true");
    expect(w.get('[data-lugar="2"]').attributes("disabled")).toBeDefined();
    await w.get('[data-lugar="4"]').trigger("click");
    await w.get('[data-prueba="guardar-lugar"]').trigger("click");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/reservas/r1/lugar",
      {
        lugar: 4,
      },
    );
    expect(w.emitted("hecho")?.[0]).toEqual([4]);
  });

  it("si alguien lo tomó antes, lo dice y vuelve a cargar los libres", async () => {
    api.get
      .mockResolvedValueOnce({
        data: { data: { total: 5, ocupados: [], mio: null } },
      })
      .mockResolvedValueOnce({
        data: { data: { total: 5, ocupados: [3], mio: null } },
      });
    api.put.mockRejectedValue(new Error("422"));
    const w = montar();
    await flushPromises();

    await w.get('[data-lugar="3"]').trigger("click");
    await w.get('[data-prueba="guardar-lugar"]').trigger("click");
    await flushPromises();

    expect(w.get('[role="alert"]').text()).toBe(
      "El lugar elegido no esta disponible.",
    );
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(w.get('[data-lugar="3"]').attributes("disabled")).toBeDefined();
    expect(w.emitted("hecho")).toBeUndefined();
  });
});
