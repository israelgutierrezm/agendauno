import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import ListaFormularios from "./ListaFormularios.vue";

/*
| Formularios de una persona: el personal los responde por ella (con `persona_id`) y,
| en el portal, la cuenta responde los suyos en `/mi/…` (la persona a cargo viaja como
| `X-Participante`, no en el cuerpo).
*/

const api = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: String }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({
    slug: "demo",
    zonaHoraria: "America/Mexico_City",
  }),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));

const formulario = {
  id: "f1",
  nombre: "Ficha de salud",
  descripcion: null,
  // 03:00 UTC del 2 de octubre = 1 de octubre en CDMX.
  respondido_en: "2026-10-02T03:00:00Z",
  campos: [
    {
      id: "c1",
      etiqueta: "Alergias",
      tipo: "texto" as const,
      obligatorio: false,
      opciones: null,
      valor: "Ninguna",
    },
  ],
};

function montar(enCuenta: boolean) {
  return mount(ListaFormularios, {
    props: {
      formularios: [formulario],
      personaId: "p1",
      puedeResponder: true,
      enCuenta,
    },
    global: { plugins: [i18n] },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  api.post.mockResolvedValue({ data: {} });
});

describe("formularios de una persona", () => {
  it("en el portal responde en /mi, sin persona_id", async () => {
    const w = montar(true);
    // La fecha de respuesta, en la zona del negocio.
    expect(w.text()).toContain("1 oct");
    await w
      .findAll("button")
      .find((b) => b.text() === "Editar respuestas")!
      .trigger("click");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/formularios/f1/respuestas",
      { valores: { c1: "Ninguna" } },
    );
  });

  it("el personal responde por la persona, con su persona_id", async () => {
    const w = montar(false);
    await w
      .findAll("button")
      .find((b) => b.text() === "Editar respuestas")!
      .trigger("click");
    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/formularios/f1/respuestas",
      { persona_id: "p1", valores: { c1: "Ninguna" } },
    );
  });
});
