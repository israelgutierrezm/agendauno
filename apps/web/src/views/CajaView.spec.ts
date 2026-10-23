import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { computed, ref } from "vue";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import caja from "@/i18n/locales/caja.es-MX";
import CajaView from "./CajaView.vue";

/*
| La caja de la sucursal (ADR 0175): recepción la abre con su fondo, mueve efectivo y la
| cuenta a ciegas; si no cuadra, escribe el motivo. Quien supervisa ve lo esperado en
| vivo y revisa los cierres con diferencia.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  confirmar: vi.fn(async () => true),
  exito: vi.fn(),
  permisos: new Set<string>(["caja.operar"]),
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post },
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: mocks.exito, error: vi.fn() }),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    puede: (p: string) => mocks.permisos.has(p),
    slug: "barberia",
    moneda: "MXN",
    pais: "MX",
    zonaHoraria: "America/Mexico_City",
  }),
}));
vi.mock("@/lib/sucursalOperativa", () => ({
  useSucursales: () => ({
    lista: computed(() => [{ id: "S1", nombre: "Roma Norte" }]),
    varias: computed(() => false),
    fija: ref("S1"),
  }),
}));

const TURNO = {
  id: "T1",
  sucursal: { id: "S1", nombre: "Roma Norte" },
  estado: "abierta",
  moneda: "MXN",
  fondo_minor: 50000,
  abierta_por: "Recepción",
  abierta_en: "2026-10-01T15:00:00Z",
  cerrada_por: null,
  cerrada_en: null,
  diferencia_minor: null,
  motivo_diferencia: null,
  requiere_revision: false,
  revisada_por: null,
  revisada_en: null,
  nota_revision: null,
  esperado: null,
  conteos: [],
  movimientos: [],
};

function montar() {
  return mount(CajaView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...es, caja } },
        }),
      ],
      stubs: {
        EncabezadoSeccion: {
          props: ["titulo"],
          template: "<div><h1>{{ titulo }}</h1><slot name='acciones' /></div>",
        },
        ModalDialogo: {
          props: ["abierto", "titulo"],
          template:
            "<div v-if='abierto'><h2>{{ titulo }}</h2><slot /><slot name='pie' /></div>",
        },
        PaginacionListado: true,
      },
    },
  });
}

describe("caja de la sucursal", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.permisos = new Set(["caja.operar"]);
  });

  it("recepción abre la caja con su fondo y la ve a ciegas", async () => {
    mocks.get.mockResolvedValueOnce({
      data: { data: { usa_caja: false, turno: null } },
    });
    mocks.post.mockResolvedValueOnce({ data: { data: TURNO } });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("La caja de Roma Norte está cerrada.");
    expect(w.text()).toContain("pedirá la caja abierta");
    await w.get('[data-prueba="fondo"]').setValue("500");
    await w.get('[data-prueba="abrir-caja"]').trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/barberia/caja/turnos",
      {
        sucursal_id: "S1",
        fondo_minor: 50000,
        nota: null,
      },
    );
    expect(w.find('[data-prueba="esperado"]').exists()).toBe(false);
    expect(w.find('[data-prueba="a-ciegas"]').exists()).toBe(true);
  });

  it("al contar sin cuadrar pide el motivo y cierra", async () => {
    mocks.get.mockResolvedValueOnce({
      data: { data: { usa_caja: true, turno: TURNO } },
    });
    mocks.post
      .mockResolvedValueOnce({
        data: {
          data: {
            ...TURNO,
            estado: "contada",
            diferencia_minor: -5000,
            cuadra: false,
            conteos: [
              {
                metodo: "efectivo",
                esperado_minor: 78000,
                contado_minor: 73000,
                diferencia_minor: -5000,
              },
            ],
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            ...TURNO,
            estado: "cerrada",
            diferencia_minor: -5000,
            requiere_revision: true,
          },
        },
      });
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="contar"]').trigger("click");
    await w.get('[data-prueba="contado-efectivo"]').setValue("730");
    await w.get("#caja-conteo").trigger("submit");
    await flushPromises();

    expect(mocks.confirmar).toHaveBeenCalled();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/barberia/caja/turnos/T1/conteo",
      {
        efectivo_minor: 73000,
        tarjeta_minor: 0,
        transferencia_minor: 0,
        ventanilla_minor: 0,
      },
    );
    expect(w.find('[data-prueba="no-cuadra"]').exists()).toBe(true);
    expect(w.text()).toContain("-$50.00");

    await w
      .get('[data-prueba="motivo-diferencia"]')
      .setValue("Di mal un cambio");
    await w.get('[data-prueba="cerrar-con-motivo"]').trigger("click");
    await flushPromises();

    expect(mocks.post).toHaveBeenLastCalledWith(
      "/api/v1/app/barberia/caja/turnos/T1/cierre",
      {
        motivo: "Di mal un cambio",
      },
    );
    expect(w.get('[data-prueba="ultimo-cierre"]').text()).toContain("-$50.00");
  });

  it("quien supervisa ve lo esperado y revisa los cierres con diferencia", async () => {
    mocks.permisos = new Set(["caja.operar", "caja.supervisar"]);
    const cierre = {
      ...TURNO,
      id: "T0",
      estado: "cerrada",
      diferencia_minor: -5000,
      requiere_revision: true,
      motivo_diferencia: "Di mal un cambio",
    };
    mocks.get
      .mockResolvedValueOnce({
        data: {
          data: {
            usa_caja: true,
            turno: {
              ...TURNO,
              esperado: {
                efectivo: 91000,
                tarjeta: 0,
                transferencia: 89900,
                ventanilla: 0,
              },
            },
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: [cierre],
          meta: { page: 1, ultima_pagina: 1, total: 1, per_page: 20 },
        },
      })
      .mockResolvedValueOnce({ data: { data: cierre } });
    mocks.post.mockResolvedValueOnce({
      data: {
        data: {
          ...cierre,
          revisada_por: "Dueña",
          revisada_en: "2026-10-01T20:00:00Z",
          nota_revision: "Se habló con recepción",
        },
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="esperado"]').text()).toContain("$910.00");
    // «Cierres» es una pestaña de la sección (PestanasSeccion), no una acción.
    await w.get('[data-pestana="cierres"]').trigger("click");
    await flushPromises();
    expect(w.get('[data-prueba="cierres"]').text()).toContain("Por revisar");

    await w.get('[data-prueba="cierres"] tbody tr').trigger("click");
    await flushPromises();
    expect(w.get('[data-prueba="detalle-cierre"]').text()).toContain(
      "Di mal un cambio",
    );
    await w
      .get('[data-prueba="nota-revision"]')
      .setValue("Se habló con recepción");
    await w.get("#caja-revision").trigger("submit");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/barberia/caja/turnos/T0/revision",
      {
        nota: "Se habló con recepción",
      },
    );
    expect(w.get('[data-prueba="detalle-cierre"]').text()).toContain(
      "Revisada por Dueña",
    );
  });

  it("reintentar un retiro que no tuvo respuesta lleva la misma clave (no lo registra dos veces)", async () => {
    mocks.get.mockResolvedValueOnce({
      data: { data: { usa_caja: true, turno: TURNO } },
    });
    mocks.post
      .mockRejectedValueOnce(new Error("Sin red"))
      .mockResolvedValueOnce({ data: { data: TURNO } });
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="retiro"]').trigger("click");
    await w.get('[data-prueba="monto-movimiento"]').setValue("200");
    await w
      .get('[data-prueba="motivo-movimiento"]')
      .setValue("Pago a proveedor");
    await w.get("#caja-movimiento").trigger("submit");
    await flushPromises();
    await w.get("#caja-movimiento").trigger("submit");
    await flushPromises();

    const claves = mocks.post.mock.calls.map(
      ([, cuerpo]) => (cuerpo as { clave: string }).clave,
    );
    expect(claves).toHaveLength(2);
    expect(claves[0]).toBe(claves[1]);
    expect(claves[0]).not.toBe("");
  });
});
