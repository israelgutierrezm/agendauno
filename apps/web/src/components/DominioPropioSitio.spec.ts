import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import DominioPropioSitio from "./DominioPropioSitio.vue";

/*
| El dominio propio del sitio (ADR 0189): se conecta, se ven los registros de su DNS
| mientras está pendiente y se quita con confirmación. Sin el plan, es Plus.
*/

const api = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const confirmar = vi.hoisted(() => vi.fn(() => Promise.resolve(true)));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "fluo" }),
}));

const URL = "/api/v1/app/fluo/sitio/dominio";
const PENDIENTE = {
  con_plan: true,
  destino: "sitios.agendauno.mx",
  dominio: {
    dominio: "reservas.fluo.mx",
    estado: "pendiente",
    problema: "sin_txt",
    propiedad_verificada: false,
    revisado_en: null,
    registros: [
      {
        tipo: "TXT",
        nombre: "_agendauno.reservas.fluo.mx",
        valor: "agendauno-verificacion=abc",
      },
      {
        tipo: "CNAME",
        nombre: "reservas.fluo.mx",
        valor: "sitios.agendauno.mx",
      },
    ],
  },
};
const global = { plugins: [i18n] };

beforeEach(() => {
  vi.clearAllMocks();
});

describe("DominioPropioSitio", () => {
  it("sin el plan dice que es Plus y no ofrece conectarlo", async () => {
    api.get.mockResolvedValue({
      data: { data: { con_plan: false, destino: "", dominio: null } },
    });
    const w = mount(DominioPropioSitio, { global });
    await flushPromises();

    expect(w.get('[data-prueba="dominio-sin-plan"]').text()).toContain("Plus");
    expect(w.find('[data-prueba="dominio-conectar"]').exists()).toBe(false);
  });

  it("lo conecta y muestra los registros que lleva su DNS y lo que falta", async () => {
    api.get.mockResolvedValue({
      data: { data: { con_plan: true, destino: "", dominio: null } },
    });
    api.put.mockResolvedValue({ data: { data: PENDIENTE } });
    const w = mount(DominioPropioSitio, { global });
    await flushPromises();

    await w
      .get('[data-prueba="dominio-escrito"]')
      .setValue(" reservas.fluo.mx ");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith(URL, { dominio: "reservas.fluo.mx" });
    expect(w.get('[data-prueba="dominio-estado"]').text()).toBe("Pendiente");
    expect(w.get('[data-prueba="dominio-problema"]').text()).toContain("TXT");
    const tabla = w.get('[data-prueba="dominio-registros"]').text();
    expect(tabla).toContain("_agendauno.reservas.fluo.mx");
    expect(tabla).toContain("sitios.agendauno.mx");

    api.post.mockResolvedValue({
      data: {
        data: {
          ...PENDIENTE,
          dominio: { ...PENDIENTE.dominio, estado: "activo", problema: null },
        },
      },
    });
    await w.get('[data-prueba="dominio-revisar"]').trigger("click");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(`${URL}/revisar`);
    expect(w.get('[data-prueba="dominio-activo"]').text()).toContain(
      "https://reservas.fluo.mx",
    );
    expect(w.find('[data-prueba="dominio-registros"]').exists()).toBe(false);
  });

  it("quitarlo se confirma; sin permiso de publicar solo se ve", async () => {
    api.get.mockResolvedValue({ data: { data: PENDIENTE } });
    confirmar.mockResolvedValueOnce(false);
    const w = mount(DominioPropioSitio, { global });
    await flushPromises();

    await w.get('[data-prueba="dominio-quitar"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(api.delete).not.toHaveBeenCalled();

    const soloVer = mount(DominioPropioSitio, {
      props: { puedePublicar: false },
      global,
    });
    await flushPromises();
    expect(soloVer.find('[data-prueba="dominio-quitar"]').exists()).toBe(false);
    expect(soloVer.find('[data-prueba="dominio-revisar"]').exists()).toBe(
      false,
    );
  });
});
