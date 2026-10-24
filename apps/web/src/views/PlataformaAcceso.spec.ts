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
  put: vi.fn(),
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

/** Lo que responde la API por omisión a cada GET de la vista. */
function respuesta(url: string) {
  return {
    data: {
      data: url.endsWith("/yo")
        ? { administrador: { nombre: "Ana Operadora" } }
        : url.endsWith("/configuracion")
          ? { facturapi_configurada: false }
          : url.endsWith("/legales")
            ? { aviso_privacidad: null, terminos: null }
            : [],
    },
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  sessionStorage.clear();
  cliente.get.mockImplementation((url: string) =>
    Promise.resolve(respuesta(url)),
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
    const w = mount(PlataformaView, { global: { plugins: [i18n] } });
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
    const w = mount(PlataformaView, { global: { plugins: [i18n] } });
    await flushPromises();

    const layout = w.get('[data-prueba="layout-plataforma"]');
    expect(layout.findAll(".lp-titulo").map((g) => g.text())).toEqual([
      "Negocios",
      "Dinero",
      "Plataforma",
      "Acceso",
    ]);
    expect(layout.findAll("a")).toHaveLength(0);
    // El título de la sección va en el contenido (con su ícono), no en la barra.
    expect(w.find('[data-prueba="titulo-plataforma"]').exists()).toBe(false);
    expect(w.get("h1").text()).toBe("Negocios");

    await w.get('[data-pestana="tarifas"]').trigger("click");
    await flushPromises();
    expect(w.get("h1").text()).toBe("Tarifas");
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

describe("secciones del superadmin sin desplazamientos largos", () => {
  it("Negocios pide 15 por página y pinta una sola paginación, con el total del servidor", async () => {
    sessionStorage.setItem("tu.plataforma.token", "sesion-123");
    // Una página de 15 de los 29 negocios (aquí, una sola fila basta).
    const negocios = {
      data: {
        data: [
          {
            slug: "estudio-uno",
            nombre: "Estudio Uno",
            estado: "active",
            estado_facturacion: "active",
            perfil: "pilates",
            modalidad: "clases",
            modo_cobro: "activos",
            cuota_fija_minor: 0,
            trial_termina_en: null,
            moneda: "MXN",
            publicado: true,
            en_directorio: true,
            ciudad: null,
            creado_en: null,
          },
        ],
        meta: {
          total: 29,
          page: 1,
          per_page: 15,
          ultima_pagina: 2,
          total_negocios: 29,
        },
      },
    };
    cliente.get.mockImplementation((url: string) =>
      Promise.resolve(url.endsWith("/estudios") ? negocios : respuesta(url)),
    );
    const w = mount(PlataformaView, { global: { plugins: [i18n] } });
    await flushPromises();

    expect(cliente.get).toHaveBeenCalledWith(
      "/api/v1/plataforma/estudios",
      expect.objectContaining({
        params: expect.objectContaining({ page: 1, per_page: 15 }),
      }),
    );
    const lista = w.get('[data-prueba="lista-estudios"]');
    // Los filtros arriba, en la misma tarjeta que la tabla.
    expect(lista.find('[data-prueba="buscar-estudios"]').exists()).toBe(true);
    expect(lista.text()).toContain("Estudio Uno");
    const paginas = lista.findAll("nav");
    expect(paginas).toHaveLength(1);
    expect(paginas[0].text()).toContain("1–15 de 29");
  });

  it("Configuración muestra un apartado a la vez y conserva lo escrito al cambiar", async () => {
    sessionStorage.setItem("tu.plataforma.token", "sesion-123");
    const w = mount(PlataformaView, {
      global: {
        plugins: [i18n],
        stubs: {
          ComercialPlataforma: true,
          WhatsAppPlataforma: true,
          SolicitudesWellhub: true,
        },
      },
    });
    await flushPromises();
    await w.get('[data-pestana="configuracion"]').trigger("click");
    await flushPromises();

    const pestanas = w.get('[data-prueba="pestanas-seccion"]');
    expect(
      pestanas.findAll("button").map((b) => b.attributes("data-pestana")),
    ).toEqual([
      "facturacion",
      "pasarelas",
      "whatsapp",
      "legales",
      "comercial",
      "bienestar",
    ]);
    function oculto(apartado: string): boolean {
      const panel = w.get(`[data-prueba="config-${apartado}"]`);
      return (panel.attributes("style") ?? "").includes("display: none");
    }
    // Primero Facturación (la llave de FacturAPI y el correo de alertas).
    expect(oculto("facturacion")).toBe(false);
    const facturacion = w.get('[data-prueba="config-facturacion"]');
    expect(facturacion.find('[data-prueba="correo-alertas"]').exists()).toBe(
      true,
    );
    for (const apartado of [
      "pasarelas",
      "whatsapp",
      "legales",
      "comercial",
      "bienestar",
    ]) {
      expect(oculto(apartado)).toBe(true);
    }

    await w.get("#correo-alertas").setValue("ops@agendauno.mx");
    await pestanas.get('[data-pestana="legales"]').trigger("click");
    expect(oculto("facturacion")).toBe(true);
    expect(oculto("legales")).toBe(false);
    // Sin responsable guardado, sus datos se ven abiertos para llenarlos.
    expect(
      w.get('[data-prueba="legales-responsable"]').attributes("open"),
    ).toBeDefined();

    // Se ocultan sin desmontarse: lo escrito en otro apartado sigue ahí.
    await pestanas.get('[data-pestana="facturacion"]').trigger("click");
    expect((w.get("#correo-alertas").element as HTMLInputElement).value).toBe(
      "ops@agendauno.mx",
    );
  });

  it("al publicar un documento legal, el aro va en el botón de publicar desde que se guarda el borrador", async () => {
    sessionStorage.setItem("tu.plataforma.token", "sesion-123");
    const pregunta = vi.spyOn(window, "confirm").mockReturnValue(true);
    // El borrador se queda guardando hasta que la prueba lo suelte.
    let soltarBorrador: (r: unknown) => void = () => {};
    cliente.put.mockReturnValue(
      new Promise((resolver) => {
        soltarBorrador = resolver;
      }),
    );
    cliente.post.mockResolvedValue({
      data: { data: { version: 2, vigente_desde: "2026-10-01T12:00:00Z" } },
    });
    const w = mount(PlataformaView, {
      global: {
        plugins: [i18n],
        stubs: {
          ComercialPlataforma: true,
          WhatsAppPlataforma: true,
          SolicitudesWellhub: true,
        },
      },
    });
    await flushPromises();
    await w.get('[data-pestana="configuracion"]').trigger("click");
    await flushPromises();
    await w
      .get('[data-prueba="pestanas-seccion"] [data-pestana="legales"]')
      .trigger("click");

    const borrador = () => w.get('[data-prueba="legales-guardar"]');
    const publicar = () =>
      w.get('[data-prueba="legales-publicar-aviso_privacidad"]');
    await publicar().trigger("click");
    await flushPromises();

    // Mientras se guarda el borrador: ocupado el que se pulsó, no «Guardar borrador».
    expect(cliente.put).toHaveBeenCalledWith(
      "/api/v1/plataforma/legales",
      expect.anything(),
      expect.anything(),
    );
    expect(publicar().attributes("aria-busy")).toBe("true");
    expect(borrador().attributes("aria-busy")).toBeUndefined();
    expect(borrador().attributes("disabled")).toBeDefined();

    soltarBorrador({ data: {} });
    await flushPromises();
    expect(cliente.post).toHaveBeenCalledWith(
      "/api/v1/plataforma/legales/aviso_privacidad/publicar",
      {},
      expect.anything(),
    );
    expect(publicar().attributes("aria-busy")).toBeUndefined();
    expect(borrador().attributes("aria-busy")).toBeUndefined();
    pregunta.mockRestore();
  });
});
