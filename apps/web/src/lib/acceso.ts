import { esInstructor, esMiembro } from "@/lib/roles";
import type {
  ModalidadServicio,
  useSesionTenantStore,
} from "@/stores/sesionTenant";

/**
 * Quién puede entrar a cada pantalla privada, independiente del menú: el menú, las
 * pestañas de cada área, la configuración y el router consultan este registro, así
 * que quitar una pantalla del lateral no le quita su protección.
 *
 * Toda ruta con `requiereSesion` debe tener aquí su regla; la que no la tenga queda
 * NEGADA (no abierta por omisión). El servidor vuelve a validar cada lectura y cada
 * cambio: esto solo decide qué se ofrece y a qué se deja llegar escribiendo la URL.
 */
type Sesion = ReturnType<typeof useSesionTenantStore>;

export interface Politica {
  // Permisos del rol ACTIVO; con varios hacen falta TODOS.
  permiso?: string | string[];
  // Solo en negocios de esta modalidad.
  modalidad?: ModalidadServicio;
  // Solo si el perfil del negocio activa esta función.
  flag?: "grupos" | "niveles" | "acceso_abierto";
  // Portal del alumno o cliente / de quien imparte.
  soloMiembro?: boolean;
  soloInstructor?: boolean;
  // Basta con la sesión (perfil propio, elegir rol).
  personal?: boolean;
  // Se puede entrar si se puede entrar a CUALQUIERA de estas rutas (portadas que
  // solo reúnen opciones, como la de Configuración).
  algunaDe?: string[];
}

export const POLITICAS: Record<string, Politica> = {
  // ---- Operación diaria ----
  panel: { permiso: "facturacion.ver" },
  tareas: { permiso: "tareas.ver" },
  agenda: { permiso: "agenda.ver" },
  recepcion: { permiso: "reservas.gestionar" },
  // Llenar lugares libres de una clase: no aplica a citas 1 a 1.
  oportunidades: { permiso: "reservas.gestionar", modalidad: "clases" },
  // La pantalla lista a los alumnos de cada grupo.
  grupos: { permiso: ["agenda.ver", "miembros.ver"], flag: "grupos" },
  miembros: { permiso: "miembros.ver" },
  retencion: { permiso: "miembros.gestionar" },
  documentos: { permiso: "documentos.subir" },
  formularios: { permiso: "formularios.gestionar" },
  cobranza: { permiso: "facturacion.ver" },
  facturas: { permiso: "ordenes.ver" },
  instructores: { permiso: "agenda.gestionar" },
  // Horarios de atención de cada profesional (se arman por sucursal).
  horarios: { permiso: ["agenda.ver", "sucursales.ver"], modalidad: "citas" },
  nomina: { permiso: ["estudio.gestionar", "usuarios.gestionar"] },

  // ---- Gestión ----
  // Vende a un alumno y muestra las órdenes: sin eso la pantalla queda a medias.
  ventas: { permiso: ["productos.ver", "ordenes.ver", "miembros.ver"] },
  pos: { permiso: ["inventario.ver", "pos.vender"] },
  inventario: { permiso: "inventario.gestionar" },
  comunicaciones: { permiso: "comunicaciones.gestionar" },
  promociones: { permiso: "promociones.gestionar" },
  lealtad: { permiso: "lealtad.ver" },
  resenas: { permiso: "miembros.ver" },
  reportes: { permiso: "facturacion.ver" },

  // ---- Configuración del negocio ----
  configuracion: { permiso: "estudio.gestionar" },
  sedes: { permiso: "sucursales.gestionar" },
  "datos-fiscales": { permiso: "estudio.gestionar" },
  region: { permiso: "estudio.gestionar" },
  catalogo: { permiso: "catalogo.gestionar" },
  planes: { permiso: "productos.ver" },
  recursos: { permiso: "agenda.gestionar" },
  "reglas-agenda": { permiso: "agenda.gestionar" },
  pasarelas: { permiso: "pagos.configurar" },
  integraciones: { permiso: "integraciones.configurar" },
  usuarios: { permiso: "usuarios.gestionar" },
  roles: { permiso: "roles.gestionar" },
  bitacora: { permiso: "auditoria.ver" },
  privacidad: { permiso: "miembros.gestionar" },
  // Portada: solo reúne opciones; se ve si alguna se puede abrir.
  ajustes: {
    algunaDe: [
      "configuracion",
      "sedes",
      "region",
      "datos-fiscales",
      "catalogo",
      "planes",
      "recursos",
      "reglas-agenda",
      "pasarelas",
      "integraciones",
      "usuarios",
      "roles",
      "documentos",
      "formularios",
      "privacidad",
      "bitacora",
    ],
  },

  // ---- Mi suscripción (lo que el negocio paga a AgendaUno) ----
  renta: { permiso: "facturacion.ver" },
  // En citas se cobra por profesional (ver Renta → quién cuenta).
  padron: { permiso: "facturacion.ver", modalidad: "clases" },

  // ---- Pantallas fuera del menú ----
  "ficha-miembro": { permiso: "miembros.ver" },
  "ficha-instructor": { permiso: "usuarios.gestionar" },
  importar: { permiso: "miembros.gestionar" },
  "importar-instructores": { permiso: "usuarios.invitar" },
  "importar-clases": { permiso: "agenda.gestionar", modalidad: "clases" },
  // Cada paso crea algo distinto: sede, catálogo, planes, equipo.
  onboarding: {
    permiso: [
      "estudio.gestionar",
      "organizaciones.gestionar",
      "sucursales.gestionar",
      "catalogo.gestionar",
      "productos.gestionar",
      "usuarios.invitar",
    ],
  },

  // ---- Portales ----
  "mi-cuenta": { soloMiembro: true },
  "mis-reservas": { soloMiembro: true },
  "mis-pagos": { soloMiembro: true },
  "mi-expediente": { soloMiembro: true },
  "mi-configuracion": { soloMiembro: true },
  "inicio-instructor": { soloInstructor: true },
  "mis-clases": { soloInstructor: true },

  // ---- Personales ----
  "mi-perfil": { personal: true },
};

