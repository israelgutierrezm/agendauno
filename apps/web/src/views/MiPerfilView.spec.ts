import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import { reactive } from "vue";

import esMX from "@/i18n/locales/es-MX";
import { miPerfil } from "@/i18n/locales/equipo.es-MX";
import { miPrivacidad } from "@/i18n/locales/gestion.es-MX";
import datosPersonales from "@/i18n/locales/datosPersonales.es-MX";
import zonaArchivo from "@/i18n/locales/zonaArchivo.es-MX";
import MiPerfilView from "./MiPerfilView.vue";

const estado = vi.hoisted(() => ({
  usuario: {
    rol: "miembro",
    nombre: "Ana Demo",
    nombre_pila: "Ana",
    primer_apellido: "Demo",
    email: "ana@example.test",
    foto_url: null,
    roles_disponibles: [{ clave: "miembro", faceta: "miembro" }],
  },
}));
const sesion = reactive({
  slug: "demo",
  usuario: estado.usuario,
  actualizarUsuario: vi.fn(),
});
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => sesion,
}));
const api = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/toast", () => ({
  useToastStore: () => ({ exito: vi.fn(), error: vi.fn() }),
}));
vi.mock("@/lib/google", () => ({
  clientIdGoogle: () => undefined,
  renderizarBotonGoogle: vi.fn(),
}));

function montar() {
  return mount(MiPerfilView, {
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: "es",
          messages: {
            es: {
              ...esMX,
              miPerfil,
              miPrivacidad,
              zonaArchivo,
              datosPersonales,
            },
          },
          missingWarn: false,
          fallbackWarn: false,
        }),
      ],
      stubs: {
        PanelApariencia: true,
        MiPrivacidad: {
          props: ["integrado"],
          template: '<div data-prueba="privacidad" />',
        },
      },
    },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  sesion.usuario.rol = "miembro";
  sesion.usuario.roles_disponibles = [{ clave: "miembro", faceta: "miembro" }];
  Object.assign(sesion.usuario, {
    tiene_ficha: false,
    celular: null,
    email_pendiente: null,
    tiene_contrasena: true,
    google_conectado: false,
  });
});

