import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import type { SesionAgenda } from "@/lib/agenda";
import CobroCita from "./CobroCita.vue";

/*
| Cobrar al terminar una cita: servicio, productos y propina en un solo cobro; en
| efectivo, lo que entregó alcanza y se ve el cambio. Un error del servidor queda en
| su campo sin perder lo capturado.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error",
  erroresPorCampo: (e: { campos?: Record<string, string> }) => e.campos ?? {},
}));
vi.mock("@/lib/acceso", () => ({ puedeEntrar: () => true }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", moneda: "MXN", pais: "MX" }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

enableAutoUnmount(afterEach);

const sesion = {
  id: "s1",
  oferta: "Corte",
  cita: {
    reserva_id: "r1",
    cliente: "Ana",
    email: "ana@correo.mx",
    estado: "confirmada",
    asistencia: "presente",
    orden_id: "o1",
    por_cobrar: true,
    total_minor: 25000,
  },
} as unknown as SesionAgenda;

function montar(s: SesionAgenda = sesion) {
  return mount(CobroCita, {
    props: { abierto: true, base: "/api/v1/app/demo", sesion: s },
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

beforeEach(() => {
  vi.clearAllMocks();
  api.get.mockResolvedValue({
    data: {
      data: [
        {
          id: "a1",
          nombre: "Cera",
          precio_minor: 5000,
          activo: true,
          stock_total: 4,
        },
        {
          id: "a2",
          nombre: "Agotado",
          precio_minor: 1000,
          activo: true,
          stock_total: 0,
        },
      ],
    },
  });
});

describe("cobro al terminar la cita", () => {
  it("suma servicio, productos y propina; en efectivo dice el cambio y cobra todo junto", async () => {
    api.post.mockResolvedValue({
      data: {
        data: {
          servicio_minor: 25000,
          productos_minor: 10000,
          propina_minor: 3500,
          total_minor: 38500,
          recibido_minor: 40000,
          cambio_minor: 1500,
          ticket: { folio: "AB12CD34" },
        },
      },
    });
    const w = montar();
    await flushPromises();
    // Sin existencias no se ofrece.
    expect(w.get('[data-prueba="producto-cita"]').text()).not.toContain(
      "Agotado",
    );

    await w.get('[data-prueba="producto-cita"]').setValue("a1");
    await w.get('[data-prueba="agregar-producto-cita"]').trigger("click");
    await w.get('input[type="number"]').setValue(2);
    const diez = w.findAll("button").find((b) => b.text() === "10 %");
    await diez!.trigger("click");
    expect(w.get('[data-prueba="total-cita"]').text()).toContain("385.00");

    await w.get('[data-prueba="recibido-cita"]').setValue("300");
    expect(w.get('[data-prueba="falta-cita"]').text()).toContain("85.00");
    expect(
      w.get('[data-prueba="cobrar-cita"]').attributes("disabled"),
    ).toBeDefined();

    await w.get('[data-prueba="recibido-cita"]').setValue("400");
    await w.get("#form-cobro-cita").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/reservas/r1/cobro",
      expect.objectContaining({
        metodo: "efectivo",
        items: [{ articulo_id: "a1", cantidad: 2, precio_minor: 5000 }],
        propina_minor: 3500,
        recibido_minor: 40000,
      }),
    );
    expect(w.get('[data-prueba="cambio-cita"]').text()).toContain("15.00");
    expect(w.text()).toContain("AB12CD34");
  });

  it("un cupón descuenta del servicio y se manda al cobrar (ADR 0220)", async () => {
    api.post.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/promociones/validar")
            ? { descuento_minor: 5000 }
            : {
                servicio_minor: 20000,
                productos_minor: 0,
                propina_minor: 0,
                total_minor: 20000,
                ticket: { folio: "CU20" },
              },
        },
      }),
    );
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="abrir-cupon"]').trigger("click");
    await w.get("#cc-codigo").setValue("corte20");
    await w.get('[data-prueba="aplicar-cupon"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/promociones/validar",
      { codigo: "corte20", subtotal_minor: 25000 },
    );
    expect(w.get('[data-prueba="cupon-aplicado"]').text()).toContain("CORTE20");
    expect(w.get('[data-prueba="total-cita"]').text()).toContain("200.00");

    await w.get("#form-cobro-cita").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/reservas/r1/cobro",
      expect.objectContaining({ cupon: "corte20" }),
    );
  });

  it("con anticipo pagado solo cobra lo que falta (ADR 0161)", async () => {
    const w = montar({
      ...sesion,
      cita: { ...sesion.cita!, anticipo_minor: 10000, por_cobrar_minor: 15000 },
    } as SesionAgenda);
    await flushPromises();
    expect(w.get('[data-prueba="total-cita"]').text()).toContain("150.00");
  });

  it("solo ofrece lo que hay en la sucursal de la cita y hasta su stock (R07)", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          {
            id: "a1",
            nombre: "Cera",
            precio_minor: 5000,
            activo: true,
            stock_total: 7,
            existencias: [
              { sucursal_id: "roma", stock: 2 },
              { sucursal_id: "polanco", stock: 5 },
            ],
          },
          {
            id: "a2",
            nombre: "Shampoo",
            precio_minor: 9000,
            activo: true,
            stock_total: 4,
            existencias: [{ sucursal_id: "polanco", stock: 4 }],
          },
        ],
      },
    });
    const w = montar({ ...sesion, sucursal_id: "roma" } as SesionAgenda);
    await flushPromises();
    const opciones = w.get('[data-prueba="producto-cita"]').text();
    expect(opciones).toContain("Cera");
    expect(opciones).toContain("2 aquí");
    // Solo hay en Polanco: no se ofrece al cobrar en Roma.
    expect(opciones).not.toContain("Shampoo");
    await w.get('[data-prueba="producto-cita"]').setValue("a1");
    await w.get('[data-prueba="agregar-producto-cita"]').trigger("click");
    expect(w.get('input[type="number"]').attributes("max")).toBe("2");
  });

  it("si el correo del ticket falla, el cobro queda y el ticket se reenvía aparte (R06)", async () => {
    api.post.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/ticket")
            ? { ticket_envio: "enviado" }
            : {
                servicio_minor: 25000,
                productos_minor: 0,
                propina_minor: 0,
                total_minor: 25000,
                recibido_minor: null,
                cambio_minor: null,
                venta_id: "v1",
                ticket: { folio: "AB12CD34" },
                ticket_envio: "fallido",
              },
        },
      }),
    );
    const w = montar();
    await flushPromises();
    await w.get("#form-cobro-cita").trigger("submit");
    await flushPromises();

    expect(w.get('[data-prueba="ticket-fallido"]').text()).toContain(
      "El cobro quedó registrado",
    );
    await w.get('[data-prueba="reenviar-ticket"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/pos/ventas/v1/ticket",
    );
    expect(w.find('[data-prueba="ticket-enviado"]').exists()).toBe(true);
  });

  it("con solo el servicio se pide el ticket, y si no se pidió se manda desde el comprobante (R06)", async () => {
    api.post.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/ticket")
            ? { ticket_envio: "pendiente" }
            : {
                servicio_minor: 25000,
                productos_minor: 0,
                propina_minor: 0,
                total_minor: 25000,
                recibido_minor: null,
                cambio_minor: null,
                venta_id: "v1",
                ticket: { folio: "AB12CD34" },
                ticket_envio: null,
              },
        },
      }),
    );
    const w = montar();
    await flushPromises();
    // Sin productos ni propina la casilla está.
    expect(w.find('input[type="checkbox"]').exists()).toBe(true);
    await w.get("#form-cobro-cita").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/reservas/r1/cobro",
      expect.objectContaining({ items: [], enviar_ticket: false }),
    );

    // No se pidió: se manda desde el comprobante y queda en camino.
    await w.get('[data-prueba="mandar-ticket"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/pos/ventas/v1/ticket",
    );
    expect(w.get('[data-prueba="ticket-pendiente"]').text()).toContain(
      "va en camino",
    );
  });

  it("tras un conflicto vuelve a leer el precio y el stock de lo que lleva (R07)", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="producto-cita"]').setValue("a1");
    await w.get('[data-prueba="agregar-producto-cita"]').trigger("click");

    // Otra venta se llevó piezas y cambió el precio: el servidor lo rechaza.
    api.post.mockRejectedValueOnce({
      campos: { items: "Cambió el precio de Cera: revísalo." },
    });
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: "a1",
            nombre: "Cera",
            precio_minor: 6000,
            activo: true,
            stock_total: 1,
          },
        ],
      },
    });
    await w.get("#form-cobro-cita").trigger("submit");
    await flushPromises();

    // Más de lo que queda: se dice y no se cobra.
    await w.get('input[type="number"]').setValue(2);
    expect(w.get('[data-prueba="excede-a1"]').text()).toContain("Solo hay 1");
    expect(
      w.get('[data-prueba="cobrar-cita"]').attributes("disabled"),
    ).toBeDefined();

    // Con lo que hay, el reintento lleva el precio de ahora.
    await w.get('input[type="number"]').setValue(1);
    api.post.mockResolvedValueOnce({
      data: {
        data: {
          servicio_minor: 25000,
          productos_minor: 6000,
          propina_minor: 0,
          total_minor: 31000,
          recibido_minor: null,
          cambio_minor: null,
          ticket: null,
        },
      },
    });
    await w.get("#form-cobro-cita").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenLastCalledWith(
      "/api/v1/app/demo/reservas/r1/cobro",
      expect.objectContaining({
        items: [{ articulo_id: "a1", cantidad: 1, precio_minor: 6000 }],
      }),
    );
  });

  it("un error del servidor queda junto a su campo y no pierde lo capturado", async () => {
    api.post.mockRejectedValue({
      campos: {
        recibido_minor: "Lo que recibiste no alcanza para cobrar la venta.",
      },
    });
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="recibido-cita"]').setValue("250");
    await w.get("#form-cobro-cita").trigger("submit");
    await flushPromises();

    expect(w.text()).toContain("Lo que recibiste no alcanza");
    expect(
      (w.get('[data-prueba="recibido-cita"]').element as HTMLInputElement)
        .value,
    ).toBe("250");
  });

  it("si no se cargan los productos lo dice y deja reintentar", async () => {
    api.get.mockRejectedValueOnce(new Error("red"));
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="error-articulos-cita"]').exists()).toBe(true);
  });
});
