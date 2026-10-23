import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import { trackEvent } from "@/lib/analytics";
import { MODALIDADES, MODOS, type Modo } from "@/marketing/modalidades";
import { PRECIOS_POR_OMISION } from "@/marketing/precios";
import { aplicarPreciosPublicos } from "@/marketing/preciosPublicos";
import FuncionesLanding from "./FuncionesLanding.vue";

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
afterEach(() => vi.mocked(trackEvent).mockClear());

function montar(modo?: Modo) {
  return mount(FuncionesLanding, {
    props: modo ? { modo } : {},
    global: {
      plugins: [createI18n({ legacy: false, locale: "es", messages: { es } })],
      stubs: {
        RouterLink: {
          props: ["to"],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  });
}
const textos = es.landing as unknown as Record<
  Modo,
  {
    funciones: Record<
      string,
      { titulo: string; descripcion: string; detalle: string }
    >;
  }
>;
describe("tarjetas de funcionalidades", () => {
  it("presenta seis funciones con iconos y ejemplos ilustrativos", () => {
    const vista = montar();
    expect(vista.findAll(".funcion")).toHaveLength(6);
    expect(vista.findAll(".funcion-icono svg")).toHaveLength(6);
    expect(vista.findAll(".funcion-visual")).toHaveLength(6);
    expect(vista.text()).toContain("Ejemplos ilustrativos");
    expect(
      vista.findAll(".funcion-detalle").every((item) => !item.isVisible()),
    ).toBe(true);
    vista.unmount();
  });
  it("abre, cambia y cierra los detalles sin navegación inesperada", async () => {
    const vista = montar();
    const botones = vista.findAll(".funcion-abrir");
    await botones[0]!.trigger("click");
    expect(botones[0]!.attributes("aria-expanded")).toBe("true");
    expect(vista.get("#detalle-funcion-agenda").isVisible()).toBe(true);
    expect(vista.get("#detalle-funcion-agenda").text()).toContain(
      "Asigna horarios",
    );
    await botones[1]!.trigger("click");
    expect(botones[0]!.attributes("aria-expanded")).toBe("false");
    expect(vista.get("#detalle-funcion-reservas").isVisible()).toBe(true);
    await botones[1]!.trigger("click");
    expect(botones[1]!.attributes("aria-expanded")).toBe("false");
    await vi.waitFor(() =>
      expect(
        vista.get("#detalle-funcion-reservas").attributes("style"),
      ).toContain("display: none"),
    );
    vista.unmount();
  });
  it("usa nombres de disciplinas sin mezclar el tipo de negocio", () => {
    expect(es.landing.paraQuien.negocios.pilates.nombre).toBe("Pilates");
    expect(es.landing.paraQuien.negocios.pole.nombre).toBe("Pole dance");
  });
  it("sin modo conserva la nota de México y el registro sin modalidad", () => {
    const vista = montar();
    expect(vista.get(".tu-nota-mexico").text()).toBe(
      "* Solo para negocios en México (cobros en pesos).",
    );
    expect(
      JSON.parse(vista.get(".funcion-detalle a").attributes("data-to")!),
    ).toEqual({ name: "registro" });
    vista.unmount();
  });
  it("cada función de cada modalidad tiene título, descripción y detalle", () => {
    for (const modo of MODOS) {
      for (const clave of MODALIDADES[modo].funciones) {
        const texto = textos[modo].funciones[clave];
        expect(texto, `${modo}.${clave}`).toBeDefined();
        expect(texto!.titulo.length).toBeGreaterThan(3);
        expect(texto!.descripcion.length).toBeGreaterThan(20);
        expect(texto!.detalle.length).toBeGreaterThan(20);
      }
    }
  });
  it("con modo clases presenta solo lo de clases, sin íconos teñidos ni cobros en línea", async () => {
    const vista = montar("clases");
    expect(vista.classes()).toContain("funcionalidades-modo");
    expect(vista.findAll(".funcion h3").map((n) => n.text())).toEqual([
      "Cupos por clase",
      "Lista de espera",
      "Membresías y créditos",
      "Pase de lista con retardos",
      "Pase QR de entrada en gimnasios",
      "Kiosco de llegada",
      "Videos y clases en línea",
      "Productos apartados al reservar",
      "Google Calendar y Outlook",
    ]);
    expect(vista.findAll(".funcion-visual")).toHaveLength(9);
    expect(vista.findAll(".funcion-icono svg")).toHaveLength(9);
    // Cada función nueva con su propio ejemplo (no el de otra ni vacío).
    for (const clave of ["kiosco", "videos", "apartados", "calendarios"]) {
      expect(
        vista.get(`.visual-${clave}`).text().length,
        clave,
      ).toBeGreaterThan(15);
    }
    expect(vista.get(".visual-calendarios").text()).toContain("Pilates");
    expect(vista.text()).not.toMatch(/profesional|corte|barba|cita/i);
    expect(vista.text()).not.toContain("✓");
    // Sin cobros en línea, sin nota de México.
    expect(vista.find(".tu-nota-mexico").exists()).toBe(false);
    // El QR es pase de entrada; la asistencia va en el pase de lista.
    expect(vista.get(".visual-paseQr").text()).toContain("Pase de entrada");
    await vista.findAll(".funcion-abrir")[4]!.trigger("click");
    expect(vista.get("#detalle-funcion-paseQr").text()).toContain(
      "En los estudios de clases la entrada es la clase misma",
    );
    expect(
      JSON.parse(vista.get("#detalle-funcion-paseQr a").attributes("data-to")!),
    ).toEqual({ name: "registro", query: { modo: "clases" } });
    vista.unmount();
  });
  it("«Probar en mi negocio» mide el clic al registro con la modalidad de la página", async () => {
    for (const modo of MODOS) {
      const vista = montar(modo);
      await vista.findAll(".funcion-abrir")[0]!.trigger("click");
      await vista.get(".funcion-detalle a").trigger("click");
      expect(trackEvent).toHaveBeenLastCalledWith("marketing_cta_clicked", {
        placement: "features",
        destination: "register",
        mode: modo,
      });
      vista.unmount();
    }
    // Si el superadmin cierra el registro, lleva a la lista (`waitlist`).
    aplicarPreciosPublicos({ registro: { agendauno: true, turnouno: false } });
    try {
      const cerrado = montar("citas");
      await cerrado.findAll(".funcion-abrir")[0]!.trigger("click");
      const enlace = cerrado.get(".funcion-detalle a");
      expect(enlace.text()).toBe(es.landing.prelanzamiento.cta);
      await enlace.trigger("click");
      expect(trackEvent).toHaveBeenLastCalledWith("marketing_cta_clicked", {
        placement: "features",
        destination: "waitlist",
        mode: "citas",
      });
      cerrado.unmount();
    } finally {
      aplicarPreciosPublicos(PRECIOS_POR_OMISION);
    }
    // Sin modalidad, sin `mode`.
    const vista = montar();
    await vista.get(".funcion-detalle a").trigger("click");
    expect(trackEvent).toHaveBeenLastCalledWith("marketing_cta_clicked", {
      placement: "features",
      destination: "register",
    });
    vista.unmount();
  });
  it("con modo citas presenta solo lo de citas, con la nota de México del cobro en línea", () => {
    const vista = montar("citas");
    expect(vista.findAll(".funcion h3").map((n) => n.text())).toEqual([
      "Agenda por profesional",
      "Cualquier profesional disponible",
      "Paquetes de servicios",
      "Cobro en línea al agendar*",
      "Recordatorios por correo",
      "Google Calendar y Outlook, sin choques",
      "Caja con arqueo y cobro mixto",
      "Comisiones y nómina",
      "Punto de venta e inventario",
    ]);
    expect(vista.findAll(".funcion-visual")).toHaveLength(9);
    for (const clave of ["calendarios", "caja", "comisiones", "inventario"]) {
      expect(
        vista.get(`.visual-${clave}`).text().length,
        clave,
      ).toBeGreaterThan(15);
    }
    // El mismo `calendarios` que en clases, con su ejemplo de citas.
    expect(vista.get(".visual-calendarios").text()).toContain("Corte y barba");
    // El anticipo ya existe (ADR 0161); los recordatorios siguen por correo.
    expect(vista.text()).not.toMatch(
      /pilates|pole|yoga|cupo|lista de espera|alumno|whatsapp/i,
    );
    expect(vista.get(".tu-nota-mexico").text()).toBe(
      "* Solo para negocios en México (cobros en pesos).",
    );
    expect(vista.get(".visual-cualquierProfesional").text()).toContain(
      "Cualquier profesional",
    );
    for (const enlace of vista.findAll(".funcion-detalle a")) {
      expect(JSON.parse(enlace.attributes("data-to")!)).toEqual({
        name: "registro",
        query: { modo: "citas" },
      });
    }
    vista.unmount();
  });
});
