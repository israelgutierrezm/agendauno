import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import planes from "@/i18n/locales/planes.es-MX";
import EditorPlan from "./EditorPlan.vue";

/*
| Con citas, un plan es un bono de sesiones o una membresía (ADR 0091): un servicio
| suelto o un combo van en Catálogo, y un bono solo sirve para los servicios que se
| toman con bono o membresía.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: String }));
const sesion = vi.hoisted(() => ({ slug: "demo", esCitas: true }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/ofertas")
          ? [
              { id: "corte", nombre: "Corte", politica_reserva: "pago" },
              {
                id: "masaje",
                nombre: "Masaje",
                politica_reserva: "entitlement",
              },
            ]
          : [],
      },
    }),
  );
});

function montar() {
  return mount(EditorPlan, {
    props: { plan: null },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { planes } },
        }),
      ],
      stubs: { RouterLink: { props: ["to"], template: "<a><slot /></a>" } },
    },
  });
}

describe("planes de un negocio de citas", () => {
  it("exige elegir sedes y guarda la selección sin convertirla en cobertura total", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/sucursales")
            ? [
                { id: "roma", nombre: "Roma" },
                { id: "polanco", nombre: "Polanco" },
              ]
            : [],
        },
      }),
    );
    api.post.mockResolvedValue({});
    const w = montar();
    await flushPromises();
    await w.get("#ep-nombre").setValue("Bono Roma");
    await w.get("#ep-precio").setValue("500");
    await w.get('[data-prueba="cobertura-sucursal"]').setValue("seleccionadas");
    expect(
      (w.get('button[type="submit"]').element as HTMLButtonElement).disabled,
    ).toBe(true);
    await w.get('input[type="checkbox"][value="roma"]').setValue(true);
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/productos",
      expect.objectContaining({ sucursal_ids: ["roma"] }),
    );
    await w.get('[data-prueba="cobertura-sucursal"]').setValue("todas");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/productos",
      expect.objectContaining({ sucursal_ids: null }),
    );
  });
  it("son bono de sesiones o membresía; el servicio suelto o el combo, en Catálogo", async () => {
    const w = montar();
    await flushPromises();

    expect(
      w.findAll(".ep-tipo").map((b) => b.find(".font-medium").text()),
    ).toEqual(["Bono de sesiones", "Membresía"]);
    expect(w.get('[data-prueba="servicio-o-combo"]').text()).toContain(
      "Catálogo",
    );
  });

  it("un bono solo se aplica a los servicios que se toman con bono", async () => {
    const w = montar();
    await flushPromises();

    const algunas = w
      .findAll('input[type="radio"]')
      .find((r) => (r.element as HTMLInputElement).value === "false")!;
    await algunas.setValue(true);
    const servicios = w
      .findAll(".ep-opcion")
      .map((o) => o.text())
      .filter((t) => t === "Corte" || t === "Masaje");
    expect(servicios).toEqual(["Masaje"]);
  });
});
