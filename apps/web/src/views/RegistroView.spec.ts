import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import modalidadNegocio from "@/i18n/locales/modalidad.es-MX";
import { trackEvent } from "@/lib/analytics";
import { giroDeQuery, modoDeQuery, type Modo } from "@/marketing/modalidades";
import RegistroView from "./RegistroView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({
  api: mocks,
  mensajeDeError: () => "No disponible",
  camposConError: (e: unknown) => (e as { campos?: string[] }).campos ?? [],
}));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
// El país y la zona que propone el navegador (ADR 0103): fijos en las pruebas.
const navegador = vi.hoisted(() => ({ pais: "MX" }));
vi.mock("@/lib/region", async (original) => ({
  ...(await original<typeof import("@/lib/region")>()),
  paisSugerido: () => navegador.pais,
  zonaSugerida: (pais: string) =>
    ({ MX: "America/Mexico_City", CO: "America/Bogota" })[pais] ?? null,
}));
vi.mock("vue-router", () => ({
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));
const montajes: ReturnType<typeof mount>[] = [];
// El modo y el giro llegan como props de la ruta (`/registro?modo=&giro=`), ya
// normalizados: la vista no lee la ruta. `adjuntar` la monta en el documento, para
// revisar a dónde va el foco.
function montar(
  props: { modo?: Modo | null; giro?: string | null } = {},
  { adjuntar = false } = {},
) {
  const vista = mount(RegistroView, {
    props,
    attachTo: adjuntar ? document.body : undefined,
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
async function avanzarADatos(vista: ReturnType<typeof montar>) {
  await vista.get("#nombre").setValue("Estudio de prueba");
  await vista.get("#perfil").setValue("pilates");
  await vista.get("form").trigger("submit");
}
beforeEach(() => {
  vi.useFakeTimers();
  vi.clearAllMocks();
  navegador.pais = "MX";
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
  it("agrupa los tipos de negocio en clases o citas: de ahí sale su modalidad (ADR 0104)", () => {
    const vista = montar();
    const grupos = vista.findAll("#perfil optgroup");
    expect(grupos.map((g) => g.attributes("label"))).toEqual([
      "Clases con cupo",
      "Citas 1 a 1",
    ]);
    const valores = (i: number) =>
      grupos[i]!.findAll("option").map((o) => o.attributes("value"));
    expect(valores(0)).toContain("pilates");
    expect(valores(0).at(-1)).toBe("general");
    expect(valores(1)).toEqual([
      "barberia",
      "estetica",
      "salon",
      "spa",
      "salud",
      "general_citas",
    ]);
    // Con el tipo de negocio se elige la modalidad; quién la cambia después va en
    // las preguntas frecuentes, no aquí.
    expect(vista.get("#perfil-ayuda").text()).toContain(
      "Con el tipo de negocio eliges tu modalidad, clases o citas.",
    );
    expect(vista.text()).not.toContain("cambiar entre ellas");
    expect(grupos[0]!.get('option[value="general"]').text()).toBe(
      "Otro negocio con clases",
    );
    expect(grupos[1]!.get('option[value="general_citas"]').text()).toBe(
      "Otro negocio de citas",
    );
    // Sin modalidad de llegada se ven las dos: no hace falta el enlace a la otra.
    expect(vista.find('[data-prueba="otra-modalidad"]').exists()).toBe(false);
  });

  it("ya no dice en el registro que solo AgendaUno cambia la modalidad", async () => {
    for (const props of [
      {},
      { modo: "citas" as const },
      { modo: "clases" as const },
    ]) {
      const vista = montar(props);
      for (const quitado of [
        "Después solo AgendaUno la cambia",
        "solo antes de que empieces a operar",
        "¿No ves tu giro?",
        "solo cambia cómo se llaman las cosas",
      ]) {
        expect(vista.text()).not.toContain(quitado);
      }
    }
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
describe("registro desde /clases o /citas (?modo=)", () => {
  const etiquetasDeGrupos = (vista: ReturnType<typeof montar>) =>
    vista.findAll("#perfil optgroup").map((g) => g.attributes("label"));
  const giros = (vista: ReturnType<typeof montar>) =>
    vista
      .findAll("#perfil option[value]:not([value=''])")
      .map((o) => o.attributes("value"));
  const enlaceOtra = (vista: ReturnType<typeof montar>) =>
    vista.get('[data-prueba="otra-modalidad"]');
  async function hastaContacto(
    vista: ReturnType<typeof montar>,
    perfil: string | null,
  ) {
    await flushPromises();
    await vista.get("#nombre").setValue("Mi negocio");
    if (perfil !== null) {
      await vista.get("#perfil").setValue(perfil);
    }
    await vista.get("form").trigger("submit");
    await vista.get("#cnombre").setValue("Ana");
    await vista.get("#cpaterno").setValue("Pérez");
    await vista.get("form").trigger("submit");
  }
  async function crear(vista: ReturnType<typeof montar>) {
    await vista.get('input[type="tel"]').setValue("55 1234 5678");
    await vista.get("#cemail").setValue("ana@correo.mx");
    await vista.get("#acepta").setValue(true);
    await vista.get("form").trigger("submit");
    await flushPromises();
  }
  function responderAlta() {
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estudio: { slug: "mi-negocio", nombre: "Mi negocio" },
          activacion: null,
        },
      },
    });
  }

  it("si el servidor rechaza un dato, vuelve a su paso; si no, se queda en la confirmación", async () => {
    const vista = montar({ modo: "clases" });
    await hastaContacto(vista, "pilates");
    // Sin red o sin legales publicados: nada que corregir atrás.
    mocks.post.mockRejectedValueOnce({});
    await crear(vista);
    expect(vista.find("#acepta").exists()).toBe(true);
    expect(vista.text()).toContain("No disponible");
    // La dirección ya la ocupó alguien: de vuelta al paso 1.
    mocks.post.mockRejectedValueOnce({ campos: ["slug"] });
    await vista.get("form").trigger("submit");
    await flushPromises();
    expect(vista.find("#nombre").exists()).toBe(true);
  });

  it("con ?modo=citas solo se ven los giros de citas, con «Otro negocio de citas»", () => {
    const vista = montar({ modo: "citas" });
    expect(etiquetasDeGrupos(vista)).toEqual(["Citas 1 a 1"]);
    expect(giros(vista)).toEqual([
      "barberia",
      "estetica",
      "salon",
      "spa",
      "salud",
      "general_citas",
    ]);
    expect(vista.get('#perfil option[value="general_citas"]').text()).toBe(
      "Otro negocio de citas",
    );
    // Debajo, el enlace discreto a los giros de clases (un botón real).
    const otra = enlaceOtra(vista);
    expect(otra.text()).toContain("¿Das clases?");
    expect(otra.get("button").attributes("type")).toBe("button");
    expect(otra.get("button").text()).toBe("Ver giros de clases");
    // Nada elegido de antemano: el dueño elige su giro.
    expect((vista.get("#perfil").element as HTMLSelectElement).value).toBe("");
  });

  it("con ?modo=clases solo se ven los de clases y el enlace lleva a los de citas", () => {
    const vista = montar({ modo: "clases" });
    expect(etiquetasDeGrupos(vista)).toEqual(["Clases con cupo"]);
    expect(giros(vista)).toContain("general");
    expect(giros(vista)).not.toContain("barberia");
    expect(giros(vista)).not.toContain("general_citas");
    expect(enlaceOtra(vista).text()).toContain("¿Atiendes con cita?");
    expect(enlaceOtra(vista).get("button").text()).toBe("Ver giros de citas");
  });

  it("el enlace cambia de modalidad sin perder lo escrito y lleva el foco al selector", async () => {
    const vista = montar({ modo: "citas" }, { adjuntar: true });
    await vista.get("#nombre").setValue("Estudio Norte");
    await vista.get("#perfil").setValue("barberia");
    await enlaceOtra(vista).get("button").trigger("click");
    await flushPromises();
    expect(etiquetasDeGrupos(vista)).toEqual(["Clases con cupo"]);
    expect((vista.get("#nombre").element as HTMLInputElement).value).toBe(
      "Estudio Norte",
    );
    // Barbería no está entre los de clases: se suelta para elegir otro.
    expect((vista.get("#perfil").element as HTMLSelectElement).value).toBe("");
    expect(document.activeElement?.id).toBe("perfil");
    expect(enlaceOtra(vista).get("button").text()).toBe("Ver giros de citas");
    // Y de regreso.
    await enlaceOtra(vista).get("button").trigger("click");
    expect(etiquetasDeGrupos(vista)).toEqual(["Citas 1 a 1"]);
    await vista.get("#perfil").setValue("spa");
    expect(
      vista.get('button[type="submit"]').attributes("disabled"),
    ).toBeUndefined();
  });

  it("sin modo, o con uno inválido, se ven los dos grupos y no hay enlace", async () => {
    // La ruta lo normaliza con modoDeQuery: lo inválido llega como null.
    for (const props of [{}, { modo: modoDeQuery("talleres") }]) {
      const vista = montar(props);
      expect(etiquetasDeGrupos(vista)).toEqual([
        "Clases con cupo",
        "Citas 1 a 1",
      ]);
      expect(vista.find('[data-prueba="otra-modalidad"]').exists()).toBe(false);
    }
    expect(trackEvent).toHaveBeenCalledWith("studio_registration_started", {});
    // Normalizado: con espacios o mayúsculas sigue valiendo.
    const otra = montar({ modo: modoDeQuery([" CITAS "]) });
    expect(etiquetasDeGrupos(otra)).toEqual(["Citas 1 a 1"]);
  });

  it("ya no avisa de un giro de la otra modalidad: no se puede elegir", async () => {
    const vista = montar();
    await vista.get("#perfil").setValue("barberia");
    await vista.get("#perfil").setValue("pilates");
    expect(vista.find('[data-prueba="aviso-otra-modalidad"]').exists()).toBe(
      false,
    );
    expect(vista.text()).not.toContain("llegaste desde");
  });

  it("en el paso 3 resume «Tipo de negocio · Modalidad» y deja volver a cambiarlo", async () => {
    const vista = montar({ modo: "citas" }, { adjuntar: true });
    await hastaContacto(vista, "barberia");
    const resumen = vista.get('[data-prueba="resumen-modalidad"]');
    expect(resumen.text()).toContain("Tipo de negocio · Modalidad");
    expect(resumen.get(".registro-resumen-valor").text()).toBe(
      "Barbería · Citas 1 a 1",
    );
    expect(resumen.text()).toContain("Revísalo antes de crear tu negocio.");
    // Quién cambia la modalidad después va en las preguntas frecuentes: tampoco
    // se repite en el paso 3.
    expect(resumen.text()).not.toContain("AgendaUno");
    for (const quitado of [
      "la modalidad solo la cambia",
      "solo antes de operar",
      "solo antes de que empieces a operar",
      "¿No ves tu giro?",
    ]) {
      expect(vista.text()).not.toContain(quitado);
    }
    const cambiar = resumen.get("button");
    expect(cambiar.attributes("aria-label")).toBe("Cambiar el tipo de negocio");
    await cambiar.trigger("click");
    await flushPromises();
    expect(vista.get('[aria-current="step"]').text()).toBe("1Tu negocio");
    expect((vista.get("#perfil").element as HTMLSelectElement).value).toBe(
      "barberia",
    );
    expect(document.activeElement?.id).toBe("perfil");
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it("sin modo también resume la modalidad del giro elegido", async () => {
    const vista = montar();
    await hastaContacto(vista, "general");
    expect(
      vista
        .get('[data-prueba="resumen-modalidad"] .registro-resumen-valor')
        .text(),
    ).toBe("Otro negocio con clases · Clases con cupo");
  });

  it("mide la intención (mode_intent) y la modalidad creada (mode)", async () => {
    responderAlta();
    const vista = montar({ modo: "citas" });
    expect(trackEvent).toHaveBeenCalledWith("studio_registration_started", {
      mode_intent: "citas",
    });
    // Llegó desde citas, pero da clases: cambia de modalidad con el enlace.
    await enlaceOtra(vista).get("button").trigger("click");
    await hastaContacto(vista, "pilates");
    expect(trackEvent).toHaveBeenCalledWith(
      "studio_registration_step_completed",
      { step: 1, mode_intent: "citas" },
    );
    await crear(vista);
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/registro",
      expect.objectContaining({ perfil_negocio: "pilates" }),
      { timeout: 120_000 },
    );
    // Llegó desde citas y creó un negocio de clases: los dos datos quedan medidos.
    expect(trackEvent).toHaveBeenCalledWith("tenant_created", {
      business_profile: "pilates",
      mode: "clases",
      mode_intent: "citas",
    });
  });

  it("sin modo, tenant_created lleva la modalidad y no inventa una intención", async () => {
    responderAlta();
    const vista = montar();
    await hastaContacto(vista, "spa");
    await crear(vista);
    expect(trackEvent).toHaveBeenCalledWith("tenant_created", {
      business_profile: "spa",
      mode: "citas",
    });
  });
});

describe("registro desde un giro (?giro=)", () => {
  const giroElegido = (vista: ReturnType<typeof montar>) =>
    vista.find('[data-prueba="giro-elegido"]');

  it("con ?modo=citas&giro=barberia el giro ya viene elegido: resumen y no selector", async () => {
    const vista = montar({ modo: "citas", giro: "barberia" });
    expect(vista.find("#perfil").exists()).toBe(false);
    expect(giroElegido(vista).get("p").text().replace(/\s+/g, " ")).toBe(
      "Tipo de negocio: Barbería · Citas 1 a 1",
    );
    const cambiar = giroElegido(vista).get("button");
    expect(cambiar.attributes("type")).toBe("button");
    expect(cambiar.text()).toBe("Cambiar");
    expect(cambiar.attributes("aria-label")).toBe("Cambiar el tipo de negocio");
    // Sin elegir nada más, el paso 1 se completa con el nombre.
    await vista.get("#nombre").setValue("Barbería Norte");
    await vista.get("form").trigger("submit");
    expect(vista.get('[aria-current="step"]').text()).toBe("2Tus datos");
  });

  it("«Cambiar» vuelve a mostrar el selector, con los giros de su modalidad", async () => {
    const vista = montar(
      { modo: "citas", giro: "barberia" },
      { adjuntar: true },
    );
    await vista.get("#nombre").setValue("Barbería Norte");
    await giroElegido(vista).get("button").trigger("click");
    await flushPromises();
    expect(giroElegido(vista).exists()).toBe(false);
    const selector = vista.get("#perfil");
    expect((selector.element as HTMLSelectElement).value).toBe("barberia");
    expect(document.activeElement?.id).toBe("perfil");
    expect(
      vista.findAll("#perfil optgroup").map((g) => g.attributes("label")),
    ).toEqual(["Citas 1 a 1"]);
    expect(vista.get('[data-prueba="otra-modalidad"] button').text()).toBe(
      "Ver giros de clases",
    );
    // Lo escrito sigue ahí.
    expect((vista.get("#nombre").element as HTMLInputElement).value).toBe(
      "Barbería Norte",
    );
  });

  it("un giro inválido se ignora: queda el selector de su modo", () => {
    // La ruta lo normaliza con giroDeQuery; aun así, la vista no confía en él.
    for (const giro of [giroDeQuery("wellness"), "wellness", "talleres"]) {
      const vista = montar({ modo: "citas", giro });
      expect(giroElegido(vista).exists()).toBe(false);
      expect((vista.get("#perfil").element as HTMLSelectElement).value).toBe(
        "",
      );
      expect(
        vista.findAll("#perfil optgroup").map((g) => g.attributes("label")),
      ).toEqual(["Citas 1 a 1"]);
    }
    expect(trackEvent).toHaveBeenLastCalledWith("studio_registration_started", {
      mode_intent: "citas",
    });
  });

  it("si el giro es de otra modalidad que el modo, manda el giro (y su modo)", async () => {
    const vista = montar({ modo: "clases", giro: "barberia" });
    expect(giroElegido(vista).text()).toContain("Barbería · Citas 1 a 1");
    expect(trackEvent).toHaveBeenCalledWith("studio_registration_started", {
      mode_intent: "citas",
      business_profile_intent: "barberia",
    });
    await giroElegido(vista).get("button").trigger("click");
    expect(
      vista.findAll("#perfil optgroup").map((g) => g.attributes("label")),
    ).toEqual(["Citas 1 a 1"]);
  });

  it("crea el negocio con el giro con que llegó y lo mide como intención", async () => {
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estudio: { slug: "mi-estudio", nombre: "Mi estudio" },
          activacion: null,
        },
      },
    });
    const vista = montar({ modo: "clases", giro: "pilates" });
    await flushPromises();
    await vista.get("#nombre").setValue("Mi estudio");
    await vista.get("form").trigger("submit");
    await vista.get("#cnombre").setValue("Ana");
    await vista.get("#cpaterno").setValue("Pérez");
    await vista.get("form").trigger("submit");
    expect(
      vista
        .get('[data-prueba="resumen-modalidad"] .registro-resumen-valor')
        .text(),
    ).toBe("Estudio de Pilates · Clases con cupo");
    await vista.get('input[type="tel"]').setValue("55 1234 5678");
    await vista.get("#cemail").setValue("ana@correo.mx");
    await vista.get("#acepta").setValue(true);
    await vista.get("form").trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/registro",
      expect.objectContaining({ perfil_negocio: "pilates" }),
      { timeout: 120_000 },
    );
    expect(trackEvent).toHaveBeenCalledWith("tenant_created", {
      business_profile: "pilates",
      mode: "clases",
      mode_intent: "clases",
      business_profile_intent: "pilates",
    });
  });

  it("si la ruta cambia con la vista abierta, toma el nuevo giro o modo", async () => {
    const vista = montar({ modo: "citas", giro: "barberia" });
    await vista.setProps({ modo: "clases", giro: null });
    expect(giroElegido(vista).exists()).toBe(false);
    expect(
      vista.findAll("#perfil optgroup").map((g) => g.attributes("label")),
    ).toEqual(["Clases con cupo"]);
    expect((vista.get("#perfil").element as HTMLSelectElement).value).toBe("");
    await vista.setProps({ modo: null, giro: "spa" });
    expect(giroElegido(vista).text()).toContain("Spa o centro de bienestar");
  });
});

