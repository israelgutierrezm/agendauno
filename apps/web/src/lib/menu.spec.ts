import { createPinia, setActivePinia } from "pinia";
import { describe, expect, it } from "vitest";

import router from "@/router";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { POLITICAS, puedeEntrar } from "./acceso";
import {
  CATEGORIAS_CONFIGURACION,
  MENU_NEGOCIO,
  menuVisible,
  rutasDelMenu,
  ubicacion,
  vistasVisibles,
} from "./menu";

type Sesion = Parameters<typeof puedeEntrar>[1];

/** Una sesión de prueba con estos permisos (en un negocio de clases o de citas). */
function sesion(
  permisos: string[],
  extra: {
    rol?: string;
    modalidad?: "clases" | "citas";
    suspendido?: boolean;
    grupos?: boolean;
  } = {},
): Sesion {
  return {
    suspendido: extra.suspendido ?? false,
    puede: (p: string) => permisos.includes("*") || permisos.includes(p),
    usuario: { rol: extra.rol ?? "admin" },
    modalidad: extra.modalidad ?? "clases",
    estudio: { perfil_config: { flags: { grupos: extra.grupos ?? false } } },
    terminologia: { sesion: "Clase", miembro: "Alumno", instructor: "Coach" },
  } as unknown as Sesion;
}

const areas = (s: Sesion) =>
  menuVisible(s).flatMap((g) => g.areas.map((a) => a.clave));
const destino = (s: Sesion, area: string) =>
  menuVisible(s)
    .flatMap((g) => g.areas)
    .find((a) => a.clave === area)?.destino.ruta;

// Los 38 destinos del panel del negocio (documento de reformulación, sección 11).
const DESTINOS_PANEL = [
  "panel",
  "tareas",
  "agenda",
  "recepcion",
  "oportunidades",
  "grupos",
  "miembros",
  "retencion",
  "documentos",
  "formularios",
  "instructores",
  "horarios",
  "nomina",
  "ventas",
  "pos",
  "inventario",
  "cobranza",
  "facturas",
  "comunicaciones",
  "promociones",
  "lealtad",
  "resenas",
  "reportes",
  "configuracion",
  "sedes",
  "datos-fiscales",
  "catalogo",
  "planes",
  "recursos",
  "reglas-agenda",
  "pasarelas",
  "integraciones",
  "usuarios",
  "roles",
  "bitacora",
  "privacidad",
  "renta",
  "padron",
];

describe("registro de acceso", () => {
  it("toda ruta privada del router tiene su regla, y toda regla es una ruta", () => {
    const privadas = router
      .getRoutes()
      .filter((r) => r.meta.requiereSesion === true)
      .map((r) => String(r.name));

    expect(privadas.filter((n) => POLITICAS[n] === undefined)).toEqual([]);
    const nombres = new Set(router.getRoutes().map((r) => String(r.name)));
    expect(Object.keys(POLITICAS).filter((n) => !nombres.has(n))).toEqual([]);
  });

  it("una pantalla sin regla no se abre, aunque se tengan todos los permisos", () => {
    expect(puedeEntrar("pantalla-inventada", sesion(["*"]))).toBe(false);
  });

  it("varios permisos significan todos, no cualquiera", () => {
    expect(puedeEntrar("nomina", sesion(["estudio.gestionar"]))).toBe(false);
    expect(
      puedeEntrar(
        "nomina",
        sesion(["estudio.gestionar", "usuarios.gestionar"]),
      ),
    ).toBe(true);
  });

  it("suspendido por renta, solo se entra a la renta para pagarla", () => {
    const s = sesion(["*"], { rol: "propietario", suspendido: true });

    expect(areas(s)).toEqual(["suscripcion"]);
    expect(destino(s, "suscripcion")).toBe("renta");
    expect(puedeEntrar("agenda", s)).toBe(false);
    expect(puedeEntrar("ficha-miembro", s)).toBe(false);
  });

  it("el inicio de cada quien siempre es una pantalla a la que puede entrar", () => {
    setActivePinia(createPinia());
    const store = useSesionTenantStore();
    const casos: [string, string[]][] = [
      ["propietario", ["*"]],
      ["recepcionista", ["reservas.gestionar", "agenda.ver"]],
      // Un rol que se llama recepcionista pero sin permiso de recepción.
      ["recepcionista", ["agenda.ver"]],
      ["instructor", ["agenda.ver"]],
      ["miembro", ["formularios.responder"]],
      ["sin-nada", []],
    ];
    for (const [rol, permisos] of casos) {
      store.usuario = { rol, permisos } as never;
      expect(puedeEntrar(store.rutaInicio, store), `${rol}`).toBe(true);
    }
  });
});

