import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

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

function lista(datos: unknown[], abiertos = datos.length) {
  return {
    data: {
      data: datos,
      meta: {
        page: 1,
        ultima_pagina: 1,
        conteos: { abierto: abiertos, resuelto: 2, ignorado: 0 },
      },
    },
  };
}

function montar() {
  return mount(ErroresPlataforma, {
    props: { apiUrl: "http://api", token: "tk" },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { plataformaAdmin } },
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
});
