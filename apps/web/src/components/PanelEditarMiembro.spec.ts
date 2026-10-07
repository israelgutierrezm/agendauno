import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PanelEditarMiembro, {
  type MiembroEditable,
} from "./PanelEditarMiembro.vue";

/*
| Editar a un cliente o alumno: también su celular, con lada (ADR 0103). Uno guardado
| sin lada no se reescribe si no se toca.
*/

const api = vi.hoisted(() => ({ put: vi.fn(), delete: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
vi.mock("@/lib/confirmar", () => ({ confirmar: () => Promise.resolve(true) }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    pais: "CO",
    lada: "57",
    puede: () => true,
  }),
}));

const base: MiembroEditable = {
  id: "m1",
  nombre: "Vale",
  segundo_nombre: null,
  primer_apellido: "Ruiz",
  segundo_apellido: null,
  email: null,
  celular: "3001234567",
  activo: true,
  es_facturable: true,
  archivado: false,
};

function montar(miembro: MiembroEditable = base) {
  return mount(PanelEditarMiembro, {
    props: { miembro },
    global: { plugins: [i18n], stubs: { teleport: true } },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  api.put.mockResolvedValue({ data: { data: base } });
});

describe("editar el celular de un cliente", () => {
  it("muestra el guardado sin lada con la del negocio y no lo manda si no cambia", async () => {
    const w = montar();
    expect(w.get('[data-prueba="lada-celular"]').text()).toBe("CO +57");
    expect(
      (w.get('[data-prueba="celular-miembro"]').element as HTMLInputElement)
        .value,
    ).toBe("3001234567");

    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.put.mock.calls[0][1]).not.toHaveProperty("celular");
  });

  it("al cambiarlo lo manda con su lada", async () => {
    const w = montar();
    await w.get('[data-prueba="celular-miembro"]').setValue("310 555 0000");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/miembros/m1",
      expect.objectContaining({ celular: "+57 3105550000" }),
    );
  });

  it("sin el dato del celular no lo ofrece ni lo toca", async () => {
    const sinCelular: MiembroEditable = { ...base };
    delete sinCelular.celular;
    const w = montar(sinCelular);
    expect(w.find('[data-prueba="celular-miembro"]').exists()).toBe(false);
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.put.mock.calls[0][1]).not.toHaveProperty("celular");
  });
});
