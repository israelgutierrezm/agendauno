import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { plataformaAdmin } from "@/i18n/locales/gestion.es-MX";
import ErroresPlataforma from "./ErroresPlataforma.vue";

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }));
vi.mock("axios", () => ({ default: { create: () => http } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: unknown) => String(e) }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const resumen = {
  id: "01ERR",
  origen: "api",
  tipo: "Illuminate\\Database\\QueryException",
  mensaje: "SQLSTATE[23000]: Duplicate entry '?' for key '?'",
  lugar: "app/Modules/Tenancy/Application/CobrarOrdenTenant.php:88",
  veces: 3,
  primera_en: "2026-09-30T10:00:00Z",
  ultima_en: "2026-09-30T12:00:00Z",
  version_primera: "abc",
  version_ultima: "def",
  estado: "abierto",
  resuelto_en: null,
  regresiones: 1,
  estudio: "barberia",
};

function lista(
  datos: unknown[],
  abiertos = datos.length,
  { page = 1, ultimaPagina = 1, total = datos.length } = {},
) {
  return {
    data: {
      data: datos,
      meta: {
        total,
        page,
        ultima_pagina: ultimaPagina,
        conteos: { abierto: abiertos, resuelto: 2, ignorado: 0 },
      },
    },
  };
}

/** El error `i` de una lista larga (para las páginas). */
function errorNumero(i: number) {
  return {
    ...resumen,
    id: `01E${String(i).padStart(3, "0")}`,
    mensaje: `Falla ${i}`,
    regresiones: 0,
  };
}

/** Los errores `desde`…`hasta`, numerados. */
function errores(desde: number, hasta: number) {
  return Array.from({ length: hasta - desde + 1 }, (_, i) =>
    errorNumero(desde + i),
  );
}

/**
 * Sesenta abiertos: la API entrega lotes de 50, así que son dos lotes y cuatro
 * páginas. `segundo` responde el lote 2 (para dejarlo pendiente o hacerlo fallar).
 */
function sesentaAbiertos(
  segundo: () => Promise<unknown> = () =>
    Promise.resolve(
      lista(errores(51, 60), 60, { page: 2, ultimaPagina: 2, total: 60 }),
    ),
) {
  http.get.mockImplementation(
    (_url: string, opciones: { params: { page: number } }) =>
      opciones.params.page === 1
        ? Promise.resolve(
            lista(errores(1, 50), 60, { page: 1, ultimaPagina: 2, total: 60 }),
          )
        : segundo(),
  );
}

/** El número de la página marcada como actual en el paginador. */
function paginaActual(w: ReturnType<typeof montar>): string {
  return w
    .get('[data-prueba="paginas-errores"] [aria-current="page"]')
    .text()
    .trim();
}

function montar() {
  return mount(ErroresPlataforma, {
    props: { apiUrl: "http://api", token: "tk" },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: {
            es: { plataformaAdmin, tabla: esMX.tabla, comun: esMX.comun },
          },
        }),
      ],
      stubs: {
        PanelLateral: {
          props: ["abierto", "titulo"],
          template:
            '<div v-if="abierto" data-prueba="panel"><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  http.get.mockImplementation((url: string) =>
    Promise.resolve(
      url.endsWith("/01ERR")
        ? {
            data: {
              data: {
                ...resumen,
                traza:
                  "QueryException en vendor/…\n#0 app/…:88 CobrarOrdenTenant->cobrar()",
                contexto: {
                  ruta: "api/v1/app/{estudio}/ordenes",
                  rol: "recepcionista",
                },
              },
            },
          }
        : lista([resumen]),
    ),
  );
});

