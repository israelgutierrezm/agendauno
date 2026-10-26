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
  vistasDeArea,
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
    conEquipo?: boolean;
    plataformas?: boolean;
    facturaPosible?: boolean;
    // Funciones que su plan no incluye (ADR 0107).
    planSin?: string[];
  } = {},
): Sesion {
  return {
    suspendido: extra.suspendido ?? false,
    conEquipo: extra.conEquipo ?? true,
    puede: (p: string) => permisos.includes("*") || permisos.includes(p),
    usuario: { rol: extra.rol ?? "admin" },
    modalidad: extra.modalidad ?? "clases",
    estudio: {
      perfil_config: { flags: { grupos: extra.grupos ?? false } },
      plataformas_bienestar: extra.plataformas ?? false,
      factura_posible: extra.facturaPosible ?? true,
      plan: { nivel: null, sin: extra.planSin ?? [] },
    },
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
  it("cuatro grupos: operación diaria, gestión, captación y configuración (ADR 0167)", () => {
    expect(MENU_NEGOCIO.map((g) => g.clave)).toEqual([
      "operacion",
      "gestion",
      "captacion",
      "configuracion",
    ]);
    expect(MENU_NEGOCIO.map((g) => g.areas.map((a) => a.clave))).toEqual([
      ["inicio", "agenda", "clientes", "cobros"],
      ["ventas", "equipo", "marketing", "reportes"],
      ["mensajes", "prospectos", "sitio", "campanas"],
      ["configuracion", "suscripcion"],
    ]);
  });

  it("el sitio web es una entrada directa de Captación, fuera de Configuración", () => {
    const captacion = MENU_NEGOCIO.find((g) => g.clave === "captacion")!;
    const sitio = captacion.areas.find((a) => a.clave === "sitio")!;
    expect(sitio.vistas.map((v) => v.ruta)).toEqual(["sitio-web"]);
    expect(CATEGORIAS_CONFIGURACION.map((c) => c.clave)).not.toContain("sitio");
    const configuracion = MENU_NEGOCIO.flatMap((g) => g.areas).find(
      (a) => a.clave === "configuracion",
    )!;
    expect(configuracion.vistas.map((v) => v.ruta)).not.toContain("sitio-web");
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
      "ventas",
      "equipo",
      "marketing",
      "reportes",
      "mensajes",
      "prospectos",
      "sitio",
      "campanas",
      "configuracion",
      "suscripcion",
    ]);
    expect(destino(s, "inicio")).toBe("panel");
    expect(destino(s, "configuracion")).toBe("ajustes");
  });

  it("Captación (prospectos) va aparte de Miembros y solo con su permiso", () => {
    const conProspectos = sesion(["miembros.ver", "prospectos.ver"]);
    const sinProspectos = sesion(["miembros.ver", "agenda.ver"]);
    expect(areas(conProspectos)).toContain("prospectos");
    expect(destino(conProspectos, "prospectos")).toBe("prospectos");
    // Quien atiende prospectos también comparte el sitio (enlace, QR y widgets).
    expect(areas(conProspectos)).toContain("sitio");
    expect(areas(sinProspectos)).not.toContain("prospectos");
    expect(areas(sinProspectos)).not.toContain("sitio");
    // Quien prepara el sitio entra aunque no atienda prospectos (ADR 0167).
    expect(areas(sesion(["sitio.editar"]))).toContain("sitio");
  });

  it("Campañas pide ver los números del negocio y el reporte avanzado del plan", () => {
    expect(areas(sesion(["facturacion.ver"]))).toContain("campanas");
    expect(areas(sesion(["prospectos.ver"]))).not.toContain("campanas");
  });

  it("las vistas respetan la modalidad: Horarios en citas, Lugares disponibles en clases", () => {
    const area = (clave: string) =>
      MENU_NEGOCIO.flatMap((g) => g.areas).find((a) => a.clave === clave)!;
    const clases = sesion(["*"], { modalidad: "clases" });
    const citas = sesion(["*"], { modalidad: "citas" });

    // La disponibilidad (horarios de atención) vive en Agenda.
    expect(
      vistasVisibles(area("agenda"), clases).map((x) => x.ruta),
    ).not.toContain("horarios");
    expect(vistasVisibles(area("agenda"), citas).map((x) => x.ruta)).toContain(
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

    // La agenda del negocio ya es su calendario: no se repite.
    expect(areas(instructor)).toEqual(["inicio-instructor", "mis-clases"]);
    expect(areas(alumno)).toEqual([
      "mi-cuenta",
      "mis-reservas",
      "mis-pagos",
      "mi-expediente",
      "mi-perfil",
    ]);
  });
});

