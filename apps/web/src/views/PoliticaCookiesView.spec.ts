import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import PoliticaCookiesView from "./PoliticaCookiesView.vue";

/*
| La política de cookies (ADR 0206): la versión publicada y, arriba, la elección de este
| navegador para el sitio en que se abre, para cambiarla ahí mismo.
*/

const api = vi.hoisted(() => ({ get: vi.fn() }));
const elegir = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api", () => ({ api }));
vi.mock("@/lib/etiquetas", () => ({ elegir }));

function montar() {
  return mount(PoliticaCookiesView, {
    global: { stubs: { RouterLink: { template: "<a><slot /></a>" } } },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  window.localStorage.clear();
  api.get.mockResolvedValue({
    data: {
      data: {
        cookies: "1. Qué cubre\n\nCookies de AgendaUno.",
        versiones: {
          cookies: { version: 2, vigente_desde: "2026-10-20T12:00:00-06:00" },
        },
      },
    },
  });
});

describe("política de cookies", () => {
  it("muestra la versión publicada", async () => {
    const w = montar();
    await flushPromises();
    expect(api.get).toHaveBeenCalledWith("/api/v1/legales");
    expect(w.text()).toContain("Versión 2");
    expect(w.text()).toContain("Cookies de AgendaUno.");
  });

  it("parte de lo que ya eligió y guarda el cambio para el producto", async () => {
    window.localStorage.setItem(
      "tu.preferencias.plataforma:agendauno",
      JSON.stringify({
        analitica: true,
        publicidad: false,
        en: "2026-10-01T12:00:00Z",
        version: 1,
      }),
    );
    const w = montar();
    await flushPromises();
    const analitica = w.get('[data-prueba="cookies-analitica"]');
    expect((analitica.element as HTMLInputElement).checked).toBe(true);

    await analitica.setValue(false);
    await w.get('[data-prueba="cookies-eleccion"]').trigger("submit");
    expect(elegir).toHaveBeenCalledWith(
      { analitica: false, publicidad: false },
      "plataforma:agendauno",
    );
    expect(w.find('[data-prueba="cookies-guardada"]').exists()).toBe(true);
  });
});
