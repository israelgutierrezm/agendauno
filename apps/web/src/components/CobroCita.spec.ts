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
          template: '<div v-if="abierto"><slot /></div>',
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

  it("con anticipo pagado solo cobra lo que falta (ADR 0161)", async () => {
    const w = montar({
      ...sesion,
      cita: { ...sesion.cita!, anticipo_minor: 10000, por_cobrar_minor: 15000 },
    } as SesionAgenda);
    await flushPromises();
    expect(w.get('[data-prueba="total-cita"]').text()).toContain("150.00");
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
