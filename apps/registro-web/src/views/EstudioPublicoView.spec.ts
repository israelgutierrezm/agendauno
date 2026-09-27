import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import EstudioPublicoView from "./EstudioPublicoView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get },
  mensajeDeError: () => "No disponible",
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
vi.mock("@/lib/seo", () => ({ updateSeo: vi.fn() }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ registrarAlumno: vi.fn(), rutaInicio: "" }),
}));
vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { slug: "estudio-a" }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));

function escaparate(resenas: unknown) {
  return {
    data: {
      data: {
        estudio: {
          slug: "estudio-a",
          nombre: "Estudio A",
          logo_url: null,
          perfil: "pole",
          perfil_config: {},
          ciudad: null,
          pais: null,
          whatsapp: null,
          tiene_citas: false,
        },
        sucursales: [],
        instructores: [],
        productos: [],
        proximas_sesiones: [],
        resenas,
      },
    },
  };
}

function montar() {
  return mount(EstudioPublicoView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
}

describe("página pública del estudio", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("muestra el promedio y los comentarios que el negocio deja visibles", async () => {
    mocks.get.mockResolvedValue(
      escaparate({
        promedio: 4.5,
        total: 2,
        recientes: [
          {
            calificacion: 5,
            comentario: "Excelente clase",
            nombre: "Ana",
            actividad: "Pole nivel 1",
            fecha: "2030-01-07",
          },
        ],
      }),
    );
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Lo que dicen sus clientes");
    expect(w.text()).toContain("4.5 de 5");
    expect(w.text()).toContain("2 reseñas");
    expect(w.text()).toContain("Excelente clase");
    expect(w.text()).toContain("Ana · Pole nivel 1");
    expect(w.get('[role="img"]').attributes("aria-label")).toBe(
      "5 de 5 estrellas",
    );
  });

  it("sin reseñas visibles no muestra la sección", async () => {
    mocks.get.mockResolvedValue(
      escaparate({ promedio: null, total: 0, recientes: [] }),
    );
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Estudio A");
    expect(w.text()).not.toContain("Lo que dicen sus clientes");
  });
});
