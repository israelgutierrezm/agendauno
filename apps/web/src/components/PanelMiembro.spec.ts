import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import { avisosWhatsApp } from "@/i18n/locales/gestion.es-MX";
import confirmaciones from "@/i18n/locales/confirmaciones.es-MX";
import planes from "@/i18n/locales/planes.es-MX";
import PanelMiembro from "./PanelMiembro.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const permisos = vi.hoisted(() => ({
  lista: ["miembros.gestionar"],
  esCitas: false,
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    terminologia: { miembro: "Cliente" },
    esCitas: permisos.esCitas,
    puede: (p: string) => permisos.lista.includes(p),
  }),
}));

function resumen(whatsapp: Record<string, boolean> | undefined) {
  return {
    data: {
      data: {
        id: "caro",
        nombre_completo: "Caro López",
        email: null,
        tipo: "miembro",
        activo: true,
        asistencias: 3,
        primera_vez: false,
        saldo_creditos: 0,
        saldo_unidades: 0,
        membresia: { estado: "sin_membresia", valido_hasta: null },
        adeudo: false,
        documentos_pendientes: 0,
        proxima_reserva: null,
        alertas: [],
        whatsapp,
      },
    },
  };
}

function montar() {
  return mount(PanelMiembro, {
    props: { personaId: "caro", nombre: "Caro", incrustado: true },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...es, avisosWhatsApp, planes, confirmaciones } },
        }),
      ],
      stubs: { RouterLink: true },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  permisos.lista = ["miembros.gestionar"];
  permisos.esCitas = false;
});

describe("resumen adaptado a citas o clases", () => {
  it("en citas no muestra la membresía inexistente ni un saldo vacío", async () => {
    permisos.esCitas = true;
    api.get.mockResolvedValue(resumen(undefined));
    const w = montar();
    await flushPromises();
    expect(w.find(".md-pastilla").exists()).toBe(false);
    expect(w.find('[data-prueba="saldo-panel"]').exists()).toBe(false);
    expect(w.text()).toContain("Próxima reserva");
  });

  it("en citas conserva los créditos reales y un plan vigente", async () => {
    permisos.esCitas = true;
    const datos = resumen(undefined);
    datos.data.data.saldo_creditos = 2;
    datos.data.data.saldo_unidades = 2000;
    datos.data.data.membresia.estado = "vigente";
    api.get.mockResolvedValue(datos);
    const w = montar();
    await flushPromises();
    expect(w.find(".md-pastilla").exists()).toBe(true);
    expect(w.get('[data-prueba="saldo-panel"]').text()).toContain("2");
  });

  it("un saldo con fracción no se trunca (1.5 créditos, no 1)", async () => {
    const datos = resumen(undefined);
    datos.data.data.saldo_creditos = 1;
    datos.data.data.saldo_unidades = 1500;
    api.get.mockResolvedValue(datos);
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="saldo-panel"]').text()).toContain("1.5");
  });

  it("en clases sigue mostrando el saldo aunque no haya un plan", async () => {
    api.get.mockResolvedValue(resumen(undefined));
    const w = montar();
    await flushPromises();
    expect(w.find(".md-pastilla").exists()).toBe(true);
    expect(w.find('[data-prueba="saldo-panel"]').exists()).toBe(true);
  });
});

describe("avisos por WhatsApp en la ficha de recepción", () => {
  it("si el negocio no los usa, no aparecen", async () => {
    api.get.mockResolvedValue(
      resumen({ disponible: false, acepta: false, con_celular: true }),
    );
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="whatsapp"]').exists()).toBe(false);
  });

  it("recepción marca que el cliente los pidió", async () => {
    api.get.mockResolvedValue(
      resumen({ disponible: true, acepta: false, con_celular: true }),
    );
    api.put.mockResolvedValue({ data: { data: { acepta_whatsapp: true } } });
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="acepta-whatsapp"]').setValue(true);
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/miembros/caro", {
      acepta_whatsapp: true,
    });
    expect(
      (w.get('[data-prueba="acepta-whatsapp"]').element as HTMLInputElement)
        .checked,
    ).toBe(true);
  });

  it("sin celular lo dice, y sin permiso para editar no se puede cambiar", async () => {
    api.get.mockResolvedValue(
      resumen({ disponible: true, acepta: false, con_celular: false }),
    );
    const sinCelular = montar();
    await flushPromises();
    expect(sinCelular.get('[data-prueba="whatsapp"]').text()).toContain(
      "Falta su celular",
    );

    permisos.lista = [];
    api.get.mockResolvedValue(
      resumen({ disponible: true, acepta: true, con_celular: true }),
    );
    const soloVer = montar();
    await flushPromises();
    expect(
      soloVer.get('[data-prueba="acepta-whatsapp"]').attributes("disabled"),
    ).toBeDefined();
  });
});

const confirmar = vi.hoisted(() => vi.fn(() => Promise.resolve(false)));
vi.mock("@/lib/confirmar", () => ({ confirmar }));

describe("venta rápida", () => {
  const plan = {
    id: "p1",
    nombre: "Mensual con permanencia",
    tipo: "membresia",
    precio_minor: 100000,
    moneda: "MXN",
    ilimitado: true,
    creditos_incluidos: null,
    vigencia_tipo: "meses",
    vigencia_cantidad: 1,
    politica_reset: "aniversario",
    unidades_por_ciclo: null,
    politica_rollover: "ninguno",
    rollover_max: null,
    sucursal_id: null,
    todas_sucursales: false,
    sucursales: [{ id: "s1", nombre: "Roma" }],
    archivado: false,
    ofertas: [],
    cobra_inscripcion: true,
    permanencia_meses: 6,
    condiciones: "Permanencia de 6 meses.",
  };

  it("dice dónde vale, suma la inscripción al total y pide aceptar las condiciones", async () => {
    permisos.lista = [
      "miembros.gestionar",
      "ordenes.gestionar",
      "productos.ver",
    ];
    api.get.mockImplementation((url: string) =>
      Promise.resolve(
        url.endsWith("/productos")
          ? { data: { data: [plan] } }
          : url.endsWith("/cuotas")
            ? { data: { data: { inscripcion_minor: 50000 } } }
            : resumen(undefined),
      ),
    );
    const w = montar();
    await flushPromises();
    await w
      .findAll("button")
      .find((b) => b.text().includes("Vender"))!
      .trigger("click");
    await flushPromises();
    await w.get("select").setValue("p1");
    await flushPromises();

    const detalle = w.get('[data-prueba="detalle-venta"]');
    expect(detalle.text()).toContain("Solo en Roma");
    expect(detalle.text()).toContain("Permanencia");
    expect(w.get('[data-prueba="inscripcion-venta"]').text()).toContain(
      "1,500",
    );
    // Sin aceptar las condiciones no se cobra.
    const cobrar = w.findAll("button").find((b) => b.text() === "Cobrar")!;
    expect(cobrar.attributes("disabled")).toBeDefined();
    await w.get('[data-prueba="condiciones-venta"] input').setValue(true);
    expect(cobrar.attributes("disabled")).toBeUndefined();

    // Lo que se confirma es lo que se cobra: el plan más su inscripción.
    await cobrar.trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalledWith(
      expect.stringContaining("1,500"),
      expect.anything(),
    );
  });
});