describe("WhatsApp del dueño", () => {
  async function hastaContacto(vista: ReturnType<typeof montar>) {
    await flushPromises();
    await avanzarADatos(vista);
    await vista.get("#cnombre").setValue("Ana");
    await vista.get("#cpaterno").setValue("Pérez");
    await vista.get("form").trigger("submit");
    await vista.get('input[type="tel"]').setValue("55 1234 5678");
    await vista.get("#cemail").setValue("ana@correo.mx");
    await vista.get("#acepta").setValue(true);
  }

  it("si la plataforma lo usa, confirma el número con un código y lo manda al crear el negocio", async () => {
    mocks.get.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data:
            url === "/api/v1/registro/whatsapp"
              ? { disponible: true }
              : { terminos: "T", aviso_privacidad: "A" },
        },
      }),
    );
    mocks.post.mockImplementation((url: string) =>
      Promise.resolve({
        data: {
          data: url.endsWith("/whatsapp/verificar")
            ? { verificacion: "comprobante" }
            : url.endsWith("/whatsapp/codigo")
              ? { enviado: true }
              : {
                  estudio: { slug: "mi-barberia", nombre: "Mi barbería" },
                  activacion: null,
                },
        },
      }),
    );
    const vista = montar();
    await hastaContacto(vista);

    await vista.get('[data-prueba="quiere-whatsapp"]').setValue(true);
    // Marcó la casilla: sin confirmar el código no se crea el negocio.
    await vista.get("form").trigger("submit");
    expect(mocks.post).not.toHaveBeenCalled();

    await vista.get('[data-prueba="enviar-codigo"]').trigger("click");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/registro/whatsapp/codigo",
      expect.objectContaining({
        contacto_whatsapp_pais: "52",
        contacto_telefono: "5512345678",
      }),
    );
    expect(vista.text()).toContain("Puedes pedir otro en 60 s");

    await vista.get("#wa-codigo").setValue("123456");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/registro/whatsapp/verificar",
      expect.objectContaining({ codigo: "123456" }),
    );
    expect(vista.get('[data-prueba="whatsapp-verificado"]').text()).toBe(
      "WhatsApp verificado",
    );

    await vista.get("form").trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenLastCalledWith(
      "/api/v1/registro",
      expect.objectContaining({
        whatsapp_verificacion: "comprobante",
        pais: "MX",
        zona_horaria: "America/Mexico_City",
        contacto_whatsapp_pais: "52",
        contacto_telefono: "5512345678",
      }),
      // Crear el negocio puede tardar más que el límite general.
      { timeout: 120_000 },
    );
  });

  it("si la plataforma no lo usa, queda la ayuda de siempre", async () => {
    const vista = montar();
    await hastaContacto(vista);

    expect(vista.find('[data-prueba="whatsapp-dueno"]').exists()).toBe(false);
    expect(vista.text()).toContain(es.registro.whatsappAyuda);
  });
});

