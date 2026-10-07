import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import OnboardingView from "./OnboardingView.vue";

/*
| Configuración inicial por tipo de negocio (ADR 0088): un negocio de citas da de alta
| sus servicios en una línea (nombre, duración y precio) y dice quién atiende y cuándo;
| uno de clases, sus clases, su horario semanal y sus planes.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }));
const sesion = vi.hoisted(() => ({
  slug: "demo",
  estudio: { logo_url: null, nombre: "Demo", perfil: "barberia" },
  // La modalidad que guarda el servidor (ADR 0104), no la de los pasos.
  modalidad: "citas" as "clases" | "citas",
  esCitas: true,
  moneda: "MXN",
  pais: "MX",
  usuario: { ulid: "u1", rol: "propietario", roles: ["propietario"] },
  terminologia: { sesion: "Cita", miembro: "Cliente", instructor: "Barbero" },
  cargarYo: vi.fn(),
  cambiarGiro: vi.fn(),
}));
function negocioDeClases(): void {
  sesion.modalidad = "clases";
  sesion.esCitas = false;
  sesion.estudio.perfil = "pole";
}
vi.mock("@/lib/api", () => ({ api, mensajeDeError: String }));
vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
const ruta = vi.hoisted(() => ({ query: {} as Record<string, string> }));
vi.mock("vue-router", () => ({
  RouterLink: { props: ["to"], template: "<a><slot /></a>" },
  useRoute: () => ruta,
  useRouter: () => ({ push: vi.fn() }),
}));

const SUGERENCIAS = {
  servicios: [
    { nombre: "Corte de cabello", duracion_minutos: 30, precio_minor: 25000 },
  ],
  clases: [{ nombre: "Pole Nivel 1", duracion_minutos: 60, capacidad: 8 }],
  planes: [
    { clave: "suelta", nombre: "Clase suelta", precio_minor: 20000, clases: 1 },
  ],
};

const ESTADO = {
  configurado: false,
  publicado: true,
  reservable: false,
  listo: false,
  primera_fecha: null,
  motivo: "sin_horario",
};

// Los giros que el servidor deja elegir (GET /onboarding `perfiles`), si los manda.
let perfilesDelServidor: string[] | undefined;

function responder(
  pasos: string[],
  completados: string[],
  extra: Record<string, unknown> = {},
  estado: Record<string, unknown> = ESTADO,
): void {
  api.get.mockImplementation((url: string) => {
    const ruta = url.replace("/api/v1/app/demo", "");
    if (ruta === "/onboarding") {
      return Promise.resolve({
        data: {
          data: {
            ...(perfilesDelServidor ? { perfiles: perfilesDelServidor } : {}),
            pasos,
            completados,
            completo: false,
            estado,
            publicacion: { publicado: true, privado: false },
            sugerencias: SUGERENCIAS,
          },
        },
      });
    }
    if (ruta === "/sucursales") {
      return Promise.resolve({
        data: { data: [{ id: "s1", nombre: "Roma Norte" }] },
      });
    }
    return Promise.resolve({ data: { data: extra[ruta] ?? [] } });
  });
}

function montar() {
  return mount(OnboardingView, {
    global: { plugins: [i18n], stubs: { CargadorLogo: true } },
  });
}

function boton(w: ReturnType<typeof montar>, texto: string) {
  const b = w.findAll("button").find((x) => x.text().includes(texto));
  if (!b) {
    throw new Error(`Sin botón «${texto}»`);
  }
  return b;
}

beforeEach(() => {
  vi.clearAllMocks();
  ruta.query = {};
  perfilesDelServidor = undefined;
  sesion.moneda = "MXN";
  sesion.pais = "MX";
  sesion.modalidad = "citas";
  sesion.esCitas = true;
  sesion.estudio.perfil = "barberia";
  api.put.mockResolvedValue({
    data: { data: { completados: [], completo: false } },
  });
  api.post.mockResolvedValue({ data: { data: [] } });
});

describe("configuración inicial de un negocio de citas", () => {
  it("son cinco pasos y el servicio se da de alta en una línea", async () => {
    responder(
      ["negocio", "servicios", "equipo", "reglas", "publicacion"],
      ["negocio"],
    );
    const w = montar();
    await flushPromises();

    expect(w.findAll(".ci-paso")).toHaveLength(5);
    expect(w.get("article").attributes("data-paso")).toBe("servicios");
    // Empieza con lo más común del giro; el dueño ajusta el precio.
    const fila = w.get('[data-prueba="filas"] .ci-fila:not(.ci-fila-cabeza)');
    expect((fila.get("input").element as HTMLInputElement).value).toBe(
      "Corte de cabello",
    );
    await fila.find(".ci-precio input").setValue("280");
    await w.get('[data-prueba="accion"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/onboarding/catalogo",
      {
        items: [
          {
            nombre: "Corte de cabello",
            duracion_minutos: 30,
            precio_minor: 28000,
          },
        ],
      },
    );
    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/onboarding", {
      paso: "servicios",
    });
  });

  it("«Yo atiendo» lo vuelve profesional y le pone el horario elegido", async () => {
    responder(
      ["negocio", "servicios", "equipo", "reglas", "publicacion"],
      ["negocio", "servicios"],
    );
    api.put.mockResolvedValue({
      data: { data: { completados: [], completo: false } },
    });
    const w = montar();
    await flushPromises();
    expect(w.get("article").attributes("data-paso")).toBe("equipo");

    // Quita el sábado (por omisión, de lunes a sábado).
    await boton(w, "Sáb").trigger("click");
    await w.get('[data-prueba="accion"]').trigger("click");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/usuarios/u1/roles", {
      roles: ["propietario", "instructor"],
    });
    expect(sesion.cargarYo).toHaveBeenCalled();
    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/horarios-atencion", {
      instructor_id: "u1",
      sucursal_id: "s1",
      horarios: [1, 2, 3, 4, 5].map((d) => ({
        dia_semana: d,
        hora_inicio: "10:00",
        hora_fin: "19:00",
      })),
    });
  });
});

describe("tipo de negocio en «Tu negocio» (ADR 0104)", () => {
  const CITAS = ["negocio", "servicios", "equipo", "reglas", "publicacion"];

  it("solo ofrece los giros de su modalidad y otro giro se guarda con el paso", async () => {
    responder(CITAS, []);
    const w = montar();
    await flushPromises();
    expect(w.get("article").attributes("data-paso")).toBe("negocio");

    const giro = w.get('[data-prueba="giro"]');
    expect(giro.findAll("option").map((o) => o.attributes("value"))).toEqual([
      "barberia",
      "estetica",
      "salon",
      "spa",
      "salud",
      "general_citas",
    ]);
    await giro.setValue("spa");
    await w.get('[data-prueba="accion"]').trigger("click");
    await flushPromises();

    expect(sesion.cambiarGiro).toHaveBeenCalledWith("spa");
    // Con el giro nuevo llegan sus sugerencias.
    expect(
      api.get.mock.calls.filter(
        ([url]) => url === "/api/v1/app/demo/onboarding",
      ),
    ).toHaveLength(2);
    expect(w.get("article").attributes("data-paso")).toBe("servicios");
  });

  it("si el servidor manda los giros permitidos, ofrece esos", async () => {
    perfilesDelServidor = ["barberia", "spa"];
    responder(CITAS, []);
    const w = montar();
    await flushPromises();

    expect(
      w
        .get('[data-prueba="giro"]')
        .findAll("option")
        .map((o) => o.attributes("value")),
    ).toEqual(["barberia", "spa"]);
  });

  it("si el servidor lo niega (MODALITY_LOCKED), lo dice y no avanza", async () => {
    sesion.cambiarGiro.mockRejectedValueOnce(
      "Este negocio trabaja con citas; cambiar a clases lo hace AgendaUno.",
    );
    responder(CITAS, []);
    const w = montar();
    await flushPromises();

    await w.get('[data-prueba="giro"]').setValue("salon");
    await w.get('[data-prueba="accion"]').trigger("click");
    await flushPromises();

    expect(w.text()).toContain(
      "Este negocio trabaja con citas; cambiar a clases lo hace AgendaUno.",
    );
    expect(w.get("article").attributes("data-paso")).toBe("negocio");
    expect(
      (w.get('[data-prueba="giro"]').element as HTMLSelectElement).value,
    ).toBe("barberia");
  });

  it("sin cambiar de giro no lo vuelve a guardar", async () => {
    negocioDeClases();
    responder(
      ["negocio", "clases", "horario", "planes", "reglas", "publicacion"],
      [],
    );
    const w = montar();
    await flushPromises();

    const opciones = w
      .get('[data-prueba="giro"]')
      .findAll("option")
      .map((o) => o.attributes("value"));
    expect(opciones).toContain("pole");
    expect(opciones).not.toContain("barberia");
    await w.get('[data-prueba="accion"]').trigger("click");
    await flushPromises();

    expect(sesion.cambiarGiro).not.toHaveBeenCalled();
    expect(w.get("article").attributes("data-paso")).toBe("clases");
  });
});

describe("configuración inicial de un negocio de clases", () => {
  it("cada clase toma sus días y su hora, y se programa cada semana", async () => {
    negocioDeClases();
    responder(
      ["negocio", "clases", "horario", "planes", "reglas", "publicacion"],
      ["negocio", "clases"],
      {
        "/ofertas": [
          {
            id: "o1",
            nombre: "Pole Nivel 1",
            modalidad: "grupal",
            duracion_minutos: 60,
            capacidad: 8,
          },
        ],
      },
    );
    api.post.mockResolvedValue({ data: { data: { id: "p1" } } });
    const w = montar();
    await flushPromises();
    expect(w.findAll(".ci-paso")).toHaveLength(6);
    expect(w.get("article").attributes("data-paso")).toBe("horario");

    const clase = w.get('[data-prueba="clase-horario"]');
    await clase
      .findAll("button")
      .find((b) => b.text() === "Lun")!
      .trigger("click");
    await clase
      .findAll("button")
      .find((b) => b.text() === "Mié")!
      .trigger("click");
    await w.get('[data-prueba="accion"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/plantillas-horario",
      expect.objectContaining({
        oferta_id: "o1",
        sucursal_id: "s1",
        dias_semana: [1, 3],
        hora_local: "19:00",
        duracion_minutos: 60,
        capacidad: 8,
      }),
    );
    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/plantillas-horario/p1/generar",
      expect.objectContaining({ desde: expect.any(String) }),
    );
  });
});

describe("los mismos pasos que «Pon tu negocio en marcha» (ADR 0090)", () => {
  it("desde el panel abre el paso pedido; las reglas se aceptan y se guardan", async () => {
    ruta.query = { paso: "reglas" };
    responder(
      ["negocio", "servicios", "equipo", "reglas", "publicacion"],
      ["negocio", "servicios", "equipo"],
      {
        "/politicas-cancelacion": [
          {
            actividad_id: null,
            horas_limite: 6,
            penaliza_tarde: true,
            penaliza_no_show: true,
            tolerancia_no_show: 0,
            ventana_no_show_dias: null,
          },
        ],
      },
    );
    const w = montar();
    await flushPromises();
    expect(w.get("article").attributes("data-paso")).toBe("reglas");

    await w.get('[data-prueba="horas-limite"]').setValue("12");
    await boton(w, "Aceptar reglas").trigger("click");
    await flushPromises();

    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/politicas-cancelacion",
      expect.objectContaining({
        actividad_id: null,
        horas_limite: 12,
        penaliza_tarde: true,
      }),
    );
    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/onboarding", {
      paso: "reglas",
    });
  });

  it("al publicar: dice si recibe reservas, qué falta y cómo se publica", async () => {
    ruta.query = { paso: "publicacion" };
    responder(
      ["negocio", "servicios", "equipo", "reglas", "publicacion"],
      ["negocio", "servicios"],
    );
    const w = montar();
    await flushPromises();

    const estados = w.get('[data-prueba="estados"]');
    expect(estados.text()).toContain("Faltan 2 pasos.");
    expect(w.get('[data-prueba="estado-reservable"]').text()).toContain(
      "Aún no hay un horario de atención.",
    );
    // Lleva al paso donde se resuelve.
    await boton(w, "Ir a Quién atiende").trigger("click");
    expect(w.get("article").attributes("data-paso")).toBe("equipo");

    await w.findAll(".ci-paso-boton")[4]!.trigger("click");
    await w.get('[data-prueba="visibilidad-enlace"]').setValue(true);
    await w.get('[data-prueba="accion"]').trigger("click");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith("/api/v1/app/demo/publicacion", {
      publicado: true,
      privado: true,
    });
  });
});

describe("precios como se escriben en el país del negocio", () => {
  const CITAS = ["negocio", "servicios", "equipo", "reglas", "publicacion"];

  it("en Colombia «25.000» son veinticinco mil pesos", async () => {
    sesion.pais = "CO";
    sesion.moneda = "COP";
    responder(CITAS, ["negocio"]);
    const w = montar();
    await flushPromises();

    const fila = w.get('[data-prueba="filas"] .ci-fila:not(.ci-fila-cabeza)');
    expect(fila.get(".ci-precio span").text()).toBe("$");
    await fila.get('[data-prueba="precio-servicio"]').setValue("25.000");
    expect(w.find('[data-prueba="precio-ilegible"]').exists()).toBe(false);
    await w.get('[data-prueba="accion"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/onboarding/catalogo",
      {
        items: [
          expect.objectContaining({
            nombre: "Corte de cabello",
            precio_minor: 2500000,
          }),
        ],
      },
    );
  });

  it("lo que se presta a confusión se marca en su campo y no avanza", async () => {
    responder(CITAS, ["negocio"]);
    const w = montar();
    await flushPromises();

    const fila = w.get('[data-prueba="filas"] .ci-fila:not(.ci-fila-cabeza)');
    // En México, «1.250» podría ser 1.25 o mil doscientos cincuenta.
    await fila.get('[data-prueba="precio-servicio"]').setValue("1.250");
    expect(w.get('[data-prueba="precio-ilegible"]').text()).toBe(
      "No entendimos este precio. Escríbelo así: 1,250.50.",
    );
    const accion = w.get('[data-prueba="accion"]');
    expect(accion.attributes("disabled")).toBeDefined();
    await accion.trigger("click");
    await flushPromises();
    expect(api.post).not.toHaveBeenCalled();

    await fila.get('[data-prueba="precio-servicio"]').setValue("1,250.50");
    expect(w.find('[data-prueba="precio-ilegible"]').exists()).toBe(false);
    expect(accion.attributes("disabled")).toBeUndefined();
  });

  it("los planes en España: «12,50» con el símbolo del euro", async () => {
    negocioDeClases();
    sesion.pais = "ES";
    sesion.moneda = "EUR";
    responder(
      ["negocio", "clases", "horario", "planes", "reglas", "publicacion"],
      ["negocio", "clases", "horario"],
    );
    api.post.mockResolvedValue({
      data: { data: { id: "p1", nombre: "Clase suelta", precio_minor: 1250 } },
    });
    const w = montar();
    await flushPromises();
    expect(w.get("article").attributes("data-paso")).toBe("planes");

    const precio = w.get('[data-prueba="precio-plan-suelta"]');
    // La sugerencia (200.00) se escribe como en España y se lee igual.
    expect((precio.element as HTMLInputElement).value).toBe("200");
    expect(w.get(".ci-plan .ci-precio span").text()).toBe("€");
    await precio.setValue("12,50");
    await w.get('[data-prueba="accion"]').trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith(
      "/api/v1/app/demo/productos",
      expect.objectContaining({ precio_minor: 1250, moneda: "EUR" }),
    );
  });
});
