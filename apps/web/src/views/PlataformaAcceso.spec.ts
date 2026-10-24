import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";

/*
| Entrada del superadmin (ADR 0126): correo, contraseña y token; luego el código que
| llega por correo abre la sesión (su propio token) y se ve quién entró.
*/

// El cliente de axios de la vista (y el de lib/api, que solo registra interceptores).
const cliente = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  interceptors: {
    request: { use: vi.fn() },
    response: { use: vi.fn() },
  },
  defaults: { headers: { common: {} } },
}));
vi.mock("axios", async (original) => {
  const real = await original<typeof import("axios")>();
  return {
    default: { ...real.default, create: () => cliente },
    isAxiosError: real.isAxiosError,
  };
});
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const { default: PlataformaView } = await import("./PlataformaView.vue");

beforeEach(() => {
  vi.clearAllMocks();
  sessionStorage.clear();
  cliente.get.mockImplementation((url: string) =>
    Promise.resolve({
      data: {
        data: url.endsWith("/yo")
          ? { administrador: { nombre: "Ana Operadora" } }
          : url.endsWith("/configuracion")
            ? { facturapi_configurada: false }
            : url.endsWith("/legales")
              ? { aviso_privacidad: null, terminos: null }
              : [],
      },
    }),
  );
});

describe("entrada del superadmin en dos pasos", () => {
  it("con correo, contraseña y token pide el código; el código abre la sesión", async () => {
    cliente.post
      .mockResolvedValueOnce({
        data: {
          data: { desafio: "d1", correo: "an***@agendauno.mx", codigo: null },
        },
      })
      .mockResolvedValueOnce({ data: { data: { token: "sesion-123" } } });
    const w = mount(PlataformaView, {
      global: { plugins: [i18n], stubs: { TablaDatos: true } },
    });
    await flushPromises();
    // La puerta no lleva a la parte pública (ni «Inicio» ni «Iniciar sesión»).
    expect(w.findAll("a")).toHaveLength(0);

    await w.get("#pl-correo").setValue("ana@agendauno.mx");
    await w.get("#pl-contrasena").setValue("contraseña-segura-1");
    await w.get("#tk").setValue("token-plataforma");
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(cliente.post).toHaveBeenCalledWith("/api/v1/plataforma/entrar", {
      email: "ana@agendauno.mx",
      password: "contraseña-segura-1",
      token: "token-plataforma",
    });
    expect(w.get('[data-prueba="paso-codigo"]').text()).toContain(
      "an***@agendauno.mx",
    );

    await w.get("#pl-codigo").setValue("123456");
    await w.get('[data-prueba="paso-codigo"] form').trigger("submit");
    await flushPromises();

    expect(cliente.post).toHaveBeenLastCalledWith(
      "/api/v1/plataforma/entrar/verificar",
      { desafio: "d1", codigo: "123456" },
    );
    expect(sessionStorage.getItem("tu.plataforma.token")).toBe("sesion-123");
    expect(w.get('[data-prueba="operador-plataforma"]').text()).toBe(
      "Ana Operadora",
    );
  });

  it("dentro, el armazón del panel: menú lateral por grupos, sin enlaces públicos, y solo «Cerrar sesión» sale", async () => {
    sessionStorage.setItem("tu.plataforma.token", "sesion-123");
    cliente.post.mockResolvedValue({ data: {} });
    const w = mount(PlataformaView, {
      global: { plugins: [i18n], stubs: { TablaDatos: true } },
    });
    await flushPromises();

    const layout = w.get('[data-prueba="layout-plataforma"]');
    expect(layout.findAll(".lp-titulo").map((g) => g.text())).toEqual([
      "Negocios",
      "Dinero",
      "Plataforma",
      "Acceso",
    ]);
    expect(layout.findAll("a")).toHaveLength(0);
    expect(w.get('[data-prueba="titulo-plataforma"]').text()).toBe("Negocios");
    expect(w.get("h1").text()).toBe("Negocios");

    await w.get('[data-pestana="tarifas"]').trigger("click");
    await flushPromises();
    expect(w.get('[data-prueba="titulo-plataforma"]').text()).toBe("Tarifas");
    expect(w.get('[data-pestana="tarifas"]').attributes("aria-current")).toBe(
      "page",
    );

    await w.get('[data-prueba="salir-plataforma"]').trigger("click");
    await flushPromises();
    expect(cliente.post).toHaveBeenCalledWith(
      "/api/v1/plataforma/salir",
      {},
      expect.anything(),
    );
    expect(sessionStorage.getItem("tu.plataforma.token")).toBeNull();
    expect(w.find('[data-prueba="layout-plataforma"]').exists()).toBe(false);
    expect(w.find("#pl-correo").exists()).toBe(true);
  });
});
