import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
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
afterEach(() => w?.unmount());

describe("calendario de días", () => {
  it("muestra una fecha encontrada fuera de la tira actual", async () => {
    w = montar();
    await flushPromises();
    api.get.mockResolvedValueOnce({
      data: { data: [{ fecha: "2030-02-01", abierto: true }] },
    });
    await w.setProps({ modelValue: "2030-02-01" });
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/mi/citas/dias", {
      params: expect.objectContaining({ desde: "2030-02-01" }),
    });
    expect(w.get('[data-fecha="2030-02-01"]').attributes("aria-pressed")).toBe(
      "true",
    );
  });
  it("las flechas desplazan las fechas sin cambiar el día seleccionado", async () => {
    w = montar();
    const tira = w.get('[data-prueba="dias"]').element;
    const scrollBy = vi.fn();
    Object.defineProperties(tira, {
      clientWidth: { value: 300 },
      scrollWidth: { value: 1200 },
      scrollLeft: { value: 0, writable: true },
      scrollBy: { value: scrollBy },
    });
    await flushPromises();
    const atras = w.get('[aria-label="Ver días anteriores"]');
    const adelante = w.get('[aria-label="Ver días siguientes"]');
    expect(atras.attributes("disabled")).toBeDefined();
    expect(adelante.attributes("disabled")).toBeUndefined();
    const seleccion = w.emitted("update:modelValue")?.length;
    await adelante.trigger("click");
    expect(scrollBy).toHaveBeenCalledWith(
      expect.objectContaining({ left: 225 }),
    );
    expect(w.emitted("update:modelValue")?.length).toBe(seleccion);
    tira.scrollLeft = 900;
    await w.get('[data-prueba="dias"]').trigger("scroll");
    expect(adelante.attributes("disabled")).toBeDefined();
    await atras.trigger("click");
    expect(scrollBy).toHaveBeenLastCalledWith(
      expect.objectContaining({ left: -225 }),
    );
  });

  it("anuncia la fecha completa y si el negocio no atiende ese día", async () => {
    w = montar();
    await flushPromises();
    expect(
      w.get('[data-fecha="2030-01-06"]').attributes("aria-label"),
    ).toContain("6 de enero de 2030, Sin atención");
    expect(
      w.get('[data-fecha="2030-01-07"]').attributes("aria-label"),
    ).toContain("7 de enero de 2030");
  });

  it("descarta la respuesta anterior si se quita la sucursal mientras carga", async () => {
    let responder!: (value: { data: { data: typeof dias } }) => void;
    api.get.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          responder = resolve;
        }),
    );
    w = montar();
    await w.setProps({ sucursalId: "" });
    responder({ data: { data: dias } });
    await flushPromises();
    expect(w.findAll("[data-fecha]")).toHaveLength(0);
    expect(w.emitted("update:modelValue")).toBeUndefined();
  });

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
