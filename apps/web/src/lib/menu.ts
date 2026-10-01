import type { MenuItem } from "@/components/nav";
import { esInstructor, esMiembro } from "@/lib/roles";
import type { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Menú lateral del panel y reglas de acceso por pantalla. Lo usan el menú (qué se
 * muestra) y el router (a qué se puede entrar escribiendo la URL): una sola fuente.
 */
type Sesion = ReturnType<typeof useSesionTenantStore>;

// Menu lateral en ARBOL (3 niveles): grupos por area -> secciones -> sub-secciones.
// Un grupo al que solo le queda una opción visible se muestra como esa opción.
export const MENU: MenuItem[] = [
  {
    // Portal del alumno o cliente (su cuenta en este negocio), una pantalla por tema.
    clave: "mi-cuenta-grupo",
    etiqueta: "nav.miCuenta",
    icono: "mi-cuenta",
    soloMiembro: true,
    hijos: [
      {
        clave: "mi-cuenta",
        etiqueta: "portal.nav.inicio",
        icono: "panel",
        ruta: "mi-cuenta",
        soloMiembro: true,
      },
      {
        clave: "mis-reservas",
        etiqueta: "portal.nav.reservas",
        icono: "agenda",
        ruta: "mis-reservas",
        soloMiembro: true,
      },
      {
        clave: "mis-pagos",
        etiqueta: "portal.nav.pagos",
        icono: "ventas",
        ruta: "mis-pagos",
        soloMiembro: true,
      },
      {
        clave: "mi-expediente",
        etiqueta: "portal.nav.expediente",
        icono: "expediente",
        ruta: "mi-expediente",
        soloMiembro: true,
      },
      {
        clave: "mi-configuracion",
        etiqueta: "portal.nav.configuracion",
        icono: "configuracion",
        ruta: "mi-configuracion",
        soloMiembro: true,
      },
    ],
  },
  {
    // Portal de quien imparte: su Inicio y su calendario (solo lo suyo).
    clave: "instructor-grupo",
    etiqueta: "portal.instructor.nav.grupo",
    icono: "agenda",
    soloInstructor: true,
    hijos: [
      {
        clave: "inicio-instructor",
        etiqueta: "portal.instructor.nav.inicio",
        icono: "panel",
        ruta: "inicio-instructor",
        soloInstructor: true,
      },
      {
        clave: "mis-clases",
        etiqueta: "portal.instructor.nav.calendario",
        icono: "cuadricula",
        ruta: "mis-clases",
        soloInstructor: true,
      },
    ],
  },
  {
    clave: "panel",
    etiqueta: "nav.panel",
    icono: "panel",
    ruta: "panel",
    permiso: "facturacion.ver",
  },
  {
    // El día a día: la agenda y el mostrador, a un clic.
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
        // La pantalla lista a los alumnos de cada grupo.
        permiso: ["agenda.ver", "miembros.ver"],
        flag: "grupos",
      },
      {
        clave: "tareas",
        etiqueta: "nav.tareas",
        icono: "tareas",
        ruta: "tareas",
        permiso: "tareas.ver",
      },
    ],
  },
  {
    // Sus alumnos o clientes (el grupo lleva el término del negocio) y lo que se
    // lleva de cada uno: renovaciones, documentos y formularios.
    clave: "clientes",
    etiqueta: "nav.miembros",
    termino: "miembro",
    icono: "personas",
    hijos: [
      {
        clave: "miembros",
        etiqueta: "operacion.menu.directorio",
        icono: "miembros",
        ruta: "miembros",
        permiso: "miembros.ver",
        terminoSuelto: "miembro",
      },
      {
        // Lo que hoy se revisa son renovaciones (membresías por vencer o vencidas);
        // "retención" prometería más (inasistencia, frecuencia…).
        clave: "retencion",
        etiqueta: "operacion.renovaciones.titulo",
        icono: "pulso",
        ruta: "retencion",
        permiso: "miembros.gestionar",
      },
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
    // Todo lo que entra de dinero: lo que se vende para reservar (ADR 0050), el
    // mostrador y la cobranza, cada uno en su subgrupo.
    clave: "ventas-grupo",
    etiqueta: "nav.grupos.ventas",
    icono: "ventas",
    hijos: [
      {
        clave: "membresias",
        etiqueta: "planes.nav.membresias",
        icono: "etiqueta",
        hijos: [
          {
            clave: "planes",
            etiqueta: "planes.nav.planes",
            icono: "etiqueta",
            ruta: "planes",
            permiso: "productos.ver",
          },
          {
            clave: "ventas",
            etiqueta: "planes.nav.vender",
            icono: "ventas",
            ruta: "ventas",
            // Vende a un alumno y muestra las órdenes: sin eso la pantalla queda a medias.
            permiso: ["productos.ver", "ordenes.ver", "miembros.ver"],
          },
        ],
      },
      // Artículos del mostrador (agua, ropa…): nada que ver con la agenda.
      {
        clave: "punto-venta",
        etiqueta: "planes.nav.puntoVenta",
        icono: "pos",
        hijos: [
          {
            clave: "pos",
            etiqueta: "planes.nav.mostrador",
            icono: "pos",
            ruta: "pos",
            // El mostrador es para cobrar.
            permiso: ["inventario.ver", "pos.vender"],
          },
          {
            clave: "inventario",
            etiqueta: "planes.nav.inventario",
            icono: "lista",
            ruta: "inventario",
            permiso: "inventario.gestionar",
          },
        ],
      },
      {
        clave: "cobros",
        etiqueta: "planes.nav.cobros",
        icono: "facturas",
        hijos: [
          {
            clave: "cobranza",
            etiqueta: "nav.cobranza",
            icono: "facturas",
            ruta: "cobranza",
            permiso: "facturacion.ver",
          },
          {
            clave: "facturas",
            etiqueta: "nav.facturas",
            icono: "facturas",
            ruta: "facturas",
            permiso: "ordenes.ver",
          },
        ],
      },
    ],
  },
  {
    // Quien imparte o atiende (sus horarios y lo que se le paga) y quién entra al
    // panel con qué permisos.
    clave: "equipo",
    etiqueta: "operacion.menu.equipo",
    icono: "instructores",
    hijos: [
      {
        clave: "instructores",
        etiqueta: "nav.instructores",
        icono: "instructores",
        ruta: "instructores",
        permiso: "agenda.gestionar",
        termino: "instructor",
      },
      {
        // Horarios de atención de cada profesional: definen los huecos para citas.
        clave: "horarios",
        etiqueta: "nav.horarios",
        icono: "reloj",
        ruta: "horarios",
        // El horario se arma por sucursal.
        permiso: ["agenda.ver", "sucursales.ver"],
        modalidad: "citas",
      },
      {
        clave: "nomina",
        etiqueta: "nav.nomina",
        icono: "nomina",
        ruta: "nomina",
        permiso: ["estudio.gestionar", "usuarios.gestionar"],
      },
      {
        clave: "usuarios-grupo",
        etiqueta: "nav.grupos.usuarios",
        icono: "usuarios",
        hijos: [
          {
            clave: "usuarios",
            etiqueta: "nav.usuarios",
            icono: "usuarios",
            ruta: "usuarios",
            permiso: "usuarios.gestionar",
          },
          {
            // Roles propios del negocio y sus permisos (ADR 0057).
            clave: "roles",
            etiqueta: "operacion.rolesPropios.titulo",
            icono: "usuarios",
            ruta: "roles",
            permiso: "roles.gestionar",
          },
        ],
      },
    ],
  },
  {
    // Atraer y conservar: avisos, promociones, lealtad y reseñas.
    clave: "marketing",
    etiqueta: "operacion.menu.marketing",
    icono: "promociones",
    hijos: [
      {
        clave: "comunicaciones",
        etiqueta: "nav.comunicaciones",
        icono: "mensaje",
        ruta: "comunicaciones",
        permiso: "comunicaciones.gestionar",
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
        clave: "resenas",
        etiqueta: "resenas.titulo",
        icono: "mensaje",
        ruta: "resenas",
        permiso: "miembros.ver",
      },
    ],
  },
  {
    clave: "reportes",
    etiqueta: "nav.reportes",
    icono: "reportes",
    ruta: "reportes",
    permiso: "facturacion.ver",
  },
  {
    // Cómo está armado el negocio: se configura una vez y se revisa de vez en
    // cuando. Por tema, para no tener una lista larga.
    clave: "ajustes",
    etiqueta: "nav.configuracion",
    icono: "ajustes",
    hijos: [
      {
        clave: "negocio-grupo",
        etiqueta: "nav.grupos.negocio",
        icono: "configuracion",
        hijos: [
          {
            clave: "configuracion",
            etiqueta: "operacion.menu.negocio",
            icono: "configuracion",
            ruta: "configuracion",
            permiso: "estudio.gestionar",
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
        ],
      },
      {
        clave: "servicios-grupo",
        etiqueta: "nav.grupos.servicios",
        icono: "agenda",
        hijos: [
          {
            // Cómo se reservan las clases/servicios y a qué precio.
            clave: "catalogo",
            etiqueta: "planes.nav.clasesServicios",
            icono: "etiqueta",
            ruta: "catalogo",
            permiso: "catalogo.gestionar",
          },
          {
            clave: "reglas-agenda",
            etiqueta: "reglasAgenda.titulo",
            icono: "agenda",
            ruta: "reglas-agenda",
            permiso: "agenda.gestionar",
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
        clave: "pagos-grupo",
        etiqueta: "nav.grupos.pagos",
        icono: "pasarelas",
        hijos: [
          {
            clave: "pasarelas",
            etiqueta: "nav.pasarelas",
            icono: "pasarelas",
            ruta: "pasarelas",
            permiso: "pagos.configurar",
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
      {
        clave: "registros-grupo",
        etiqueta: "nav.grupos.registros",
        icono: "lista",
        hijos: [
          {
            clave: "bitacora",
            etiqueta: "bitacora.titulo",
            icono: "lista",
            ruta: "bitacora",
            permiso: "auditoria.ver",
          },
          {
            // Derechos ARCO: bajas de datos que pidieron los alumnos.
            clave: "privacidad",
            etiqueta: "privacidadNegocio.titulo",
            icono: "documentos",
            ruta: "privacidad",
            permiso: "miembros.gestionar",
          },
        ],
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
const PERMISOS_FUERA_DEL_MENU: Record<string, string | string[]> = {
  "ficha-miembro": "miembros.ver",
  "ficha-instructor": "usuarios.gestionar",
  importar: "miembros.gestionar",
  "importar-instructores": "usuarios.invitar",
  // Cada paso crea algo distinto: sede, catálogo, planes, equipo.
  onboarding: [
    "estudio.gestionar",
    "organizaciones.gestionar",
    "sucursales.gestionar",
    "catalogo.gestionar",
    "productos.gestionar",
    "usuarios.invitar",
  ],
};

export function hojas(items: MenuItem[]): MenuItem[] {
  return items.flatMap((i) => (i.hijos !== undefined ? hojas(i.hijos) : [i]));
}

/** ¿Entró como alumno o cliente? Cuenta solo el rol activo. */
export function esAlumno(sesion: Sesion): boolean {
  return esMiembro(sesion.usuario);
}

export function esVisible(item: MenuItem, sesion: Sesion): boolean {
  // Suspendido por renta vencida: solo la renta, para pagarla (ADR 0073).
  if (sesion.suspendido) {
    return item.ruta === "renta";
  }
  if (item.soloMiembro === true) {
    return esAlumno(sesion);
  }
  if (item.soloInstructor === true) {
    return esInstructor(sesion.usuario);
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
  if (sesion.suspendido) {
    return nombreRuta === "renta";
  }
  const hoja = hojas(MENU).find((h) => h.ruta === nombreRuta);
  if (hoja !== undefined) {
    return esVisible(hoja, sesion);
  }
  const permiso = PERMISOS_FUERA_DEL_MENU[nombreRuta];
  if (permiso === undefined) {
    return true;
  }
  return (Array.isArray(permiso) ? permiso : [permiso]).every((p) =>
    sesion.puede(p),
  );
}
