import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import CobranzaView from "./CobranzaView.vue";

/*
| Cobros con el patrón de los listados: indicadores de cada vista, y en movimientos
| búsqueda por cliente, filtro por estado y por fechas y páginas (las resuelve el API).
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  esCitas: false,
  ruta: { query: { vista: "movimientos" } as Record<string, string> },
  replace: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post, put: vi.fn(), delete: vi.fn() },
  mensajeDeError: () => "Error",
  erroresPorCampo: (e: unknown) =>
    (e as { campos?: Record<string, string> })?.campos ?? {},
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: mocks.esCitas,
    moneda: "MXN",
    zonaHoraria: "America/Mexico_City",
    puede: () => true,
  }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => mocks.ruta,
  useRouter: () => ({ replace: mocks.replace }),
  RouterLink: {
    props: ["to"],
    template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
  },
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

// El resumen de todo lo encontrado, como lo da el API.
const resumen = (
  cobrado: { moneda: string; total_minor: number }[],
  extra: Record<string, unknown> = {},
) => ({
  total: 2,
  page: 1,
  per_page: 25,
  ultima_pagina: 1,
  resumen: { cobros: 1, cobrado, reembolsado: [], sin_aprobar: 1, ...extra },
  ...extra,
});

beforeEach(() => {
  vi.clearAllMocks();
  mocks.ruta.query = { vista: "movimientos" };
  mocks.esCitas = false;
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/pagos")
        ? {
            data: [
              pago("1", "Ana López", "aprobado"),
              pago("2", "Bea Ruiz", "reembolsado"),
            ],
            meta: resumen([{ moneda: "MXN", total_minor: 10000 }]),
          }
        : { data: [] },
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
        meta: resumen([
          { moneda: "MXN", total_minor: 10000 },
          { moneda: "USD", total_minor: 2000 },
        ]),
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
  it("el reembolso que no pasa se explica dentro del diálogo, junto a su campo", async () => {
    mocks.post.mockRejectedValueOnce({
      campos: { monto_minor: "El monto excede lo reembolsable." },
    });
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();
    // La hora del cobro, en la zona del negocio (18:00 UTC = 12:00 en CDMX).
    expect(w.text()).toContain("12:00");

    await w
      .findAll("button")
      .find((b) => b.text() === "Reembolsar")!
      .trigger("click");
    const dialogo = w.get('[role="dialog"]');
    expect(dialogo.get("#rm").attributes("max")).toBe("100");
    await dialogo.get("#rmt").setValue("Cobro doble");
    await dialogo.get("#form-reembolso").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledTimes(1);
    expect(w.get('[data-prueba="error-reembolso"]').text()).toBe(
      "Revisa los campos marcados.",
    );
    expect(w.get("#rm").attributes("aria-invalid")).toBe("true");
    expect(w.get("#rm-error").text()).toBe("El monto excede lo reembolsable.");
  });
  it("resume lo cobrado y pide al API por cliente, por estado y por fechas", async () => {
    vi.useFakeTimers();
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    expect(w.text()).toContain("Cobros aprobados");
    expect(w.get('[data-prueba="indicador-cobrado"]').text()).toContain(
      "$100.00",
    );
    expect(w.findAll("tbody > tr")).toHaveLength(2);

    await w.get('[data-prueba="buscar-pago"]').setValue("bea");
    await vi.advanceTimersByTimeAsync(300);
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/pagos", {
      params: { page: 1, q: "bea" },
    });

    await w.get('[data-prueba="buscar-pago"]').setValue("");
    await vi.advanceTimersByTimeAsync(300);
    await w
      .findAll("button")
      .find((b) => b.text() === "Aprobados")!
      .trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/pagos", {
      params: { page: 1, estado: "aprobado" },
    });

    await w.get('[data-prueba="pagos-desde"]').setValue("2026-10-01");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/pagos", {
      params: { page: 1, estado: "aprobado", desde: "2026-10-01" },
    });
    vi.useRealTimers();
  });

  it("pagina los movimientos y, sin resultados con filtros, deja quitarlos", async () => {
    mocks.get.mockImplementation(
      (url: string, config?: { params?: Record<string, unknown> }) =>
        Promise.resolve({
          data: url.endsWith("/pagos")
            ? config?.params?.q
              ? { data: [], meta: { ...resumen([]), total: 0 } }
              : {
                  data: [pago("1", "Ana López", "aprobado")],
                  meta: { ...resumen([]), total: 60, ultima_pagina: 3 },
                }
            : { data: [] },
        }),
    );
    vi.useFakeTimers();
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();
    expect(w.text()).toContain("1–25 de 60");

    await w
      .findAll("nav button")
      .find((b) => b.text() === "2")!
      .trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/pagos", {
      params: { page: 2 },
    });

    await w.get('[data-prueba="buscar-pago"]').setValue("zz");
    await vi.advanceTimersByTimeAsync(300);
    expect(w.text()).toContain("Ningún movimiento coincide");
    // La barra sigue a la vista: se puede limpiar la búsqueda.
    await w
      .findAll("button")
      .find((b) => b.text() === "Limpiar")!
      .trigger("click");
    await vi.advanceTimersByTimeAsync(300);
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/pagos", {
      params: { page: 1 },
    });
    vi.useRealTimers();
  });

  it("los filtros van en la dirección: se leen al abrir y cada cambio la actualiza", async () => {
    mocks.ruta.query = {
      vista: "movimientos",
      q: "ana",
      estado: "aprobado",
      desde: "2026-10-01",
      hasta: "no-es-fecha",
    };
    mocks.get.mockResolvedValue({
      data: { data: [], meta: { ...resumen([]), total: 0 } },
    });
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    // Lo que no es fecha se ignora.
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/demo/pagos", {
      params: { page: 1, q: "ana", estado: "aprobado", desde: "2026-10-01" },
    });
    await w
      .findAll("button")
      .find((b) => b.text() === "Todos")!
      .trigger("click");
    await flushPromises();
    // La vista se conserva y lo que no era fecha se quita.
    expect(mocks.replace).toHaveBeenLastCalledWith({
      query: { vista: "movimientos", q: "ana", desde: "2026-10-01" },
    });
  });

  it("descarga en CSV lo encontrado con los mismos filtros", async () => {
    globalThis.URL.createObjectURL = vi.fn(() => "blob:x");
    globalThis.URL.revokeObjectURL = vi.fn();
    mocks.get.mockImplementation(
      (url: string, config?: { params?: Record<string, unknown> }) =>
        Promise.resolve({
          data:
            config?.params?.formato === "csv"
              ? new Blob(["Fecha"])
              : url.endsWith("/pagos")
                ? {
                    data: [pago("1", "Ana López", "aprobado")],
                    meta: { ...resumen([]), total: 1 },
                  }
                : { data: [] },
        }),
    );
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    await w.get('[data-prueba="pagos-desde"]').setValue("2026-10-01");
    await flushPromises();
    await w.get('[data-prueba="descargar-pagos"]').trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/app/demo/pagos", {
      params: { formato: "csv", desde: "2026-10-01" },
      responseType: "blob",
    });
  });
});

describe("cobros: facturar la venta (ADR 0179)", () => {
  it("un cobro aprobado se factura o lleva a su factura", async () => {
    mocks.get.mockResolvedValue({
      data: {
        data: [
          { ...pago("1", "Ana", "aprobado"), orden: "O1", facturable: true },
          {
            ...pago("2", "Bea", "aprobado"),
            orden: "O2",
            factura: { id: "F2", uuid: "U-2" },
          },
          { ...pago("3", "Caro", "aprobado"), orden: "O3", facturable: false },
        ],
        meta: resumen([{ moneda: "MXN", total_minor: 30000 }]),
      },
    });
    const w = mount(CobranzaView, {
      global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    const facturar = w.findAll('[data-prueba="pago-facturar"]');
    expect(facturar).toHaveLength(1);
    expect(JSON.parse(facturar[0]!.attributes("data-to")!)).toEqual({
      name: "facturas",
      query: { orden: "O1" },
    });
    const facturada = w.get('[data-prueba="pago-facturado"]');
    expect(facturada.text()).toBe("Facturada");
    expect(JSON.parse(facturada.attributes("data-to")!)).toEqual({
      name: "facturas",
      query: { q: "U-2" },
    });
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