describe("país del negocio", () => {
  // Elige en el selector con buscador: escribe y toma la primera coincidencia.
  async function elegirPais(vista: ReturnType<typeof montar>, texto: string) {
    const campo = vista.get("#pais");
    await campo.trigger("click");
    await campo.setValue(texto);
    await campo.trigger("keydown", { key: "Enter" });
  }
  function ladaElegida(vista: ReturnType<typeof montar>): string {
    return vista.get('[data-prueba="lada-celular"]').text();
  }

  it("propone el del navegador y con él la lada del WhatsApp", async () => {
    navegador.pais = "CO";
    const vista = montar();
    await flushPromises();
    expect((vista.get("#pais").element as HTMLInputElement).value).toBe(
      "Colombia",
    );
    await avanzarADatos(vista);
    await vista.get("#cnombre").setValue("Ana");
    await vista.get("#cpaterno").setValue("Pérez");
    await vista.get("form").trigger("submit");
    expect(ladaElegida(vista)).toBe("CO +57");
  });

  it("la lada sigue al país elegido y se mandan el país y su zona", async () => {
    mocks.post.mockResolvedValue({
      data: {
        data: {
          estudio: { slug: "mi-estudio", nombre: "Mi estudio" },
          activacion: null,
        },
      },
    });
    const vista = montar();
    await flushPromises();
    await avanzarADatos(vista);
    await vista.get("#cnombre").setValue("Ana");
    await vista.get("#cpaterno").setValue("Pérez");
    await vista.get("form").trigger("submit");
    expect(ladaElegida(vista)).toBe("MX +52");
    await vista.get("#cwhatsapp").setValue("300 123 4567");

    // Regresa al primer paso y cambia el país: la lada pasa a la del nuevo.
    await vista.get("button.tu-btn-fantasma").trigger("click");
    await vista.get("button.tu-btn-fantasma").trigger("click");
    await elegirPais(vista, "colombia");
    await vista.get("form").trigger("submit");
    await vista.get("form").trigger("submit");
    expect(ladaElegida(vista)).toBe("CO +57");
    expect((vista.get("#cwhatsapp").element as HTMLInputElement).value).toBe(
      "3001234567",
    );

    await vista.get("#cemail").setValue("ana@correo.mx");
    await vista.get("#acepta").setValue(true);
    await vista.get("form").trigger("submit");
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/registro",
      expect.objectContaining({
        pais: "CO",
        zona_horaria: "America/Bogota",
        contacto_whatsapp_pais: "57",
        contacto_telefono: "3001234567",
      }),
      { timeout: 120_000 },
    );
  });
});
