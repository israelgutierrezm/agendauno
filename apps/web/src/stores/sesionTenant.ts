import { defineStore } from "pinia";
import { computed, ref, watch } from "vue";

import { aplicarTerminologia, i18n } from "@/i18n";
import { api, fijarBearer, mensajeDeError } from "@/lib/api";
import { esInstructor, esMiembro, type RolDisponible } from "@/lib/roles";
import { useAparienciaStore, type Apariencia } from "@/stores/apariencia";

export interface UsuarioTenant {
  ulid: string;
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
  // Correo nuevo que espera confirmación por enlace.
  email_pendiente?: string | null;
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
  const rutaInicio = computed<string>(() => {
    const u = usuario.value;
    if (u === null) {
      return "entrar";
    }
    if (esMiembro(u)) {
      return "mi-cuenta";
    }
    if (puede("facturacion.ver")) {
      return "panel";
    }
    if (u.rol === "recepcionista" || puede("reservas.gestionar")) {
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

  /** A dónde va tras iniciar sesión: a elegir rol si tiene varios; si no, a su inicio. */
  const destinoAlEntrar = computed<string>(() =>
    tieneVariosRoles.value ? "elegir-rol" : rutaInicio.value,
  );

  /**
   * Modalidad de servicio del negocio (derivada de su perfil). La agenda, el menú, la
   * terminología y el cobro se adaptan a ella; por defecto, clases.
   */
  const modalidad = computed<ModalidadServicio>(
    () => estudio.value?.perfil_config?.modalidad ?? "clases",
  );
  const esCitas = computed(() => modalidad.value === "citas");

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

  fijarBearer(bearer.value);

  function establecer(datos: RespuestaAuth): void {
    bearer.value = datos.token;
    slug.value = datos.estudio.slug;
    usuario.value = datos.usuario;
    estudio.value = datos.estudio;
    useAparienciaStore().activar(datos.usuario.apariencia);
    verificado.value = true;
    fijarBearer(datos.token);
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

  /**
   * Crea la cuenta del alumno. Si el correo ya es de alguien en el negocio, el
   * servidor no la liga todavía: manda un enlace a ese correo y devuelve a qué
   * correo (`confirmar`); si no, entra de una vez (`confirmar` = null).
   */
  async function registrarAlumno(
    slugEstudio: string,
    datos: {
      nombre: string;
      primer_apellido?: string;
      email: string;
      password: string;
      passwordConfirmation: string;
    },
  ): Promise<{ confirmar: string | null }> {
    cargando.value = true;
    error.value = null;
    try {
      const respuesta = await api.post<{
        data: RespuestaAuth | { confirmacion: string; email: string };
      }>(`/api/v1/app/${slugEstudio}/registro-alumno`, {
        nombre: datos.nombre,
        primer_apellido: datos.primer_apellido || null,
        email: datos.email,
        password: datos.password,
        password_confirmation: datos.passwordConfirmation,
      });
      const cuerpo = respuesta.data.data;
      if (respuesta.status === 202 && "confirmacion" in cuerpo) {
        return { confirmar: cuerpo.email };
      }
      establecer(cuerpo as RespuestaAuth);
      return { confirmar: null };
    } catch (e) {
      error.value = mensajeDeError(
        e,
        i18n.global.t("validacion.sesion.registrar"),
      );
      throw e;
    } finally {
      cargando.value = false;
    }
  }

  /**
   * Abre el enlace del correo de confirmación de registro: liga la cuenta a su
   * historial y entra.
   */
  async function confirmarRegistro(
    slugEstudio: string,
    email: string,
    token: string,
  ): Promise<void> {
    const { data } = await api.post<{ data: RespuestaAuth }>(
      `/api/v1/app/${slugEstudio}/registro-alumno/confirmar`,
      { email, token },
    );
    establecer(data.data);
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

  async function verificarSesion(): Promise<void> {
    if (verificado.value) {
      return;
    }
    try {
      await cargarYo();
    } catch {
      limpiar();
    } finally {
      verificado.value = true;
    }
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
    puede,
    rutaInicio,
    tieneVariosRoles,
    destinoAlEntrar,
    modalidad,
    esCitas,
    terminologia,
    iniciarSesion,
    iniciarSesionConGoogle,
    activar,
    restablecerContrasena,
    registrarAlumno,
    confirmarRegistro,
    cargarYo,
    cambiarRol,
    actualizarUsuario,
    verificarSesion,
    cerrarSesion,
  };
});

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
