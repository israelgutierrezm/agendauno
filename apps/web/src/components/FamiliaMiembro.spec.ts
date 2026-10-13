import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import familias from "@/i18n/locales/familias.es-MX";
import FamiliaMiembro from "./FamiliaMiembro.vue";

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
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(async () => true) }));

const familia = {
  activo: true,
  version: 2,
  texto: "Texto del negocio.",
  tiene_cuenta: true,
  a_su_cargo: [
    {
      id: "t-sofia",
      persona: { id: "p-sofia", nombre: "Sofía", tiene_cuenta: false },
      parentesco: "madre_padre",
      estado: "pendiente",
      autorizacion: null,
    },
    {
      id: "t-leo",
      persona: { id: "p-leo", nombre: "Leo", tiene_cuenta: false },
      parentesco: "tutor_legal",
      estado: "activa",
      autorizacion: {
        version: 1,
        aceptada_en: "2026-10-01T12:00:00+00:00",
        via: "cuenta",
      },
    },
  ],
  responsables: [],
};

function montar() {
  api.get.mockResolvedValue({ data: { data: familia } });
  api.post.mockResolvedValue({ data: { data: {} } });
  return mount(FamiliaMiembro, {
    props: { personaId: "p-mama", nombre: "Mamá" },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { ...esMX, familias } },
        }),
      ],
      stubs: {
        RouterLink: { props: ["to"], template: "<a><slot /></a>" },
        BuscarPersona: true,
        PanelLateral: true,
        ModalDialogo: {
          props: ["abierto", "titulo"],
          template: '<div v-if="abierto"><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

beforeEach(() => vi.clearAllMocks());

describe("la familia en la ficha", () => {
  it("si no se puede vincular, el error se ve en el panel y no en la ficha", async () => {
    api.get.mockResolvedValue({ data: { data: familia } });
    api.post.mockRejectedValueOnce("Ya está a cargo de alguien más.");
    const w = mount(FamiliaMiembro, {
      props: { personaId: "p-mama", nombre: "Mamá" },
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: "es",
            missingWarn: false,
            fallbackWarn: false,
            messages: { es: { ...esMX, familias } },
          }),
        ],
        stubs: {
          RouterLink: { props: ["to"], template: "<a><slot /></a>" },
          BuscarPersona: {
            props: ["modelValue"],
            emits: ["update:modelValue"],
            template:
              '<button type="button" data-prueba="elegir" @click="$emit(\'update:modelValue\', \'p-ana\')" />',
          },
          PanelLateral: {
            props: ["abierto", "titulo", "ocupado"],
            template:
              '<div v-if="abierto" data-prueba="panel" :data-ocupado="ocupado"><slot /><slot name="pie" /></div>',
          },
          ModalDialogo: true,
        },
      },
    });
    await flushPromises();
    await w.get('[data-prueba="agregar-familiar"]').trigger("click");
    const panel = w.get('[data-prueba="panel"]');
    await panel.get('[data-prueba="elegir"]').trigger("click");
    await panel
      .findAll("button")
      .find((b) => b.text() === "Poner a su cargo")!
      .trigger("click");
    await flushPromises();

    expect(w.get('[data-prueba="error-vincular"]').text()).toContain(
      "Ya está a cargo de alguien más.",
    );
    expect(w.text().split("Ya está a cargo de alguien más.")).toHaveLength(2);
    expect(w.find('[data-prueba="panel"]').exists()).toBe(true);
  });

  it("dice a quién tiene a su cargo, su parentesco y el estado de la autorización", async () => {
    const w = montar();
    await flushPromises();

    const texto = w.get('[data-prueba="familia"]').text();
    expect(texto).toContain("A su cargo");
    expect(texto).toContain("Sofía");
    expect(texto).toContain("Madre o padre");
    expect(texto).toContain("Falta la autorización");
    expect(texto).toContain("Leo");
    expect(texto).toContain("en su cuenta");
  });

  it("registra la autorización del mostrador con el texto vigente a la vista", async () => {
    const w = montar();
    await flushPromises();

    await w
      .findAll("button")
      .find((b) => b.text() === "Registrar autorización en mostrador")!
      .trigger("click");
    expect(w.text()).toContain("Confirma que Mamá leyó y aceptó");
    expect(w.text()).toContain("Texto del negocio.");
    await w
      .findAll("button")
      .find((b) => b.text() === "Sí, lo aceptó")!
      .trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/tutelas/t-sofia/autorizar",
      { version: 2 },
    );
  });

  it("retira la autorización con confirmación", async () => {
    const w = montar();
    await flushPromises();

    await w
      .findAll("button")
      .filter((b) => b.text() === "Retirar")[1]
      .trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/tutelas/t-leo/retirar",
      {},
    );
  });
});
