import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import BuscarPersona from "@/components/BuscarPersona.vue";
import { i18n } from "@/i18n";
import PosView from "./PosView.vue";

/*
| Mostrador: se vende lo que hay en la sucursal, a una persona del negocio (con su
| ticket por correo si se marca) o a público en general (ADR 0145). Las ventas
| recientes muestran la forma de pago a la vista (no el valor interno), las anuladas
| marcadas, y una venta con error se corrige por su propia ruta (ADR 0089).
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  confirmar: vi.fn(),
  exito: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, put: mocks.put, post: mocks.post },
  mensajeDeError: () => "Error",
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  puede: () => true,
  moneda: "MXN",
  pais: "MX",
  zonaHoraria: "America/Mexico_City",
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: mocks.exito, error: vi.fn() }),
}));

const VENTAS = [
  {
    id: "v1",
    sucursal: "Roma Norte",
    comprador: {
      id: "p1",
      nombre: "Valeria Ríos",
      tipo: "miembro",
      genero: "mujer",
    },
    ticket_enviado_en: "2026-10-01T18:00:05Z",
    total_minor: 56000,
    moneda: "MXN",
    metodo_pago: "efectivo",
    creado_en: "2026-10-01T18:00:00Z",
    lineas: [{ articulo: "Pomada mate", cantidad: 2 }],
    corregible: true,
    anulable: true,
  },
  {
    id: "v2",
    sucursal: "Roma Norte",
    comprador: null,
    total_minor: 23000,
    moneda: "MXN",
    metodo_pago: "tarjeta",
    creado_en: "2026-10-01T17:00:00Z",
    lineas: [{ articulo: "Cera brillante", cantidad: 1 }],
    anulada_en: "2026-10-01T17:10:00Z",
    motivo_anulacion: "Se registró dos veces",
    corregible: false,
    anulable: false,
  },
];
const META = {
  total: 2,
  page: 1,
  per_page: 20,
  ultima_pagina: 1,
  resumen: { ventas: 1, vendido_minor: 56000, propinas_minor: 0 },
  hoy: { ventas: 3, vendido_minor: 150000, propinas_minor: 0 },
};

const ARTICULOS = [
  {
    id: "a1",
    nombre: "Pomada mate",
    sku: "PM-1",
    precio_minor: 28000,
    moneda: "MXN",
    activo: true,
    stock_total: 2,
    existencias: [{ sucursal_id: "s1", sucursal: "Roma Norte", stock: 2 }],
  },
];

function respuestas(articulos: unknown[], ventas: unknown[] = VENTAS) {
  mocks.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: url.endsWith("/pos/ventas")
        ? { data: ventas, meta: { ...META, total: ventas.length } }
        : {
            data: url.endsWith("/sucursales")
              ? [{ id: "s1", nombre: "Roma Norte" }]
              : url.endsWith("/articulos")
                ? articulos
                : [],
          },
    }),
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  sesion.moneda = "MXN";
  sesion.pais = "MX";
  mocks.confirmar.mockResolvedValue(true);
  mocks.put.mockResolvedValue({ data: {} });
  respuestas(ARTICULOS);
});

// El diálogo de extras, en línea (sin teleport) para encontrarlo en la prueba.
function montarConDialogo() {
  return mount(PosView, {
    global: {
      plugins: [i18n],
      stubs: {
        ModalDialogo: {
          props: ["abierto", "titulo"],
          template: '<div v-if="abierto"><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

// Una proteína preparada con sus extras (ADR 0139).
const PROTEINA = {
  id: "a2",
  nombre: "Proteína preparada",
  sku: null,
  precio_minor: 6000,
  moneda: "MXN",
  activo: true,
  stock_total: 10,
  existencias: [{ sucursal_id: "s1", sucursal: "Roma Norte", stock: 10 }],
  extras: [
    { id: "e1", nombre: "Fresas", precio_minor: 1500 },
    { id: "e2", nombre: "Leche de soya", precio_minor: 1000 },
  ],
};

describe("extras, propina y cambio (ADR 0139)", () => {
  beforeEach(() => {
    respuestas([PROTEINA], []);
    mocks.post.mockResolvedValue({ data: { data: { cambio_minor: 1500 } } });
  });

  it("un producto con extras pregunta cuáles lleva y los cobra con su precio", async () => {
    const w = montarConDialogo();
    await flushPromises();

    await w.get('[data-prueba="producto"]').trigger("click");
    const opciones = w.findAll('[data-prueba="extras-elegir"] button');
    expect(opciones.map((o) => o.text())).toEqual([
      "Fresas · $15.00",
      "Leche de soya · $10.00",
    ]);
    await opciones[0]!.trigger("click");
    await w.get('[data-prueba="extras-agregar"]').trigger("click");

    const linea = w.get('[data-prueba="linea-venta"]');
    expect(linea.text()).toContain("con Fresas");
    expect(w.get('[data-prueba="total-cobrar"]').text()).toBe("$75.00");
  });

  it("la propina va aparte, sin «para quién»; lo recibido da el cambio", async () => {
    const w = montarConDialogo();
    await flushPromises();

    await w.get('[data-prueba="producto"]').trigger("click");
    await w.get('[data-prueba="extras-agregar"]').trigger("click");
    // 10 % de $60 = $6; total a cobrar $66; paga con $100: cambio $34.
    await w
      .findAll('[data-prueba="propina"] button')
      .find((b) => b.text() === "10 %")!
      .trigger("click");
    expect(w.find('[data-prueba="propina"] select').exists()).toBe(false);
    expect(w.get('[data-prueba="total-cobrar"]').text()).toBe("$66.00");
    await w.get('[data-prueba="recibido"]').setValue("50");
    expect(w.get('[data-prueba="cambio"]').text()).toBe("Falta $16.00");
    expect(
      w.get('[data-prueba="cobrar"]').attributes("disabled"),
    ).toBeDefined();
    await w.get('[data-prueba="recibido"]').setValue("100");
    expect(w.get('[data-prueba="cambio"]').text()).toBe("Cambio: $34.00");

    await w.get('[data-prueba="cobrar"]').trigger("click");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/pos/ventas", {
      sucursal_id: "s1",
      metodo_pago: "efectivo",
      items: [{ articulo_id: "a2", cantidad: 1, extras: [] }],
      propina_minor: 600,
      recibido_minor: 10000,
      comprador_id: null,
      enviar_ticket: false,
    });
  });
});

describe("a quién se le vende (ADR 0145)", () => {
  async function elegir(
    w: ReturnType<typeof mount>,
    persona: Record<string, unknown>,
  ): Promise<void> {
    const buscador = w.findComponent(BuscarPersona);
    buscador.vm.$emit("update:modelValue", persona.id);
    buscador.vm.$emit("elegir", persona);
    await flushPromises();
  }

  it("sin elegir a nadie es público en general y no ofrece ticket", async () => {
    mocks.post.mockResolvedValue({ data: {} });
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(w.get('[data-prueba="comprador"]').text()).toContain(
      "venta a público en general",
    );
    expect(w.find('[data-prueba="enviar-ticket"]').exists()).toBe(false);
    expect(w.findComponent(BuscarPersona).props("buscarEn")).toBe(
      "/api/v1/app/demo/pos/compradores",
    );
    await w.get('[data-prueba="producto"]').trigger("click");
    await w.get('[data-prueba="cobrar"]').trigger("click");
    await flushPromises();
    expect(String(mocks.confirmar.mock.calls[0]![0])).toContain(
      "Se registra como venta a público en general.",
    );
  });

  it("a una persona con correo le manda su ticket, salvo que se desmarque", async () => {
    mocks.post.mockResolvedValue({
      data: {
        data: { cambio_minor: null, ticket_enviado_en: "2026-10-01T18:00Z" },
      },
    });
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    await elegir(w, {
      id: "persona:p1",
      nombre: "Valeria Ríos",
      email: "vale@correo.mx",
      tipo: "miembro",
    });
    const ticket = w.get('[data-prueba="enviar-ticket"]');
    expect(ticket.text()).toContain("A vale@correo.mx");
    expect((ticket.get("input").element as HTMLInputElement).checked).toBe(
      true,
    );

    await w.get('[data-prueba="producto"]').trigger("click");
    await w.get('[data-prueba="cobrar"]').trigger("click");
    await flushPromises();
    expect(String(mocks.confirmar.mock.calls[0]![0])).toContain(
      "Se registra como venta a Valeria Ríos.",
    );
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/pos/ventas",
      expect.objectContaining({
        comprador_id: "persona:p1",
        enviar_ticket: true,
      }),
    );
    expect(mocks.exito).toHaveBeenCalledWith(
      "Venta registrada. Le mandamos su ticket a vale@correo.mx.",
    );
    // Vendido, la siguiente venta empieza otra vez en público en general.
    expect(w.find('[data-prueba="enviar-ticket"]').exists()).toBe(false);
  });

  it("sin correo no se puede marcar el ticket", async () => {
    mocks.post.mockResolvedValue({ data: {} });
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    await elegir(w, { id: "usuario:u1", nombre: "Toño", tipo: "staff" });
    const ticket = w.get('[data-prueba="enviar-ticket"]');
    expect(ticket.text()).toContain("No tiene correo registrado");
    expect(ticket.get("input").attributes("disabled")).toBeDefined();

    await w.get('[data-prueba="producto"]').trigger("click");
    await w.get('[data-prueba="cobrar"]').trigger("click");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/pos/ventas",
      expect.objectContaining({
        comprador_id: "usuario:u1",
        enviar_ticket: false,
      }),
    );
  });
});

describe("ventas recientes del mostrador", () => {
  it("dice a quién se le vendió, la forma de pago con su nombre y marca las anuladas", async () => {
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    const filas = w.findAll('[data-prueba="venta"]');
    expect(filas[0]!.text()).toContain("Efectivo");
    expect(filas[0]!.text()).toContain("2 × Pomada mate");
    expect(filas[0]!.text()).toContain("Valeria Ríos");
    // Con el término del negocio, en su género.
    expect(filas[0]!.get('[data-prueba="venta-comprador"]').text()).toContain(
      "Alumna",
    );
    expect(filas[0]!.get('[data-prueba="ticket-enviado"]').text()).toBe(
      "Ticket enviado",
    );
    expect(filas[1]!.text()).toContain("Público en general");
    expect(w.get('[data-prueba="venta-anulada"]').text()).toBe("Anulada");
    expect(w.findAll('[data-prueba="corregir-venta"]')).toHaveLength(1);
  });

  it("los indicadores del día vienen del servidor (hoy en el negocio)", async () => {
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    const texto = w.text();
    expect(texto).toContain("Ventas de hoy3");
    expect(texto).toContain("Vendido hoy$1,500.00");
    expect(texto).toContain("Ticket promedio$500.00");
  });

  it("corrige la forma de pago por la ruta de la venta", async () => {
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    await w.get('[data-prueba="corregir-venta"]').trigger("click");
    await w.get('[data-prueba="corregir-pago"]').trigger("click");
    await w.get('[data-prueba="venta-actual"]'); // la venta y su corrección conviven
    const metodo = w
      .findAll("select")
      .find((s) => s.text().includes("Transferencia"))!;
    await metodo.setValue("tarjeta");
    await w
      .findAll("button")
      .find((b) => b.text() === "Guardar forma de pago")!
      .trigger("click");
    await flushPromises();

    expect(mocks.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/pos/ventas/v1/metodo",
      { metodo: "tarjeta" },
    );
  });

  it("no deja vender más de lo que hay en la sucursal y cobra con la forma elegida", async () => {
    mocks.post.mockResolvedValue({ data: {} });
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    const producto = w.get('[data-prueba="producto"]');
    await producto.trigger("click");
    await producto.trigger("click");
    // Solo hay 2: el tercero ya no se puede agregar.
    expect(producto.attributes("disabled")).toBeDefined();
    await w
      .findAll("button")
      .find((b) => b.text() === "Tarjeta")!
      .trigger("click");
    await w.get('[data-prueba="cobrar"]').trigger("click");
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledWith("/api/v1/app/demo/pos/ventas", {
      sucursal_id: "s1",
      metodo_pago: "tarjeta",
      items: [{ articulo_id: "a1", cantidad: 2, extras: [] }],
      propina_minor: 0,
      recibido_minor: null,
      comprador_id: null,
      enviar_ticket: false,
    });
  });

  it("el total y la confirmación van en la moneda del negocio, no en pesos", async () => {
    sesion.moneda = "EUR";
    sesion.pais = "ES";
    respuestas(ARTICULOS, []);
    mocks.post.mockResolvedValue({ data: {} });
    const w = mount(PosView, { global: { plugins: [i18n] } });
    await flushPromises();

    await w.get('[data-prueba="producto"]').trigger("click");
    const plano = (s: string): string => s.replace(/\s/g, " ");
    expect(plano(w.get(".mo-total").text())).toContain("280,00 €");
    await w.get('[data-prueba="cobrar"]').trigger("click");
    await flushPromises();

    expect(plano(String(mocks.confirmar.mock.calls[0]![0]))).toContain(
      "280,00 €",
    );
    expect(w.text()).not.toContain("$");
  });
});
