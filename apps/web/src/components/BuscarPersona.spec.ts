import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import buscarPersona from "@/i18n/locales/buscarPersona.es-MX";
import BuscarPersona from "./BuscarPersona.vue";

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({ api }));

function montar() {
  return mount(BuscarPersona, {
    props: {
      modelValue: "",
      buscarEn: "/api/v1/app/demo/miembros",
      parametros: { tipo: "miembro" },
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { buscarPersona } },
        }),
      ],
    },
  });
}

describe("buscar persona en el servidor", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("busca entre todos (no solo los cargados) y elige con su nombre", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          {
            id: "p729",
            nombre: "Enrique",
            nombre_completo: "Enrique Herrera",
            email: null,
            celular: "5512345678",
          },
        ],
      },
    });
    const w = montar();
    await w.get("input").setValue("Enrique Herrera");
    await vi.advanceTimersByTimeAsync(300);
    await flushPromises();

    expect(api.get).toHaveBeenCalledTimes(1);
    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/miembros", {
      params: { tipo: "miembro", q: "Enrique Herrera", page: 1, per_page: 8 },
    });
    expect(w.text()).toContain("Enrique Herrera");
    expect(w.text()).toContain("5512345678");

    await w.get(".bp-opcion").trigger("mousedown");
    expect(w.emitted("update:modelValue")?.at(-1)).toEqual(["p729"]);
    expect(w.emitted("elegir")?.at(-1)).toEqual([
      { id: "p729", nombre: "Enrique Herrera", detalle: "5512345678" },
    ]);
  });

  it("espera a que deje de escribir: una sola búsqueda por lo último escrito", async () => {
    api.get.mockResolvedValue({ data: { data: [] } });
    const w = montar();
    await w.get("input").setValue("En");
    await vi.advanceTimersByTimeAsync(100);
    await w.get("input").setValue("Enri");
    await vi.advanceTimersByTimeAsync(300);
    await flushPromises();

    expect(api.get).toHaveBeenCalledTimes(1);
    expect(api.get.mock.calls[0][1].params.q).toBe("Enri");
    expect(w.text()).toContain("Nadie coincide con esa búsqueda.");
  });
});
