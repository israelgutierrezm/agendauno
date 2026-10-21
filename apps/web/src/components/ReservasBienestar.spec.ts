import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ReservasBienestar from "./ReservasBienestar.vue";

/*
| Reservas desde la app de Wellhub en Integraciones (ADR 0149): qué clases se publican,
| con qué producto y de qué sucursal.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "fluo" }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

function configuracion(cambios: Record<string, unknown> = {}) {
  return {
    disponible: true,
    conectada: true,
    activas: false,
    sucursal_id: null,
    sucursales: [{ id: "suc1", nombre: "Roma Norte" }],
    dias_publicados: 14,
    lugares_sin_tope: 20,
    avisos: { registrados: false },
    productos: [{ id: "987", nombre: "Clases ilimitadas", virtual: false }],
    clases: [
      {
        oferta_id: "of1",
        nombre: "Pole Sport · Nivel 1",
        publicada: false,
        producto: null,
        error: null,
      },
    ],
    horarios: { publicados: 0, con_error: 0, ultimo_error: null },
    reservas_por_venir: 0,
    ...cambios,
  };
}

function montar(proveedor = "wellhub") {
  return mount(ReservasBienestar, {
    props: { proveedor },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("reservas desde la app de la plataforma", () => {
  it("publica las clases elegidas con su producto, en la única sucursal", async () => {
    api.get.mockResolvedValue({ data: { data: configuracion() } });
    api.put.mockResolvedValue({
      data: {
        data: configuracion({
          activas: true,
          sucursal_id: "suc1",
          productos: null,
          avisos: { registrados: true },
          horarios: { publicados: 4, con_error: 0, ultimo_error: null },
          clases: [
            {
              oferta_id: "of1",
              nombre: "Pole Sport · Nivel 1",
              publicada: true,
              producto: "987",
              error: null,
            },
          ],
        }),
      },
    });
    const w = montar();
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/fluo/integraciones/wellhub/reservas",
      { params: {} },
    );
    expect(w.get('[data-prueba="reservas-plataforma-estado"]').text()).toBe(
      "Sin publicar",
    );

    // Se enciende con su interruptor.
    await w.get('[data-prueba="reservas-activas"]').trigger("click");
    await w.get('[data-prueba="reservas-clase"] input').setValue(true);
    await w.get('[data-prueba="reservas-clase"] select').setValue("987");
    await w.get('[data-prueba="reservas-guardar"]').trigger("click");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/fluo/integraciones/wellhub/reservas",
      {
        activas: true,
        sucursal_id: "suc1",
        clases: [{ oferta_id: "of1", publicada: true, producto: "987" }],
      },
      { params: {} },
    );
    expect(toast.exito).toHaveBeenCalled();
    expect(
      w.get('[data-prueba="reservas-plataforma-resumen"]').text(),
    ).toContain("4 horarios publicados");
    // Al guardar no se vuelven a consultar los productos: se conservan.
    expect(w.find('[data-prueba="reservas-clase"] select').exists()).toBe(true);
  });

  it("sin productos de la plataforma, pide el ID del producto", async () => {
    api.get.mockResolvedValue({
      data: {
        data: configuracion({
          activas: true,
          productos: null,
          clases: [
            {
              oferta_id: "of1",
              nombre: "Pole Sport · Nivel 1",
              publicada: true,
              producto: "987",
              error: "Wellhub no lo aceptó: producto inválido",
            },
          ],
        }),
      },
    });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("No se pudieron consultar los productos");
    expect(
      (
        w.get('[data-prueba="reservas-clase"] input.tu-input')
          .element as HTMLInputElement
      ).value,
    ).toBe("987");
    expect(w.text()).toContain("producto inválido");
  });

  it("en TotalPass cada clase va con su plan", async () => {
    api.get.mockResolvedValue({
      data: {
        data: configuracion({
          activas: true,
          productos: null,
          clases: [
            {
              oferta_id: "of1",
              nombre: "Pole Sport · Nivel 1",
              publicada: true,
              producto: null,
              error: null,
            },
          ],
        }),
      },
    });
    const w = montar("totalpass");
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/fluo/integraciones/totalpass/reservas",
      { params: {} },
    );
    expect(w.text()).toContain(
      "No se pudieron consultar los planes de TotalPass",
    );
    expect(
      w
        .get('[data-prueba="reservas-clase"] input.tu-input')
        .attributes("placeholder"),
    ).toBe("ID del plan");
  });

  it("la conexión de una sede publica las clases de esa sede", async () => {
    api.get.mockResolvedValue({
      data: {
        data: configuracion({
          activas: true,
          sucursal_id: "suc2",
          sucursales: [
            { id: "suc1", nombre: "Roma Norte" },
            { id: "suc2", nombre: "Del Valle" },
          ],
        }),
      },
    });
    api.put.mockResolvedValue({ data: { data: configuracion() } });
    const w = mount(ReservasBienestar, {
      props: { proveedor: "wellhub", sucursal: "suc2" },
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/fluo/integraciones/wellhub/reservas",
      { params: { sucursal: "suc2" } },
    );
    // Sin elegir sucursal: es la de la conexión.
    expect(w.find("#rb-sucursal-wellhub").exists()).toBe(false);
    await w.get('[data-prueba="reservas-guardar"]').trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/fluo/integraciones/wellhub/reservas",
      expect.objectContaining({ sucursal_id: "suc2" }),
      { params: { sucursal: "suc2" } },
    );
  });

  it("no se muestra si la plataforma aún no publica clases", async () => {
    api.get.mockResolvedValue({
      data: { data: configuracion({ disponible: false }) },
    });
    const w = montar();
    await flushPromises();

    expect(w.find('[data-prueba="reservas-plataforma"]').exists()).toBe(false);
  });
});