describe("menú de quien imparte", () => {
  it("un solo grupo con lo suyo: sin dos «Inicio», sin Agenda, Equipo, Marketing ni Configuración", () => {
    // Los permisos del rol de instructor (CatalogoDePermisosTenant), en una barbería.
    const barbero = sesion(
      [
        "miembros.ver",
        "documentos.subir",
        "formularios.responder",
        "catalogo.ver",
        "sucursales.ver",
        "agenda.ver",
        "derechos.ver",
        "reservas.ver",
        "asistencia.marcar",
        "checkins.registrar",
        "tareas.ver",
        "tareas.gestionar",
      ],
      { rol: "instructor", modalidad: "citas" },
    );
    const grupos = menuVisible(barbero);

    expect(grupos.map((g) => g.clave)).toEqual(["instructor"]);
    expect(grupos[0].areas.map((a) => a.clave)).toEqual([
      "inicio-instructor",
      "mis-clases",
      // Lo que lleva ganado (ADR 0219).
      "mis-comisiones",
      "inicio",
      "clientes",
      "marketing",
    ]);
    const area = (clave: string) =>
      grupos[0].areas.find((a) => a.clave === clave)!;
    // Con el nombre de lo suyo.
    expect(area("inicio").etiqueta).toBe("tareas.tituloMias");
    expect(area("clientes").mio).toBe(true);
    expect(area("marketing").etiqueta).toBe("portal.instructor.nav.resenas");
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

  it("quien trabaja solo no ve la nómina; aparece en cuanto tiene equipo", () => {
    const equipo = MENU_NEGOCIO.flatMap((g) => g.areas).find(
      (a) => a.clave === "equipo",
    );
    expect(equipo).toBeDefined();
    const rutas = (s: Sesion) => vistasVisibles(equipo!, s).map((x) => x.ruta);

    expect(rutas(sesion(["*"], { conEquipo: false }))).not.toContain("nomina");
    expect(rutas(sesion(["*"], { conEquipo: true }))).toContain("nomina");
  });

  it("perfiles: quien trabaja solo ve agenda, clientes, cobros y su disponibilidad; con equipo, además personas, rendimiento y nómina", () => {
    const solo = sesion(["*"], { modalidad: "citas", conEquipo: false });
    const conEquipo = sesion(["*"], { modalidad: "citas", conEquipo: true });
    const vistas = (s: Sesion, area: string) =>
      vistasDeArea(
        MENU_NEGOCIO.flatMap((g) => g.areas).find((a) => a.clave === area)!,
        s,
      ).map((x) => x.clave);

    expect(areas(solo)).not.toContain("equipo");
    expect(areas(solo)).toEqual(
      expect.arrayContaining(["agenda", "clientes", "cobros"]),
    );
    expect(vistas(solo, "agenda")).toContain("disponibilidad");

    expect(areas(conEquipo)).toContain("equipo");
    expect(vistas(conEquipo, "equipo")).toEqual([
      "personas",
      "rendimiento",
      "nomina",
    ]);
    expect(vistas(conEquipo, "agenda")).toContain("disponibilidad");
    // Rendimiento abre el reporte del equipo y se marca en Equipo, no en Reportes.
    expect(
      ubicacion({ name: "reportes", query: { vista: "equipo" } })?.vista.clave,
    ).toBe("rendimiento");
    expect(ubicacion({ name: "reportes" })?.area.clave).toBe("reportes");
  });

  it("lo avanzado aparece según lo que el negocio tiene, no por ser dueño", () => {
    const vistas = (s: Sesion, area: string) =>
      vistasDeArea(
        MENU_NEGOCIO.flatMap((g) => g.areas).find((a) => a.clave === area)!,
        s,
      ).map((x) => x.clave);

    // Wellhub y TotalPass: solo si los recibe.
    expect(vistas(sesion(["*"]), "reportes")).not.toContain("plataformas");
    expect(vistas(sesion(["*"], { plataformas: true }), "reportes")).toContain(
      "plataformas",
    );
    // Reportes: cada reporte es una vista del área; los avanzados, si el plan los
    // incluye. Quien trabaja solo ve su agenda, no la del equipo.
    expect(vistas(sesion(["*"]), "reportes")).toEqual([
      "reportes",
      "ingresos",
      "ocupacion",
      "clientes",
      "embudo",
      "sucursales",
    ]);
    expect(
      vistas(
        sesion(["*"], { modalidad: "citas", planSin: ["reportes_avanzados"] }),
        "reportes",
      ),
    ).toEqual(["reportes", "sucursales"]);
    const reportes = MENU_NEGOCIO.flatMap((g) => g.areas).find(
      (a) => a.clave === "reportes",
    )!;
    const etiqueta = (s: Sesion) =>
      vistasDeArea(reportes, s).find((x) => x.clave === "sucursales")?.etiqueta;
    expect(etiqueta(sesion(["*"]))).toBe("operacion.reportes.pestanas.equipo");
    expect(etiqueta(sesion(["*"], { conEquipo: false }))).toBe(
      "operacion.reportes.pestanas.equipoSolo",
    );
    expect(
      ubicacion({ name: "reportes", query: { vista: "sucursales" } })?.vista
        .clave,
    ).toBe("sucursales");
    // Facturas: solo donde se puede facturar.
    expect(
      vistas(sesion(["*"], { facturaPosible: false }), "cobros"),
    ).not.toContain("facturas");
    expect(vistas(sesion(["*"]), "cobros")).toContain("facturas");
  });
});
