import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import familias from "@/i18n/locales/familias.es-MX";
import { participanteEnUso } from "@/lib/api";
import { useParticipantes } from "@/lib/participantes";
import PersonasACargo from "./PersonasACargo.vue";

const red = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", async (original) => ({
  ...(await original<typeof import("@/lib/api")>()),
  api: red,
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(async () => true) }));

const sofia = {
  id: "t-sofia",
  persona: {
    id: "p-sofia",
    nombre: "Sofía",
    tiene_cuenta: false,
    fecha_nacimiento: null,
  },
  parentesco: "madre_padre",
  estado: "pendiente",
  autorizacion: null,
};

function respuesta(estado: "pendiente" | "activa") {
  return {
    data: {
      data: [{ ...sofia, estado }],
      meta: { activo: true, version: 3, texto: "Texto del negocio." },
    },
  };
}

function montar() {
  return mount(PersonasACargo, {
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
        ModalDialogo: {
          props: ["abierto", "titulo"],
          template: '<div v-if="abierto"><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  useParticipantes().reiniciar();
});

describe("personas a cargo en la cuenta", () => {
  it("autoriza leyendo el texto vigente y aceptándolo", async () => {
    red.get.mockResolvedValueOnce(respuesta("pendiente"));
    red.post.mockResolvedValue({ data: {} });
    red.get.mockResolvedValueOnce(respuesta("activa"));
    await useParticipantes().cargar();
    const w = montar();
    await flushPromises();

    expect(w.text()).toContain("Falta la autorización");
    await w
      .findAll("button")
      .find((b) => b.text() === "Autorizar")!
      .trigger("click");
    expect(w.get('[data-prueba="texto-autorizacion"]').text()).toBe(
      "Texto del negocio.",
    );
    const aceptar = w.findAll("button").filter((b) => b.text() === "Autorizar");
    expect(aceptar.at(-1)!.attributes("disabled")).toBeDefined();

    await w.get('input[type="checkbox"]').setValue(true);
    await aceptar.at(-1)!.trigger("click");
    await flushPromises();

    expect(red.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/mi/participantes/t-sofia/autorizar",
      { acepto: true, version: 3 },
    );
    expect(w.text()).toContain("Autorizada");
  });

  it("«Ver su cuenta» actúa por esa persona", async () => {
    red.get.mockResolvedValue(respuesta("activa"));
    await useParticipantes().cargar();
    const w = montar();
    await flushPromises();

    await w
      .findAll("button")
      .find((b) => b.text() === "Ver su cuenta")!
      .trigger("click");

    expect(useParticipantes().actual).toBe("p-sofia");
    expect(participanteEnUso()).toBe("p-sofia");
    // Dentro de su cuenta, la lista ya no se muestra.
    await flushPromises();
    expect(w.find('[data-prueba="personas-a-cargo"]').exists()).toBe(false);
    useParticipantes().elegir(null);
  });
});

describe("al salir de la cuenta", () => {
  it("no queda guardada la persona a cargo elegida", () => {
    sessionStorage.setItem("agendauno:participante:demo", "p-sofia");
    useParticipantes().reiniciar();
    expect(sessionStorage.getItem("agendauno:participante:demo")).toBeNull();
  });
});
