import { computed, ref } from "vue";

import { api, mensajeDeError } from "@/lib/api";
import type { FormularioPersona } from "@/lib/formularios";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Estado del portal del alumno o cliente, compartido por sus pantallas (Inicio,
 * Reservas, Pagos, Expediente, Configuración): se carga una vez y cada pantalla toma
 * lo suyo. Las acciones (reservar, cancelar, pagar…) recargan en silencio.
 */
/** Estado efectivo de un plan, como lo dice el servidor (no solo por fechas). */
export type EstadoPlan =
  | "vigente"
  | "agotado"
  | "por_empezar"
  | "pausado"
  | "suspendido"
  | "vencido"
  | "cancelado";
export interface Derecho {
  todas_sucursales?: boolean;
  sucursales?: { id: string; nombre: string }[];
  id: string;
  producto?: string | null;
  pausa_hasta?: string | null;
  ilimitado: boolean;
  saldo: number | null;
  disponible: number | null;
  estado: EstadoPlan;
  // Desde y hasta cuándo se puede usar (null = desde siempre / no vence).
  desde?: string | null;
  vence?: string | null;
}
export interface Reserva {
  id: string;
  sesion_id: string | null;
  // Clase o cita (de la sesión): se nombra por lo que es.
  tipo?: "clase" | "cita" | null;
  estado: string;
  oferta: string | null;
  sucursal: string | null;
  inicia_en: string | null;
  termina_en?: string | null;
  instructor?: string | null;
  // Si la agendó para otra persona: quién asiste.
  asiste?: string | null;
  zona_horaria: string | null;
  oferta_expira_en: string | null;
  orden_id: string | null;
  // Cómo llegar a la sucursal.
  mapa_url?: string | null;
}
// Qué partes de su cuenta le sirven (ADR 0091).
export interface PortalCuenta {
  creditos: boolean;
  pase: boolean;
  expediente: boolean;
}
export interface Producto {
  todas_sucursales?: boolean;
  sucursales?: { id: string; nombre: string }[];
  id: string;
  nombre: string;
  tipo: string;
  precio_minor: number;
  moneda: string;
  ilimitado: boolean;
  creditos_incluidos: number | null;
  // Cuánto dura lo que se compra (ADR 0050).
  vigencia_tipo?: "dias" | "meses" | "fin_de_mes" | null;
  vigencia_cantidad?: number | null;
}
export interface Orden {
  id: string;
  estado: string;
  total_minor: number;
  moneda: string;
  fecha: string | null;
  pagada_en?: string | null;
  recurrente?: boolean;
  // Qué se pagó en una línea: sus productos o, si es una cita, el servicio.
  concepto?: string | null;
  // Si es el pago de una cita o clase: cuál, con quién, cuándo y dónde.
  sesion?: {
    tipo: "clase" | "cita";
    servicio: string | null;
    profesional: string | null;
    inicia_en: string;
    zona_horaria: string | null;
    sucursal: string | null;
  } | null;
  lineas: {
    producto: string | null;
    cantidad: number;
    subtotal_minor: number;
  }[];
}
/**
 * Si su plan cubre una clase, dicho por el servidor con la misma regla que al
 * reservar: incluida, solo con membresía, no incluida (y por qué) o de pago.
 */
export interface Cobertura {
  estado: "incluida" | "solo_membresia" | "no_incluida" | "de_pago";
  motivo:
    | "sin_plan"
    | "clase"
    | "pausa"
    | "suspendido"
    | "vigencia"
    | "sucursal"
    | "saldo"
    | null;
  precio_minor?: number;
  moneda?: string;
}
export interface Clase {
  id: string;
  oferta: string | null;
  oferta_id?: string | null;
  actividad?: string | null;
  actividad_id?: string | null;
  sucursal: string | null;
  sucursal_id?: string | null;
  inicia_en: string;
  termina_en?: string | null;
  instructor?: string | null;
  instructor_id?: string | null;
  zona_horaria: string;
  capacidad: number | null;
  ocupados: number;
  cobertura?: Cobertura | null;
}
export interface Waiver {
  id: string;
  titulo: string;
  contenido: string;
  version: number;
}
export interface Politica {
  horas_limite: number;
  penaliza_tarde: boolean;
  penaliza_no_show: boolean;
}
export interface Voucher {
  referencia?: string;
  codigo_barras?: string;
  recibo?: string;
  vence?: string;
}

