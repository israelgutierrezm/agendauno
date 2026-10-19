import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CortesNomina from "./CortesNomina.vue";

/*
| Cortes de nómina: solo se cierra un periodo que ya terminó y no se encima; cerrado
| se paga con referencia o se reabre; pagado, lo que cambie va en ajustes.
*/

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error",
  erroresPorCampo: (e: { campos?: Record<string, string> }) => e.campos ?? {},
}));
const confirmar = vi.hoisted(() => vi.fn(async () => true));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    moneda: "MXN",
    pais: "MX",
    zonaHoraria: "America/Mexico_City",
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

enableAutoUnmount(afterEach);

const corte = {
  id: "c1",
  desde: "2026-09-01",
  hasta: "2026-09-15",
  estado: "cerrado",
  moneda: "MXN",
  total_minor: 40000,
  ajustes_minor: 0,
  total_con_ajustes_minor: 40000,
  cerrado_por: "Dueña",
  cerrado_en: "2026-09-16T10:00:00-06:00",
  pagado_por: null,
  pagado_en: null,
  referencia_pago: null,
};

function respuestas(lista: object[], detalle: object = {}): void {
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/nomina/cortes")
        ? {
            data: lista,
            meta: {
              total: lista.length,
              page: 1,
              per_page: 10,
              ultima_pagina: 1,
            },
          }
        : {
            data: {
              ...corte,
              lineas: [
                {
                  usuario_id: "u1",
                  usuario: "Coach",
                  tipo: "por_clase",
                  unidades: 2,
                  total_minor: 40000,
                },
              ],
              ajustes: [],
              diferencias: [],
              ...detalle,
            },
          },
    }),
  );
}

function montar(periodo: { desde: string; hasta: string } | null) {
  return mount(CortesNomina, {
    props: {
      periodo,
      devengado: 40000,
      equipo: [{ id: "u1", nombre: "Coach" }],
    },
    global: {
      plugins: [i18n],
      stubs: { PanelLateral: { template: "<div><slot /></div>" } },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2026-10-05T18:00:00Z"));
});
afterEach(() => vi.useRealTimers());

describe("cortes de nómina", () => {
  it("cierra el periodo calculado si ya terminó, con el total a la vista", async () => {
    respuestas([]);
    api.post.mockResolvedValue({ data: { data: corte } });
    const w = montar({ desde: "2026-10-01", hasta: "2026-10-04" });
    await flushPromises();
    expect(w.text()).toContain("Aún no cierras ningún periodo.");

    await w.get('[data-prueba="cerrar-periodo"]').trigger("click");
    await flushPromises();
    expect(confirmar.mock.calls[0]?.[0]).toContain("$400.00");
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/nomina/cortes", {
      desde: "2026-10-01",
      hasta: "2026-10-04",
    });
  });

  it("no deja cerrar un periodo que no ha terminado o que se encima con uno cerrado", async () => {
    respuestas([corte]);
    const w = montar({ desde: "2026-10-01", hasta: "2026-10-05" });
    await flushPromises();
    expect(
      w.get('[data-prueba="cerrar-periodo"]').attributes("disabled"),
    ).toBeDefined();
    expect(w.get('[data-prueba="motivo-no-cerrar"]').text()).toContain(
      "ya terminó",
    );

    await w.setProps({ periodo: { desde: "2026-09-10", hasta: "2026-09-20" } });
    expect(w.get('[data-prueba="motivo-no-cerrar"]').text()).toContain(
      "se encima",
    );
  });

  it("si no se cargan los periodos lo dice y deja reintentar (no parece vacío)", async () => {
    respuestas([corte]);
    api.get.mockRejectedValueOnce(new Error("red"));
    const w = montar(null);
    await flushPromises();
    expect(w.find('[data-prueba="error-cortes"]').exists()).toBe(true);
    expect(w.text()).not.toContain("Aún no cierras ningún periodo.");

    await w.get('[data-prueba="error-cortes"] button').trigger("click");
    await flushPromises();
    expect(w.text()).toContain("Cerrado, sin pagar");
  });

  it("registra el pago de un periodo cerrado con su referencia", async () => {
    respuestas([corte]);
    api.post.mockResolvedValue({
      data: { data: { ...corte, estado: "pagado" } },
    });
    const w = montar(null);
    await flushPromises();
    await w.get('[data-prueba="ver-corte-c1"]').trigger("click");
    await flushPromises();

    await w.get('[data-prueba="referencia-pago"]').setValue("SPEI 0099");
    await w.get("#form-pago-nomina").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/nomina/cortes/c1/pagar",
      { referencia: "SPEI 0099" },
    );
  });

  it("ya pagado, la diferencia se convierte en ajuste con su signo", async () => {
    respuestas([{ ...corte, estado: "pagado" }], {
      estado: "pagado",
      referencia_pago: "SPEI 0099",
      pagado_en: "2026-09-17T10:00:00-06:00",
      diferencias: [
        {
          usuario_id: "u1",
          usuario: "Coach",
          en_corte_minor: 40000,
          hoy_minor: 20000,
          diferencia_minor: -20000,
        },
      ],
    });
    api.post.mockResolvedValue({ data: { data: { id: "a1" } } });
    const w = montar(null);
    await flushPromises();
    await w.get('[data-prueba="ver-corte-c1"]').trigger("click");
    await flushPromises();

    await w.get('[data-prueba="diferencias-corte"] button').trigger("click");
    await w.get('[data-prueba="ajuste-motivo"]').setValue("Clase cancelada");
    await w.get("#form-ajuste-nomina").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/nomina/cortes/c1/ajustes",
      {
        usuario_id: "u1",
        monto_minor: -20000,
        motivo: "Clase cancelada",
        compensa_diferencia: true,
      },
    );
  });

  it("una diferencia ya atendida no ofrece ajustarla otra vez (R09)", async () => {
    respuestas([{ ...corte, estado: "pagado" }], {
      estado: "pagado",
      referencia_pago: "SPEI 0099",
      pagado_en: "2026-09-17T10:00:00-06:00",
      diferencias: [
        {
          usuario_id: "u1",
          usuario: "Coach",
          en_corte_minor: 10000,
          ajustes_minor: 10000,
          hoy_minor: 20000,
          diferencia_minor: 0,
        },
      ],
    });
    const w = montar(null);
    await flushPromises();
    await w.get('[data-prueba="ver-corte-c1"]').trigger("click");
    await flushPromises();

    expect(w.get('[data-prueba="diferencias-corte"]').text()).toContain(
      "ya se ajustó",
    );
    expect(w.find('[data-prueba="diferencias-corte"] button').exists()).toBe(
      false,
    );
  });
});