describe("errores de la plataforma", () => {
  it("lista los abiertos con cuántas veces, dónde y si volvió", async () => {
    const w = montar();
    await flushPromises();

    expect(http.get).toHaveBeenCalledWith("/api/v1/plataforma/errores", {
      headers: { Authorization: "Bearer tk" },
      params: { estado: "abierto", origen: undefined, page: 1 },
    });
    expect(w.get('[data-prueba="estado-abierto"]').text()).toContain("1");
    expect(w.get('[data-prueba="estado-resuelto"]').text()).toContain("2");
    const fila = w.get('[data-prueba="error"]').text();
    expect(fila).toContain("QueryException");
    expect(fila).toContain("CobrarOrdenTenant.php:88");
    expect(fila).toContain("3 veces");
    expect(fila).toContain("barberia");
    expect(w.get('[data-prueba="volvio"]').text()).toContain("Volvió");
  });

  it("abre el detalle con traza y contexto, y lo marca resuelto", async () => {
    http.put.mockResolvedValue({
      data: { data: { ...resumen, estado: "resuelto" } },
    });
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="error"]').trigger("click");
    await flushPromises();
    expect(w.get('[data-prueba="traza"]').text()).toContain(
      "CobrarOrdenTenant->cobrar()",
    );
    expect(w.get('[data-prueba="contexto"]').text()).toContain("recepcionista");

    http.get.mockResolvedValue(lista([], 0));
    await w.get('[data-prueba="resolver"]').trigger("click");
    await flushPromises();

    expect(http.put).toHaveBeenCalledWith(
      "/api/v1/plataforma/errores/01ERR",
      { estado: "resuelto" },
      { headers: { Authorization: "Bearer tk" } },
    );
    expect(toast.exito).toHaveBeenCalled();
    expect(w.find('[data-prueba="panel"]').exists()).toBe(false);
    expect(w.get('[data-prueba="vacio"]').text()).toBe(
      "No hay errores abiertos.",
    );
  });

  it("filtra por estado y por origen", async () => {
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="estado-ignorado"]').trigger("click");
    await flushPromises();
    await w.get("select").setValue("web");
    await flushPromises();

    expect(http.get).toHaveBeenLastCalledWith("/api/v1/plataforma/errores", {
      headers: { Authorization: "Bearer tk" },
      params: { estado: "ignorado", origen: "web", page: 1 },
    });
  });

  it("muestra 15 por página y pide el lote siguiente de la API al llegar a él", async () => {
    sesentaAbiertos();
    const w = montar();
    await flushPromises();

    let filas = w.findAll('[data-prueba="error"]');
    expect(filas).toHaveLength(15);
    expect(filas[0]!.text()).toContain("Falla 1");
    expect(http.get).toHaveBeenCalledTimes(1);

    await w.get('button[aria-label="Página 4"]').trigger("click");
    await flushPromises();

    expect(http.get).toHaveBeenLastCalledWith("/api/v1/plataforma/errores", {
      headers: { Authorization: "Bearer tk" },
      params: { estado: "abierto", origen: undefined, page: 2 },
    });
    filas = w.findAll('[data-prueba="error"]');
    expect(filas).toHaveLength(15);
    expect(filas[0]!.text()).toContain("Falla 46");
    expect(filas[14]!.text()).toContain("Falla 60");

    // Volver a una página ya traída no la pide otra vez.
    await w.get('button[aria-label="Página 2"]').trigger("click");
    await flushPromises();
    expect(http.get).toHaveBeenCalledTimes(2);
    expect(w.findAll('[data-prueba="error"]')[0]!.text()).toContain("Falla 16");
  });

  it("al actualizar desde la página 4 se queda en ella sin pasar por la 1", async () => {
    sesentaAbiertos();
    const w = montar();
    await flushPromises();
    await w.get('button[aria-label="Página 4"]').trigger("click");
    await flushPromises();
    expect(paginaActual(w)).toBe("4");

    // El lote 2 tarda: mientras llega, se sigue viendo la página 4 completa (ni la 1
    // ni una 4 recortada con solo lo del lote 1).
    let soltar: (respuesta: unknown) => void = () => {};
    sesentaAbiertos(
      () =>
        new Promise((resolver) => {
          soltar = resolver;
        }),
    );
    await w.get('[data-prueba="actualizar"]').trigger("click");
    await flushPromises();

    expect(http.get).toHaveBeenLastCalledWith("/api/v1/plataforma/errores", {
      headers: { Authorization: "Bearer tk" },
      params: { estado: "abierto", origen: undefined, page: 2 },
    });
    expect(paginaActual(w)).toBe("4");
    let filas = w.findAll('[data-prueba="error"]');
    expect(filas).toHaveLength(15);
    expect(filas[0]!.text()).toContain("Falla 46");

    soltar(lista(errores(51, 60), 60, { page: 2, ultimaPagina: 2, total: 60 }));
    await flushPromises();

    expect(http.get).toHaveBeenCalledTimes(4);
    expect(paginaActual(w)).toBe("4");
    filas = w.findAll('[data-prueba="error"]');
    expect(filas).toHaveLength(15);
    expect(filas[0]!.text()).toContain("Falla 46");
    expect(filas[14]!.text()).toContain("Falla 60");
  });

  it("si falla un lote al actualizar, nada cambia y «Reintentar» vuelve a la misma página", async () => {
    sesentaAbiertos();
    const w = montar();
    await flushPromises();
    await w.get('button[aria-label="Página 4"]').trigger("click");
    await flushPromises();

    sesentaAbiertos(() => Promise.reject(new Error("sin red")));
    await w.get('[data-prueba="actualizar"]').trigger("click");
    await flushPromises();

    expect(w.get('[role="alert"]').text()).toContain("sin red");
    expect(paginaActual(w)).toBe("4");
    expect(w.findAll('[data-prueba="error"]')[0]!.text()).toContain("Falla 46");

    sesentaAbiertos();
    await w.get('[data-prueba="reintentar"]').trigger("click");
    await flushPromises();

    expect(w.find('[role="alert"]').exists()).toBe(false);
    expect(http.get).toHaveBeenLastCalledWith("/api/v1/plataforma/errores", {
      headers: { Authorization: "Bearer tk" },
      params: { estado: "abierto", origen: undefined, page: 2 },
    });
    expect(paginaActual(w)).toBe("4");
    const filas = w.findAll('[data-prueba="error"]');
    expect(filas[0]!.text()).toContain("Falla 46");
    expect(filas[14]!.text()).toContain("Falla 60");
  });

  it("si bajó el total mientras traía lotes, vuelve a pedir desde el lote 1 y acota la página", async () => {
    sesentaAbiertos();
    const w = montar();
    await flushPromises();

    // Mientras tanto otro superadmin resolvió 20: quedan 40, un solo lote, y el lote 2
    // llega vacío.
    http.get.mockImplementation(
      (_url: string, opciones: { params: { page: number } }) =>
        Promise.resolve(
          opciones.params.page === 1
            ? lista(errores(1, 40), 40, { page: 1, ultimaPagina: 1, total: 40 })
            : lista([], 40, { page: 2, ultimaPagina: 1, total: 40 }),
        ),
    );
    await w.get('button[aria-label="Página 4"]').trigger("click");
    await flushPromises();

    expect(
      http.get.mock.calls.map(
        (llamada) => (llamada[1] as { params: { page: number } }).params.page,
      ),
    ).toEqual([1, 2, 1]);
    // La página 4 ya no existe: queda en la última (la 3), con lo vigente.
    expect(paginaActual(w)).toBe("3");
    const filas = w.findAll('[data-prueba="error"]');
    expect(filas).toHaveLength(10);
    expect(filas[0]!.text()).toContain("Falla 31");
    expect(filas[9]!.text()).toContain("Falla 40");
    expect(w.get('[data-prueba="paginas-errores"]').text()).toContain(
      "31–40 de 40",
    );
    expect(w.get('[data-prueba="estado-abierto"]').text()).toContain("40");
  });
});
