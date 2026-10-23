import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { validacion } from "@/i18n/locales/gestion.es-MX";
import planes from "@/i18n/locales/planes.es-MX";
import CuotasNegocio from "./CuotasNegocio.vue";

/*
| Cuotas del negocio (B04): montos en el formato del país, la inscripción con su
| reingreso y la anualidad en el aniversario o en una fecha fija.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: String }));
const sesion = vi.hoisted(() => ({ slug: "demo", moneda: "MXN", pais: "MX" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

function montar() {
  return mount(CuotasNegocio, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, planes, validacion } },
        }),
      ],
    },
  });
}

beforeEach(() => {
  sesion.moneda = "MXN";
  sesion.pais = "MX";
  api.get.mockReset();
  api.put.mockReset();
  api.get.mockResolvedValue({
    data: {
      data: {
        inscripcion_minor: 50000,
        reingreso_meses: 6,
        anualidad_minor: 0,
        anualidad_modo: "aniversario",
        anualidad_mes: null,
        anualidad_dia: null,
      },
    },
  });
  api.put.mockResolvedValue({ data: { data: {} } });
});

describe("cuotas del negocio", () => {
  it("carga lo guardado y guarda la anualidad en una fecha fija", async () => {
    const w = montar();
    await flushPromises();
    expect((w.get("#cu-inscripcion").element as HTMLInputElement).value).toBe(
      "500",
    );

    await w.get("#cu-anualidad").setValue("1,200.50");
    await w.get('input[type="radio"][value="fecha_fija"]').setValue(true);
    await w.get("#cu-mes").setValue("2");
    await w.get("#cu-dia").setValue("29");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/cuotas", {
      inscripcion_minor: 50000,
      reingreso_meses: 6,
      anualidad_minor: 120050,
      anualidad_modo: "fecha_fija",
      anualidad_mes: 2,
      anualidad_dia: 29,
    });
    expect(w.emitted("guardado")).toHaveLength(1);
  });

  it("un monto que no se puede leer se marca y no se guarda", async () => {
    const w = montar();
    await flushPromises();
    await w.get("#cu-inscripcion").setValue("1.2.3");
    expect(w.find('[role="alert"]').exists()).toBe(true);
    // «Guardar» va en el pie del diálogo (PlanesView): el editor le avisa que no se
    // puede guardar, y un Enter en un campo tampoco lo guarda.
    expect(w.emitted("estado")?.at(-1)?.[0]).toMatchObject({ valido: false });
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.put).not.toHaveBeenCalled();
  });

  it("con coma decimal, lo guardado se muestra como en el país y se vuelve a leer igual", async () => {
    sesion.pais = "ES";
    sesion.moneda = "EUR";
    api.get.mockResolvedValue({
      data: {
        data: {
          inscripcion_minor: 125050,
          reingreso_meses: 6,
          anualidad_minor: 0,
          anualidad_modo: "aniversario",
          anualidad_mes: null,
          anualidad_dia: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    expect((w.get("#cu-inscripcion").element as HTMLInputElement).value).toBe(
      "1250,50",
    );
    expect(w.find('[role="alert"]').exists()).toBe(false);

    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/cuotas",
      expect.objectContaining({ inscripcion_minor: 125050 }),
    );
  });
});
