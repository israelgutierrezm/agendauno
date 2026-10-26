import { esInstructor, esMiembro } from "@/lib/roles";
import type { FuncionPlan } from "@/lib/suscripcion";
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
  // Basta con CUALQUIERA de estos permisos (una pantalla con partes para cada uno).
  algunPermiso?: string[];
  // Solo en negocios de esta modalidad: la misma clasificación que las rutas del
  // servidor con `modalidad:clases|citas` (ADR 0104).
  modalidad?: ModalidadServicio;
  // Solo si el perfil del negocio activa esta función.
  flag?: "grupos" | "niveles" | "acceso_abierto";
  // Solo si el plan del negocio la incluye (ADR 0107; el servidor ya la niega).
  funcion?: FuncionPlan;
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
  // Pase de lista de una clase (las citas se marcan en su detalle).
  "pase-lista": {
    permiso: ["reservas.ver", "asistencia.marcar"],
    modalidad: "clases",
  },
  // Llenar lugares libres de una clase: no aplica a citas 1 a 1.
  oportunidades: { permiso: "reservas.gestionar", modalidad: "clases" },
  // La pantalla lista a los alumnos de cada grupo. Los grupos son de clases: el
  // servidor los niega en citas (ADR 0104).
  grupos: {
    permiso: ["agenda.ver", "miembros.ver"],
    flag: "grupos",
    modalidad: "clases",
  },
  miembros: { permiso: "miembros.ver" },
  // Captación (ADR 0117): aparte de Miembros.
  prospectos: { permiso: "prospectos.ver" },
  "captacion-formularios": { permiso: "prospectos.ver" },
  "captacion-integrar": { permiso: "prospectos.ver" },
  // Campañas (ADR 0144/0167): el reporte por campaña, con su plan.
  campanas: { permiso: "facturacion.ver", funcion: "reportes_avanzados" },
  retencion: { permiso: "miembros.gestionar" },
  documentos: { permiso: "documentos.subir" },
  formularios: { permiso: "formularios.gestionar", funcion: "formularios" },
  cobranza: { permiso: "facturacion.ver" },
  facturas: { permiso: "ordenes.ver", funcion: "facturacion" },
  instructores: { permiso: "agenda.gestionar" },
  // Horarios de atención de cada profesional (se arman por sucursal).
  horarios: { permiso: ["agenda.ver", "sucursales.ver"], modalidad: "citas" },
  nomina: {
    permiso: ["estudio.gestionar", "usuarios.gestionar"],
    funcion: "comisiones",
  },

  // ---- Gestión ----
  // Vende a un alumno y muestra las órdenes: sin eso la pantalla queda a medias.
  ventas: { permiso: ["productos.ver", "ordenes.ver", "miembros.ver"] },
  pos: { permiso: ["inventario.ver", "pos.vender"], funcion: "inventario" },
  // La caja de la sucursal (ADR 0175): quien la opera; lo esperado y los cierres, quien supervisa.
  caja: { permiso: "caja.operar" },
  // Los cierres de caja, en Cobros: solo quien supervisa (sin pedir ver la facturación).
  "cierres-caja": { permiso: "caja.supervisar" },
  inventario: { permiso: "inventario.gestionar", funcion: "inventario" },
  // Presupuestos (ADR 0127): de los negocios de citas.
  presupuestos: { permiso: "presupuestos.ver", modalidad: "citas" },
  "presupuesto-impreso": { permiso: "presupuestos.ver", modalidad: "citas" },
  comunicaciones: { permiso: "comunicaciones.gestionar", funcion: "mensajes" },
  // La bandeja de WhatsApp e Instagram (ADR 0200).
  bandeja: { permiso: "mensajes.atender", funcion: "mensajes" },
  promociones: { permiso: "promociones.gestionar", funcion: "promociones" },
  lealtad: { permiso: "lealtad.ver", funcion: "lealtad" },
  resenas: { permiso: "miembros.ver" },
  reportes: { permiso: "facturacion.ver" },
  // El asistente sobre los datos del negocio (ADR 0196).
  "asistente-ia": { permiso: "facturacion.ver", funcion: "reportes_avanzados" },
  // Visitantes de Wellhub y TotalPass (ADR 0148): solo negocios de clases.
  "reportes-plataformas": { permiso: "facturacion.ver", modalidad: "clases" },

  // ---- Configuración del negocio ----
  configuracion: { permiso: "estudio.gestionar" },
  // Sitio web (ADR 0167): quien lo prepara (`sitio.editar`) o quien atiende prospectos
  // (solo «Compartir»: enlaces, QR y widgets).
  "sitio-web": { algunPermiso: ["sitio.editar", "prospectos.ver"] },
  sedes: { permiso: "sucursales.gestionar" },
  "datos-fiscales": { permiso: "estudio.gestionar" },
  region: { permiso: "estudio.gestionar" },
  catalogo: { permiso: "catalogo.gestionar" },
  planes: { permiso: "productos.ver", funcion: "paquetes" },
  // Biblioteca de videos para los clientes (ADR 0184).
  videos: { permiso: "catalogo.gestionar", modalidad: "clases" },
  recursos: { permiso: "agenda.gestionar", funcion: "recursos" },
  "reglas-agenda": { permiso: "agenda.gestionar" },
  pasarelas: { permiso: "pagos.configurar" },
  integraciones: {
    permiso: "integraciones.configurar",
    funcion: "integraciones",
  },
  usuarios: { permiso: "usuarios.gestionar" },
  roles: { permiso: "roles.gestionar", funcion: "roles_propios" },
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
      "videos",
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
  // Solo con el negocio dado de baja (ADR 0213; la regla de arriba lo abre).
  "negocio-dado-de-baja": { permiso: "facturacion.ver" },
  timbres: { permiso: "facturacion.ver" },
  // En citas se cobra por profesional (ver Renta → quién cuenta).
  padron: { permiso: "facturacion.ver", modalidad: "clases" },

  // ---- Pantallas fuera del menú ----
  "ficha-miembro": { permiso: "miembros.ver" },
  "ficha-instructor": { permiso: "usuarios.gestionar" },
  importar: { permiso: "miembros.gestionar" },
  // Concede planes y créditos sin cobro: pide las dos cosas y dar cortesías (C07,
  // ADR 0207).
  "importar-saldos": {
    permiso: [
      "membresias.gestionar",
      "creditos.gestionar",
      "membresias.cortesia",
    ],
  },
  // Reservas futuras de clases de otro sistema (ADR 0176).
  "importar-reservas": { permiso: "reservas.gestionar", modalidad: "clases" },
  "importar-instructores": { permiso: "usuarios.invitar" },
  "importar-clases": { permiso: "agenda.gestionar", modalidad: "clases" },
  // Citas futuras de otro sistema (ADR 0160).
  "importar-citas": {
    permiso: ["agenda.gestionar", "reservas.gestionar"],
    modalidad: "citas",
  },
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
  "mis-videos": { soloMiembro: true, modalidad: "clases" },
  "mi-configuracion": { soloMiembro: true },
  "inicio-instructor": { soloInstructor: true },
  "mis-clases": { soloInstructor: true },
  // Lo que lleva ganado quien atiende citas (ADR 0219).
  "mis-comisiones": {
    soloInstructor: true,
    modalidad: "citas",
    funcion: "comisiones",
  },

  // ---- Personales ----
  "mi-perfil": { personal: true },
};

