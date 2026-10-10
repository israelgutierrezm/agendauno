import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import es from "@/i18n/locales/es-MX";
import modalidadNegocio from "@/i18n/locales/modalidad.es-MX";
import { trackEvent } from "@/lib/analytics";
import { giroDeQuery, modoDeQuery, type Modo } from "@/marketing/modalidades";
import { PRECIOS_POR_OMISION } from "@/marketing/precios";
import { aplicarPreciosPublicos } from "@/marketing/preciosPublicos";
import interesados from "@/i18n/locales/interesados.es-MX";
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
          messages: { es: { ...es, modalidadNegocio, interesados } },
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
  it("solo los tipos de negocio de su producto: en AgendaUno, los de clases (ADR 0108)", () => {
    const vista = montar();
    const grupos = vista.findAll("#perfil optgroup");
    expect(grupos.map((g) => g.attributes("label"))).toEqual([
      "Clases con cupo",
    ]);
    const valores = grupos[0]!
      .findAll("option")
      .map((o) => o.attributes("value"));
    expect(valores).toContain("pilates");
    expect(valores.at(-1)).toBe("general");
    expect(valores).not.toContain("barberia");
    expect(grupos[0]!.get('option[value="general"]').text()).toBe(
      "Otro negocio con clases",
    );
    // Ya no se elige la modalidad: es la del producto.
    expect(vista.text()).not.toContain("eliges tu modalidad");
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

  it("un ?modo= de la otra modalidad no aplica: siguen los giros del producto", () => {
    const vista = montar({ modo: "citas" });
    expect(etiquetasDeGrupos(vista)).toEqual(["Clases con cupo"]);
    expect(giros(vista)).not.toContain("barberia");
    expect(vista.find('[data-prueba="otra-modalidad"]').exists()).toBe(false);
    // Nada elegido de antemano: el dueño elige su giro.
    expect((vista.get("#perfil").element as HTMLSelectElement).value).toBe("");
  });

  it("sin modo, o con uno inválido, los giros del producto y sin intención", async () => {
    // La ruta lo normaliza con modoDeQuery: lo inválido llega como null.
    for (const props of [{}, { modo: modoDeQuery("talleres") }]) {
      const vista = montar(props);
      expect(etiquetasDeGrupos(vista)).toEqual(["Clases con cupo"]);
      expect(vista.find('[data-prueba="otra-modalidad"]').exists()).toBe(false);
    }
    expect(trackEvent).toHaveBeenCalledWith("studio_registration_started", {});
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
    const vista = montar({ modo: "clases" }, { adjuntar: true });
    await hastaContacto(vista, "pilates");
    const resumen = vista.get('[data-prueba="resumen-modalidad"]');
    expect(resumen.text()).toContain("Tipo de negocio · Modalidad");
    expect(resumen.get(".registro-resumen-valor").text()).toBe(
      "Estudio de Pilates · Clases con cupo",
    );
    expect(resumen.text()).toContain("Revísalo antes de crear tu negocio.");
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
      "pilates",
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

  it("mide la intención (mode_intent) y la modalidad creada (mode), y manda el producto", async () => {
    responderAlta();
    const vista = montar({ modo: "clases" });
    expect(trackEvent).toHaveBeenCalledWith("studio_registration_started", {
      mode_intent: "clases",
    });
    await hastaContacto(vista, "pilates");
    expect(trackEvent).toHaveBeenCalledWith(
      "studio_registration_step_completed",
      { step: 1, mode_intent: "clases" },
    );
    await crear(vista);
    // El producto desde el que se registra (ADR 0108): el servidor revisa el giro.
    expect(mocks.post).toHaveBeenCalledWith(
      "/api/v1/registro",
      expect.objectContaining({
        perfil_negocio: "pilates",
        producto: "agendauno",
      }),
      { timeout: 120_000 },
    );
    expect(trackEvent).toHaveBeenCalledWith("tenant_created", {
      business_profile: "pilates",
      mode: "clases",
      mode_intent: "clases",
    });
  });

  it("sin modo, tenant_created lleva la modalidad y no inventa una intención", async () => {
    responderAlta();
    const vista = montar();
    await hastaContacto(vista, "yoga");
    await crear(vista);
    expect(trackEvent).toHaveBeenCalledWith("tenant_created", {
      business_profile: "yoga",
      mode: "clases",
    });
  });
});

describe("registro desde un giro (?giro=)", () => {
  const giroElegido = (vista: ReturnType<typeof montar>) =>
    vista.find('[data-prueba="giro-elegido"]');

  it("con ?giro=pilates el giro ya viene elegido: resumen y no selector", async () => {
    const vista = montar({ modo: "clases", giro: "pilates" });
    expect(vista.find("#perfil").exists()).toBe(false);
    expect(giroElegido(vista).get("p").text().replace(/\s+/g, " ")).toBe(
      "Tipo de negocio: Estudio de Pilates · Clases con cupo",
    );
    const cambiar = giroElegido(vista).get("button");
    expect(cambiar.attributes("type")).toBe("button");
    expect(cambiar.text()).toBe("Cambiar");
    expect(cambiar.attributes("aria-label")).toBe("Cambiar el tipo de negocio");
    // Sin elegir nada más, el paso 1 se completa con el nombre.
    await vista.get("#nombre").setValue("Pilates Norte");
    await vista.get("form").trigger("submit");
    expect(vista.get('[aria-current="step"]').text()).toBe("2Tus datos");
  });

  it("«Cambiar» vuelve a mostrar el selector, con los giros de su producto", async () => {
    const vista = montar(
      { modo: "clases", giro: "pilates" },
      { adjuntar: true },
    );
    await vista.get("#nombre").setValue("Pilates Norte");
    await giroElegido(vista).get("button").trigger("click");
    await flushPromises();
    expect(giroElegido(vista).exists()).toBe(false);
    const selector = vista.get("#perfil");
    expect((selector.element as HTMLSelectElement).value).toBe("pilates");
    expect(document.activeElement?.id).toBe("perfil");
    expect(
      vista.findAll("#perfil optgroup").map((g) => g.attributes("label")),
    ).toEqual(["Clases con cupo"]);
    expect(vista.find('[data-prueba="otra-modalidad"]').exists()).toBe(false);
    // Lo escrito sigue ahí.
    expect((vista.get("#nombre").element as HTMLInputElement).value).toBe(
      "Pilates Norte",
    );
  });

  it("un giro inválido o del otro producto se ignora: queda el selector", () => {
    // La ruta lo normaliza con giroDeQuery; aun así, la vista no confía en él. Un giro
    // de citas es de TurnoUno (ADR 0108): aquí no aplica.
    for (const giro of [
      giroDeQuery("wellness"),
      "wellness",
      "talleres",
      "barberia",
    ]) {
      const vista = montar({ modo: "clases", giro });
      expect(giroElegido(vista).exists()).toBe(false);
      expect((vista.get("#perfil").element as HTMLSelectElement).value).toBe(
        "",
      );
      expect(
        vista.findAll("#perfil optgroup").map((g) => g.attributes("label")),
      ).toEqual(["Clases con cupo"]);
    }
    expect(trackEvent).toHaveBeenLastCalledWith("studio_registration_started", {
      mode_intent: "clases",
    });
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

  it("si la ruta cambia con la vista abierta, toma el nuevo giro", async () => {
    const vista = montar({ modo: "clases", giro: "pilates" });
    await vista.setProps({ modo: "clases", giro: null });
    expect(giroElegido(vista).exists()).toBe(false);
    expect(
      vista.findAll("#perfil optgroup").map((g) => g.attributes("label")),
    ).toEqual(["Clases con cupo"]);
    await vista.setProps({ modo: null, giro: "yoga" });
    expect(giroElegido(vista).text()).toContain("Estudio de Yoga");
  });
});

describe("producto que aún no recibe registros (ADR 0108)", () => {
  it("muestra la lista de interesados en lugar del registro", async () => {
    aplicarPreciosPublicos({ registro: { agendauno: false, turnouno: false } });
    const vista = montar();
    await flushPromises();
    expect(vista.find('[data-prueba="registro-cerrado"]').exists()).toBe(true);
    expect(vista.text()).toContain("AgendaUno abre pronto");
    expect(vista.find("#nombre").exists()).toBe(false);
    aplicarPreciosPublicos(PRECIOS_POR_OMISION);
  });

  it("la lista llega con el giro de la página (`?giro=`) ya elegido", async () => {
    aplicarPreciosPublicos({ registro: { agendauno: false, turnouno: false } });
    try {
      const vista = montar({ modo: "clases", giro: "pilates" });
      await flushPromises();
      const lista = vista.get('[data-prueba="lista-interesados"]');
      expect((lista.get("select").element as HTMLSelectElement).value).toBe(
        "pilates",
      );
    } finally {
      aplicarPreciosPublicos(PRECIOS_POR_OMISION);
    }
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
