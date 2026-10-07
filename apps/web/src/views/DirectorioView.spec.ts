import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import modalidadNegocio from "@/i18n/locales/modalidad.es-MX";
import { PERFILES_POR_MODO } from "@/marketing/modalidades";
import DirectorioView from "./DirectorioView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: { get: mocks.get },
  mensajeDeError: () => "No disponible",
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
vi.mock("@/lib/negociosRecientes", () => ({ recordarNegocio: vi.fn() }));
vi.mock("vue-router", () => ({
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));

const negocio = (slug: string, perfil: string) => ({
  slug,
  nombre: `Negocio ${slug}`,
  perfil,
  logo_url: null,
  ciudad: "Puebla",
  pais: "México",
});

function montar() {
  return mount(DirectorioView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { ...es, modalidadNegocio } },
        }),
      ],
    },
  });
}

describe("directorio de negocios", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.get.mockResolvedValue({ data: { data: [] } });
  });

  it("filtra por todos los giros del registro, agrupados por modalidad", async () => {
    const vista = montar();
    await flushPromises();
    const grupos = vista.findAll("select optgroup");
    expect(grupos.map((g) => g.attributes("label"))).toEqual([
      "Clases con cupo",
      "Citas 1 a 1",
    ]);
    // La misma lista que el registro (y que acepta el filtro del API): incluye los
    // dos giros generales.
    expect(
      grupos.map((g) => g.findAll("option").map((o) => o.attributes("value"))),
    ).toEqual([PERFILES_POR_MODO.clases, PERFILES_POR_MODO.citas]);
    expect(grupos[1]!.get('option[value="general_citas"]').text()).toBe(
      "Otro negocio de citas",
    );

    await vista.get("select").setValue("general_citas");
    await vista.get("form").trigger("submit");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/directorio", {
      params: { perfil: "general_citas" },
    });
  });

  it("los giros generales no llevan insignia y tienen foto de su modalidad", async () => {
    mocks.get.mockResolvedValue({
      data: {
        data: [
          negocio("pilates", "pilates"),
          negocio("clases", "general"),
          negocio("citas", "general_citas"),
        ],
      },
    });
    const vista = montar();
    await flushPromises();
    const tarjetas = vista.findAll(".tu-estudio-card");
    expect(tarjetas).toHaveLength(3);
    expect(tarjetas[0]!.get(".tu-estudio-perfil").text()).toBe(
      es.registro.perfiles.pilates,
    );
    for (const tarjeta of tarjetas.slice(1)) {
      expect(tarjeta.find(".tu-estudio-perfil").exists()).toBe(false);
      expect(tarjeta.text()).not.toContain("Otro negocio");
    }
    const foto = (i: number) =>
      tarjetas[i]!.get(".tu-estudio-portada img").attributes("src");
    expect(foto(1)).toBe("/assets/landing/disciplinas/academias-v1.jpg");
    expect(foto(2)).toBe("/assets/landing/disciplinas/wellness-v1.webp");
  });
});
