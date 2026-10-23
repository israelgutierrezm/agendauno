import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import TimbresView from "./TimbresView.vue";

/*
| Timbres para facturar, en su pantalla: saldo, uso por mes y para cuánto alcanzan,
| compra (con regreso a esta pantalla) y todos los movimientos por páginas, cada uno
| con su factura o su compra; filtros en el servidor.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
const permisos = vi.hoisted(() => ({ lista: null as string[] | null }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/retornoPago", async (original) => {
  const { ref } = await import("vue");
  return {
    ...(await original<typeof import("@/lib/retornoPago")>()),
    useConfirmacionPago: () => ref(null),
  };
});
vi.mock("vue-router", () => ({
  useRoute: () => undefined,
  useRouter: () => undefined,
  RouterLink: { template: "<a><slot /></a>" },
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    puede: (p: string) => permisos.lista === null || permisos.lista.includes(p),
    zonaHoraria: "America/Mexico_City",
  }),
}));

const meses = Array.from({ length: 12 }, (_, i) => ({
  mes: `2026-${String(i + 1).padStart(2, "0")}`,
  usados: i === 9 ? 6 : 0,
  comprados: i === 5 ? 50 : 0,
}));
function timbres(extra: Record<string, unknown> = {}) {
  return {
    disponibles: 44,
    precio_timbre_minor: 180,
    iva_porcentaje: 16,
    moneda: "MXN",
    paquetes: [
      { cantidad: 50, precio_minor: 9000 },
      { cantidad: 100, precio_minor: 18000 },
    ],
    posible: true,
    motivo: null,
    movimientos: [],
    resumen: {
      usados_mes: 6,
      usados_mes_anterior: 0,
      promedio_mensual: 0,
      alcanza_meses: 7,
      por_mes: meses,
    },
    compra_pendiente: null,
    ...extra,
  };
}
const movimientos = [
  {
    id: "m2",
    tipo: "consumo",
    cantidad: -1,
    saldo_despues: 44,
    detalle: null,
    fecha: "2026-10-02T23:00:00Z",
    factura: {
      id: "f1",
      cliente: "Ana Pérez",
      rfc: "PEAA800101AB1",
      total_minor: 139999,
      moneda: "MXN",
      estado: "timbrada",
      uuid: "UUID-1",
    },
    compra: null,
  },
  {
    id: "m1",
    tipo: "compra",
    cantidad: 50,
    saldo_despues: 45,
    detalle: "Paquete de 50 timbres",
    fecha: "2026-06-15T15:00:00Z",
    factura: null,
    compra: { id: "c1", monto_minor: 10440, moneda: "MXN" },
  },
];

function responder(datos: Record<string, unknown> = timbres()) {
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/timbres/movimientos")
        ? {
            data: movimientos,
            meta: { total: 45, page: 1, per_page: 20, ultima_pagina: 3 },
          }
        : { data: datos },
    }),
  );
}
function montar() {
  return mount(TimbresView, {
    global: {
      plugins: [i18n],
      stubs: { Teleport: true, RouterLink: { template: "<a><slot /></a>" } },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  permisos.lista = null;
  responder();
  api.post.mockResolvedValue({ data: { data: {} } });
});

describe("timbres para facturar", () => {
  it("muestra el saldo, el uso por mes y cada movimiento con su factura o su compra", async () => {
    const w = montar();
    await flushPromises();

    const indicadores = w.get('[data-prueba="indicadores-timbres"]').text();
    expect(indicadores).toContain("44");
    expect(indicadores).toContain("~7 meses");
    // Sin meses completos con uso no hay promedio.
    expect(indicadores).toContain("—");
    expect(w.findAll('[data-prueba="uso-por-mes"] li')).toHaveLength(12);
    expect(w.get('[data-prueba="total-con-iva"]').text()).toContain("$104.40");

    const filas = w.findAll('[data-prueba="fila-timbre"]');
    expect(filas).toHaveLength(2);
    expect(filas[0].text()).toContain("Ana Pérez");
    expect(filas[0].text()).toContain("PEAA800101AB1");
    expect(filas[0].text()).toContain("$1,399.99");
    expect(filas[0].text()).toContain("−1");
    expect(filas[1].text()).toContain("Paquete de 50 timbres");
    expect(filas[1].text()).toContain("$104.40");
    expect(filas[1].text()).toContain("+50");
  });

  it("filtra por tipo y pagina en el servidor", async () => {
    const w = montar();
    await flushPromises();
    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/demo/timbres/movimientos",
      { params: { page: 1, per_page: 20 } },
    );

    await w.get('[data-prueba="timbres-compra"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/timbres/movimientos",
      { params: { tipo: "compra", page: 1, per_page: 20 } },
    );

    const pagina2 = w.findAll("button").find((b) => b.text() === "2");
    await pagina2!.trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/timbres/movimientos",
      { params: { tipo: "compra", page: 2, per_page: 20 } },
    );
  });

  it("compra un paquete y pide volver a esta pantalla", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="paquete"]').setValue("100");
    await w.get('[data-prueba="comprar-timbres"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/timbres/comprar", {
      cantidad: 100,
      retorno: "/renta/timbres",
    });
  });

  it("una compra sin pagar se paga desde aquí", async () => {
    responder(
      timbres({
        compra_pendiente: {
          id: "cargo1",
          cantidad: 100,
          monto_minor: 20880,
          moneda: "MXN",
        },
      }),
    );
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="compra-pendiente"]').text()).toContain(
      "$208.80",
    );
    await w.get('[data-prueba="pagar-pendiente"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/renta/cargos/cargo1/pagar",
      // Vuelve a Timbres, no a Renta (con el aviso de la renta).
      { retorno: "/renta/timbres" },
    );
  });

  it("sin permiso para comprar, ve el resumen pero no el botón", async () => {
    permisos.lista = ["facturacion.ver"];
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="comprar-timbres"]').exists()).toBe(false);
    expect(w.text()).toContain(
      "Solo quien administra el negocio puede comprar timbres.",
    );
  });

  it("sin acceso a la suscripción no pide nada", async () => {
    permisos.lista = [];
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="no-disponible"]').exists()).toBe(true);
    expect(api.get).not.toHaveBeenCalled();
  });
});
