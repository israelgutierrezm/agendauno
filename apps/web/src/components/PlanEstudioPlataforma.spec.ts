import { flushPromises, mount } from "@vue/test-utils";
import axios from "axios";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import type { PlanCitas } from "@/lib/suscripcion";
import PlanEstudioPlataforma from "./PlanEstudioPlataforma.vue";

/*
| El plan de un negocio desde su ficha en el superadmin (ADR 0107): se ve lo
| contratado y se cambia; quitar «Cobrar la diferencia» sube sin cobrar (cortesía).
| Subir cobrando se confirma antes, y el resultado va en un aviso flotante (la ficha
| se recarga al cambiar).
*/

vi.mock("axios", async (original) => {
  const real = await original<typeof import("axios")>();
  return { default: { ...real.default, put: vi.fn() } };
});
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
const confirmar = vi.hoisted(() => vi.fn());
vi.mock("@/lib/confirmar", () => ({ confirmar }));

const plan: PlanCitas = {
  nivel: "premium",
  profesionales: 3,
  periodicidad: "mensual",
  elegido: true,
  en_prueba: false,
  cubierto_hasta: "2026-10-31",
  siguiente: null,
  profesionales_actuales: 3,
  limite_profesionales: 3,
  max_profesionales: 20,
  meses_anual: 10,
  moneda_tarifa: "USD",
  moneda_cobro: "MXN",
  iva_porcentaje: 16,
  tipo_cambio: null,
  precios: {
    individual: { "1": 900 },
    premium: { "2": 2400, "3": 2800, "4": 3300 },
    pro: { "2": 3300, "3": 5000, "4": 5000 },
  },
};

function montar(p: PlanCitas = plan) {
  return mount(PlanEstudioPlataforma, {
    props: { apiUrl: "http://api", token: "t", slug: "barberia", plan: p },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("PlanEstudioPlataforma", () => {
  it("muestra el plan y lo cambia sin cobrar la diferencia si así se pide", async () => {
    vi.mocked(axios.put).mockResolvedValueOnce({
      data: { data: { aplica: "ahora", ajuste: null } },
    });
    const w = montar();

    expect(w.text()).toContain("Premium · 3 profesionales");
    expect(w.text()).toContain("Pagado hasta el");

    await w.get("select").setValue("pro");
    await w.get("input[type=checkbox]").setValue(false);
    expect(w.text()).toMatch(/USD\s50\.00 al mes/);
    await w.get("form").trigger("submit");
    await flushPromises();

    // Sin cobro no hay nada que confirmar.
    expect(confirmar).not.toHaveBeenCalled();
    expect(axios.put).toHaveBeenCalledWith(
      "http://api/api/v1/plataforma/estudios/barberia/plan",
      {
        nivel: "pro",
        profesionales: 3,
        periodicidad: "mensual",
        cobrar_diferencia: false,
      },
      expect.anything(),
    );
    expect(w.emitted("cambiado")).toHaveLength(1);
    expect(toast.exito).toHaveBeenCalledWith("Listo, tu plan cambió.");
  });

  it("subir cobrando la diferencia se confirma antes (a la tarjeta del negocio)", async () => {
    const pagado = { ...plan, cubierto_hasta: "2099-12-31" };
    confirmar.mockResolvedValueOnce(false);
    const w = montar(pagado);

    await w.get("select").setValue("pro");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(confirmar).toHaveBeenCalledTimes(1);
    expect(confirmar.mock.calls[0]![0]).toContain("Pro · 3 profesionales");
    expect(confirmar.mock.calls[0]![0]).toContain(
      "tarjeta guardada del negocio",
    );
    expect(axios.put).not.toHaveBeenCalled();

    confirmar.mockResolvedValueOnce(true);
    vi.mocked(axios.put).mockResolvedValueOnce({
      data: {
        data: { aplica: "ahora", ajuste: { monto_minor: 1500, moneda: "USD" } },
      },
    });
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(axios.put).toHaveBeenCalledTimes(1);
    expect(vi.mocked(axios.put).mock.calls[0]![1]).toMatchObject({
      nivel: "pro",
      cobrar_diferencia: true,
    });
    expect(toast.exito).toHaveBeenCalledWith(
      expect.stringMatching(/Te cobramos USD\s15\.00/),
    );
  });

  it("bajar no cobra nada hoy: no pide confirmar; un error va en el aviso", async () => {
    const pagado = { ...plan, cubierto_hasta: "2099-12-31" };
    vi.mocked(axios.put).mockRejectedValueOnce(new Error("sin red"));
    const w = montar(pagado);

    await w.get("select").setValue("individual");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(confirmar).not.toHaveBeenCalled();
    expect(axios.put).toHaveBeenCalledTimes(1);
    expect(toast.error).toHaveBeenCalledTimes(1);
    expect(w.emitted("cambiado")).toBeUndefined();
  });
});
