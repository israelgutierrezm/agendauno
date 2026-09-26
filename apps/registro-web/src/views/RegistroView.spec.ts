import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import RegistroView from "./RegistroView.vue";

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
function montar() {
  const vista = mount(RegistroView, {
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
    },
  });
  montajes.push(vista);
  return vista;
}
async function avanzarADatos(vista: ReturnType<typeof montar>) {
  await vista.get("#nombre").setValue("Estudio de prueba");
  await vista.get("#perfil").setValue("pilates");
  await vista.get("form").trigger("submit");
}
beforeEach(() => {
  vi.useFakeTimers();
  vi.clearAllMocks();
  mocks.get.mockResolvedValue({
    data: {
      data: {
        terminos: "Términos de prueba",
        aviso_privacidad: "Aviso de prueba",
      },
    },
  });
});
afterEach(() => {
  montajes.splice(0).forEach((vista) => vista.unmount());
  vi.clearAllTimers();
  vi.useRealTimers();
});
describe("presentación del registro", () => {
  it("ofrece el aviso antes de capturar datos sin perder el registro", () => {
    const vista = montar();
    const enlace = vista.get(".registro-aviso");
    expect(enlace.attributes("to")).toBe("/aviso-de-privacidad");
    expect(enlace.attributes("target")).toBe("_blank");
    expect(enlace.attributes("rel")).toBe("noopener");
    expect(vista.find("#nombre").exists()).toBe(true);
  });
  it("muestra círculos numerados y marca los pasos completados", async () => {
    const vista = montar();
    expect(vista.findAll(".registro-paso")).toHaveLength(3);
    expect(
      vista.findAll(".registro-paso-circulo").map((n) => n.text()),
    ).toEqual(["1", "2", "3"]);
    expect(
      vista.findAll(".registro-foto").map((n) => n.attributes("src")),
    ).toEqual([
      "/assets/landing/disciplinas/terapeutas-v1.webp",
      "/assets/landing/disciplinas/yoga-v1.jpg",
    ]);
    expect(vista.get('[aria-current="step"]').text()).toBe("1Tu negocio");
    expect(vista.text()).not.toContain("Paso 1 de 3");
    expect(vista.get(".registro-intro").text()).toBe(es.registro.intro1);
    expect(vista.findAll(".registro-mini-cita")).toHaveLength(1);
    expect(vista.get(".registro-mini-cita").text()).toContain(
      "Sesión de terapia",
    );
    expect(vista.get(".registro-mini-cita").text()).toContain(
      "10:30 · Confirmada",
    );
    await avanzarADatos(vista);
    expect(vista.get('[aria-current="step"]').text()).toBe("2Tus datos");
    expect(vista.text()).not.toContain("Paso 2 de 3");
    expect(vista.get(".registro-intro").text()).toBe(es.registro.intro2);
    expect(vista.findAll(".es-completo")).toHaveLength(1);
    expect(
      vista.findAll(".es-completo .registro-paso-circulo svg"),
    ).toHaveLength(1);
  });
  it("indica opcional solo en el placeholder y permite dejar esos campos vacíos", async () => {
    const vista = montar();
    await avanzarADatos(vista);
    expect(vista.get('label[for="csegnombre"]').text()).toBe("Segundo nombre");
    expect(vista.get('label[for="cmaterno"]').text()).toBe("Apellido materno");
    expect(vista.get("#csegnombre").attributes("placeholder")).toBe("Opcional");
    expect(vista.get("#cmaterno").attributes("placeholder")).toBe("Opcional");
    expect(vista.get("#csegnombre").attributes("required")).toBeUndefined();
    await vista.get("#cnombre").setValue("Ana");
    await vista.get("#cpaterno").setValue("Pérez");
    await vista.get("form").trigger("submit");
    expect(vista.get('[aria-current="step"]').text()).toBe("3Contacto");
    expect(mocks.post).not.toHaveBeenCalled();
  });
  it("separa los enlaces legales del texto y abre cada documento sin aceptar términos", async () => {
    const vista = montar();
    await flushPromises();
    await avanzarADatos(vista);
    await vista.get("#cnombre").setValue("Ana");
    await vista.get("#cpaterno").setValue("Pérez");
    await vista.get("form").trigger("submit");
    const legales = vista.get(".registro-legales");
    expect(legales.text().replace(/\s+/g, " ")).toBe(
      "Acepto los términos y el aviso de privacidad.",
    );
    expect(vista.get("#acepta").attributes("aria-label")).toBe(
      es.registro.terminos,
    );
    await legales.findAll("button")[0]!.trigger("click");
    expect(vista.text()).toContain("Términos de prueba");
    await vista.get('button[aria-label="Cerrar"]').trigger("click");
    await legales.findAll("button")[1]!.trigger("click");
    expect(vista.text()).toContain("Aviso de prueba");
    expect((vista.get("#acepta").element as HTMLInputElement).checked).toBe(
      false,
    );
    expect(mocks.post).not.toHaveBeenCalled();
  });
});
