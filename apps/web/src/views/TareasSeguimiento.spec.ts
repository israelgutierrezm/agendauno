import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import operacion from "@/i18n/locales/operacion.es-MX";
import TareasView from "./TareasView.vue";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo", puede: () => true }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const seguimiento = {
  id: "t1",
  titulo: "Plan por vencer: Ana López",
  detalle:
    "Mensaje sugerido (edítalo antes de enviarlo): Hola Ana, tu plan vence el 15 de octubre. Si quieres seguir, te ayudamos a renovarlo.",
  estado: "pendiente",
  vence_en: "2026-10-02T06:00:00+00:00",
  persona: "Ana López",
  persona_id: "p1",
  celular: "+52 55 1234 5678",
  motivo: "plan_por_vencer",
  resultado: null,
  responsable: null,
  automatica: false,
  completada_en: null,
};

function montar() {
  api.get.mockResolvedValue({
    data: { data: [seguimiento], pendientes: 1, alcance: "equipo" },
  });
  api.post.mockResolvedValue({ data: { data: {} } });
  return mount(TareasView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, operacion } },
        }),
      ],
      stubs: {
        EncabezadoSeccion: true,
        RouterLink: { props: ["to"], template: "<a><slot /></a>" },
        PanelLateral: {
          props: ["abierto", "titulo"],
          template: '<div v-if="abierto"><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

beforeEach(() => vi.clearAllMocks());

describe("seguimiento en tareas", () => {
  it("sin celular propio, escribe por WhatsApp a su responsable y lo dice", async () => {
    api.get.mockResolvedValue({
      data: {
        data: [
          {
            ...seguimiento,
            persona: "Sofía Ríos",
            celular: null,
            contacto: { nombre: "Laura Ríos", celular: "5511112222" },
            detalle:
              "Mensaje sugerido (edítalo antes de enviarlo): Hola Laura, el plan de Sofía vence el 15 de octubre. Si quieren seguir, les ayudamos a renovarlo.\n\nNo tiene celular propio: escríbele a Laura Ríos, su responsable.",
          },
        ],
        pendientes: 1,
        alcance: "equipo",
      },
    });
    api.post.mockResolvedValue({ data: { data: {} } });
    const w = mount(TareasView, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            missingWarn: false,
            fallbackWarn: false,
            messages: { es: { ...esMX, operacion } },
          }),
        ],
        stubs: {
          EncabezadoSeccion: true,
          RouterLink: { props: ["to"], template: "<a><slot /></a>" },
          PanelLateral: true,
        },
      },
    });
    await flushPromises();

    const wa = w.get('a[target="_blank"]').attributes("href") ?? "";
    expect(wa).toMatch(/^https:\/\/wa\.me\/\d*5511112222\?text=/);
    expect(decodeURIComponent(wa.split("text=")[1])).toBe(
      "Hola Laura, el plan de Sofía vence el 15 de octubre. Si quieren seguir, les ayudamos a renovarlo.",
    );
    expect(w.get('[data-prueba="contacto-responsable"]').text()).toBe(
      "Se le escribe a Laura Ríos, su responsable",
    );
  });

  it("ofrece escribirle por WhatsApp con el mensaje sugerido y copiarlo", async () => {
    const escribir = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText: escribir } });
    const w = montar();
    await flushPromises();

    const wa = w.get('a[target="_blank"]').attributes("href") ?? "";
    expect(wa).toMatch(/^https:\/\/wa\.me\/525512345678\?text=/);
    expect(decodeURIComponent(wa.split("text=")[1])).toBe(
      "Hola Ana, tu plan vence el 15 de octubre. Si quieres seguir, te ayudamos a renovarlo.",
    );

    await w
      .findAll("button")
      .find((b) => b.text() === "Copiar mensaje")!
      .trigger("click");
    await flushPromises();
    expect(escribir).toHaveBeenCalledWith(
      "Hola Ana, tu plan vence el 15 de octubre. Si quieres seguir, te ayudamos a renovarlo.",
    );
  });

  it("registra con qué terminó, con su nota, en lugar de solo completarla", async () => {
    const w = montar();
    await flushPromises();
    expect(w.findAll("button").some((b) => b.text() === "Completar")).toBe(
      false,
    );

    await w
      .findAll("button")
      .find((b) => b.text() === "Registrar resultado")!
      .trigger("click");
    await w.get('input[value="no_desea_contacto"]').setValue(true);
    expect(w.text()).toContain("Deja de recibir promociones");
    await w.get("#s-nota").setValue("Pidió no recibir mensajes");
    await w
      .findAll("button")
      .find((b) => b.text() === "Guardar resultado")!
      .trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/tareas/t1/resultado",
      { resultado: "no_desea_contacto", nota: "Pidió no recibir mensajes" },
    );
    expect(toast.exito).toHaveBeenCalledWith("Resultado guardado");
  });

  it("filtra solo los seguimientos", async () => {
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="solo-seguimiento"]').trigger("click");
    await flushPromises();
    expect(api.get).toHaveBeenLastCalledWith("/api/v1/app/demo/tareas", {
      params: { estado: "pendiente", tipo: "seguimiento" },
    });
  });
});
