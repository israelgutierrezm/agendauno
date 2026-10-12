import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ComplementosNegocio from "./ComplementosNegocio.vue";

/*
| Complementos (ADR 0140): Familias se ofrece como un plus que se pide con un botón;
| pedido, dice desde cuándo; activo, lo dice y ya no se pide.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  zonaHoraria: "America/Mexico_City",
  puede: vi.fn(() => true),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const FAMILIAS = {
  clave: "familias",
  nombre: "Familias y responsables",
  descripcion: "Un alumno con cuenta reserva y compra por personas a su cargo.",
  activo: false,
  solicitado_en: null,
};

beforeEach(() => {
  vi.clearAllMocks();
  sesion.puede.mockReturnValue(true);
  api.get.mockResolvedValue({ data: { data: [FAMILIAS] } });
});

describe("complementos", () => {
  it("se pide con un botón y queda como solicitado", async () => {
    api.post.mockResolvedValue({
      data: {
        data: { ...FAMILIAS, solicitado_en: "2026-10-12T17:00:00+00:00" },
      },
    });
    const w = mount(ComplementosNegocio, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(w.text()).toContain("Familias y responsables");
    await w.get('[data-prueba="pedir-complemento"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/renta/complementos/familias/solicitud",
      {},
    );
    expect(w.text()).toContain("Solicitado el 12 de octubre");
    expect(w.get('[data-prueba="pedir-complemento"]').text()).toBe(
      "Volver a pedirlo",
    );
  });

  it("activo, lo dice; sin permiso, no se ofrece pedirlo", async () => {
    api.get.mockResolvedValue({
      data: { data: [{ ...FAMILIAS, activo: true }] },
    });
    const w = mount(ComplementosNegocio, { global: { plugins: [i18n] } });
    await flushPromises();
    expect(w.text()).toContain("Activo");
    expect(w.find('[data-prueba="pedir-complemento"]').exists()).toBe(false);

    api.get.mockResolvedValue({ data: { data: [FAMILIAS] } });
    sesion.puede.mockReturnValue(false);
    const sinPermiso = mount(ComplementosNegocio, {
      global: { plugins: [i18n] },
    });
    await flushPromises();
    expect(sinPermiso.find('[data-prueba="pedir-complemento"]').exists()).toBe(
      false,
    );
  });
});
