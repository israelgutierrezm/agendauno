import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

import esMX from "@/i18n/locales/es-MX";
import { confirmar } from "@/lib/confirmar";
import operacion from "@/i18n/locales/operacion.es-MX";
import RolesView from "./RolesView.vue";

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}));
vi.mock("@/lib/api", () => ({
  api,
  mensajeDeError: (e: unknown) => String(e),
}));
vi.mock("@/stores/sesionTenant", () => ({
  useSesionTenantStore: () => ({ slug: "demo" }),
}));
const toast = vi.hoisted(() => ({ exito: vi.fn(), error: vi.fn() }));
vi.mock("@/stores/toast", () => ({ useToastStore: () => toast }));
vi.mock("@/lib/confirmar", () => ({ confirmar: vi.fn(async () => true) }));

const respuesta = {
  data: [
    {
      id: null,
      clave: "propietario",
      nombre: null,
      sistema: true,
      permisos: ["*"],
      personas: 1,
      puede_cambiar: false,
    },
    {
      id: "01ROL",
      clave: "rol_apoyo",
      nombre: "Apoyo",
      sistema: false,
      permisos: ["miembros.ver"],
      personas: 0,
      puede_cambiar: true,
    },
    {
      id: "01GER",
      clave: "rol_gerencia",
      nombre: "Gerencia",
      sistema: false,
      permisos: ["miembros.ver", "pagos.configurar"],
      personas: 2,
      puede_cambiar: false,
    },
  ],
  catalogo: {
    clientes: ["miembros.ver", "miembros.gestionar"],
    negocio: ["pagos.configurar"],
  },
  requisitos: { "miembros.gestionar": ["miembros.ver"] },
  // Quien arma el rol: ve alumnos y administra roles, pero no configura pagos.
  mis_permisos: ["miembros.ver", "miembros.gestionar", "roles.gestionar"],
};

const i18n = createI18n({
  legacy: false,
  locale: "es-MX",
  messages: { "es-MX": { ...esMX, operacion } },
});

/** El catálogo tal como lo expone el API (CatalogoDePermisosTenant::catalogo()). */
function catalogoDelApi(): Record<string, string[]> {
  const php = readFileSync(
    resolve(
      __dirname,
      "../../../api/app/Modules/Tenancy/Application/CatalogoDePermisosTenant.php",
    ),
    "utf8",
  );
  const cuerpo =
    php.split("public static function catalogo(): array")[1]?.split("];")[0] ??
    "";
  const grupos: Record<string, string[]> = {};
  for (const m of cuerpo.matchAll(/'([a-z_]+)' => \[([^\]]*)\]/g)) {
    grupos[m[1]] = [...m[2].matchAll(/'([a-z_]+\.[a-z_]+)'/g)].map((p) => p[1]);
  }
  return grupos;
}

function montar() {
  return mount(RolesView, {
    global: {
      plugins: [i18n],
      stubs: {
        PanelLateral: {
          props: ["abierto", "titulo"],
          template:
            '<div v-if="abierto" data-prueba="editor"><slot /><slot name="pie" /></div>',
        },
      },
    },
  });
}