describe("perfil unificado", () => {
  it("el miembro tiene su perfil y privacidad juntos", () => {
    const w = montar();
    expect(w.find('[data-prueba="privacidad"]').exists()).toBe(true);
    expect(w.findAll("h1")).toHaveLength(1);
    expect(w.get('input[autocomplete="given-name"]').element).toHaveProperty(
      "value",
      "Ana",
    );
    w.unmount();
  });

  it.each(["propietario", "instructor"])(
    "no ofrece privacidad de alumno al rol %s",
    (rol) => {
      sesion.usuario.rol = rol;
      const w = montar();
      expect(w.find('[data-prueba="privacidad"]').exists()).toBe(false);
      expect(w.find('input[autocomplete="given-name"]').exists()).toBe(true);
      w.unmount();
    },
  );

  it("el calendario nombra Google Calendar, Apple Calendar y Outlook con su miniatura", () => {
    const w = montar();
    const apps = w.get('[data-prueba="apps-calendario"]');
    // El nombre va en el texto; la miniatura es decorativa (aria-hidden).
    const nombres = apps.findAll("li").map((li) => li.text());
    expect(nombres).toHaveLength(3);
    ["Google Calendar", "Apple Calendar", "Outlook"].forEach((n, i) =>
      expect(nombres[i]).toContain(n),
    );
    expect(
      apps.findAll("svg[data-marca]").map((s) => s.attributes("data-marca")),
    ).toEqual(["google", "apple", "outlook"]);
    w.unmount();
  });

  it("se ordena en Datos personales, Acceso y Preferencias y privacidad", () => {
    const w = montar();
    expect(w.findAll("h2.mp-seccion").map((h) => h.text())).toEqual([
      "Datos personales",
      "Acceso",
      "Preferencias y privacidad",
    ]);
    w.unmount();
  });

  it("muestra la identidad y enlaces a secciones reales sin esconder formularios", () => {
    const w = montar();
    const identidad = w.get('[data-prueba="identidad-perfil"]');
    expect(identidad.text()).toContain("Ana Demo");
    expect(identidad.text()).toContain("ana@example.test");
    const enlaces = w.findAll("nav.mp-atajos a");
    expect(enlaces).toHaveLength(3);
    for (const enlace of enlaces) {
      const destino = w.get(enlace.attributes("href")!);
      expect(destino.attributes("aria-labelledby")).toBeTruthy();
    }
    expect(w.findAll("h1")).toHaveLength(1);
    expect(
      w.get('#mp-acceso input[autocomplete="current-password"]').exists(),
    ).toBe(true);
    w.unmount();
  });

  it("no promete privacidad de alumno en los atajos del personal", () => {
    sesion.usuario.rol = "instructor";
    const w = montar();
    expect(w.get("#mp-preferencias-titulo").text()).toBe("Preferencias");
    expect(w.get('nav a[href="#mp-preferencias"]').text()).toBe("Preferencias");
    expect(w.find('[data-prueba="privacidad"]').exists()).toBe(false);
    w.unmount();
  });

  it("respeta el nombre del rol personalizado en la cabecera", () => {
    sesion.usuario.roles_disponibles = [
      {
        clave: "cliente-vip",
        faceta: "miembro",
        nombre: "Cliente VIP",
      } as (typeof sesion.usuario.roles_disponibles)[number],
    ];
    sesion.usuario.rol = "cliente-vip";
    const w = montar();
    expect(w.get(".mp-rol").text()).toBe("Cliente VIP");
    expect(w.get("#mp-preferencias-titulo").text()).toBe(
      "Preferencias y privacidad",
    );
    w.unmount();
  });

  it("permite abrir y cancelar el formulario de correo sin enviar cambios", async () => {
    const w = montar();
    const cambiar = w
      .findAll("button")
      .find((b) => b.text() === "Cambiar correo")!;
    await cambiar.trigger("click");
    expect(w.find("#mp-correo").exists()).toBe(true);
    const cancelar = w.findAll("button").find((b) => b.text() === "Cancelar")!;
    await cancelar.trigger("click");
    expect(w.find("#mp-correo").exists()).toBe(false);
    expect(api.post).not.toHaveBeenCalled();
    w.unmount();
  });

  it("mantiene visible un cambio de correo pendiente de confirmación", () => {
    Object.assign(sesion.usuario, { email_pendiente: "nuevo@example.test" });
    const w = montar();
    expect(w.get("#mp-acceso").text()).toContain("nuevo@example.test");
    expect(w.get("#mp-acceso").text()).toContain("Cancelar cambio");
    expect(w.find("#mp-correo").exists()).toBe(false);
    w.unmount();
  });

  it("muestra el estado de guardado sin perder las etiquetas traducidas", async () => {
    let terminar!: (value: unknown) => void;
    api.put.mockReturnValue(
      new Promise((resolve) => {
        terminar = resolve;
      }),
    );
    const w = montar();
    await w.get("#mp-datos form").trigger("submit");
    const guardar = w.get('#mp-datos button[type="submit"]');
    expect(guardar.text()).toBe("Guardando…");
    expect(guardar.attributes("disabled")).toBeDefined();
    terminar({ data: { data: { usuario: sesion.usuario } } });
    await flushPromises();
    expect(guardar.text()).toBe("Guardar");
    w.unmount();
  });

  it("con ficha de cliente edita su celular junto a su nombre", async () => {
    Object.assign(sesion.usuario, { tiene_ficha: true, celular: "5511112222" });
    api.put.mockResolvedValue({ data: { data: { usuario: sesion.usuario } } });
    const w = montar();
    const celular = w.get('[data-prueba="celular"]');
    expect(celular.element).toHaveProperty("value", "5511112222");

    await celular.setValue("55 3333 4444");
    await w.findAll("form")[0].trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/yo/perfil",
      expect.objectContaining({ nombre: "Ana", celular: "55 3333 4444" }),
    );
    w.unmount();
  });

  it("con ficha anota su fecha de nacimiento y su género (opcionales)", async () => {
    Object.assign(sesion.usuario, {
      tiene_ficha: true,
      celular: null,
      fecha_nacimiento: "1994-03-14",
      genero: null,
    });
    api.put.mockResolvedValue({ data: { data: { usuario: sesion.usuario } } });
    const w = montar();
    expect(w.get('[data-prueba="fecha-nacimiento"]').element).toHaveProperty(
      "value",
      "1994-03-14",
    );
    const opciones = w
      .get('[data-prueba="genero"]')
      .findAll("option")
      .map((o) => o.text());
    expect(opciones).toEqual([
      "Sin especificar",
      "Mujer",
      "Hombre",
      "No binario",
      "Otro",
      "Prefiero no decirlo",
    ]);

    await w.get('[data-prueba="genero"]').setValue("no_binario");
    await w.findAll("form")[0].trigger("submit");
    await flushPromises();
    expect(api.put).toHaveBeenCalledWith(
      "/api/v1/app/demo/yo/perfil",
      expect.objectContaining({
        fecha_nacimiento: "1994-03-14",
        genero: "no_binario",
      }),
    );
    w.unmount();
  });

  it("sin ficha (personal) no hay celular que editar ni se manda", async () => {
    sesion.usuario.rol = "propietario";
    api.put.mockResolvedValue({ data: { data: { usuario: sesion.usuario } } });
    const w = montar();
    expect(w.find('[data-prueba="celular"]').exists()).toBe(false);

    await w.findAll("form")[0].trigger("submit");
    await flushPromises();
    expect(api.put.mock.calls[0][1]).not.toHaveProperty("celular");
    expect(w.find('[data-prueba="genero"]').exists()).toBe(false);
    expect(api.put.mock.calls[0][1]).not.toHaveProperty("genero");
    w.unmount();
  });

  it("reacciona al rol activo, incluyendo roles personalizados", async () => {
    const w = montar();
    sesion.usuario.rol = "propietario";
    await flushPromises();
    expect(w.find('[data-prueba="privacidad"]').exists()).toBe(false);
    sesion.usuario.roles_disponibles = [
      { clave: "cliente-vip", faceta: "miembro" },
    ];
    sesion.usuario.rol = "cliente-vip";
    await flushPromises();
    expect(w.find('[data-prueba="privacidad"]').exists()).toBe(true);
    w.unmount();
  });
});
