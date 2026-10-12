import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { plataformaAdmin } from "@/i18n/locales/gestion.es-MX";
import BitacoraPlataforma from "./BitacoraPlataforma.vue";
import EquipoPlataforma from "./EquipoPlataforma.vue";

/*
| El equipo del superadmin (ADR 0132): operadores y sesiones abiertas, y la bitácora
| de lo que cada uno cambió.
*/

const http = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("axios", () => ({ default: { create: () => http } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: unknown) => String(e) }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
const confirmar = vi.hoisted(() => vi.fn(async () => true));
vi.mock("@/lib/confirmar", () => ({ confirmar }));

const i18n = () =>
  createI18n({
    legacy: false,
    locale: "es",
    messages: { es: { plataformaAdmin } },
  });

const ana = {
  id: "op-ana",
  nombre: "Ana",
  email: "ana@agendauno.mx",
  activo: true,
  ultimo_acceso_en: "2026-10-01T12:00:00Z",
  sesiones_abiertas: 1,
  soy_yo: true,
};
const beto = {
  ...ana,
  id: "op-beto",
  nombre: "Beto",
  email: "beto@agendauno.mx",
  soy_yo: false,
};

beforeEach(() => {
  vi.clearAllMocks();
  http.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/administradores")
          ? [ana, beto]
          : url.endsWith("/sesiones")
            ? [
                {
                  id: "s1",
                  administrador: { nombre: "Ana", email: "ana@agendauno.mx" },
                  ip: "10.0.0.1",
                  agente: "Firefox",
                  abierta_en: "2026-10-01T12:00:00Z",
                  ultimo_uso_en: "2026-10-01T12:30:00Z",
                  expira_en: "2026-10-02T00:00:00Z",
                  actual: true,
                },
              ]
            : [],
        meta: { pagina: 1, ultima_pagina: 1, total: 0 },
      },
    }),
  );
});

describe("equipo del superadmin", () => {
  it("lista a los operadores; a uno mismo no se le ofrece desactivarse", async () => {
    const w = mount(EquipoPlataforma, {
      props: { apiUrl: "http://api", token: "tk" },
      global: { plugins: [i18n()] },
    });
    await flushPromises();

    const filas = w.findAll('[data-prueba="operador"]');
    expect(filas).toHaveLength(2);
    expect(filas[0]!.text()).toContain("tú");
    expect(filas[0]!.text()).not.toContain("Desactivar");
    expect(w.find('[data-prueba="sesion"]').text()).toContain("esta sesión");

    // Desactivar a otro pide confirmarlo.
    await filas[1]!
      .findAll("button")
      .find((b) => b.text() === "Desactivar")!
      .trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(http.put).toHaveBeenCalledWith(
      "/api/v1/plataforma/administradores/op-beto",
      { activo: false },
      expect.anything(),
    );
  });

  it("agrega a un operador", async () => {
    http.post.mockResolvedValue({ data: { data: { id: "op-nuevo" } } });
    const w = mount(EquipoPlataforma, {
      props: { apiUrl: "http://api", token: "tk" },
      global: { plugins: [i18n()] },
    });
    await flushPromises();

    await w.get("#op-nombre").setValue("Caro");
    await w.get("#op-correo").setValue("caro@agendauno.mx");
    await w.get("#op-contrasena").setValue("contraseña-segura-1");
    await w.get('[data-prueba="nuevo-operador"]').trigger("submit");
    await flushPromises();

    expect(http.post).toHaveBeenCalledWith(
      "/api/v1/plataforma/administradores",
      {
        nombre: "Caro",
        email: "caro@agendauno.mx",
        password: "contraseña-segura-1",
      },
      expect.anything(),
    );
  });

  it("la bitácora dice qué hizo cada quien, en qué negocio y si se hizo", async () => {
    http.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/bitacora")
            ? [
                {
                  id: "b1",
                  fecha: "2026-10-01T12:00:00Z",
                  accion: "estudios.suspender",
                  metodo: "POST",
                  ruta: "/api/v1/plataforma/estudios/fluo/suspender",
                  estado: 200,
                  ip: "10.0.0.1",
                  operador: { nombre: "Ana", email: "ana@agendauno.mx" },
                  negocio: {
                    slug: "fluo",
                    nombre: "Fluo",
                    producto: "agendauno",
                  },
                  datos: { motivo: "Pidió pausar" },
                },
              ]
            : [ana],
          meta: { pagina: 1, ultima_pagina: 1, total: 1 },
        },
      }),
    );
    const w = mount(BitacoraPlataforma, {
      props: { apiUrl: "http://api", token: "tk" },
      global: { plugins: [i18n()] },
    });
    await flushPromises();

    const registro = w.get('[data-prueba="registro-bitacora"]');
    expect(registro.text()).toContain("Suspendió un negocio");
    expect(registro.text()).toContain("Fluo (AgendaUno)");
    expect(registro.text()).toContain("Hecho");
    await registro
      .findAll("button")
      .find((b) => b.text() === "Ver datos")!
      .trigger("click");
    expect(registro.text()).toContain("Pidió pausar");
  });
});
