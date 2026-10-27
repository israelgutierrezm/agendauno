import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import MisInvitados from "./MisInvitados.vue";

/*
| Mis invitados (ADR 0182): el alumno ve los pases que le quedan y a quién invitó a
| sus próximas clases, y cancela una invitación (con confirmación) para recuperar el
| pase. Sin pases ni invitados, no aparece.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "No se pudo" }));
const confirmar = vi.hoisted(() => vi.fn(() => Promise.resolve(true)));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ zonaHoraria: "America/Mexico_City" }),
}));

const BASE = "/api/v1/app/fluo";
const invitado = {
  id: "i1",
  nombre: "Luz Mar",
  estado: "reservado",
  sesion: {
    id: "s1",
    oferta: "Pole Nivel 1",
    inicia_en: "2030-01-10T16:00:00Z",
  },
};
function respuesta(proximos: unknown[], quedan = 1) {
  return {
    data: {
      data: {
        pases: [{ plan: "Socio", por_mes: 2, usados: 2 - quedan, quedan }],
        proximos,
      },
    },
  };
}
function montar() {
  return mount(MisInvitados, {
    props: { base: BASE },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("mis invitados", () => {
  it("muestra sus pases y a quién invitó; cancelar devuelve el pase", async () => {
    api.get
      .mockResolvedValueOnce(respuesta([invitado], 1))
      .mockResolvedValue(respuesta([], 2));
    api.delete.mockResolvedValue({ data: {} });
    const w = montar();
    await flushPromises();

    expect(w.get('[data-prueba="pases-invitado"]').text()).toBe(
      "Socio: te queda 1 de 2 pases este mes.",
    );
    expect(w.get('[data-prueba="mi-invitado"]').text()).toContain("Luz Mar");
    expect(w.get('[data-prueba="mi-invitado"]').text()).toContain(
      "Pole Nivel 1",
    );

    await w.get('[data-prueba="cancelar-invitado"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalledWith(
      "¿Cancelar la invitación de Luz Mar a Pole Nivel 1? Su lugar queda libre y recuperas tu pase.",
      { peligro: true, aceptar: "Cancelar invitación" },
    );
    expect(api.delete).toHaveBeenCalledWith(`${BASE}/mi/invitados/i1`);
    expect(w.text()).toContain(
      "Cancelamos la invitación de Luz Mar. Recuperaste tu pase.",
    );
    expect(w.find('[data-prueba="mi-invitado"]').exists()).toBe(false);
    expect(w.get('[data-prueba="pases-invitado"]').text()).toContain(
      "te quedan 2 de 2",
    );
  });

  it("si no confirma, no cancela; si falla, lo dice junto a la lista", async () => {
    api.get.mockResolvedValue(respuesta([invitado]));
    confirmar.mockResolvedValueOnce(false);
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="cancelar-invitado"]').trigger("click");
    await flushPromises();
    expect(api.delete).not.toHaveBeenCalled();

    api.delete.mockRejectedValue(new Error("La clase ya empezó"));
    await w.get('[data-prueba="cancelar-invitado"]').trigger("click");
    await flushPromises();
    expect(w.get('[role="alert"]').text()).toBe("No se pudo");
    expect(w.find('[data-prueba="mi-invitado"]').exists()).toBe(true);
  });

  it("sin pases ni invitados (o si no se pudo consultar) no aparece", async () => {
    api.get.mockResolvedValue({ data: { data: { pases: [], proximos: [] } } });
    const vacio = montar();
    await flushPromises();
    expect(vacio.find('[data-prueba="mis-invitados"]').exists()).toBe(false);

    api.get.mockRejectedValue(new Error("403"));
    const sinPerfil = montar();
    await flushPromises();
    expect(sinPerfil.find('[data-prueba="mis-invitados"]').exists()).toBe(
      false,
    );
  });
});
