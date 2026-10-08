import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import DocumentoLegalContenido from "./DocumentoLegalContenido.vue";

afterEach(() => vi.unstubAllEnvs());
describe("documento legal", () => {
  it("separa los apartados y las listas sin reescribir el documento publicado", () => {
    const vista = mount(DocumentoLegalContenido, {
      props: {
        contenido:
          "AVISO DE PRIVACIDAD\n\n1. Responsable\n\nTexto del responsable.\n\n2. Datos\n\nDatos tratados:\n• Nombre.\n• Correo.",
      },
    });
    expect(vista.findAll("h2").map((n) => n.text())).toEqual([
      "1. Responsable",
      "2. Datos",
    ]);
    expect(vista.findAll(".legal-texto > p").map((n) => n.text())).toEqual([
      "Texto del responsable.",
      "Datos tratados:",
    ]);
    expect(vista.findAll("li").map((n) => n.text())).toEqual([
      "Nombre.",
      "Correo.",
    ]);
    vista.unmount();
  });
  it("prioriza el documento publicado y no interpreta HTML", () => {
    const vista = mount(DocumentoLegalContenido, {
      props: { contenido: "Aviso vigente <script>alert(1)</script>" },
    });
    expect(vista.text()).toBe("Aviso vigente <script>alert(1)</script>");
    expect(vista.find("script").exists()).toBe(false);
    expect(vista.find(".legal-pendiente").exists()).toBe(false);
    vista.unmount();
  });
  it("identifica expresamente el borrador de desarrollo, el de cada documento", () => {
    vi.stubEnv("DEV", true);
    const aviso = mount(DocumentoLegalContenido, {
      props: { contenido: "  " },
    });
    expect(aviso.get('[role="status"]').text()).toContain(
      "Solo visible en desarrollo",
    );
    expect(aviso.text()).toContain("Quiénes somos y cómo contactarnos");
    aviso.unmount();
    const terminos = mount(DocumentoLegalContenido, {
      props: { contenido: null, tipo: "terminos" },
    });
    expect(terminos.text()).toContain("Aceptación y partes");
    terminos.unmount();
  });
  it("nunca presenta el borrador como publicado en producción", () => {
    vi.stubEnv("DEV", false);
    const vista = mount(DocumentoLegalContenido, {
      props: { contenido: null },
    });
    expect(vista.text()).toContain("aún no está disponible");
    expect(vista.text()).toContain("hola@agendauno.mx");
    expect(vista.find(".legal-texto").exists()).toBe(false);
    vista.unmount();
  });
});
