import { defineStore } from "pinia";
import { computed, ref, watch } from "vue";

import { aplicarTerminologia, i18n } from "@/i18n";
import {
  api,
  fallaPasajera,
  fijarBearer,
  mensajeDeError,
  type FallaPasajera,
} from "@/lib/api";
import { esInstructor, esMiembro, type RolDisponible } from "@/lib/roles";
import { useAparienciaStore, type Apariencia } from "@/stores/apariencia";

// Sucursal con la que puede trabajar quien entró (todas o las asignadas).
export interface SucursalSesion {
  id: string;
  nombre: string;
  zona_horaria: string | null;
  // Su región o zona: distingue sedes que se llaman igual.
  region?: string | null;
}

export interface UsuarioTenant {
  ulid: string;
  // Sucursales que puede operar: con más de una, elige con cuál trabaja.
  sucursales?: SucursalSesion[];
  // Personal sin sucursal asignada en un negocio con varias: no ve nada hasta que
  // le asignen una (ADR 0098).
  sin_sucursal?: boolean;
  nombre: string;
  email: string;
  // Rol ACTIVO: con el que entró (el menú, su inicio y los permisos son de este).
  rol: string;
  // Todos sus roles en el negocio y, de ellos, los que puede elegir para entrar.
  roles?: string[];
  roles_disponibles?: RolDisponible[];
  // Permisos del rol activo.
  permisos?: string[];
  // Tema y colores propios guardados en la cuenta.
  apariencia?: Apariencia;
  // "Mi perfil": partes del nombre, forma corta (primer nombre + apellido
  // paterno) y foto.
  nombre_pila?: string | null;
  primer_apellido?: string | null;
  segundo_apellido?: string | null;
  nombre_corto?: string;
  foto_url?: string | null;
  tiene_contrasena?: boolean;
  // Conectó Google para entrar con él (ADR 0093).
  google_conectado?: boolean;
  // Correo nuevo que espera confirmación por enlace.
  email_pendiente?: string | null;
  // Su ficha de cliente o alumno (si la tiene): de ahí sale su celular, que
  // edita en «Mi perfil».
  tiene_ficha?: boolean;
  celular?: string | null;
  // También de su ficha (opcionales): fecha de nacimiento y género.
  fecha_nacimiento?: string | null;
  genero?: string | null;
}

/** Cómo atiende el negocio: clases con cupo o citas 1 a 1 con un profesional. */
export type ModalidadServicio = "clases" | "citas";

export interface Terminologia {
  sesion: string;
  miembro: string;
  instructor: string;
  // Plurales (ADR 0049): Citas, Clientes, Barberos.
  sesiones?: string;
  miembros?: string;
  instructores?: string;
}

/** Configuración del perfil de negocio: adapta etiquetas y opciones sin forks. */
export interface PerfilConfig {
  terminologia: Terminologia;
  flags: { grupos: boolean; niveles: boolean; acceso_abierto: boolean };
  modalidad: ModalidadServicio;
}

export interface EstudioSesion {
  slug: string;
  nombre: string;
  logo_url?: string | null;
  estado: string;
  estado_facturacion?: string;
  trial_termina_en?: string | null;
  publicado?: boolean;
  en_directorio?: boolean;
  perfil?: string;
  perfil_config?: PerfilConfig;
  // El negocio manda avisos por WhatsApp a sus clientes (ADR 0069).
  whatsapp_clientes?: boolean;
  // Su moneda y su zona horaria (ADR 0099): una sola moneda para todo el negocio.
  moneda?: string;
  zona_horaria?: string;
  // Con pesos mexicanos cobra en línea y (en México) factura a sus clientes.
  cobra_en_linea_posible?: boolean;
  factura_posible?: boolean;
  // ¿La plataforma ya factura? En producción, solo con su proveedor configurado.
  facturacion_disponible?: boolean;
}

const TERMINOLOGIA_DEFAULT: Terminologia = {
  sesion: "Clase",
  miembro: "Miembro",
  instructor: "Instructor",
};

interface RespuestaAuth {
  token: string;
  usuario: UsuarioTenant;
  estudio: EstudioSesion;
}

const CLAVE_BEARER = "tu.tenant.bearer";
const CLAVE_SLUG = "tu.tenant.slug";
const CLAVE_ROL_PENDIENTE = "tu.tenant.rol-pendiente";

// Qué se le dice a quien abrió la web con su sesión guardada cuando no se pudo
// confirmar (la sesión NO se borra: el token sigue vigente en el servidor).
const AVISO_SIN_CONFIRMAR: Record<FallaPasajera, string> = {
  "sin-conexion": "validacion.sesion.sinConfirmar.sinConexion",
  mantenimiento: "validacion.sesion.sinConfirmar.mantenimiento",
  servidor: "validacion.sesion.sinConfirmar.servidor",
};

