import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import asistente from "@/i18n/locales/asistente.es-MX";
import esMX from "@/i18n/locales/es-MX";
import OnboardingView from "./OnboardingView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: String }));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    esCitas: false,
    estudio: { logo_url: null },
  }),
}));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
  useRouter: () => ({ push: vi.fn() }),
}));

const PASOS = [
  "marca",
  "sucursal",
  "actividades",
  "horarios",
  "productos",
  "politicas",
  "pasarela",
  "personal",
  "publicacion",
];

function montar() {
  return mount(OnboardingView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, asistente } },
        }),
      ],
    },
  });
}

describe("asistente de configuración", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({
      data: {
        data: { pasos: PASOS, completados: ["marca"], completo: false },
      },
    });
    api.put.mockResolvedValue({
      data: { data: { completados: ["marca", "sucursal"], completo: false } },
    });
    api.post.mockResolvedValue({ data: { data: { id: "x", nombre: "Roma" } } });
  });

  it("muestra los pasos numerados, el avance y empieza en el primero pendiente", async () => {
    const w = montar();
    await flushPromises();

    expect(w.findAll(".ob-paso")).toHaveLength(9);
    expect(w.text()).toContain("Paso 2 de 9");
    expect(w.text()).toContain("11%"); // 1 de 9 completado
    expect(w.find(".ob-hecho").exists()).toBe(true);
    expect(w.find(".ob-actual").text()).toContain("Sucursal");
  });

  it("el pie guarda el paso y avanza; sin datos, no deja continuar", async () => {
    const w = montar();
    await flushPromises();
    const principal = () => w.findAll(".ob-pie button").at(-1)!;

    expect(principal().text()).toContain("Guardar y continuar");
    expect(principal().attributes("disabled")).toBeDefined();

    await w.find("#sn").setValue("Roma Norte");
    await principal().trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/organizaciones/x/sucursales",
      expect.objectContaining({ nombre: "Roma Norte" }),
    );
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/onboarding",
      expect.objectContaining({ paso: "sucursal" }),
    );
    expect(w.find(".ob-actual").text()).toContain("Actividades");
  });
});
