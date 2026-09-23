import { defineStore } from "pinia";
import { computed, ref } from "vue";

import { api, fijarBearer, mensajeDeError } from "@/lib/api";
import { useAparienciaStore, type Apariencia } from "@/stores/apariencia";

export interface UsuarioTenant {
  ulid: string;
  nombre: string;
  email: string;
  rol: string;
  roles?: string[];
  permisos?: string[];
  // Tema y colores propios guardados en la cuenta.
  apariencia?: Apariencia;
}

/** Cómo atiende el negocio: clases con cupo o citas 1 a 1 con un profesional. */
export type ModalidadServicio = "clases" | "citas";

export interface Terminologia {
  sesion: string;
  miembro: string;
  instructor: string;
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
   * recepción → operación de hoy; instructor → su agenda; alumno → su cuenta.
   */
  const rutaInicio = computed<string>(() => {
    const u = usuario.value;
    if (u === null) {
      return "entrar";
    }
    if (u.rol === "miembro") {
      return "mi-cuenta";
    }
    if (puede("facturacion.ver")) {
      return "panel";
    }
    if (u.rol === "recepcionista" || puede("reservas.gestionar")) {
      return "recepcion";
    }
    if (puede("agenda.ver")) {
      return "agenda";
    }
    return "mi-cuenta";
  });

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
      error.value = mensajeDeError(e, "No se pudo iniciar sesión.");
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
      error.value = mensajeDeError(e, "No se pudo iniciar sesión con Google.");
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
      error.value = mensajeDeError(e, "No se pudo activar la cuenta.");
      throw e;
    } finally {
      cargando.value = false;
    }
  }

  async function registrarAlumno(
    slugEstudio: string,
    datos: {
      nombre: string;
      primer_apellido?: string;
      email: string;
      password: string;
      passwordConfirmation: string;
    },
  ): Promise<void> {
    cargando.value = true;
    error.value = null;
    try {
      const { data } = await api.post<{ data: RespuestaAuth }>(
        `/api/v1/app/${slugEstudio}/registro-alumno`,
        {
          nombre: datos.nombre,
          primer_apellido: datos.primer_apellido || null,
          email: datos.email,
          password: datos.password,
          password_confirmation: datos.passwordConfirmation,
        },
      );
      establecer(data.data);
    } catch (e) {
      error.value = mensajeDeError(e, "No se pudo crear la cuenta.");
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
    modalidad,
    esCitas,
    terminologia,
    iniciarSesion,
    iniciarSesionConGoogle,
    activar,
    registrarAlumno,
    cargarYo,
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
