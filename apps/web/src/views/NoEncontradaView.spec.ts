import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import NoEncontradaView from "./NoEncontradaView.vue";

/*
| La página de lo que no existe (ADR 0174): lo dice, no se indexa y ofrece volver.
*/

const mocks = vi.hoisted(() => ({
  updateSeo: vi.fn(),
  back: vi.fn(),
  sesion: { autenticado: false },
}));
vi.mock("@/lib/seo", () => ({ updateSeo: mocks.updateSeo }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => mocks.sesion,
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ path: "/esto/no-existe" }),
  useRouter: () => ({ back: mocks.back }),
  RouterLink: {
    props: ["to"],
    template: '<a :href="to"><slot /></a>',
  },
}));

function montar() {
  return mount(NoEncontradaView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
}

describe("página no encontrada", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.sesion.autenticado = false;
    window.history.replaceState(null, "");
  });

  it("dice que no existe, no se indexa y lleva al inicio", () => {
    const w = montar();

    expect(w.text()).toContain("No encontramos esta página");
    expect(w.get('[data-prueba="ne-inicio"]').attributes("href")).toBe("/");
    expect(w.get('[data-prueba="ne-inicio"]').text()).toBe("Ir al inicio");
    expect(mocks.updateSeo).toHaveBeenCalledWith(
      expect.objectContaining({ path: "/esto/no-existe", index: false }),
    );
    // Abrió el enlace directo: no hay a dónde volver.
    expect(w.find('[data-prueba="ne-volver"]').exists()).toBe(false);
  });

  it("con sesión lleva a su inicio; si venía de otra página, puede volver", async () => {
    mocks.sesion.autenticado = true;
    window.history.replaceState({ back: "/agenda" }, "");
    const w = montar();

    expect(w.get('[data-prueba="ne-inicio"]').text()).toBe("Ir a mi inicio");
    await w.get('[data-prueba="ne-volver"]').trigger("click");
    expect(mocks.back).toHaveBeenCalled();
  });
});