describe("menú del negocio", () => {
  it("tres grupos: operación diaria, gestión y configuración", () => {
    expect(MENU_NEGOCIO.map((g) => g.clave)).toEqual([
      "operacion",
      "gestion",
      "configuracion",
    ]);
    expect(MENU_NEGOCIO.map((g) => g.areas.map((a) => a.clave))).toEqual([
      ["inicio", "agenda", "clientes", "cobros", "equipo"],
      ["ventas", "marketing", "reportes"],
      ["configuracion", "suscripcion"],
    ]);
  });

  it("ninguna pantalla se perdió: los 38 destinos del panel siguen en el menú", () => {
    const rutas = rutasDelMenu();
    expect(DESTINOS_PANEL.filter((r) => !rutas.includes(r))).toEqual([]);
  });

  it("el dueño ve todas las áreas; cada área abre su primera vista", () => {
    const s = sesion(["*"], { rol: "propietario", grupos: true });

    expect(areas(s)).toEqual([
      "inicio",
      "agenda",
      "clientes",
      "cobros",
      "equipo",
      "ventas",
      "marketing",
      "reportes",
      "configuracion",
      "suscripcion",
    ]);
    expect(destino(s, "inicio")).toBe("panel");
    expect(destino(s, "configuracion")).toBe("ajustes");
  });

  it("las vistas respetan la modalidad: Horarios en citas, Lugares disponibles en clases", () => {
    const area = (clave: string) =>
      MENU_NEGOCIO.flatMap((g) => g.areas).find((a) => a.clave === clave)!;
    const clases = sesion(["*"], { modalidad: "clases" });
    const citas = sesion(["*"], { modalidad: "citas" });

    expect(
      vistasVisibles(area("equipo"), clases).map((x) => x.ruta),
    ).not.toContain("horarios");
    expect(vistasVisibles(area("equipo"), citas).map((x) => x.ruta)).toContain(
      "horarios",
    );
    expect(
      vistasVisibles(area("agenda"), citas).map((x) => x.ruta),
    ).not.toContain("oportunidades");
    expect(
      vistasVisibles(area("suscripcion"), citas).map((x) => x.ruta),
    ).toEqual(["renta"]);
  });

  it("un rol solo de facturas llega a Cobros › Facturas sin pasar por Cobranza", () => {
    const s = sesion(["ordenes.ver"]);

    expect(areas(s)).toEqual(["cobros"]);
    expect(destino(s, "cobros")).toBe("facturas");
    expect(puedeEntrar("cobranza", s)).toBe(false);
  });

  it("un rol solo de tareas entra a Inicio › Tareas sin el resumen financiero", () => {
    const s = sesion(["tareas.ver"]);

    expect(areas(s)).toEqual(["inicio"]);
    expect(destino(s, "inicio")).toBe("tareas");
    expect(puedeEntrar("panel", s)).toBe(false);
  });

  it("un rol solo de integraciones llega a Configuración sin permiso del negocio", () => {
    const s = sesion(["integraciones.configurar"]);

    expect(areas(s)).toEqual(["configuracion"]);
    expect(puedeEntrar("ajustes", s)).toBe(true);
    expect(puedeEntrar("configuracion", s)).toBe(false);
    const visibles = CATEGORIAS_CONFIGURACION.filter(
      (c) => vistasVisibles({ ...c, vistas: c.opciones }, s).length > 0,
    ).map((c) => c.clave);
    expect(visibles).toEqual(["pagos"]);
  });

  it("recepción ve agenda y atención, no roles, pasarelas ni nómina", () => {
    const s = sesion(["agenda.ver", "reservas.gestionar", "miembros.ver"], {
      rol: "recepcionista",
    });

    expect(areas(s)).toEqual(["agenda", "clientes", "marketing"]);
    for (const r of ["roles", "pasarelas", "nomina", "ajustes"]) {
      expect(puedeEntrar(r, s)).toBe(false);
    }
  });

  it("el instructor y el alumno tienen su portal, no el panel del dueño", () => {
    const instructor = sesion(["agenda.ver"], { rol: "instructor" });
    const alumno = sesion(["formularios.responder"], { rol: "miembro" });

    expect(areas(instructor)).toEqual([
      "inicio-instructor",
      "mis-clases",
      "agenda",
    ]);
    expect(areas(alumno)).toEqual([
      "mi-cuenta",
      "mis-reservas",
      "mis-pagos",
      "mi-expediente",
      "mi-perfil",
    ]);
  });
});

describe("ubicación de la pantalla actual", () => {
  it("Mi perfil sustituye el puente de configuración y el enlace anterior redirige", () => {
    expect(destino(sesion([], { rol: "miembro" }), "mi-perfil")).toBe(
      "mi-perfil",
    );
    expect(areas(sesion([], { rol: "miembro" }))).not.toContain(
      "mi-configuracion",
    );
    expect(areas(sesion(["*"], { rol: "propietario" }))).not.toContain(
      "mi-perfil",
    );
    expect(areas(sesion(["agenda.ver"], { rol: "instructor" }))).not.toContain(
      "mi-perfil",
    );
    expect(ubicacion({ name: "mi-perfil" })?.area.clave).toBe("mi-perfil");
    expect(
      router.getRoutes().find((r) => r.name === "mi-configuracion")?.redirect,
    ).toEqual({ name: "mi-perfil" });
  });
  it("una ficha deja activa su área; una vista por `vista` o por ancla, la suya", () => {
    const u = (name: string, query = {}, hash = "") =>
      ubicacion({ name, query, hash });

    expect(u("ficha-miembro")?.area.clave).toBe("clientes");
    expect(u("ficha-instructor")?.area.clave).toBe("equipo");
    expect(u("documentos")?.vista.clave).toBe("expedientes");
    expect(u("documentos", { vista: "requisitos" })?.categoria?.clave).toBe(
      "documentos",
    );
    expect(u("cobranza", { vista: "caja" })?.vista.clave).toBe("caja");
    expect(u("cobranza")?.vista.clave).toBe("por-cobrar");
    expect(u("configuracion", {}, "#terminologia")?.vista.clave).toBe(
      "terminologia",
    );
    expect(u("configuracion")?.vista.clave).toBe("datos");
    expect(u("reglas-agenda", {}, "#cierres")?.categoria?.clave).toBe("agenda");
    expect(u("ajustes")?.vista.clave).toBe("portada");
  });
});