/**
 * Sesion TENANT-LOCAL: no hay login global. Se resuelve el estudio por slug y se
 * guarda un bearer token que se envia en cada peticion a `/app/{slug}/...`. El
 * bearer y el slug se persisten para reanudar la sesion al recargar.
 */
export const useSesionTenantStore = defineStore("sesionTenant", () => {
  const slug = ref<string | null>(leer(CLAVE_SLUG));
  const bearer = ref<string | null>(leer(CLAVE_BEARER));
  const usuario = ref<UsuarioTenant | null>(null);
  const estudio = ref<EstudioSesion | null>(null);
  const cargando = ref(false);
  const error = ref<string | null>(null);
  const verificado = ref(false);
  // Hay token guardado pero /yo no se pudo consultar (red, mantenimiento, 5xx): por
  // qué, y la dirección que se abrió, para volver ahí al reintentar.
  const sinConfirmar = ref<FallaPasajera | null>(null);
  const volverTrasConfirmar = ref<string | null>(null);
  const avisoSinConfirmar = computed(() =>
    sinConfirmar.value === null
      ? null
      : i18n.global.t(AVISO_SIN_CONFIRMAR[sinConfirmar.value]),
  );
  const requiereElegirRol = ref(leer(CLAVE_ROL_PENDIENTE) === "1");
  function confirmarRolInicial(): void {
    requiereElegirRol.value = false;
    borrar(CLAVE_ROL_PENDIENTE);
  }

  const autenticado = computed(
    () => usuario.value !== null && bearer.value !== null,
  );
  // Hay un token guardado pero aún no se confirma con /yo (al recargar la página).
  const validando = computed(() => bearer.value !== null && !verificado.value);

  /** RBAC de UI: el propietario (`*`) puede todo. El backend es la barrera real. */
  function puede(permiso: string): boolean {
    const permisos = usuario.value?.permisos ?? [];
    return permisos.includes("*") || permisos.includes(permiso);
  }

  /**
   * Ruta de INICIO según el rol: cada quien aterriza donde empieza su trabajo, no
   * en un panel que quizá no puede ver (P0). Dueño/admin → resumen del negocio;
   * recepción → operación de hoy; instructor → su Inicio (sus clases); alumno →
   * su cuenta.
   */
  // Suspendido por renta vencida (ADR 0073): solo se entra a pagarla.
  const suspendido = computed(() => estudio.value?.estado === "suspended");

  const rutaInicio = computed<string>(() => {
    const u = usuario.value;
    if (u === null) {
      return "entrar";
    }
    if (suspendido.value) {
      return "renta";
    }
    if (esMiembro(u)) {
      return "mi-cuenta";
    }
    if (puede("facturacion.ver")) {
      return "panel";
    }
    // El inicio también pasa por las reglas de acceso: Recepción solo con su
    // permiso (no por llamarse «recepcionista» el rol).
    if (puede("reservas.gestionar")) {
      return "recepcion";
    }
    if (esInstructor(u)) {
      return "inicio-instructor";
    }
    if (puede("agenda.ver")) {
      return "agenda";
    }
    // Sin pantallas de trabajo con este rol: su perfil.
    return "mi-perfil";
  });

  /** ¿Puede entrar con más de un rol? Entonces elige al entrar y cambia arriba. */
  const tieneVariosRoles = computed(
    () => (usuario.value?.roles_disponibles?.length ?? 0) > 1,
  );

  /**
   * A dónde va tras iniciar sesión: su inicio. Con varios roles, antes el panel
   * lateral pregunta con cuál entra (`requiereElegirRol`).
   */
  const destinoAlEntrar = computed<string>(() => rutaInicio.value);

  /**
   * Modalidad de servicio del negocio (derivada de su perfil). La agenda, el menú, la
   * terminología y el cobro se adaptan a ella; por defecto, clases.
   */
  const modalidad = computed<ModalidadServicio>(
    () => estudio.value?.perfil_config?.modalidad ?? "clases",
  );
  const esCitas = computed(() => modalidad.value === "citas");
  /** Moneda del negocio (ISO 4217): la de los precios nuevos. */
  const moneda = computed(() => estudio.value?.moneda ?? "MXN");
  /**
   * Zona horaria del negocio (ADR 0099): define qué día es «hoy» en sus pantallas,
   * aunque quien mira esté en otra zona. Sin dato, la del navegador.
   */
  const zonaHoraria = computed(
    () =>
      estudio.value?.zona_horaria ||
      Intl.DateTimeFormat().resolvedOptions().timeZone,
  );

  /** Terminología del perfil (p. ej. Cita / Cliente / Barbero). */
  const terminologia = computed<Terminologia>(
    () => estudio.value?.perfil_config?.terminologia ?? TERMINOLOGIA_DEFAULT,
  );
  // Los textos de las pantallas hablan como el negocio: en una barbería, "citas" y
  // "clientes" en lugar de "clases" y "alumnos" (ADR 0049).
  watch(
    () => estudio.value?.perfil_config?.terminologia ?? null,
    (terminos) => aplicarTerminologia(terminos),
    { immediate: true, deep: true },
  );

  // El bearer va solo a las rutas de su negocio (lib/api); se mantiene al día con
  // la sesión aunque cambie por otra vía.
  watch([bearer, slug], ([b, s]) => fijarBearer(b, s), {
    immediate: true,
    flush: "sync",
  });

  function establecer(datos: RespuestaAuth): void {
    bearer.value = datos.token;
    slug.value = datos.estudio.slug;
    usuario.value = datos.usuario;
    requiereElegirRol.value =
      (datos.usuario.roles_disponibles?.length ?? 0) > 1;
    if (requiereElegirRol.value) guardar(CLAVE_ROL_PENDIENTE, "1");
    else borrar(CLAVE_ROL_PENDIENTE);
    estudio.value = datos.estudio;
    useAparienciaStore().activar(datos.usuario.apariencia);
    verificado.value = true;
    sinConfirmar.value = null;
    fijarBearer(datos.token, datos.estudio.slug);
    guardar(CLAVE_BEARER, datos.token);
    guardar(CLAVE_SLUG, datos.estudio.slug);
  }

  async function iniciarSesion(
    slugEstudio: string,
    email: string,
    password: string,
  ): Promise<void> {
    cargando.value = true;
    error.value = null;
    try {
      const { data } = await api.post<{ data: RespuestaAuth }>(
        `/api/v1/app/${slugEstudio}/login`,
        { email, password },
      );
      establecer(data.data);
    } catch (e) {
      error.value = mensajeDeError(
        e,
        i18n.global.t("validacion.sesion.entrar"),
      );
      throw e;
    } finally {
      cargando.value = false;
    }
  }

  async function iniciarSesionConGoogle(
    slugEstudio: string,
    credential: string,
  ): Promise<void> {
    cargando.value = true;
    error.value = null;
    try {
      const { data } = await api.post<{ data: RespuestaAuth }>(
        `/api/v1/app/${slugEstudio}/auth/google`,
        { credential },
      );
      establecer(data.data);
    } catch (e) {
      error.value = mensajeDeError(
        e,
        i18n.global.t("validacion.sesion.google"),
      );
      throw e;
    } finally {
      cargando.value = false;
    }
  }

  /**
   * Conecta (o quita) Google a la cuenta con la que se entró: desde entonces puede
   * entrar con Google. Google no crea cuentas (ADR 0093).
   */
  async function conectarGoogle(credential: string): Promise<void> {
    const { data } = await api.put<{ data: { usuario: UsuarioTenant } }>(
      `/api/v1/app/${slug.value}/yo/google`,
      { credential },
    );
    usuario.value = data.data.usuario;
  }
  async function desconectarGoogle(): Promise<void> {
    const { data } = await api.delete<{ data: { usuario: UsuarioTenant } }>(
      `/api/v1/app/${slug.value}/yo/google`,
    );
    usuario.value = data.data.usuario;
  }

  async function activar(
    slugEstudio: string,
    email: string,
    token: string,
    password: string,
    passwordConfirmation: string,
  ): Promise<void> {
    cargando.value = true;
    error.value = null;
    try {
      const { data } = await api.post<{ data: RespuestaAuth }>(
        `/api/v1/app/${slugEstudio}/activar`,
        { email, token, password, password_confirmation: passwordConfirmation },
      );
      establecer(data.data);
    } catch (e) {
      error.value = mensajeDeError(
        e,
        i18n.global.t("validacion.sesion.activar"),
      );
      throw e;
    } finally {
      cargando.value = false;
    }
  }

  /** Fija la contraseña nueva con el enlace del correo y deja la sesión iniciada. */
  async function restablecerContrasena(
    slugEstudio: string,
    email: string,
    token: string,
    password: string,
    passwordConfirmation: string,
  ): Promise<void> {
    cargando.value = true;
    error.value = null;
    try {
      const { data } = await api.post<{ data: RespuestaAuth }>(
        `/api/v1/app/${slugEstudio}/restablecer-contrasena`,
        { email, token, password, password_confirmation: passwordConfirmation },
      );
      establecer(data.data);
    } catch (e) {
      error.value = mensajeDeError(
        e,
        i18n.global.t("validacion.sesion.activar"),
      );
      throw e;
    } finally {
      cargando.value = false;
    }
  }

  async function cargarYo(): Promise<void> {
    if (slug.value === null || bearer.value === null) {
      return;
    }
    const { data } = await api.get<{
      data: { usuario: UsuarioTenant; estudio: EstudioSesion };
    }>(`/api/v1/app/${slug.value}/yo`);
    usuario.value = data.data.usuario;
    estudio.value = data.data.estudio;
    useAparienciaStore().activar(data.data.usuario.apariencia);
  }

  /**
   * Cambia el rol con el que se trabaja. La API lo guarda en la sesión y desde ese
   * momento solo concede los permisos de ese rol; también lo recuerda para la
   * próxima vez.
   */
  async function cambiarRol(rol: string): Promise<void> {
    if (slug.value === null) {
      return;
    }
    const { data } = await api.put<{
      data: { usuario: UsuarioTenant; estudio: EstudioSesion };
    }>(`/api/v1/app/${slug.value}/yo/rol-activo`, { rol });
    usuario.value = data.data.usuario;
    estudio.value = data.data.estudio;
  }

  /** Tras editar "Mi perfil": la API devuelve el usuario ya actualizado. */
  function actualizarUsuario(datos: UsuarioTenant): void {
    usuario.value = datos;
  }

  /**
   * Confirma con /yo la sesión guardada (al abrir o recargar la web). Solo se borra
   * cuando el servidor dice que ya no sirve (401, o 404: el negocio ya no existe).
   * Sin red, en mantenimiento (503 al publicar), con 429 o 5xx se conserva el token
   * y se deja seguir: `sinConfirmar` dice por qué y la pantalla de Entrar ofrece
   * reintentar.
   */
  async function verificarSesion(): Promise<void> {
    if (verificado.value) {
      return;
    }
    try {
      await cargarYo();
      sinConfirmar.value = null;
    } catch (e) {
      const falla = fallaPasajera(e);
      if (falla === null) {
        limpiar();
      } else {
        sinConfirmar.value = falla;
        volverTrasConfirmar.value ??= direccionAbierta();
      }
    } finally {
      verificado.value = true;
    }
  }

  /** «Reintentar»: vuelve a consultar /yo. ¿Quedó la sesión confirmada? */
  async function reintentarSesion(): Promise<boolean> {
    if (bearer.value === null) {
      return false;
    }
    verificado.value = false;
    await verificarSesion();
    return autenticado.value;
  }

  /**
   * El servidor ya no reconoce el token (401 en plena sesión): se olvida en este
   * navegador sin llamar a /logout, que también respondería 401.
   */
  function olvidarSesion(): void {
    limpiar();
  }

  async function cerrarSesion(): Promise<void> {
    try {
      if (slug.value !== null && bearer.value !== null) {
        await api.post(`/api/v1/app/${slug.value}/logout`);
      }
    } catch {
      // Aunque falle en el servidor, limpiamos localmente.
    } finally {
      limpiar();
    }
  }

  function limpiar(): void {
    confirmarRolInicial();
    sinConfirmar.value = null;
    volverTrasConfirmar.value = null;
    bearer.value = null;
    usuario.value = null;
    estudio.value = null;
    fijarBearer(null);
    borrar(CLAVE_BEARER);
    useAparienciaStore().desactivar();
  }

  return {
    slug,
    bearer,
    usuario,
    estudio,
    cargando,
    error,
    autenticado,
    validando,
    sinConfirmar,
    avisoSinConfirmar,
    volverTrasConfirmar,
    puede,
    rutaInicio,
    suspendido,
    tieneVariosRoles,
    destinoAlEntrar,
    requiereElegirRol,
    confirmarRolInicial,
    modalidad,
    esCitas,
    moneda,
    zonaHoraria,
    terminologia,
    iniciarSesion,
    iniciarSesionConGoogle,
    activar,
    restablecerContrasena,
    cargarYo,
    conectarGoogle,
    desconectarGoogle,
    cambiarRol,
    actualizarUsuario,
    verificarSesion,
    reintentarSesion,
    olvidarSesion,
    cerrarSesion,
  };
});

/**
 * La dirección que se abrió (ruta, búsqueda y ancla), si es interna y no es la de
 * Entrar: a donde volver cuando la sesión se confirme.
 */
function direccionAbierta(): string | null {
  try {
    const { pathname, search, hash } = window.location;
    return pathname.startsWith("/entrar") ? null : pathname + search + hash;
  } catch {
    return null;
  }
}

function leer(clave: string): string | null {
  try {
    return localStorage.getItem(clave);
  } catch {
    return null;
  }
}
function guardar(clave: string, valor: string): void {
  try {
    localStorage.setItem(clave, valor);
  } catch {
    // Ignora si no hay localStorage.
  }
}
function borrar(clave: string): void {
  try {
    localStorage.removeItem(clave);
  } catch {
    // Ignora.
  }
}
