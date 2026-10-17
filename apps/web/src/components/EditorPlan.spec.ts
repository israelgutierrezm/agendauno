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

describe("permanencia de una membresía (B04)", () => {
  it("guarda los meses, qué paga si da de baja antes y el monto fijo", async () => {
    sesion.esCitas = false;
    api.post.mockResolvedValue({});
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="tipo-membresia"]').trigger("click");
    await w.get("#ep-nombre").setValue("Mensualidad");
    await w.get("#ep-precio").setValue("1200");
    await w.get("#ep-permanencia").setValue("12");
    await w
      .get('[data-prueba="permanencia-plan"] input[value="monto_fijo"]')
      .setValue(true);
    await w.get("#ep-penalizacion").setValue("1500");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/productos",
      expect.objectContaining({
        permanencia_meses: 12,
        baja_anticipada: "monto_fijo",
        penalizacion_minor: 150000,
      }),
    );
    sesion.esCitas = true;
  });

  it("con «meses restantes» guarda qué porcentaje de lo que falte (ADR 0137)", async () => {
    sesion.esCitas = false;
    api.post.mockResolvedValue({});
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="tipo-membresia"]').trigger("click");
    await w.get("#ep-nombre").setValue("Mensualidad");
    await w.get("#ep-precio").setValue("1200");
    await w.get("#ep-permanencia").setValue("6");
    await w
      .get('[data-prueba="permanencia-plan"] input[value="meses_restantes"]')
      .setValue(true);
    // Fuera de 1–100 se ajusta al límite.
    await w.get("#ep-porcentaje").setValue("150");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/productos",
      expect.objectContaining({
        baja_anticipada: "meses_restantes",
        penalizacion_porcentaje: 100,
        penalizacion_minor: null,
      }),
    );

    await w.get("#ep-porcentaje").setValue("50");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/productos",
      expect.objectContaining({ penalizacion_porcentaje: 50 }),
    );
    sesion.esCitas = true;
  });
});

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
  it("solo son bonos de sesiones (sin membresías, ADR 0130); el servicio suelto o el combo, en Catálogo", async () => {
    const w = montar();
    await flushPromises();

    expect(
      w.findAll(".ep-tipo").map((b) => b.find(".font-medium").text()),
    ).toEqual(["Bono de sesiones"]);
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
      .findAll(".so-opcion")
      .map((o) => o.text())
      .filter((t) => t === "Corte" || t === "Masaje");
    expect(servicios).toEqual(["Masaje"]);
  });

  it("para la página: qué incluye, destacado y primera visita", async () => {
    api.post.mockResolvedValue({});
    const w = montar();
    await flushPromises();
    await w.get("#ep-nombre").setValue("Primera visita");
    await w.get("#ep-precio").setValue("150");
    await w.get("#ep-descripcion").setValue("  Un masaje para conocernos.  ");
    const destacado = w
      .findAll('input[type="checkbox"]')
      .find((c) =>
        c.element.closest("label")?.textContent?.includes("Destacarlo"),
      )!;
    await destacado.setValue(true);
    await w.get('[data-prueba="primera-vez"] input').setValue(true);
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/productos",
      expect.objectContaining({
        descripcion: "Un masaje para conocernos.",
        destacado: true,
        primera_vez: true,
      }),
    );

    expect(w.find('[data-prueba="tipo-membresia"]').exists()).toBe(false);
  });
});
