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
const permisos = vi.hoisted(() => ({ gestionar: true }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "barberia",
    pais: "MX",
    moneda: "MXN",
    puede: (p: string) => p !== "presupuestos.gestionar" || permisos.gestionar,
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
  permisos.gestionar = true;
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

  it("con cambios sin guardar, Enviar primero guarda y confirma lo que se va a enviar", async () => {
    const borrador = {
      ...aceptado,
      estado: "borrador",
      editable: true,
      puede_convertir: false,
      contacto: { nombre: "Diego", email: "diego@correo.mx", celular: null },
    };
    api.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/presupuestos")
            ? [borrador]
            : url.endsWith("/p1")
              ? borrador
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
    // Si guardar falla, no se envía nada y lo capturado se queda.
    api.put.mockRejectedValueOnce(new Error("El servidor no respondió"));
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="fila-presupuesto"]').trigger("click");
    await flushPromises();

    const enviar = () => w.get('[data-prueba="enviar-presupuesto"]');
    expect(enviar().text()).toBe("Enviar por correo");
    await w.get('[data-prueba="precio-concepto"]').setValue("1,500.00");
    expect(enviar().text()).toBe("Guardar y enviar");

    await enviar().trigger("click");
    await flushPromises();
    expect(api.post).not.toHaveBeenCalled();
    expect(w.text()).toContain("El servidor no respondió");
    expect(
      (w.get('[data-prueba="precio-concepto"]').element as HTMLInputElement)
        .value,
    ).toBe("1,500.00");

    // Ahora sí guarda: se envía lo guardado, con su total en la confirmación.
    api.put.mockResolvedValueOnce({
      data: {
        data: {
          ...borrador,
          total_minor: 174000,
          revision: 2,
          lineas: [{ ...borrador.lineas[0]!, precio_unitario_minor: 150000 }],
        },
      },
    });
    api.post.mockResolvedValue({ data: { data: borrador } });
    await enviar().trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenLastCalledWith(
      "/api/v1/app/barberia/presupuestos/p1",
      expect.objectContaining({
        lineas: [expect.objectContaining({ precio_unitario_minor: 150000 })],
      }),
    );
    expect(confirmar).toHaveBeenLastCalledWith(
      expect.stringContaining("$1,740.00 a diego@correo.mx"),
      expect.anything(),
    );
    // Con la revisión que se confirmó: si alguien la cambió, el API no la envía.
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/barberia/presupuestos/p1/enviar",
      { revision: 2 },
    );
  });

  it("un monto que no se puede leer se marca y no se guarda como 0", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="nuevo-presupuesto"]').trigger("click");
    await w.get('input[type="checkbox"]').setValue(true);
    await w.get('input[placeholder="Nombre"]').setValue("Diego");
    // En México, «12,50» se presta a confusión.
    await w.get('[data-prueba="precio-concepto"]').setValue("12,50");
    expect(w.get('[data-prueba="monto-ilegible"]').text()).toContain(
      "1,250.50",
    );
    expect(
      w.get('[data-prueba="precio-concepto"]').attributes("aria-invalid"),
    ).toBe("true");

    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).not.toHaveBeenCalled();
    expect(w.text()).toContain("Hay montos que no se pueden leer");
  });

  it("la tasa de impuestos se valida antes de guardarla", async () => {
    api.put.mockResolvedValue({
      data: {
        data: {
          impuestos: "desglosados",
          tasa_impuesto_bp: 850,
          vigencia_dias: 15,
          notas: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="ajustes-presupuestos"]').trigger("click");
    await w.get("#pr-tasa").setValue("dieciséis");
    await w.get("#form-ajustes-presupuestos").trigger("submit");
    await flushPromises();
    expect(api.put).not.toHaveBeenCalled();
    expect(w.find('[data-prueba="tasa-invalida"]').exists()).toBe(true);

    await w.get("#pr-tasa").setValue("8,5");
    await w.get("#form-ajustes-presupuestos").trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/barberia/presupuestos/configuracion",
      expect.objectContaining({ tasa_impuesto_bp: 850 }),
    );
  });

  it("si el catálogo falla, los ajustes llegan igual; sin permiso de armar, no se pide", async () => {
    api.get.mockImplementation((url: string) =>
      url.endsWith("/catalogo")
        ? Promise.reject(new Error("403"))
        : Promise.resolve({
            data: {
              data: url.endsWith("/configuracion")
                ? {
                    impuestos: "desglosados",
                    tasa_impuesto_bp: 800,
                    vigencia_dias: 15,
                    notas: null,
                  }
                : [],
            },
          }),
    );
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="ajustes-presupuestos"]').trigger("click");
    expect((w.get("#pr-tasa").element as HTMLInputElement).value).toBe("8");
    w.unmount();

    api.get.mockClear();
    permisos.gestionar = false;
    montar();
    await flushPromises();
    const urls = api.get.mock.calls.map((c) => String(c[0]));
    expect(urls.some((u) => u.endsWith("/presupuestos/catalogo"))).toBe(false);
    expect(urls.some((u) => u.endsWith("/presupuestos/configuracion"))).toBe(
      true,
    );
  });
});
