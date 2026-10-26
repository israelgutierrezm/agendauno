import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";

import { i18n } from "@/i18n";
import CitaSinCuentaView from "./CitaSinCuentaView.vue";

/*
| La cita desde el enlace de sus avisos, sin cuenta (ADR 0221): abrirla no cambia nada;
| cancelar pide confirmarlo y manda la firma; un enlace que no sirve lo dice.
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  confirmar: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post },
  mensajeDeError: () => "Error",
  noEncontrado: (e: { response?: { status: number } }) =>
    e.response?.status === 404,
}));
vi.mock("@/lib/confirmar", () => ({ confirmar: mocks.confirmar }));

enableAutoUnmount(afterEach);

const CITA = {
  id: "r1",
  estado: "confirmada",
  negocio: "La Navaja",
  servicio: "Corte",
  con: "Beto",
  sucursal: "Centro",
  direccion: null,
  inicia_en: "2026-10-03T16:00:00Z",
  zona_horaria: "America/Mexico_City",
  cancelar: { cancelable: true, mensaje: "Cancelas sin costo." },
};

async function montar() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/cita/:slug/:reserva", component: CitaSinCuentaView },
      {
        path: "/agendar/:slug",
        name: "agendar-cita",
        component: { template: "<div />" },
      },
    ],
  });
  await router.push("/cita/la-navaja/r1?f=abc123");
  const w = mount(CitaSinCuentaView, { global: { plugins: [i18n, router] } });
  await flushPromises();
  return w;
}

beforeEach(() => {
  vi.clearAllMocks();
  mocks.get.mockResolvedValue({ data: { data: CITA } });
});

describe("cita sin cuenta", () => {
  it("muestra la cita sin tocarla y la cancela al confirmar, con la firma", async () => {
    mocks.post.mockResolvedValue({
      data: { data: { ...CITA, estado: "cancelada" } },
    });
    const w = await montar();

    expect(mocks.get).toHaveBeenCalledWith(
      "/api/v1/app/la-navaja/citas/enlace/r1",
      { params: { f: "abc123" } },
    );
    expect(mocks.post).not.toHaveBeenCalled();
    expect(w.text()).toContain("Corte");
    expect(w.text()).toContain("Con Beto");

    mocks.confirmar.mockResolvedValueOnce(false);
    await w.get('[data-prueba="cancelar-cita"]').trigger("click");
    await flushPromises();
    expect(mocks.post).not.toHaveBeenCalled();

    mocks.confirmar.mockResolvedValueOnce(true);
    await w.get('[data-prueba="cancelar-cita"]').trigger("click");
    await flushPromises();
    expect(String(mocks.confirmar.mock.calls[1]![0])).toContain(
      "Cancelas sin costo.",
    );
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/app/la-navaja/citas/enlace/r1/cancelar",
      { f: "abc123" },
    );
    expect(w.find('[data-prueba="cita-cancelada"]').exists()).toBe(true);
    expect(w.find('[data-prueba="cancelar-cita"]').exists()).toBe(false);
  });

  it("si ya no se puede cancelar, dice por qué y no ofrece el botón", async () => {
    mocks.get.mockResolvedValue({
      data: {
        data: {
          ...CITA,
          cancelar: {
            cancelable: false,
            mensaje: "La cita ya empezó: ya no se puede cancelar.",
          },
        },
      },
    });
    const w = await montar();
    expect(w.text()).toContain("La cita ya empezó");
    expect(w.find('[data-prueba="cancelar-cita"]').exists()).toBe(false);
  });

  it("un enlace que no sirve lo dice", async () => {
    mocks.get.mockRejectedValue({ response: { status: 404 } });
    const w = await montar();
    expect(w.find('[data-prueba="enlace-invalido"]').exists()).toBe(true);
  });
});
