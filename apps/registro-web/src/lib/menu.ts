import type { MenuItem } from "@/components/nav";
import type { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Menú lateral del panel y reglas de acceso por pantalla. Lo usan el menú (qué se
 * muestra) y el router (a qué se puede entrar escribiendo la URL): una sola fuente.
 */
type Sesion = ReturnType<typeof useSesionTenantStore>;

// Menu lateral en ARBOL (3 niveles): grupos por area -> secciones -> sub-secciones.
export const MENU: MenuItem[] = [
  {
    clave: "mi-cuenta",
    etiqueta: "nav.miCuenta",
    icono: "mi-cuenta",
    ruta: "mi-cuenta",
    soloMiembro: true,
  },
  {
    clave: "panel",
    etiqueta: "nav.panel",
    icono: "panel",
    ruta: "panel",
    permiso: "facturacion.ver",
  },
  {
    clave: "personas",
    etiqueta: "nav.grupos.personas",
    icono: "personas",
    hijos: [
      {
        clave: "miembros",
        etiqueta: "nav.miembros",
        icono: "miembros",
        ruta: "miembros",
        permiso: "miembros.ver",
        termino: "miembro",
      },
      {
        // Seguimiento comercial (quién está por vencer): no es del instructor.
        clave: "retencion",
        etiqueta: "nav.retencion",
        icono: "pulso",
        ruta: "retencion",
        permiso: "miembros.gestionar",
      },
      {
        clave: "comunicaciones",
        etiqueta: "nav.comunicaciones",
        icono: "mensaje",
        ruta: "comunicaciones",
        permiso: "comunicaciones.gestionar",
      },
      {
        clave: "instructores",
        etiqueta: "nav.instructores",
        icono: "instructores",
        ruta: "instructores",
        permiso: "agenda.gestionar",
        termino: "instructor",
      },
      {
        clave: "nomina",
        etiqueta: "nav.nomina",
        icono: "nomina",
        ruta: "nomina",
        permiso: ["estudio.gestionar", "usuarios.gestionar"],
      },
      {
        clave: "usuarios",
        etiqueta: "nav.usuarios",
        icono: "usuarios",
        ruta: "usuarios",
        permiso: "usuarios.gestionar",
      },
    ],
  },
  {
    clave: "operacion",
    etiqueta: "nav.grupos.operacion",
    icono: "operacion",
    hijos: [
      {
        clave: "agenda",
        etiqueta: "nav.agenda",
        icono: "agenda",
        ruta: "agenda",
        permiso: "agenda.ver",
      },
      {
        // Mostrador del día (llegadas, ventas, lista de espera): para quien atiende.
        clave: "recepcion",
        etiqueta: "nav.recepcion",
        icono: "recepcion",
        ruta: "recepcion",
        permiso: "reservas.gestionar",
      },
      {
        // Horarios de atención de cada profesional: definen los huecos para citas.
        clave: "horarios",
        etiqueta: "nav.horarios",
        icono: "reloj",
        ruta: "horarios",
        permiso: "agenda.ver",
        modalidad: "citas",
      },
      {
        clave: "oportunidades",
        etiqueta: "nav.oportunidades",
        icono: "oportunidades",
        ruta: "oportunidades",
        permiso: "reservas.gestionar",
        // Llenar lugares libres de una clase: no aplica a citas 1 a 1.
        modalidad: "clases",
      },
      {
        clave: "grupos",
        etiqueta: "nav.cursos",
        icono: "grupos",
        ruta: "grupos",
        permiso: "agenda.ver",
        flag: "grupos",
      },
      {
        clave: "tareas",
        etiqueta: "nav.tareas",
        icono: "tareas",
        ruta: "tareas",
        permiso: "tareas.ver",
      },
      {
        // Salas y equipo: se configuran, no se operan.
        clave: "recursos",
        etiqueta: "nav.recursos",
        icono: "recursos",
        ruta: "recursos",
        permiso: "agenda.gestionar",
      },
    ],
  },
  {
    clave: "comercio",
    etiqueta: "nav.grupos.comercio",
    icono: "comercio",
    hijos: [
      {
        clave: "ventas",
        etiqueta: "nav.ventas",
        icono: "ventas",
        ruta: "ventas",
        permiso: "productos.ver",
      },
      {
        clave: "pos",
        etiqueta: "nav.pos",
        icono: "pos",
        ruta: "pos",
        permiso: "inventario.ver",
      },
      {
        // Cómo se reservan las clases/servicios y a qué precio: configuración.
        clave: "catalogo",
        etiqueta: "nav.catalogo",
        icono: "etiqueta",
        ruta: "catalogo",
        permiso: "catalogo.gestionar",
      },
      {
        clave: "promociones",
        etiqueta: "nav.promociones",
        icono: "promociones",
        ruta: "promociones",
        permiso: "promociones.gestionar",
      },
      {
        clave: "lealtad",
        etiqueta: "nav.lealtad",
        icono: "lealtad",
        ruta: "lealtad",
        permiso: "lealtad.ver",
      },
      {
        clave: "facturas",
        etiqueta: "nav.facturas",
        icono: "facturas",
        ruta: "facturas",
        permiso: "ordenes.ver",
      },
      {
        clave: "cobranza",
        etiqueta: "nav.cobranza",
        icono: "facturas",
        ruta: "cobranza",
        permiso: "facturacion.ver",
      },
      {
        clave: "reportes",
        etiqueta: "nav.reportes",
        icono: "reportes",
        ruta: "reportes",
        permiso: "facturacion.ver",
      },
    ],
  },
  {
    clave: "contenido",
    etiqueta: "nav.grupos.contenido",
    icono: "contenido",
    hijos: [
      {
        clave: "documentos",
        etiqueta: "nav.documentos",
        icono: "documentos",
        ruta: "documentos",
        permiso: "documentos.subir",
      },
      {
        // Se llenan por persona: además hace falta ver miembros (un alumno no).
        clave: "formularios",
        etiqueta: "nav.formularios",
        icono: "formularios",
        ruta: "formularios",
        permiso: "formularios.gestionar",
      },
    ],
  },
  {
    clave: "ajustes",
    etiqueta: "nav.grupos.ajustes",
    icono: "ajustes",
    hijos: [
      {
        clave: "configuracion",
        etiqueta: "nav.configuracion",
        icono: "configuracion",
        ruta: "configuracion",
        permiso: "estudio.gestionar",
      },
      {
        clave: "reglas-agenda",
        etiqueta: "reglasAgenda.titulo",
        icono: "agenda",
        ruta: "reglas-agenda",
        permiso: "agenda.gestionar",
      },
      {
        clave: "sedes",
        etiqueta: "nav.sedes",
        icono: "ubicacion",
        ruta: "sedes",
        permiso: "sucursales.gestionar",
      },
      {
        clave: "datos-fiscales",
        etiqueta: "nav.datosFiscales",
        icono: "datosFiscales",
        ruta: "datos-fiscales",
        permiso: "estudio.gestionar",
      },
      {
        clave: "pasarelas",
        etiqueta: "nav.pasarelas",
        icono: "pasarelas",
        ruta: "pasarelas",
        permiso: "pagos.configurar",
      },
      {
        clave: "bitacora",
        etiqueta: "bitacora.titulo",
        icono: "lista",
        ruta: "bitacora",
        permiso: "auditoria.ver",
      },
      {
        clave: "integraciones",
        etiqueta: "nav.integraciones",
        icono: "integraciones",
        ruta: "integraciones",
        permiso: "integraciones.configurar",
      },
    ],
  },
  // Pagos del SaaS (lo que el dueño le paga a AgendaUno): separado de la operación/venta del estudio.
  {
    clave: "suscripcion",
    etiqueta: "nav.grupos.suscripcion",
    icono: "renta",
    hijos: [
      {
        clave: "renta",
        etiqueta: "nav.renta",
        icono: "renta",
        ruta: "renta",
        permiso: "facturacion.ver",
      },
      {
        clave: "padron",
        etiqueta: "nav.padron",
        icono: "facturas",
        ruta: "padron",
        permiso: "facturacion.ver",
        // En citas se cobra por profesional (ver Renta → quién cuenta).
        modalidad: "clases",
      },
    ],
  },
];

/**
 * Pantallas que no están en el menú pero piden permiso (fichas, importaciones…).
 * Las que no aparecen aquí ni en el menú solo piden sesión.
 */
const PERMISOS_FUERA_DEL_MENU: Record<string, string> = {
  "ficha-miembro": "miembros.ver",
  "ficha-instructor": "usuarios.gestionar",
  importar: "miembros.gestionar",
  "importar-instructores": "usuarios.invitar",
  onboarding: "estudio.gestionar",
};

/** Título de la barra superior para pantallas que no están en el menú. */
export const TITULOS_FUERA_DEL_MENU: Record<string, string> = {
  "mi-perfil": "miPerfil.titulo",
  onboarding: "onboarding.titulo",
  importar: "importar.titulo",
  "importar-instructores": "importarInstructores.titulo",
};

export function hojas(items: MenuItem[]): MenuItem[] {
  return items.flatMap((i) => (i.hijos !== undefined ? hojas(i.hijos) : [i]));
}

/** Quien tiene el rol de alumno (aunque su rol principal sea otro). */
export function esAlumno(sesion: Sesion): boolean {
  const u = sesion.usuario;
  return (
    u !== null && (u.rol === "miembro" || (u.roles ?? []).includes("miembro"))
  );
}

export function esVisible(item: MenuItem, sesion: Sesion): boolean {
  if (item.soloMiembro === true) {
    return esAlumno(sesion);
  }
  // Congruencia por modalidad y perfil: solo lo que le sirve a este negocio.
  if (item.modalidad !== undefined && item.modalidad !== sesion.modalidad) {
    return false;
  }
  if (
    item.flag !== undefined &&
    sesion.estudio?.perfil_config?.flags[item.flag] !== true
  ) {
    return false;
  }
  if (item.permiso === undefined) {
    return true;
  }
  const permisos = Array.isArray(item.permiso) ? item.permiso : [item.permiso];
  return permisos.every((p) => sesion.puede(p));
}

/**
 * ¿Puede entrar a esta pantalla? Las del menú siguen su misma regla de
 * visibilidad; las demás, su permiso (si piden uno).
 */
export function puedeEntrar(nombreRuta: string, sesion: Sesion): boolean {
  const hoja = hojas(MENU).find((h) => h.ruta === nombreRuta);
  if (hoja !== undefined) {
    return esVisible(hoja, sesion);
  }
  const permiso = PERMISOS_FUERA_DEL_MENU[nombreRuta];
  return permiso === undefined || sesion.puede(permiso);
}
