import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import AvisoPrivacidadContenido from "./AvisoPrivacidadContenido.vue";

afterEach(() => vi.unstubAllEnvs());
describe("contenido de privacidad", () => {
  it("separa los apartados sin reescribir el documento publicado", () => {
    const vista = mount(AvisoPrivacidadContenido, {
      props: {
        contenido:
          "Actualizado: 24 de septiembre de 2026\n\n1. RESPONSABLE\n\nTexto del responsable.\n\n2. DATOS\n\nDatos tratados.",
      },
    });
    expect(vista.findAll("h2").map((n) => n.text())).toEqual([
      "1. RESPONSABLE",
      "2. DATOS",
    ]);
    expect(vista.findAll(".aviso-texto > p").map((n) => n.text())).toEqual([
      "Actualizado: 24 de septiembre de 2026",
      "Texto del responsable.",
      "Datos tratados.",
    ]);
    vista.unmount();
  });
  it("prioriza el documento publicado y no interpreta HTML", () => {
    const vista = mount(AvisoPrivacidadContenido, {
      props: { contenido: "Aviso vigente <script>alert(1)</script>" },
    });
    expect(vista.text()).toBe("Aviso vigente <script>alert(1)</script>");
    expect(vista.find("script").exists()).toBe(false);
    expect(vista.find(".aviso-pendiente").exists()).toBe(false);
    vista.unmount();
  });
  it("identifica expresamente el borrador de desarrollo", () => {
    vi.stubEnv("DEV", true);
    const vista = mount(AvisoPrivacidadContenido, {
      props: { contenido: "  " },
    });
    expect(vista.get('[role="status"]').text()).toContain(
      "Solo visible en desarrollo",
    );
    expect(vista.text()).toContain("[NOMBRE COMPLETO O RAZÓN SOCIAL]");
    vista.unmount();
  });
  it("nunca presenta el borrador como aviso publicado en producción", () => {
    vi.stubEnv("DEV", false);
    const vista = mount(AvisoPrivacidadContenido, {
      props: { contenido: null },
    });
    expect(vista.text()).toContain("aún no está disponible");
    expect(vista.text()).not.toContain("[NOMBRE COMPLETO");
    expect(vista.find(".aviso-texto").exists()).toBe(false);
    vista.unmount();
  });
});
