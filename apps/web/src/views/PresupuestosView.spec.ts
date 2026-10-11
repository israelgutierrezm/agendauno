import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PresupuestosView from "./PresupuestosView.vue";

/*
| Presupuestos (ADR 0127): se arman con conceptos del catálogo o libres, el total se
| adelanta con los impuestos del negocio y, aceptado, se convierte en cobro.
*/

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "barberia",
    pais: "MX",
    puede: () => true,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
const confirmar = vi.hoisted(() => vi.fn(async () => true));
vi.mock("@/lib/confirmar", () => ({ confirmar }));

const aceptado = {
  id: "p1",
  folio: "P-0001",
  cliente: "Diego",
  estado: "aceptado",
  vencido: false,
  valido_hasta: "2026-10-26",
  total_minor: 116000,
  moneda: "MXN",
  persona_id: null,
  contacto: { nombre: "Diego", email: null, celular: null },
  sucursal_id: null,
  impuestos: "desglosados",
  tasa_impuesto_bp: 1600,
  tasa_impuesto: "16 %",
  subtotal_minor: 100000,
  descuento_minor: 0,
  impuesto_minor: 16000,
  notas: null,
  lineas: [
    {
      tipo: "libre",
      referencia: null,
      descripcion: "Tratamiento",
      cantidad: 1,
      precio_unitario_minor: 100000,
      descuento_minor: 0,
      subtotal_minor: 100000,
    },
  ],
  editable: false,
  puede_convertir: true,
  orden: null,
};

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/catalogo")
          ? {
              servicios: [
                { id: "s1", nombre: "Corte y barba", precio_minor: 38000 },
              ],
              productos: [],
              articulos: [],
            }
          : url.endsWith("/configuracion")
            ? {
                impuestos: "desglosados",
                tasa_impuesto_bp: 1600,
                vigencia_dias: 15,
                notas: null,
              }
            : url.endsWith("/p1")
              ? aceptado
              : url.endsWith("/sucursales")
                ? []
                : [],
      },
    }),
  );
});

function montar() {
  return mount(PresupuestosView, {
    global: {
      plugins: [i18n],
      stubs: {
        EncabezadoSeccion: { template: "<div><slot name='acciones' /></div>" },
        BuscarPersona: true,
        RouterLink: { template: "<a><slot /></a>" },
        PanelLateral: {
          props: ["abierto", "titulo"],
          template: '<div v-if="abierto"><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

describe("presupuestos", () => {
  it("se arma con un servicio del catálogo y el total suma el IVA desglosado", async () => {
    api.post.mockResolvedValue({
      data: {
        data: {
          ...aceptado,
          estado: "borrador",
          editable: true,
          puede_convertir: false,
        },
      },
    });
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="nuevo-presupuesto"]').trigger("click");

    await w.get('input[type="checkbox"]').setValue(true);
    await w.get('input[placeholder="Nombre"]').setValue("Diego");
    const [, referencia] = w.get('[data-prueba="concepto"]').findAll("select");
    await referencia!.setValue("s1");
    await w.get('[data-prueba="concepto"] input[type="number"]').setValue(2);

    // 2 × $380 = $760 + IVA 16 % = $881.60.
    expect(w.get('[data-prueba="totales-presupuesto"]').text()).toContain(
      "$881.60",
    );

    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/barberia/presupuestos",
      expect.objectContaining({
        persona_id: null,
        nombre_contacto: "Diego",
        lineas: [
          expect.objectContaining({
            tipo: "servicio",
            referencia: "s1",
            descripcion: "Corte y barba",
            cantidad: 2,
            precio_unitario_minor: 38000,
          }),
        ],
      }),
    );
  });

  it("uno aceptado se convierte en cobro tras confirmarlo", async () => {
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/presupuestos")
            ? [aceptado]
            : url.endsWith("/p1")
              ? aceptado
              : url.endsWith("/catalogo")
                ? { servicios: [], productos: [], articulos: [] }
                : url.endsWith("/configuracion")
                  ? {
                      impuestos: "desglosados",
                      tasa_impuesto_bp: 1600,
                      vigencia_dias: 15,
                      notas: null,
                    }
                  : [],
        },
      }),
    );
    api.post.mockResolvedValue({ data: { data: { orden: "o1" } } });
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="fila-presupuesto"]').trigger("click");
    await flushPromises();

    await w.get('[data-prueba="convertir-presupuesto"]').trigger("click");
    await flushPromises();

    expect(confirmar).toHaveBeenCalledWith(
      expect.stringContaining("$1,160.00"),
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/barberia/presupuestos/p1/convertir",
      {},
    );
  });
});
