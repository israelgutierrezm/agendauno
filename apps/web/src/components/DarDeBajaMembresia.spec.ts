import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import DarDeBajaMembresia from "./DarDeBajaMembresia.vue";

/*
| Dar de baja una membresía (B04): antes de confirmar se ve cuándo termina, la
| penalización pactada con su cuenta y el adeudo; exentar pide motivo.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const sesion = vi.hoisted(() => ({
  moneda: "MXN",
  pais: "MX",
  puede: vi.fn(() => true),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));

const simulacion = {
  efectiva_el: "2026-10-31",
  en_permanencia: true,
  permanencia_hasta: "2027-04-01",
  modo: "meses_restantes",
  meses_restantes: 5,
  precio_base_minor: 120000,
  penalizacion_minor: 600000,
  adeudo_minor: 120000,
  moneda: "MXN",
  condiciones: "Permanencia mínima de 6 meses…",
};

function montar() {
  return mount(DarDeBajaMembresia, {
    props: {
      base: "/api/v1/app/demo",
      acuerdoId: "a1",
      producto: "Mensualidad",
    },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
  sesion.puede.mockReturnValue(true);
  api.get.mockResolvedValue({ data: { data: simulacion } });
  api.post.mockResolvedValue({ data: { data: { efectiva_el: "2026-10-31" } } });
});

describe("dar de baja una membresía", () => {
  it("muestra el cálculo y confirma con lo elegido", async () => {
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="baja-efectiva"]').text()).toContain(
      "31 de octubre de 2026",
    );
    expect(w.get('[data-prueba="baja-permanencia"]').text()).toContain(
      "5 × $1,200.00 = $6,000.00",
    );
    expect(w.get('[data-prueba="baja-total"]').text()).toContain("$7,200.00");

    await w.get('[data-prueba="confirmar-baja"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/acuerdos/a1/baja", {
      motivo: null,
      exentar_penalizacion: false,
      conservar_adeudo: true,
    });
    expect(w.emitted("hecho")?.[0]?.[0]).toContain("Mensualidad");
  });

  it("exentar la penalización pide un motivo y baja lo que se cobra", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="exentar-penalizacion"]').setValue(true);
    expect(w.get('[data-prueba="baja-total"]').text()).toContain("$1,200.00");
    expect(
      w.get('[data-prueba="confirmar-baja"]').attributes("disabled"),
    ).toBeDefined();
    await w.get("#baja-motivo").setValue("Cambio de ciudad");
    await w.get('[data-prueba="confirmar-baja"]').trigger("click");
    await flushPromises();
    expect(api.post.mock.calls[0]![1]).toMatchObject({
      motivo: "Cambio de ciudad",
      exentar_penalizacion: true,
    });
  });

  it("sin permiso no se ofrece exentar", async () => {
    sesion.puede.mockReturnValue(false);
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="exentar-penalizacion"]').exists()).toBe(false);
  });
});
