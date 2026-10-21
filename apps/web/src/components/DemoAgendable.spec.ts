import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import DemoAgendable from "./DemoAgendable.vue";

/*
| La demo de 15 minutos desde la landing (ADR 0193): solo se ofrece si la plataforma
| agenda demos; se elige día y hora, y la confirmación por WhatsApp no viene marcada.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: () => "Error",
  camposConError: () => [],
}));
vi.mock("@/lib/recaptcha", () => ({
  tokenRecaptcha: () => Promise.resolve(""),
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));

const HORARIOS = {
  activas: true,
  minutos: 15,
  zona: "America/Mexico_City",
  whatsapp: true,
  dias: [
    { fecha: "2026-10-01", horas: ["10:00", "10:15"] },
    { fecha: "2026-10-02", horas: ["12:00"] },
  ],
};
const global = { plugins: [i18n], stubs: { RouterLink: true } };

function respuestas(horarios: unknown) {
  api.get.mockImplementation((url: string) =>
    Promise.resolve(
      url === "/api/v1/demos"
        ? { data: { data: horarios } }
        : {
            data: { data: { versiones: { aviso_privacidad: { version: 3 } } } },
          },
    ),
  );
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("DemoAgendable", () => {
  it("apagada, no se muestra", async () => {
    respuestas({ ...HORARIOS, activas: false, dias: [] });
    const w = mount(DemoAgendable, {
      props: { producto: "agendauno" },
      global,
    });
    await flushPromises();
    expect(w.find('[data-prueba="demo-agendable"]').exists()).toBe(false);
  });

  it("elige día y hora y la agenda con sus datos; WhatsApp solo si lo pide", async () => {
    respuestas(HORARIOS);
    api.post.mockResolvedValue({ data: { data: { id: "d1" } } });
    const w = mount(DemoAgendable, {
      props: { producto: "agendauno" },
      global,
    });
    await flushPromises();

    await w.get('[data-dia="2026-10-02"]').trigger("click");
    expect(w.findAll("[data-hora]").map((b) => b.text())).toEqual(["12:00"]);
    await w.get('[data-hora="12:00"]').trigger("click");

    const campos = w.findAll("input.tu-input");
    await campos[0]!.setValue("Ana López");
    await campos[1]!.setValue("Fluo Pilates");
    await campos[2]!.setValue("ana@fluo.mx");
    await campos[3]!.setValue("5512345678");
    const casillas = w.findAll('input[type="checkbox"]');
    // La de WhatsApp no viene marcada; se acepta el aviso.
    expect((casillas[0]!.element as HTMLInputElement).checked).toBe(false);
    await casillas[1]!.setValue(true);

    await w.get("form").trigger("submit");
    await flushPromises();
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/demos",
      expect.objectContaining({
        producto: "agendauno",
        fecha: "2026-10-02",
        hora: "12:00",
        nombre: "Ana López",
        acepta_whatsapp: false,
        acepta_aviso: true,
        aviso_version: 3,
      }),
    );
    expect(w.text()).toContain("Tu demo quedó agendada");
  });
});