/** ¿Entró como alumno o cliente? Cuenta solo el rol activo. */
export function esAlumno(sesion: Sesion): boolean {
  return esMiembro(sesion.usuario);
}

/**
 * ¿Puede entrar a esta pantalla? Sin regla registrada, no. Suspendido por renta
 * vencida, solo a la renta, para pagarla (ADR 0073).
 */
export function puedeEntrar(
  nombreRuta: string,
  sesion: Sesion,
  vistas: Set<string> = new Set(),
): boolean {
  if (sesion.suspendido) {
    return nombreRuta === "renta";
  }
  const politica = POLITICAS[nombreRuta];
  if (politica === undefined) {
    return false;
  }
  if (politica.algunaDe !== undefined) {
    // Evita ciclos si una portada llegara a incluirse a sí misma.
    const siguiente = new Set(vistas).add(nombreRuta);
    return politica.algunaDe.some(
      (r) => !siguiente.has(r) && puedeEntrar(r, sesion, siguiente),
    );
  }
  if (politica.personal === true) {
    return true;
  }
  if (politica.soloMiembro === true) {
    return esAlumno(sesion);
  }
  if (politica.soloInstructor === true) {
    return esInstructor(sesion.usuario);
  }
  if (
    politica.modalidad !== undefined &&
    politica.modalidad !== sesion.modalidad
  ) {
    return false;
  }
  if (
    politica.flag !== undefined &&
    sesion.estudio?.perfil_config?.flags[politica.flag] !== true
  ) {
    return false;
  }
  if (politica.permiso === undefined) {
    return true;
  }
  const permisos = Array.isArray(politica.permiso)
    ? politica.permiso
    : [politica.permiso];
  return permisos.every((p) => sesion.puede(p));
}