/** ¿Entró como alumno o cliente? Cuenta solo el rol activo. */
export function esAlumno(sesion: Sesion): boolean {
  return esMiembro(sesion.usuario);
}

/**
 * ¿Puede entrar a esta pantalla? Sin regla registrada, no. Suspendido por renta
 * vencida, solo a la renta, para pagarla (ADR 0073); dado de baja, a la baja y a la
 * renta (ADR 0213). Todo se evalúa junto: el rol
 * activo (alumno, instructor), la modalidad del negocio, las funciones de su perfil,
 * lo que incluye su plan y los permisos. El rol no basta: un cliente de citas no entra
 * a «Mis videos» aunque sea un portal de alumno.
 */
export function puedeEntrar(
  nombreRuta: string,
  sesion: Sesion,
  vistas: Set<string> = new Set(),
): boolean {
  if (sesion.dadoDeBaja) {
    return nombreRuta === "negocio-dado-de-baja" || nombreRuta === "renta";
  }
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
  // El rol activo es una condición más, no un pase: luego se revisa lo demás.
  if (politica.soloMiembro === true && !esAlumno(sesion)) {
    return false;
  }
  if (politica.soloInstructor === true && !esInstructor(sesion.usuario)) {
    return false;
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
  if (
    politica.funcion !== undefined &&
    sesion.estudio?.plan?.sin.includes(politica.funcion) === true
  ) {
    return false;
  }
  if (politica.algunPermiso !== undefined) {
    return politica.algunPermiso.some((p) => sesion.puede(p));
  }
  if (politica.permiso === undefined) {
    return true;
  }
  const permisos = Array.isArray(politica.permiso)
    ? politica.permiso
    : [politica.permiso];
  return permisos.every((p) => sesion.puede(p));
}
