import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CobranzaView from "./CobranzaView.vue";

/*
| Cobros con el patrón de los listados: indicadores de cada vista, y en movimientos
| búsqueda por cliente y filtro por estado.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  esCitas: false,
  ruta: { query: { vista: "movimientos" } as Record<string, string> },
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post, put: vi.fn(), delete: vi.fn() },
  mensajeDeError: () => "Error",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: mocks.esCitas,
    puede: () => true,
  }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => mocks.ruta,
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
}));

const pago = (id: string, persona: string, estado: string) => ({
  id,
  fecha: "2026-10-01T18:00:00Z",
  persona,
  monto_minor: 10000,
  moneda: "MXN",
  estado,
  proveedor: null,
  metodo: "efectivo",
  reembolsado_minor: estado === "reembolsado" ? 10000 : 0,
  reembolsable_minor: estado === "reembolsado" ? 0 : 10000,
});

beforeEach(() => {
  vi.clearAllMocks();
  mocks.ruta.query = { vista: "movimientos" };
  mocks.esCitas = false;
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/pagos")
          ? [
              pago("1", "Ana López", "aprobado"),
              pago("2", "Bea Ruiz", "reembolsado"),
            ]
          : [],
      },
    }),
  );
});

describe("cobros: movimientos", () => {
  it("no suma importes de monedas diferentes en un mismo saldo", async () => {
    mocks.get.mockResolvedValue({
      data: {
        data: [
          pago("1", "Ana", "aprobado"),
          { ...pago("2", "Bea", "aprobado"), moneda: "USD", monto_minor: 2000 },
        ],
      },
    });
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();
    const saldo = w.get('[data-prueba="indicador-cobrado"]').text();
    expect(saldo).toContain("100.00");
    expect(saldo).toContain("MXN");
    expect(saldo).toContain("20.00");
    expect(saldo).toContain("USD");
    expect(saldo).not.toContain("120.00");
  });
  it("un error inicial permite reintentar sin mostrar ceros como si se hubieran cargado", async () => {
    mocks.get.mockRejectedValue(new Error("red"));
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();
    expect(w.text()).toContain("Error");
    expect(w.find('[data-prueba="indicador-cobrado"]').exists()).toBe(false);
    expect(w.text()).not.toContain("No hay pagos");
    mocks.get.mockResolvedValue({
      data: { data: [pago("1", "Ana", "aprobado")] },
    });
    await w
      .findAll("button")
      .find((b) => b.text() === "Reintentar")!
      .trigger("click");
    await flushPromises();
    expect(w.text()).toContain("Ana");
    expect(mocks.post).not.toHaveBeenCalled();
  });
  it("resume lo cobrado y filtra por cliente y por estado", async () => {
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    expect(w.text()).toContain("Cobros aprobados");
    expect(w.text()).toContain("$100.00");
    const filas = () => w.findAll("tbody > tr").map((f) => f.text());
    expect(filas()).toHaveLength(2);

    await w.get('[data-prueba="buscar-pago"]').setValue("bea");
    expect(filas()).toHaveLength(1);
    expect(filas()[0]).toContain("Bea Ruiz");

    await w.get('[data-prueba="buscar-pago"]').setValue("");
    await w
      .findAll("button")
      .find((b) => b.text() === "Aprobados")!
      .trigger("click");
    expect(filas()).toHaveLength(1);
    expect(filas()[0]).toContain("Ana López");
  });
});

describe("cobros: por cobrar", () => {
  it("en citas oculta renovaciones vacías pero conserva los cobros pendientes", async () => {
    mocks.esCitas = true;
    mocks.ruta.query = { vista: "por-cobrar" };
    mocks.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.endsWith("/cobranza/pendientes")
          ? {
              data: [],
              meta: {
                page: 1,
                ultima_pagina: 1,
                total: 0,
                per_page: 20,
                por_cobrar: [],
                proximas: 3,
              },
            }
          : { data: [] },
      }),
    );
    const w = mount(CobranzaView, { global: { plugins: [i18n] } });
    await flushPromises();
    expect(w.text()).toContain("Pendientes de pago");
    expect(w.text()).toContain("3 citas próximas");
    expect(w.text()).not.toContain("Próximas renovaciones");
    expect(w.text()).not.toContain("No hay membresías con cobro recurrente");
  });

  it("muestra lo que ya se debe (como el Inicio) y registra su pago", async () => {
    mocks.ruta.query = { vista: "por-cobrar" };
    const pendiente = {
      id: "o1",
      persona: { id: "p1", nombre: "Ana López" },
      concepto: "Corte",
      total_minor: 25000,
      moneda: "MXN",
      creada_en: "2026-10-01T18:00:00Z",
      sesion: {
        tipo: "cita",
        profesional: "Luis",
        inicia_en: "2026-10-01T18:00:00Z",
        zona_horaria: "America/Mexico_City",
        sucursal: "Centro",
      },
    };
    let pagada = false;
    mocks.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.endsWith("/cobranza/pendientes")
          ? {
              data: pagada ? [] : [pendiente],
              meta: {
                page: 1,
                ultima_pagina: 1,
                total: pagada ? 0 : 1,
                per_page: 20,
                por_cobrar: pagada
                  ? []
                  : [{ moneda: "MXN", total_minor: 25000 }],
                proximas: 3,
              },
            }
          : { data: [] },
      }),
    );
    mocks.post.mockImplementation(() => {
      pagada = true;
      return Promise.resolve({ data: { data: {} } });
    });
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    const tabla = w.get('[data-prueba="pendientes"]');
    expect(tabla.text()).toContain("Ana López");
    expect(tabla.text()).toContain("Corte");
    expect(tabla.text()).toContain("Con Luis");
    expect(w.text()).toContain("$250.00");
    expect(w.text()).toContain("3 citas próximas se cobran al atenderlas.");

    await w.get('[data-prueba="registrar-pago"]').trigger("click");
    await w.get('[data-prueba="confirmar-pago"]').trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/ordenes/o1/liquidar",
      { metodo: "efectivo", referencia: null },
    );
    expect(w.text()).toContain("Pago de Ana López registrado: $250.00.");
    expect(w.text()).toContain("Nada pendiente de pago.");
  });
});
