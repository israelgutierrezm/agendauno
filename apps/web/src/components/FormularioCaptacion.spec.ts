import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import FormularioCaptacion from "./FormularioCaptacion.vue";

/*
| El formulario de captación que llena quien todavía no es cliente (ADR 0117): pide sus
| datos y la aceptación del aviso del negocio, manda lo que le interesaba y agradece.
| Sin aviso publicado no se puede enviar.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  camposConError: () => [],
  mensajeDeError: () => "Error",
}));
vi.mock("vue-router", () => ({
  RouterLink: { template: "<a><slot /></a>" },
}));

const formulario = {
  id: "01form",
  titulo: "Tu primera clase",
  descripcion: "Déjanos tus datos.",
  pide_telefono: true,
  telefono_obligatorio: false,
  campos: [
    {
      id: "01campo",
      etiqueta: "¿Has practicado pole?",
      tipo: "seleccion",
      obligatorio: true,
      opciones: ["Nunca", "Un poco"],
    },
  ],
  aviso: { version: 2 },
  negocio: { nombre: "Fluo", slug: "fluo" },
};

function montar(props: Record<string, unknown> = {}) {
  return mount(FormularioCaptacion, {
    props: { slug: "fluo", formulario: "01form", ...props },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  api.get.mockReset();
  api.post.mockReset();
});

describe("FormularioCaptacion", () => {
  it("manda sus datos, sus respuestas, lo que le interesaba y la versión del aviso", async () => {
    api.get.mockResolvedValueOnce({ data: { data: formulario } });
    api.post.mockResolvedValueOnce({
      data: { data: { mensaje: "Te contactamos pronto." } },
    });
    const w = montar({
      interes: { sesion: "01sesion", etiqueta: "Pole · lun 12 oct" },
    });
    await flushPromises();

    expect(api.get).toHaveBeenCalledWith(
      "/api/v1/app/fluo/captacion/formularios/01form",
    );
    expect(w.text()).toContain("Tu primera clase");
    expect(w.get('[data-prueba="captacion-interes"]').text()).toContain(
      "Pole · lun 12 oct",
    );
    // Sin aceptar el aviso no se envía.
    expect(w.get('button[type="submit"]').attributes("disabled")).toBeDefined();

    const inputs = w.findAll("input");
    await inputs[0]!.setValue("Lucía");
    await w.get('input[type="email"]').setValue("lucia@correo.mx");
    await w.get("select").setValue("Un poco");
    await w.get('input[type="checkbox"]').setValue(true);
    await w.get("form").trigger("submit");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/fluo/captacion/formularios/01form/envios",
      expect.objectContaining({
        nombre: "Lucía",
        email: "lucia@correo.mx",
        respuestas: { "01campo": "Un poco" },
        interes: expect.objectContaining({ sesion: "01sesion" }),
        acepta_aviso: true,
        aviso_version: 2,
        // En la página del negocio, por omisión.
        canal: "pagina",
      }),
    );
    expect(w.text()).toContain("Te contactamos pronto.");
  });

  it("sin aviso de privacidad publicado no deja enviar", async () => {
    api.get.mockResolvedValueOnce({
      data: { data: { ...formulario, aviso: null } },
    });
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("aún no publica su aviso de privacidad");
    expect(w.get('button[type="submit"]').attributes("disabled")).toBeDefined();
  });

  it("si el formulario no existe o la captación está apagada, lo dice", async () => {
    api.get.mockRejectedValueOnce(new Error("404"));
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Este formulario no está disponible.");
    expect(w.find("form").exists()).toBe(false);
  });
});
