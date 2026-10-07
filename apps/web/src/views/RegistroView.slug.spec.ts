import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import modalidadNegocio from "@/i18n/locales/modalidad.es-MX";
import RegistroView from "./RegistroView.vue";

// La dirección del negocio mide a lo más 40 (como en la API): con ella se nombra la
// base del negocio y el subdominio.
const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: mocks,
  mensajeDeError: () => "No disponible",
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
vi.mock("vue-router", () => ({
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));
const montajes: ReturnType<typeof mount>[] = [];
function montarRegistro() {
  const vista = mount(RegistroView, {
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
  montajes.push(vista);
  return vista;
}
beforeEach(() => {
  vi.useFakeTimers();
  vi.clearAllMocks();
  mocks.get.mockResolvedValue({
    data: { data: { terminos: "T", aviso_privacidad: "A" } },
  });
});
afterEach(() => {
  montajes.splice(0).forEach((vista) => vista.unmount());
  vi.clearAllTimers();
  vi.useRealTimers();
});
describe("la dirección del negocio", () => {
  it("con un nombre largo se recorta a 40 caracteres, sin guion al final", async () => {
    const vista = montarRegistro();
    await vista
      .get("#nombre")
      .setValue(
        "Estudio de Pilates y Yoga Integral Roma Norte Ciudad de México",
      );
    expect(vista.get(".registro-direccion-valor span").text()).toBe(
      "estudio-de-pilates-y-yoga-integral-roma",
    );
  });
  it("personalizada tampoco pasa de 40 caracteres", async () => {
    const vista = montarRegistro();
    const personalizar = vista
      .findAll("button")
      .find((b) => b.text() === es.registro.personalizar);
    await personalizar!.trigger("click");
    const campo = vista.get("#slug");
    expect(campo.attributes("maxlength")).toBe("40");
    await campo.setValue("a".repeat(45));
    expect((campo.element as HTMLInputElement).value).toBe("a".repeat(40));
  });
});
