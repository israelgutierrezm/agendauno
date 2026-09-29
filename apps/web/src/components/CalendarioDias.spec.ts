import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import perfilPublico from "@/i18n/locales/perfilPublico.es-MX";
import CalendarioDias from "./CalendarioDias.vue";

const api = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "No disponible",
}));

// El domingo 6 nadie atiende; el lunes 7 y el martes 8, sí.
const dias = [
  { fecha: "2030-01-06", abierto: false },
  { fecha: "2030-01-07", abierto: true },
  { fecha: "2030-01-08", abierto: true },
];

function montar(props: Record<string, unknown> = {}) {
  return mount(CalendarioDias, {
    props: {
      ruta: "/api/v1/app/demo/mi/citas/dias",
      sucursalId: "centro",
      zona: "America/Mexico_City",
      modelValue: "",
      "onUpdate:modelValue": (v: string) => w.setProps({ modelValue: v }),
      ...props,
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...es, perfilPublico } },
        }),
      ],
    },
  });
}
let w: ReturnType<typeof montar>;

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue({ data: { data: dias } });
});

describe("calendario de días", () => {
  it("pide los días de la sede, no deja elegir los que no tienen atención y abre en el primero que sí", async () => {
    w = montar();
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith("/api/v1/app/demo/mi/citas/dias", {
      params: expect.objectContaining({ sucursal_id: "centro", dias: 14 }),
    });
    expect(
      w.get('[data-fecha="2030-01-06"]').attributes("disabled"),
    ).toBeDefined();
    expect(w.emitted("update:modelValue")?.[0]).toEqual(["2030-01-07"]);
    expect(w.get('[data-fecha="2030-01-07"]').attributes("aria-pressed")).toBe(
      "true",
    );

    await w.get('[data-fecha="2030-01-08"]').trigger("click");
    expect(w.emitted("update:modelValue")?.at(-1)).toEqual(["2030-01-08"]);
  });

  it("trae más fechas desde el día siguiente al último", async () => {
    w = montar();
    await flushPromises();
    await w.get('[data-prueba="mas-fechas"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/mi/citas/dias", {
      params: expect.objectContaining({ desde: "2030-01-09" }),
    });
  });

  it("con alguien de preferencia pide sus días y los vuelve a pedir si cambia", async () => {
    w = montar({ instructorId: "luis" });
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/mi/citas/dias", {
      params: expect.objectContaining({ instructor_id: "luis" }),
    });
    await w.setProps({ instructorId: null });
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/mi/citas/dias", {
      params: expect.not.objectContaining({ instructor_id: "luis" }),
    });
  });

  it("si ningún día tiene atención lo dice", async () => {
    api.get.mockResolvedValue({
      data: { data: [{ fecha: "2030-01-06", abierto: false }] },
    });
    w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="sin-dias"]').text()).toContain(
      "No hay días con atención",
    );
  });
});