// Estado único (módulo): todas las pantallas del portal ven lo mismo.
//
// Es de UNA sesión (negocio + token): al cambiar de cuenta o de negocio se vacía
// antes de pintar nada, y las respuestas que lleguen de la sesión anterior se
// descartan (`generacion`). Así, en un equipo compartido, si la carga de la nueva
// sesión falla no queda a la vista nada de la anterior.
const derechos = ref<Derecho[]>([]);
const reservas = ref<Reserva[]>([]);
const clases = ref<Clase[]>([]);
const waivers = ref<Waiver[]>([]);
const productos = ref<Producto[]>([]);
// Todo lo que debe (GET /mi/ordenes/pendientes, completo): no sale de la primera
// página del historial, así un adeudo antiguo no deja de verse. El historial de
// compras se pide aparte, paginado, en Pagos.
const pendientes = ref<Orden[]>([]);
const politica = ref<Politica | null>(null);
const formularios = ref<FormularioPersona[]>([]);
const personaId = ref<string | null>(null);
const portal = ref<PortalCuenta | null>(null);
const asistencias = ref(0);
const pagoEnLinea = ref(false);
const pagoAutomatico = ref(false);
const cargando = ref(false);
const cargado = ref(false);
const error = ref<string | null>(null);
const accionando = ref(false);
// De qué sesión es lo que hay en memoria, y un número que cambia con cada
// reinicio: una respuesta que vuelve con otro número es de una sesión anterior.
let identidadCargada: string | null = null;
let generacion = 0;

/** Vacía el estado del portal (al cambiar de cuenta o de negocio, o al salir). */
export function reiniciarMiCuenta(): void {
  generacion++;
  identidadCargada = null;
  derechos.value = [];
  reservas.value = [];
  clases.value = [];
  waivers.value = [];
  productos.value = [];
  pendientes.value = [];
  politica.value = null;
  formularios.value = [];
  personaId.value = null;
  portal.value = null;
  asistencias.value = 0;
  pagoEnLinea.value = false;
  pagoAutomatico.value = false;
  cargando.value = false;
  cargado.value = false;
  error.value = null;
  accionando.value = false;
}

/** La sesión del portal: negocio + token (cambia con cada inicio de sesión). */
export function identidadDeSesion(
  slug: string | null,
  bearer: string | null,
): string | null {
  return slug !== null && bearer !== null ? `${slug}|${bearer}` : null;
}

