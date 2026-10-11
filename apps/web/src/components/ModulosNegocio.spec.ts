import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import { plataformaAdmin } from "@/i18n/locales/gestion.es-MX";
import ModulosNegocio from "./ModulosNegocio.vue";

const put = vi.hoisted(() => vi.fn());
vi.mock("axios", () => ({ default: { create: () => ({ put }) } }));
vi.mock("@/lib/api", () => ({ mensajeDeError: (e: unknown) => String(e) }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(async () => true) }));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));

const familias = {
  clave: "familias",
  etiqueta: "Familias y responsables",
  descripcion: "Un alumno con cuenta gestiona a personas a su cargo.",
  habilitado: false,
};

function montar() {
  return mount(ModulosNegocio, {
    props: {
      apiUrl: "http://api",
      token: "tok",
      slug: "demo",
      nombre: "Demo",
      inicial: [familias],
    },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          missingWarn: false,
          fallbackWarn: false,
          messages: { es: { plataformaAdmin } },
        }),
      ],
    },
  });
}

beforeEach(() => vi.clearAllMocks());

describe("módulos del negocio (superadmin)", () => {
  it("muestra el módulo apagado por omisión y lo enciende con el token de la plataforma", async () => {
    put.mockResolvedValue({
      data: { data: [{ ...familias, habilitado: true }] },
    });
    const w = montar();
    expect(w.get('[data-modulo="familias"]').text()).toContain("apagado");

    await w.get("button").trigger("click");
    await flushPromises();

    expect(put).toHaveBeenCalledWith(
      "/api/v1/plataforma/estudios/demo/modulos",
      { modulo: "familias", habilitado: true },
      { headers: { Authorization: "Bearer tok" } },
    );
    expect(w.get('[data-modulo="familias"]').text()).toContain("encendido");
    expect(w.get("button").text()).toBe("Apagar");
  });
});