describe("roles y permisos", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ data: respuesta });
    api.post.mockResolvedValue({ data: {} });
  });

  it("eliminar un rol propio se confirma como acción destructiva", async () => {
    api.delete.mockResolvedValue({ data: {} });
    const w = montar();
    await flushPromises();

    const boton = w.get('[data-prueba="eliminar-rol"]');
    expect(boton.classes()).toEqual(
      expect.arrayContaining(["tu-btn-contorno", "tu-tono-peligro"]),
    );
    await boton.trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalledWith(
      expect.any(String),
      expect.objectContaining({ peligro: true }),
    );
    expect(api.delete).toHaveBeenCalledWith("/api/v1/app/demo/roles/01ROL");
  });

  it("separa los roles propios de los del sistema y solo deja cambiar los que caben en lo suyo", async () => {
    const w = montar();
    await flushPromises();

    const propios = w.findAll('[data-prueba="rol-propio"]');
    expect(propios.map((r) => r.find(".rp-nombre").text())).toEqual([
      "Apoyo",
      "Gerencia",
    ]);
    expect(propios[0].text()).toContain("Editar");
    // Gerencia configura pagos: quien arma no lo tiene, no puede cambiarla.
    expect(propios[1].text()).not.toContain("Editar");
    expect(propios[1].text()).toContain("no puedes cambiarlo");
    // Los del sistema no se editan.
    const sistema = w.findAll('[data-prueba="rol-sistema"]');
    expect(sistema[0].find(".rp-nombre").text()).toBe("Dueño");
    expect(sistema[0].text()).toContain("Todos los permisos");
    expect(sistema[0].text()).not.toContain("Editar");
  });

  it("al armar un rol, lo que no tiene aparece deshabilitado y guarda lo elegido", async () => {
    const w = montar();
    await flushPromises();
    await w.find(".tu-btn-primario").trigger("click");

    const editor = w.find('[data-prueba="editor"]');
    await editor.find("#rol-nombre").setValue("Recepción de tarde");
    const opciones = editor.findAll("label.rp-opcion");
    expect(opciones.map((o) => o.text())).toEqual([
      "Ver alumnos y su ficha",
      "Dar de alta y editar alumnos",
      "Configurar pasarelas de pago",
    ]);
    expect(opciones[2].find("input").attributes("disabled")).toBeDefined();

    await opciones[0].find("input").trigger("change");
    await editor.find(".tu-btn-primario").trigger("click");
    await flushPromises();

    expect(api.post).toHaveBeenCalledWith("/api/v1/app/demo/roles", {
      nombre: "Recepción de tarde",
      permisos: ["miembros.ver"],
      faceta: "equipo",
    });
    expect(toast.exito).toHaveBeenCalled();
  });

  it("un permiso sin lo que necesita no se guarda; se agrega a la vista, no solo", async () => {
    const w = montar();
    await flushPromises();
    await w.find(".tu-btn-primario").trigger("click");
    const editor = w.find('[data-prueba="editor"]');
    await editor.find("#rol-nombre").setValue("Altas");

    // Dar de alta alumnos sin poder verlos: se explica y no deja guardar.
    await editor.findAll("label.rp-opcion")[1].find("input").trigger("change");
    const aviso = editor.find('[data-prueba="necesita-miembros.gestionar"]');
    expect(aviso.text()).toContain("Ver alumnos y su ficha");
    const guardar = () =>
      w.findAll(".tu-btn-primario").at(-1)!.attributes("disabled");
    expect(guardar()).toBeDefined();
    expect(
      (
        editor.findAll("label.rp-opcion")[0].find("input")
          .element as HTMLInputElement
      ).checked,
    ).toBe(false);

    await aviso.find("button").trigger("click");
    expect(
      (
        editor.findAll("label.rp-opcion")[0].find("input")
          .element as HTMLInputElement
      ).checked,
    ).toBe(true);
    expect(guardar()).toBeUndefined();
  });

  it("un rol de quien imparte pide ver la agenda y guarda su faceta", async () => {
    const w = montar();
    await flushPromises();
    await w.find(".tu-btn-primario").trigger("click");
    const editor = w.find('[data-prueba="editor"]');
    await editor.find("#rol-nombre").setValue("Instructor en formación");
    await editor.find('[data-prueba="faceta-instructor"]').setValue(true);
    await editor.findAll("label.rp-opcion")[0].find("input").trigger("change");

    // Sin «Ver la agenda» no ve sus clases: se explica y no deja guardar.
    expect(editor.find('[data-prueba="necesita-agenda"]').exists()).toBe(true);
    expect(
      w.findAll(".tu-btn-primario").at(-1)!.attributes("disabled"),
    ).toBeDefined();
  });

  it("busca permisos por su nombre o el de su área, sin perder lo elegido", async () => {
    const w = montar();
    await flushPromises();
    await w.find(".tu-btn-primario").trigger("click");
    const editor = w.find('[data-prueba="editor"]');
    const opciones = () =>
      editor.findAll("label.rp-opcion").map((o) => o.text());
    await editor.findAll("label.rp-opcion")[0].find("input").trigger("change");

    const buscar = editor.find('[data-prueba="buscar-permiso"]');
    await buscar.setValue("pasarelas");
    expect(opciones()).toEqual(["Configurar pasarelas de pago"]);
    // El nombre del área también cuenta: «Alumnos» trae los de su área.
    await buscar.setValue("ALUMNOS");
    expect(opciones()).toEqual([
      "Ver alumnos y su ficha",
      "Dar de alta y editar alumnos",
    ]);

    await buscar.setValue("zzz");
    expect(opciones()).toEqual([]);
    const sin = editor.find('[data-prueba="sin-permisos"]');
    expect(sin.text()).toContain("Ningún permiso coincide con «zzz».");
    await sin.find("button").trigger("click");
    expect(opciones()).toHaveLength(3);
    expect(
      (
        editor.findAll("label.rp-opcion")[0].find("input")
          .element as HTMLInputElement
      ).checked,
    ).toBe(true);
  });

  it("dice en palabras a qué da acceso cada rol y lo elegido en el editor", async () => {
    const w = montar();
    await flushPromises();

    const propios = w.findAll('[data-prueba="rol-propio"]');
    expect(propios[0].get('[data-prueba="resumen-acceso"]').text()).toBe(
      "Acceso a Alumnos (solo consulta).",
    );
    expect(propios[1].get('[data-prueba="resumen-acceso"]').text()).toBe(
      "Acceso a Alumnos (solo consulta) y Negocio.",
    );
    // El dueño tiene todo: basta «Todos los permisos».
    const dueno = w.findAll('[data-prueba="rol-sistema"]')[0];
    expect(dueno.find('[data-prueba="resumen-acceso"]').exists()).toBe(false);

    // Al ver los permisos, cada área dice su nivel.
    await propios[1].findAll("button")[0].trigger("click");
    expect(propios[1].find(".rp-permisos").text()).toContain("Solo consulta");
    expect(propios[1].find(".rp-permisos").text()).toContain("Acceso completo");

    await w.find(".tu-btn-primario").trigger("click");
    const editor = w.find('[data-prueba="editor"]');
    const elegido = () => editor.get('[data-prueba="acceso-elegido"]').text();
    expect(elegido()).toContain("Todavía no da acceso a ninguna área.");
    await editor.findAll("label.rp-opcion")[0].find("input").trigger("change");
    await editor.findAll("label.rp-opcion")[1].find("input").trigger("change");
    expect(elegido()).toContain("Acceso a Alumnos.");
    expect(
      editor.get('[data-prueba="grupo-clientes"] legend').text(),
    ).toContain("Acceso completo");
  });

  it("ningún permiso ni área del catálogo del API se muestra con su llave", async () => {
    const catalogo = catalogoDelApi();
    const permisos = Object.values(catalogo).flat();
    // La lectura del archivo del API no se rompió.
    expect(Object.keys(catalogo)).toContain("captacion");
    expect(permisos).toContain("prospectos.ver");

    const sinTexto = [
      ...Object.keys(catalogo)
        .map((g) => `operacion.rolesPropios.grupos.${g}`)
        .filter((llave) => !i18n.global.te(llave)),
      ...permisos
        .map((p) => `operacion.rolesPropios.permiso.${p}`)
        .filter((llave) => !i18n.global.te(llave)),
    ];
    expect(sinTexto).toEqual([]);

    api.get.mockResolvedValue({
      data: { ...respuesta, catalogo, requisitos: {}, mis_permisos: ["*"] },
    });
    const w = montar();
    await flushPromises();
    await w.find(".tu-btn-primario").trigger("click");
    const editor = w.find('[data-prueba="editor"]');
    const textos = [
      ...editor.findAll("label.rp-opcion").map((o) => o.text()),
      ...editor
        .findAll("legend.rp-grupo-fila > span:first-child")
        .map((l) => l.text()),
    ];
    expect(textos).toHaveLength(permisos.length + Object.keys(catalogo).length);
    expect(textos.filter((x) => /^[a-z_]+(\.[a-z_]+)?$/.test(x))).toEqual([]);
    expect(textos).toContain("Prospectos");
    expect(textos).toContain("Ver prospectos");
  });
});