export function useMiCuenta() {
  const sesion = useSesionTenantStore();
  const base = computed(() => `/api/v1/app/${sesion.slug}`);
  const identidad = () => identidadDeSesion(sesion.slug, sesion.bearer);

  // Lo que hay en memoria es de otra sesión: se vacía antes de que la pantalla lo
  // pinte (esto corre en el setup, antes del primer render).
  if (identidadCargada !== null && identidadCargada !== identidad()) {
    reiniciarMiCuenta();
  }

  async function cargarFormularios(): Promise<void> {
    const mia = generacion;
    try {
      const { data } = await api.get<{
        data: { persona_id: string; formularios: FormularioPersona[] };
      }>(`${base.value}/mi/formularios`);
      if (mia !== generacion) {
        return; // De una sesión anterior.
      }
      personaId.value = data.data.persona_id;
      formularios.value = data.data.formularios;
    } catch {
      // Sin perfil de alumno: no hay formularios que llenar.
      if (mia === generacion) {
        formularios.value = [];
      }
    }
  }

  /** `silencioso`: recarga sin ocultar la pantalla. */
  async function cargar(silencioso = false): Promise<void> {
    const id = identidad();
    // Otra sesión: nada de la anterior se queda a la vista, ni si esta falla.
    if (id !== identidadCargada) {
      reiniciarMiCuenta();
      silencioso = false;
    }
    const mia = generacion;
    cargando.value = !silencioso;
    error.value = null;
    try {
      const [p, a, w, pr, o] = await Promise.all([
        api.get<{
          data: {
            derechos: Derecho[];
            reservas: Reserva[];
            politica_cancelacion: Politica | null;
            portal?: PortalCuenta;
            asistencias_30_dias?: number;
            pago_en_linea?: boolean;
            pago_automatico?: boolean;
          };
        }>(`${base.value}/mi/perfil`),
        // Las próximas clases (para el Inicio) no tumban la cuenta si fallan: el
        // calendario de Reservas pide su periodo aparte y muestra su propio error.
        api
          .get<{ data: Clase[] }>(`${base.value}/mi/agenda`)
          .catch(() => ({ data: { data: [] as Clase[] } })),
        api.get<{ data: Waiver[] }>(`${base.value}/mi/waivers`),
        api.get<{ data: Producto[] }>(`${base.value}/mi/productos`),
        api.get<{ data: Orden[] }>(`${base.value}/mi/ordenes/pendientes`),
      ]);
      if (mia !== generacion) {
        return; // Llegó tarde, de una sesión anterior: se descarta.
      }
      derechos.value = p.data.data.derechos;
      reservas.value = p.data.data.reservas;
      politica.value = p.data.data.politica_cancelacion;
      portal.value = p.data.data.portal ?? null;
      asistencias.value = p.data.data.asistencias_30_dias ?? 0;
      pagoEnLinea.value = p.data.data.pago_en_linea === true;
      pagoAutomatico.value = p.data.data.pago_automatico === true;
      clases.value = a.data.data;
      waivers.value = w.data.data;
      productos.value = pr.data.data;
      pendientes.value = o.data.data;
      cargado.value = true;
      identidadCargada = id;
    } catch (e) {
      if (mia === generacion) {
        error.value = mensajeDeError(e);
      }
    } finally {
      if (mia === generacion) {
        cargando.value = false;
      }
    }
    if (mia === generacion) {
      await cargarFormularios();
    }
  }

  /** Carga si aún no hay datos de esta sesión; si ya los hay, recarga en silencio. */
  async function asegurar(): Promise<void> {
    await cargar(cargado.value && identidadCargada === identidad());
  }

  async function accion(fn: () => Promise<unknown>): Promise<boolean> {
    accionando.value = true;
    error.value = null;
    try {
      await fn();
      await cargar(true);
      return true;
    } catch (e) {
      error.value = mensajeDeError(e);
      return false;
    } finally {
      accionando.value = false;
    }
  }

  const reservadas = computed(
    () =>
      new Set(
        reservas.value
          .map((r) => r.sesion_id)
          .filter((id): id is string => id !== null),
      ),
  );
  // Lo que pide atención: firmar, aceptar un lugar ofrecido, pagar.
  const porPagar = computed(() => pendientes.value);
  const proximas = computed(() =>
    reservas.value
      .filter(
        (r) =>
          r.inicia_en !== null &&
          ["confirmada", "pendiente_pago", "ofrecida", "en_espera"].includes(
            r.estado,
          ),
      )
      .sort((a, b) => (a.inicia_en ?? "").localeCompare(b.inicia_en ?? "")),
  );

  return {
    base,
    derechos,
    reservas,
    clases,
    waivers,
    productos,
    politica,
    formularios,
    personaId,
    portal,
    asistencias,
    pagoEnLinea,
    pagoAutomatico,
    cargado,
    cargando,
    error,
    accionando,
    reservadas,
    porPagar,
    proximas,
    cargar,
    asegurar,
    cargarFormularios,
    reservar: (c: Clase, esperar: boolean) =>
      accion(() =>
        api.post(`${base.value}/mi/reservas`, { sesion_id: c.id, esperar }),
      ),
    cancelar: (r: Reserva) =>
      accion(() => api.post(`${base.value}/mi/reservas/${r.id}/cancelar`, {})),
    aceptar: (r: Reserva) =>
      accion(() => api.post(`${base.value}/mi/reservas/${r.id}/aceptar`, {})),
    aceptarWaiver: (w: Waiver) =>
      accion(() => api.post(`${base.value}/mi/waivers/${w.id}/aceptar`, {})),
    comprar: (p: Producto) =>
      accion(() =>
        api.post(`${base.value}/mi/ordenes`, {
          items: [{ producto_id: p.id, cantidad: 1 }],
        }),
      ),
  };
}

/** Fecha y hora cortas en la zona de la sede. */
export function cuandoCorto(iso: string | null, zona: string | null): string {
  if (!iso) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona ?? undefined,
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}

export function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

/** Cómo se presenta la cobertura de una clase: su píldora y, si no entra, por qué. */
export interface CoberturaVista {
  texto: string;
  tono: string;
  motivo: string | null;
  // Se puede reservar con lo que tiene (o pagando la clase).
  reservable: boolean;
}
export function presentarCobertura(
  c: Cobertura | null | undefined,
  t: (clave: string, valores?: Record<string, unknown>) => string,
): CoberturaVista | null {
  if (!c) {
    return null;
  }
  switch (c.estado) {
    case "incluida":
      return {
        texto: t("portal.cobertura.incluida"),
        tono: "var(--exito)",
        motivo: null,
        reservable: true,
      };
    case "de_pago":
      return {
        texto: c.precio_minor
          ? t("portal.cobertura.dePagoPrecio", {
              precio: dinero(c.precio_minor, c.moneda ?? "MXN"),
            })
          : t("portal.cobertura.dePago"),
        tono: "var(--primario)",
        motivo: null,
        reservable: true,
      };
    case "solo_membresia":
      return {
        texto: t("portal.cobertura.soloMembresia"),
        tono: "var(--aviso)",
        motivo: t("portal.cobertura.motivos.soloMembresia"),
        reservable: false,
      };
    default:
      return {
        texto: t("portal.cobertura.noIncluida"),
        tono: "var(--texto-suave)",
        motivo: c.motivo ? t(`portal.cobertura.motivos.${c.motivo}`) : null,
        reservable: false,
      };
  }
}
