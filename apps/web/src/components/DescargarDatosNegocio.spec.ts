import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import datosNegocio from "@/i18n/locales/datosNegocio.es-MX";
import DescargarDatosNegocio from "./DescargarDatosNegocio.vue";

/*
| Descargar todos los datos del negocio (ADR 0213): se prepara aparte; lista, se
| descarga con la sesión (no con un enlace suelto).
*/

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  descargar: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get, post: mocks.post },
  mensajeDeError: (e: Error) => e.message,
}));
vi.mock("@/lib/descargas", () => ({ descargarDeApi: mocks.descargar }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "pilates-centro" }),
}));

function montar() {
  return mount(DescargarDatosNegocio, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { datosNegocio } },
        }),
      ],
    },
  });
}

const BASE = "/api/v1/app/pilates-centro/negocio/exportar";

describe("descargar los datos del negocio", () => {
  beforeEach(() => vi.clearAllMocks());

  it("la pide y avisa que se está preparando", async () => {
    mocks.get.mockResolvedValueOnce({ data: { data: null } });
    mocks.post.mockResolvedValueOnce({
      data: {
        data: {
          id: "01X",
          estado: "en_proceso",
          tamano_bytes: null,
          lista_en: null,
          vence_en: null,
          error: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    await w.get('[data-prueba="preparar-datos"]').trigger("click");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(BASE, {});
    expect(w.get('[data-prueba="estado-datos"]').text()).toContain(
      "Lo estamos preparando",
    );
    expect(
      w.get('[data-prueba="preparar-datos"]').attributes("disabled"),
    ).toBeDefined();
    w.unmount();
  });

  it("lista, dice su tamaño y la descarga con la sesión", async () => {
    mocks.get.mockResolvedValueOnce({
      data: {
        data: {
          id: "01X",
          estado: "lista",
          tamano_bytes: 3 * 1024 * 1024,
          lista_en: "2026-10-01T12:00:00+00:00",
          vence_en: "2026-10-08T12:00:00+00:00",
          error: null,
        },
      },
    });
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="estado-datos"]').text()).toContain("3 MB");
    await w.get('[data-prueba="descargar-datos"]').trigger("click");
    await flushPromises();
    expect(mocks.descargar).toHaveBeenCalledWith(
      `${BASE}/01X/descargar`,
      "datos-pilates-centro.zip",
    );
    w.unmount();
  });
});
