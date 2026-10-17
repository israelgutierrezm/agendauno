import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { onBeforeRouteLeave } from "vue-router";

import { i18n } from "@/i18n";
import SitioWebView from "./SitioWebView.vue";

/*
| El constructor del sitio (ADR 0114): ordena y oculta secciones, cambia de plantilla
| conservando textos, agrega banners, guarda el borrador y publica (con confirmación,
| guardando antes lo que está en pantalla).
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const confirmar = vi.hoisted(() => vi.fn(() => Promise.resolve(true)));
vi.mock("@/lib/confirmar", () => ({ confirmar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "fluo",
    esCitas: false,
    estudio: { nombre: "Fluō Pilates" },
    puede: () => true,
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("vue-router", () => ({
  RouterLink: { template: "<a><slot /></a>" },
  onBeforeRouteLeave: vi.fn(),
}));

const ORDEN = [
  "inicio",
  "promociones",
  "nosotros",
  "agenda",
  "servicios",
  "horario",
  "precios",
  "equipo",
  "resenas",
  "sucursales",
  "contacto",
];

function datos(cambios: Record<string, unknown> = {}) {
  return {
    borrador: {
      plantilla: "esencial",
      secciones: ORDEN.map((tipo) => ({
        tipo,
        visible: true,
        titulo: tipo === "nosotros" ? "Quiénes somos" : null,
        texto: null,
        foto_url: null,
      })),
      banners: [],
    },
    cambios_sin_publicar: false,
    publicado_en: null,
    pagina_publica: true,
    tiene_portada: false,
    catalogo: {
      plantillas: [
        { clave: "esencial", orden: ORDEN.slice(1, -1) },
        {
          clave: "portada",
          orden: [
            "promociones",
            "servicios",
            "equipo",
            "nosotros",
            "agenda",
            "horario",
            "precios",
            "resenas",
            "sucursales",
          ],
        },
        { clave: "compacta", orden: ORDEN.slice(1, -1) },
      ],
      con_texto: ["inicio", "nosotros", "contacto"],
      con_foto: ["nosotros"],
      max_titulo: 80,
      max_texto: 2000,
      banners_maximos: 2,
    },
    ...cambios,
  };
}

function montar() {
  return mount(SitioWebView, {
    attachTo: document.body,
    global: { plugins: [i18n], stubs: { CargadorImagen: true } },
  });
}
type Vista = ReturnType<typeof montar>;

function orden(w: Vista): string[] {
  return w
    .findAll('[data-prueba^="seccion-"]')
    .map((li) => li.attributes("data-prueba")!.replace("seccion-", ""));
}

describe("constructor del sitio", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: datos() } });
    api.put.mockImplementation((_url: string, cuerpo: unknown) =>
      Promise.resolve({
        data: {
          data: datos({ borrador: cuerpo, cambios_sin_publicar: true }),
        },
      }),
    );
    api.post.mockResolvedValue({
      data: { data: datos({ publicado_en: "2026-10-10T12:00:00Z" }) },
    });
  });

  it("ordena y oculta secciones; la portada y el contacto no se mueven", async () => {
    const w = montar();
    await flushPromises();
    expect(orden(w)).toEqual(ORDEN);
    expect(w.get('[data-prueba="subir-inicio"]').attributes()).toHaveProperty(
      "disabled",
    );
    expect(w.find('[data-prueba="visible-inicio"]').exists()).toBe(false);
    expect(
      w.get('[data-prueba="subir-promociones"]').attributes(),
    ).toHaveProperty("disabled");

    await w.get('[data-prueba="bajar-nosotros"]').trigger("click");
    expect(orden(w).slice(1, 4)).toEqual(["promociones", "agenda", "nosotros"]);
    await w.get('[data-prueba="visible-resenas"]').setValue(false);

    await w.get('[data-prueba="guardar-sitio"]').trigger("click");
    await flushPromises();
    const enviado = api.put.mock.calls[0][1];
    expect(enviado.secciones.map((s: { tipo: string }) => s.tipo)).toEqual(
      orden(w),
    );
    expect(
      enviado.secciones.find((s: { tipo: string }) => s.tipo === "resenas")
        .visible,
    ).toBe(false);
    w.unmount();
  });

  it("otra plantilla cambia el orden y conserva los textos", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="plantilla-portada"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(orden(w).slice(0, 4)).toEqual([
      "inicio",
      "promociones",
      "servicios",
      "equipo",
    ]);
    await w.get('[data-prueba="editar-nosotros"]').trigger("click");
    expect(
      (w.get("#sw-titulo-nosotros").element as HTMLInputElement).value,
    ).toBe("Quiénes somos");
    w.unmount();
  });

  it("agrega banners hasta su tope", async () => {
    const w = montar();
    await flushPromises();
    const agregar = w.get('[data-prueba="agregar-banner"]');
    await agregar.trigger("click");
    await agregar.trigger("click");
    expect(w.findAll('[data-prueba^="banner-"]')).toHaveLength(2);
    expect(agregar.attributes()).toHaveProperty("disabled");
    expect(w.text()).toContain("Puedes tener hasta 2 banners.");
    w.unmount();
  });

  it("publicar confirma y guarda antes lo que está en pantalla", async () => {
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="bajar-nosotros"]').trigger("click");
    await w.get('[data-prueba="publicar-sitio"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(api.put).toHaveBeenCalledTimes(1);
    expect(api.post).toHaveBeenCalledWith("/api/v1/app/fluo/sitio/publicar");
    expect(w.get('[data-prueba="estado-sitio"]').text()).toContain("Publicado");
    w.unmount();
  });

  it("avisa si la página no está abierta al público", async () => {
    api.get.mockResolvedValue({
      data: { data: datos({ pagina_publica: false }) },
    });
    const w = montar();
    await flushPromises();
    expect(w.find('[data-prueba="pagina-oculta"]').exists()).toBe(true);
    // La vista previa es la del borrador, en el mismo origen que el panel.
    expect(w.get("iframe").attributes("src")).toBe(
      "/estudio/fluo/vista-previa",
    );
    w.unmount();
  });
});

describe("bloques propios del sitio (ADR 0146)", () => {
  const CATALOGO_BLOQUES = {
    bloques: [
      "texto_imagen",
      "galeria",
      "preguntas",
      "testimonios",
      "llamado",
      "video",
      "beneficios",
      "formulario",
    ],
    bloques_maximos: 2,
    fondos: ["normal", "suave", "marca", "oscuro"],
    alineaciones: ["izquierda", "centro"],
    max_elementos: 12,
    max_preguntas: 20,
    formularios: [{ id: "f1", nombre: "Pedir informes" }],
  };
  const conBloques = (cambios: Record<string, unknown> = {}) =>
    datos({
      catalogo: { ...datos().catalogo, ...CATALOGO_BLOQUES },
      ...cambios,
    });

  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: conBloques() } });
    api.put.mockImplementation((_url: string, cuerpo: unknown) =>
      Promise.resolve({
        data: {
          data: conBloques({ borrador: cuerpo, cambios_sin_publicar: true }),
        },
      }),
    );
  });

  function montarBloques() {
    return mount(SitioWebView, {
      attachTo: document.body,
      global: {
        plugins: [i18n],
        stubs: {
          CargadorImagen: true,
          ModalDialogo: {
            props: ["abierto", "titulo"],
            template: '<div v-if="abierto"><slot /></div>',
          },
        },
      },
    });
  }
  async function agregar(w: ReturnType<typeof montarBloques>, tipo: string) {
    await w.get('[data-prueba="agregar-bloque"]').trigger("click");
    await w.get(`[data-prueba="nuevo-${tipo}"]`).trigger("click");
  }

  it("agrega un bloque antes del contacto, ya abierto, y lo guarda con su contenido y estilo", async () => {
    const w = montarBloques();
    await flushPromises();
    await agregar(w, "preguntas");

    const lista = orden(w);
    expect(lista.at(-1)).toBe("contacto");
    expect(lista.at(-2)).toMatch(/^[a-z0-9]{20}$/);
    const editor = w.get('[data-prueba="editor-preguntas"]');
    await editor
      .get('[data-prueba="bloque-items"] input')
      .setValue("¿Necesito experiencia?");
    await editor
      .findAll('[data-prueba="bloque-fondo"] button')
      .find((b) => b.text() === "Suave")!
      .trigger("click");

    await w.get('[data-prueba="guardar-sitio"]').trigger("click");
    await flushPromises();
    const bloque = api.put.mock.calls[0][1].secciones.at(-2);
    expect(bloque).toMatchObject({
      tipo: "preguntas",
      visible: true,
      estilo: { fondo: "suave", alineacion: "izquierda" },
      datos: {
        items: [{ pregunta: "¿Necesito experiencia?", respuesta: null }],
      },
    });
    w.unmount();
  });

  it("duplica y quita bloques, hasta su tope", async () => {
    const w = montarBloques();
    await flushPromises();
    await agregar(w, "llamado");
    const id = orden(w).at(-2)!;
    await w.get(`[data-prueba="duplicar-${id}"]`).trigger("click");

    expect(orden(w).filter((c) => /^[a-z0-9]{20}$/.test(c))).toHaveLength(2);
    expect(w.get('[data-prueba="agregar-bloque"]').attributes()).toHaveProperty(
      "disabled",
    );
    expect(w.text()).toContain("Tu sitio puede tener hasta 2 bloques propios.");

    await w.get(`[data-prueba="editar-${id}"]`).trigger("click");
    await w.get(`[data-prueba="quitar-${id}"]`).trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(orden(w)).not.toContain(id);
    w.unmount();
  });

  it("otra plantilla conserva los bloques, antes del contacto", async () => {
    const w = montarBloques();
    await flushPromises();
    await agregar(w, "galeria");
    const id = orden(w).at(-2)!;

    await w.get('[data-prueba="plantilla-portada"]').trigger("click");
    await flushPromises();
    expect(orden(w).slice(-2)).toEqual([id, "contacto"]);
    expect(orden(w)[1]).toBe("promociones");
    w.unmount();
  });
});

describe("páginas del sitio (ADR 0146, fase 2)", () => {
  const CATALOGO = {
    bloques: ["texto_imagen", "preguntas", "llamado"],
    bloques_maximos: 5,
    fondos: ["normal", "suave"],
    alineaciones: ["izquierda", "centro"],
    max_elementos: 12,
    max_preguntas: 20,
    formularios: [],
    paginas_maximas: 2,
    movibles: ORDEN.slice(1, -1),
    max_direccion: 40,
    max_titulo_pagina: 60,
    max_seo_titulo: 70,
    max_seo_descripcion: 160,
    max_pie: 300,
  };
  const conPaginas = (cambios: Record<string, unknown> = {}) => {
    const base = datos();
    return {
      ...base,
      borrador: {
        ...base.borrador,
        seo: { titulo: null, descripcion: null, imagen_url: null },
        paginas: [],
        pie: { texto: null },
      },
      catalogo: { ...base.catalogo, ...CATALOGO },
      ...cambios,
    };
  };

  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: conPaginas() } });
    api.put.mockImplementation((_url: string, cuerpo: unknown) =>
      Promise.resolve({
        data: {
          data: conPaginas({ borrador: cuerpo, cambios_sin_publicar: true }),
        },
      }),
    );
  });

  function montarPaginas() {
    return mount(SitioWebView, {
      attachTo: document.body,
      global: {
        plugins: [i18n],
        stubs: {
          CargadorImagen: true,
          ModalDialogo: {
            props: ["abierto", "titulo"],
            template: '<div v-if="abierto"><slot /></div>',
          },
        },
      },
    });
  }
  async function crear(w: ReturnType<typeof montarPaginas>, titulo: string) {
    await w.get('[data-prueba="agregar-pagina"]').trigger("click");
    await w.get('[data-prueba="titulo-pagina-nueva"]').setValue(titulo);
    await w.get('[data-prueba="crear-pagina"]').trigger("submit");
  }

  it("crea una página, le pone secciones del negocio y bloques, y la guarda", async () => {
    const w = montarPaginas();
    await flushPromises();
    await crear(w, "Preguntas frecuentes");

    expect(w.find('[data-prueba="pagina-preguntas-frecuentes"]').exists()).toBe(
      true,
    );
    expect(w.get('[data-prueba="titulo-secciones"]').text()).toBe(
      "Secciones de «Preguntas frecuentes»",
    );
    expect(w.find('[data-prueba="pagina-vacia"]').exists()).toBe(true);
    expect(w.get("iframe").attributes("src")).toBe(
      "/estudio/fluo/vista-previa?pagina=preguntas-frecuentes",
    );

    await w.get('[data-prueba="agregar-bloque"]').trigger("click");
    await w.get('[data-prueba="nueva-seccion-precios"]').trigger("click");
    await w.get('[data-prueba="agregar-bloque"]').trigger("click");
    // Ya no ofrece la que tiene.
    expect(w.find('[data-prueba="nueva-seccion-precios"]').exists()).toBe(
      false,
    );
    await w.get('[data-prueba="nuevo-preguntas"]').trigger("click");

    await w.get('[data-prueba="guardar-sitio"]').trigger("click");
    await flushPromises();
    const enviado = api.put.mock.calls[0][1];
    expect(enviado.paginas).toHaveLength(1);
    expect(enviado.paginas[0]).toMatchObject({
      slug: "preguntas-frecuentes",
      titulo: "Preguntas frecuentes",
      en_menu: true,
    });
    expect(
      enviado.paginas[0].secciones.map((s: { tipo: string }) => s.tipo),
    ).toEqual(["precios", "preguntas"]);
    // Inicio no cambió.
    expect(enviado.secciones).toHaveLength(ORDEN.length);
    w.unmount();
  });

  it("la dirección se escribe como queda; quitar la página vuelve a Inicio; hasta su tope", async () => {
    const w = montarPaginas();
    await flushPromises();
    await crear(w, "Precios");
    // Recién creada, su configuración ya está abierta.
    const direccion = w.get('[data-prueba="direccion-0"]');
    await direccion.setValue("Mis Precios!");
    await direccion.trigger("change");
    expect(w.find('[data-prueba="pagina-mis-precios"]').exists()).toBe(true);

    await crear(w, "Nosotros");
    expect(w.get('[data-prueba="agregar-pagina"]').attributes()).toHaveProperty(
      "disabled",
    );
    expect(w.text()).toContain(
      "Tu sitio puede tener hasta 2 páginas, además de Inicio.",
    );

    await w.get('[data-prueba="quitar-pagina-nosotros"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(w.find('[data-prueba="pagina-nosotros"]').exists()).toBe(false);
    expect(w.get('[data-prueba="titulo-secciones"]').text()).toBe("Secciones");
    w.unmount();
  });
});

describe("estilo del sitio y versiones (ADR 0146, fase 3)", () => {
  const conEstilo = (cambios: Record<string, unknown> = {}) => {
    const base = datos();
    return {
      ...base,
      color_marca: "#aa3366",
      versiones: [
        {
          id: "v2",
          publicada_en: "2026-10-01T18:00:00Z",
          publicada_por: "Dueña",
          actual: true,
        },
        {
          id: "v1",
          publicada_en: "2026-09-20T18:00:00Z",
          publicada_por: null,
          actual: false,
        },
      ],
      borrador: {
        ...base.borrador,
        seo: { titulo: null, descripcion: null, imagen_url: null },
        paginas: [],
        pie: { texto: null },
        estilo: {
          color: null,
          tipografia: "predeterminada",
          esquinas: "suaves",
          tema: "automatico",
        },
        encabezado: { boton_texto: null, boton_enlace: null },
      },
      catalogo: {
        ...base.catalogo,
        tipografias: ["predeterminada", "moderna", "elegante"],
        esquinas: ["rectas", "suaves", "redondas"],
        temas: ["automatico", "claro", "oscuro"],
      },
      ...cambios,
    };
  };

  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: conEstilo() } });
    api.put.mockImplementation((_url: string, cuerpo: unknown) =>
      Promise.resolve({
        data: {
          data: conEstilo({ borrador: cuerpo, cambios_sin_publicar: true }),
        },
      }),
    );
    api.post.mockResolvedValue({
      data: { data: conEstilo({ cambios_sin_publicar: true }) },
    });
  });

  it("elige tipografía, esquinas, fondo y el botón principal, y lo guarda", async () => {
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="tipografia-elegante"]').trigger("click");
    await w.get('[data-prueba="esquinas-redondas"]').trigger("click");
    await w.get('[data-prueba="tema-oscuro"]').trigger("click");
    await w.get('[data-prueba="boton-principal-texto"]').setValue("Agendar");
    await w.get('[data-prueba="boton-principal-enlace"]').setValue("#agenda");
    await w.get('[data-prueba="estilo-color"]').setValue("#123456");
    await w.get('[data-prueba="estilo-color"]').trigger("change");

    await w.get('[data-prueba="guardar-sitio"]').trigger("click");
    await flushPromises();
    const enviado = api.put.mock.calls[0][1];
    expect(enviado.estilo).toEqual({
      color: "#123456",
      tipografia: "elegante",
      esquinas: "redondas",
      tema: "oscuro",
    });
    expect(enviado.encabezado).toEqual({
      boton_texto: "Agendar",
      boton_enlace: "#agenda",
    });
    w.unmount();
  });

  it("sin color propio usa el de su marca; con uno, vuelve a la marca", async () => {
    const w = montar();
    await flushPromises();
    const color = w.get('[data-prueba="estilo-color"]');
    expect((color.element as HTMLInputElement).value).toBe("#aa3366");
    expect(w.find('[data-prueba="estilo-color-marca"]').exists()).toBe(false);

    await color.setValue("#123456");
    await color.trigger("change");
    await w.get('[data-prueba="estilo-color-marca"]').trigger("click");
    expect((color.element as HTMLInputElement).value).toBe("#aa3366");
    w.unmount();
  });

  it("el color de la página web no es el de la app del negocio: cada uno dice dónde se usa", async () => {
    const w = montar();
    await flushPromises();
    const sitio = w.get('[data-prueba="color-sitio"]');
    expect(sitio.text()).toContain("Color de tu página web");
    expect(sitio.text()).toContain("El mismo de tu app");
    expect(sitio.text()).toContain("No cambia la app de tu negocio.");
    const app = w.get('[data-prueba="color-app"]');
    expect(app.text()).toContain("Color de la app de tu negocio");
    expect(app.text()).toContain("#aa3366");
    expect(app.get('[data-prueba="ir-color-app"]').text()).toBe(
      "Configuración › Perfil público",
    );
    // El de la app solo se consulta aquí: no hay con qué cambiarlo.
    expect(app.find("input").exists()).toBe(false);

    // Con un color propio, el sitio cambia y el de la app sigue igual.
    await w.get('[data-prueba="estilo-color"]').setValue("#123456");
    await w.get('[data-prueba="estilo-color"]').trigger("change");
    expect(sitio.text()).toContain("#123456");
    expect(sitio.text()).toContain("Usar el color de tu app");
    expect(app.text()).toContain("#aa3366");
    w.unmount();
  });

  it("sin color de la app, dice que usa el de la plataforma", async () => {
    api.get.mockResolvedValue({
      data: { data: conEstilo({ color_marca: null }) },
    });
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="color-app"]').text()).toContain(
      "El de la plataforma",
    );
    w.unmount();
  });

  it("salir con cambios sin guardar pregunta antes; guardados, sale sin preguntar", async () => {
    const w = montar();
    await flushPromises();
    const guardias = vi
      .mocked(onBeforeRouteLeave)
      .mock.calls.map(([guardia]) => guardia);
    expect(guardias.length).toBeGreaterThan(0);
    async function salir(): Promise<boolean> {
      for (const guardia of guardias) {
        const sigue = await (
          guardia as unknown as (
            a: { path: string },
            b: { path: string },
          ) => Promise<boolean> | boolean
        )({ path: "/panel" }, { path: "/sitio" });
        if (sigue === false) {
          return false;
        }
      }
      return true;
    }

    // Sin cambios: sale sin preguntar.
    expect(await salir()).toBe(true);
    expect(confirmar).not.toHaveBeenCalled();

    // Con un cambio sin guardar: pregunta y, si no se confirma, se queda.
    await w.get('[data-prueba="tipografia-elegante"]').trigger("click");
    confirmar.mockResolvedValueOnce(false);
    expect(await salir()).toBe(false);
    expect(confirmar).toHaveBeenCalledTimes(1);
    expect(confirmar.mock.calls[0]![0]).toBe(
      i18n.global.t("comun.cambiosSinGuardar"),
    );

    // Guardado el borrador, ya no hay nada que perder.
    await w.get('[data-prueba="guardar-sitio"]').trigger("click");
    await flushPromises();
    expect(await salir()).toBe(true);
    expect(confirmar).toHaveBeenCalledTimes(1);
    w.unmount();
  });

  it("vuelve a una versión publicada: confirma y la deja como borrador", async () => {
    const w = montar();
    await flushPromises();
    const versiones = w.findAll('[data-prueba="version"]');
    expect(versiones).toHaveLength(2);
    expect(versiones[0]!.text()).toContain("Publicada ahora");
    expect(versiones[0]!.text()).toContain("Por Dueña");

    await w.get('[data-prueba="restaurar-v1"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/fluo/sitio/versiones/v1/restaurar",
    );
    w.unmount();
  });
});

describe("editor visual (ADR 0146, fase 3)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ data: { data: datos() } });
  });

  function desdePrevia(
    w: Vista,
    data: Record<string, unknown>,
    fuente?: Window | null,
  ) {
    const marco = w.get("iframe").element as HTMLIFrameElement;
    window.dispatchEvent(
      new MessageEvent("message", {
        data: { fuente: "agendauno-sitio", ...data },
        origin: window.location.origin,
        source: fuente === undefined ? marco.contentWindow : fuente,
      }),
    );
    return marco;
  }

  it("un clic en la vista previa abre la sección; lo que se edita llega en vivo", async () => {
    const w = montar();
    await flushPromises();
    const marco = desdePrevia(w, { tipo: "lista" });
    const enviar = vi.spyOn(marco.contentWindow!, "postMessage");

    desdePrevia(w, { tipo: "editar", pagina: null, seccion: "nosotros" });
    await flushPromises();
    expect(
      w
        .get('[data-prueba="seccion-nosotros"] [aria-expanded]')
        .attributes("aria-expanded"),
    ).toBe("true");
    // Ya se ve en la vista previa: no hay que llevarla.
    expect(enviar).not.toHaveBeenCalled();

    await w.get("#sw-titulo-nosotros").setValue("Así somos");
    await new Promise((r) => setTimeout(r, 400));
    expect(enviar).toHaveBeenCalledWith(
      expect.objectContaining({
        fuente: "agendauno-sitio",
        tipo: "borrador",
        borrador: expect.objectContaining({
          secciones: expect.arrayContaining([
            expect.objectContaining({ tipo: "nosotros", titulo: "Así somos" }),
          ]),
        }),
      }),
      window.location.origin,
    );
    expect(w.get('[data-prueba="previa-estado"]').text()).toContain("En vivo");
    w.unmount();
  });

  it("abrir una sección en el editor la lleva a la vista en la vista previa", async () => {
    const w = montar();
    await flushPromises();
    const marco = desdePrevia(w, { tipo: "lista" });
    const enviar = vi.spyOn(marco.contentWindow!, "postMessage");

    await w
      .get('[data-prueba="seccion-nosotros"] [aria-expanded]')
      .trigger("click");
    await flushPromises();
    expect(enviar).toHaveBeenCalledWith(
      { fuente: "agendauno-sitio", tipo: "enfocar", seccion: "nosotros" },
      window.location.origin,
    );
    w.unmount();
  });

  it("avisa si el borrador aún no se puede mostrar e ignora mensajes de otra ventana", async () => {
    const w = montar();
    await flushPromises();
    desdePrevia(w, { tipo: "lista" });

    desdePrevia(
      w,
      { tipo: "editar", pagina: null, seccion: "nosotros" },
      window,
    );
    await flushPromises();
    expect(
      w
        .get('[data-prueba="seccion-nosotros"] [aria-expanded]')
        .attributes("aria-expanded"),
    ).toBe("false");

    desdePrevia(w, { tipo: "estado", valido: false });
    await flushPromises();
    expect(w.get('[data-prueba="previa-estado"]').text()).toContain(
      "por completar",
    );
    w.unmount();
  });
});
