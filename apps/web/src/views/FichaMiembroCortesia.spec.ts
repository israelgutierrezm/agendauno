import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import FichaMiembroView from "./FichaMiembroView.vue";

/*
| Desde la ficha del alumno, cada una con su permiso, su confirmación y un texto que
| dice qué pasa: dar un plan de cortesía (ADR 0181: sin cobro y con motivo), quitar
| créditos (de lo disponible, con motivo; no devuelve dinero) y corregir la forma de
| pago de una compra cobrada en caja. En citas se habla de bonos y sesiones.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  confirmar: vi.fn(),
  toast: { exito: vi.fn(), error: vi.fn() },
  permisos: new Set<string>(),
  esCitas: false,
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post, put: vi.fn() },
  mensajeDeError: () => "Error",
  erroresPorCampo: () => ({}),
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    pais: "MX",
    moneda: "MXN",
    get esCitas() {
      return mocks.esCitas;
    },
    terminologia: { miembro: "Alumno", instructor: "Instructor" },
    puede: (p: string) => mocks.permisos.has(p),
  }),
}));
vi.mock("@/stores/toast", () => ({ useToastStore: () => mocks.toast }));
vi.mock("@/lib/regreso", () => ({
  useRegreso: () => ({ destino: { name: "miembros" }, etiqueta: "Alumnos" }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { id: "p1" }, query: {} }),
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  onBeforeRouteLeave: vi.fn(),
  RouterLink: { template: "<a><slot /></a>" },
}));

function derecho(extra: Record<string, unknown> = {}) {
  return {
    id: "d1",
    producto: "Paquete 8 clases",
    estado: "activo",
    ilimitado: false,
    saldo_creditos: 4,
    saldo_unidades: 4000,
    disponible_unidades: 4000,
    valido_hasta: "2099-12-31",
    acuerdo_id: "a1",
    pausa_hasta: null,
    ...extra,
  };
}
const PLAN = {
  id: "plan1",
  nombre: "Paquete 8 clases",
  tipo: "paquete",
  precio_minor: 120000,
  moneda: "MXN",
  ilimitado: false,
  creditos_incluidos: 8000,
  unidades_por_ciclo: null,
  archivado: false,
  ofertas: [],
};

function respuestas(
  derechos: unknown[],
  ordenes: unknown[] = [],
  planes: unknown[] = [PLAN],
) {
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve(
      url.endsWith("/productos")
        ? { data: { data: planes } }
        : url.endsWith("/resumen")
          ? {
              data: {
                data: {
                  nombre_completo: "Vale Ruiz",
                  email: null,
                  saldo_creditos: 0,
                  membresia: { estado: "vigente", valido_hasta: null },
                  adeudo: false,
                  documentos_pendientes: 0,
                  asistencias: 0,
                  primera_vez: false,
                  proxima_reserva: null,
                  alertas: [],
                },
              },
            }
          : {
              data: {
                data: {
                  persona: {
                    id: "p1",
                    nombre_completo: "Vale Ruiz",
                    email: null,
                    tipo: "miembro",
                    activo: true,
                    es_facturable: true,
                    archivado: false,
                    alta: null,
                    sucursal: null,
                  },
                  derechos,
                  reservas: [],
                  ordenes,
                },
              },
            },
    ),
  );
}

function montar() {
  return mount(FichaMiembroView, {
    global: {
      plugins: [i18n],
      stubs: {
        PanelMiembro: true,
        VentasMostrador: true,
        PanelEditarMiembro: true,
        CortePlanes: true,
        ExpedientePersona: true,
        AvatarIniciales: true,
        NivelesMiembro: true,
        FamiliaMiembro: true,
        teleport: true,
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  mocks.permisos.clear();
  mocks.esCitas = false;
  mocks.confirmar.mockResolvedValue(true);
  mocks.post.mockResolvedValue({ data: { data: {} } });
});

describe("quitar créditos desde la ficha", () => {
  it("de lo disponible, con motivo y confirmación que dice cuánto le queda", async () => {
    mocks.permisos.add("creditos.gestionar");
    mocks.permisos.add("derechos.ver");
    respuestas([derecho()]);
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="abrir-quitar-creditos"]').trigger("click");
    const form = w.get('[data-prueba="quitar-creditos"]');
    expect(form.text()).toContain("No se devuelve dinero.");
    expect(form.text()).toContain("Disponibles: 4");
    await form.get('input[type="number"]').setValue(1.5);
    await form
      .get('input[maxlength="255"]')
      .setValue("Clase tomada sin reservar");
    await form.trigger("submit");
    await flushPromises();

    expect(mocks.confirmar).toHaveBeenCalledWith(
      "¿Quitar 1.5 créditos de «Paquete 8 clases»? Le quedarán 2.5 disponibles. Queda en sus movimientos con el motivo y no se devuelve dinero.",
      { aceptar: "Quitar créditos", peligro: true },
    );
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/derechos/d1/consumos",
      { unidades: 1500, descripcion: "Clase tomada sin reservar" },
    );
    expect(mocks.toast.exito).toHaveBeenCalledWith("Créditos quitados.");
  });

  it("no deja quitar más de lo disponible ni sin motivo", async () => {
    mocks.permisos.add("creditos.gestionar");
    respuestas([derecho({ disponible_unidades: 1000 })]);
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="abrir-quitar-creditos"]').trigger("click");
    const form = w.get('[data-prueba="quitar-creditos"]');
    await form.get('input[type="number"]').setValue(2);
    await form.get('input[maxlength="255"]').setValue("Ajuste");
    expect(
      form
        .get('[data-prueba="confirmar-quitar-creditos"]')
        .attributes("disabled"),
    ).toBeDefined();
    await form.trigger("submit");
    await flushPromises();
    expect(mocks.confirmar).not.toHaveBeenCalled();
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("sin su permiso, o en un plan ilimitado o vencido, no aparece", async () => {
    respuestas([derecho()]);
    const sinPermiso = montar();
    await flushPromises();
    expect(
      sinPermiso.find('[data-prueba="abrir-quitar-creditos"]').exists(),
    ).toBe(false);
    sinPermiso.unmount();

    mocks.permisos.add("creditos.gestionar");
    respuestas([
      derecho({ id: "d2", ilimitado: true }),
      derecho({ id: "d3", valido_hasta: "2020-01-31" }),
    ]);
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="abrir-quitar-creditos"]').exists()).toBe(
      false,
    );
  });

  it("en citas habla de sesiones del bono", async () => {
    mocks.esCitas = true;
    mocks.permisos.add("creditos.gestionar");
    respuestas([derecho({ producto: "Bono 5 sesiones" })]);
    const w = montar();
    await flushPromises();

    const abrir = w.get('[data-prueba="abrir-quitar-creditos"]');
    expect(abrir.text()).toBe("Quitar sesiones");
    await abrir.trigger("click");
    const form = w.get('[data-prueba="quitar-creditos"]');
    expect(form.text()).toContain("en este bono");
    await form.get('input[type="number"]').setValue(1);
    await form
      .get('input[maxlength="255"]')
      .setValue("Sesión tomada sin agendar");
    await form.trigger("submit");
    await flushPromises();
    expect(mocks.confirmar).toHaveBeenCalledWith(
      expect.stringContaining("¿Quitar 1 sesión de «Bono 5 sesiones»?"),
      { aceptar: "Quitar sesiones", peligro: true },
    );
  });
});

describe("dar un plan de cortesía desde la ficha", () => {
  it("elige el plan, dice que no se cobra, pide motivo y confirma", async () => {
    mocks.permisos.add("membresias.cortesia");
    mocks.permisos.add("productos.ver");
    respuestas([]);
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="abrir-cortesia"]').trigger("click");
    await flushPromises();
    expect(mocks.get).toHaveBeenCalledWith("/api/v1/app/demo/productos");
    expect(w.text()).toContain("Dar un plan de cortesía");
    expect(w.text()).toContain("sin cobro: no queda venta ni pago por cobrar");
    const confirmarBoton = w.get('[data-prueba="confirmar-cortesia"]');
    expect(confirmarBoton.attributes("disabled")).toBeDefined();

    await w.get("#dc-plan").setValue("plan1");
    expect(w.get('[data-prueba="detalle-cortesia"]').text()).toContain(
      "8 clases",
    );
    await w.get("#dc-motivo").setValue("Compensación por clase cancelada");
    await w.get("#dar-cortesia").trigger("submit");
    await flushPromises();

    expect(mocks.confirmar).toHaveBeenCalledWith(
      "¿Dar «Paquete 8 clases» de cortesía a Vale Ruiz? Se activa hoy, sin cobro ni venta, y el motivo queda en la bitácora.",
      { aceptar: "Dar de cortesía" },
    );
    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/acuerdos", {
      persona_id: "p1",
      producto_id: "plan1",
      motivo: "Compensación por clase cancelada",
    });
    expect(mocks.toast.exito).toHaveBeenCalledWith(
      "Listo: Paquete 8 clases quedó activo para Vale Ruiz, sin cobro.",
    );
    expect(w.find("#dar-cortesia").exists()).toBe(false);
  });

  it("si no confirma, no se da", async () => {
    mocks.permisos.add("membresias.cortesia");
    mocks.permisos.add("productos.ver");
    mocks.confirmar.mockResolvedValue(false);
    respuestas([]);
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="abrir-cortesia"]').trigger("click");
    await flushPromises();
    await w.get("#dc-plan").setValue("plan1");
    await w.get("#dc-motivo").setValue("Cortesía de bienvenida");
    await w.get("#dar-cortesia").trigger("submit");
    await flushPromises();
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("sin el permiso de cortesías no aparece", async () => {
    mocks.permisos.add("productos.ver");
    respuestas([]);
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="abrir-cortesia"]').exists()).toBe(false);
  });

  it("en citas se da un bono", async () => {
    mocks.esCitas = true;
    mocks.permisos.add("membresias.cortesia");
    mocks.permisos.add("productos.ver");
    respuestas([]);
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="abrir-cortesia"]').trigger("click");
    await flushPromises();
    expect(w.text()).toContain("Dar un bono de cortesía");
    expect(w.get('label[for="dc-plan"]').text()).toBe("Bono");
  });
});

describe("corregir la forma de pago de una compra", () => {
  const compra = (pago: Record<string, unknown> | null) => ({
    id: "o1",
    fecha: "2030-01-05T10:00:00Z",
    estado: "pagada",
    total_minor: 120000,
    moneda: "MXN",
    metodo_pago: "efectivo",
    pagada_en: "2030-01-05T10:00:00Z",
    concepto: "Paquete 8 clases",
    pago,
  });

  it("quien gestiona órdenes corrige el cobro en caja desde su historial", async () => {
    mocks.permisos.add("ordenes.gestionar");
    respuestas(
      [],
      [
        compra({
          id: "pg1",
          metodo: "efectivo",
          en_caja: true,
          corregible: true,
          anulable: false,
        }),
      ],
    );
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="corregir-compra"]').trigger("click");
    expect(w.find('[data-prueba="corregir-pago"]').exists()).toBe(true);
  });

  it("sin el permiso o sin cobro corregible, no se ofrece", async () => {
    respuestas(
      [],
      [
        compra({
          id: "pg1",
          metodo: "efectivo",
          en_caja: true,
          corregible: true,
          anulable: false,
        }),
      ],
    );
    const sinPermiso = montar();
    await flushPromises();
    expect(sinPermiso.find('[data-prueba="corregir-compra"]').exists()).toBe(
      false,
    );
    sinPermiso.unmount();

    mocks.permisos.add("ordenes.gestionar");
    respuestas([], [compra(null)]);
    const sinPago = montar();
    await flushPromises();
    expect(sinPago.find('[data-prueba="corregir-compra"]').exists()).toBe(
      false,
    );
  });
});
