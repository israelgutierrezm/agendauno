import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import es from "@/i18n/locales/es-MX";
import modalidadNegocio from "@/i18n/locales/modalidad.es-MX";
import { trackEvent } from "@/lib/analytics";
import { PERFILES_POR_MODO } from "@/marketing/modalidades";
import { PRECIOS_POR_OMISION } from "@/marketing/precios";
import { aplicarPreciosPublicos } from "@/marketing/preciosPublicos";
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

  it("filtra por los giros de su producto y lista solo sus negocios (ADR 0108)", async () => {
    const vista = montar();
    await flushPromises();
    // En AgendaUno (localhost), los de clases.
    const grupos = vista.findAll("select optgroup");
    expect(grupos.map((g) => g.attributes("label"))).toEqual([
      "Clases con cupo",
    ]);
    // La misma lista que el registro (y que acepta el filtro del API): incluye el
    // giro general.
    expect(
      grupos.map((g) => g.findAll("option").map((o) => o.attributes("value"))),
    ).toEqual([PERFILES_POR_MODO.clases]);
    expect(grupos[0]!.get('option[value="general"]').text()).toBe(
      "Otro negocio con clases",
    );

    await vista.get("select").setValue("general");
    await vista.get("form").trigger("submit");
    await flushPromises();
    expect(mocks.get).toHaveBeenLastCalledWith("/api/v1/directorio", {
      params: { perfil: "general", producto: "agendauno" },
    });
  });

  it("a quien administra un negocio le ofrece probar o, sin registro abierto, avisarle", async () => {
    const abierto = montar();
    await flushPromises();
    const probar = abierto.get('[data-prueba="registrar-negocio"]');
    expect(probar.text()).toBe(es.landing.ctaRegistrar);
    await probar.trigger("click");
    expect(trackEvent).toHaveBeenLastCalledWith("marketing_cta_clicked", {
      placement: "directory",
      destination: "register",
    });
    abierto.unmount();

    aplicarPreciosPublicos({ registro: { agendauno: false, turnouno: false } });
    try {
      const cerrado = montar();
      await flushPromises();
      const avisar = cerrado.get('[data-prueba="registrar-negocio"]');
      expect(avisar.text()).toBe(es.landing.prelanzamiento.cta);
      expect(cerrado.text()).toContain(es.directorio.duenoDescPrelanzamiento);
      expect(cerrado.text()).not.toMatch(/gratis|Crea tu cuenta/);
      await avisar.trigger("click");
      expect(trackEvent).toHaveBeenLastCalledWith("marketing_cta_clicked", {
        placement: "directory",
        destination: "waitlist",
      });
      cerrado.unmount();
    } finally {
      aplicarPreciosPublicos(PRECIOS_POR_OMISION);
    }
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
    // La misma foto por giro que en el resto de la aplicación (lib/fotoNegocio).
    expect(foto(1)).toBe("/assets/landing/disciplinas/wellness-v1.webp");
    expect(foto(2)).toBe("/assets/landing/disciplinas/wellness-v1.webp");
  });

  it("las categorías rápidas son giros del producto del dominio (ADR 0108)", async () => {
    const w = montar();
    await flushPromises();
    const claves = w
      .findAll(".tu-categoria")
      .map((b) => b.text())
      .map(
        (texto) =>
          Object.entries(es.registro.perfiles).find(
            ([, v]) => v === texto,
          )?.[0],
      );
    expect(claves).toHaveLength(4);
    // Sin dominio de producto (pruebas), AgendaUno: solo giros de clases.
    for (const clave of claves) {
      expect(PERFILES_POR_MODO.clases).toContain(clave);
    }
  });
});
