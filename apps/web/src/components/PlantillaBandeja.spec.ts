import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import bandejaMensajes from "@/i18n/locales/bandejaMensajes.es-MX";
import PlantillaBandeja from "./PlantillaBandeja.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (_e: unknown, porDefecto: string) => porDefecto,
}));

const recordatorio = {
  nombre: "recordatorio_cita",
  idioma: "es_MX",
  categoria: "utility",
  encabezado: "Estudio Cal",
  cuerpo: "Hola {{1}}, ¿seguimos con tu cita del {{2}}?",
  pie: null,
  variables: 2,
};

function montar() {
  return mount(PlantillaBandeja, {
    props: { base: "/api/v1/app/demo", conversacionId: "c1" },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: { es: { bandejaMensajes } },
        }),
      ],
    },
  });
}

describe("contestar con una plantilla de WhatsApp (fuera de las 24 horas)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("se elige, se llenan sus datos, se ve cómo llega y se manda", async () => {
    api.get.mockResolvedValue({ data: { data: [recordatorio] } });
    api.post.mockResolvedValue({ data: { data: {} } });
    const w = montar();
    await flushPromises();

    await w.get("select").setValue("recordatorio_cita|es_MX");
    const enviar = w.get('button[type="submit"]');
    expect(enviar.attributes("disabled")).toBeDefined();
    const campos = w.findAll("input");
    expect(campos).toHaveLength(2);
    await campos[0].setValue("Lucía");
    await campos[1].setValue("martes");

    expect(w.get('[data-prueba="vista-previa"]').text()).toBe(
      "Estudio Cal\n\nHola Lucía, ¿seguimos con tu cita del martes?",
    );
    expect(w.text()).toContain(bandejaMensajes.plantillas.cobro);
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/bandeja/conversaciones/c1/plantilla",
      {
        nombre: "recordatorio_cita",
        idioma: "es_MX",
        variables: ["Lucía", "martes"],
      },
    );
    expect(w.emitted("enviada")).toHaveLength(1);
  });

  it("sin plantillas que se puedan mandar, dice dónde crearlas", async () => {
    api.get.mockResolvedValue({ data: { data: [] } });
    const w = montar();
    await flushPromises();
    expect(w.get('[data-prueba="sin-plantillas"]').text()).toBe(
      bandejaMensajes.plantillas.ninguna,
    );
  });
});
