<script setup lang="ts">
import { esInstructor } from "@/lib/roles";
import { computed, onMounted, ref, toRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import BuscarPersona from "@/components/BuscarPersona.vue";
import BotonImportar from "@/components/BotonImportar.vue";
import AgendaClasesSemana from "@/components/AgendaClasesSemana.vue";
import AgendaKpis from "@/components/AgendaKpis.vue";
import type {
  Indicador,
  Tendencia,
} from "@/components/TarjetasIndicadores.vue";
import AgendaMes from "@/components/AgendaMes.vue";
import AgendaProfesionales from "@/components/AgendaProfesionales.vue";
import CambiarHorario from "@/components/CambiarHorario.vue";
import CambiarSerie from "@/components/CambiarSerie.vue";
import ConfirmarCancelacion from "@/components/ConfirmarCancelacion.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import AvatarIniciales from "@/components/AvatarIniciales.vue";
import IconoNav from "@/components/IconoNav.vue";
import LeyendaSucursal from "@/components/LeyendaSucursal.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PanelCita from "@/components/PanelCita.vue";
import PanelNuevaCita from "@/components/PanelNuevaCita.vue";
import {
  kpisCitas,
  kpisClases,
  COLOR_ESTADO_CITA,
  estadoCita,
  pagoCita,
  tonoServicio,
  type CitaTitular,
  type SesionAgenda,
  type BloqueoAgenda,
  type VentanaAtencion,
} from "@/lib/agenda";
import { puedeEntrar } from "@/lib/acceso";
import { api, mensajeDeError } from "@/lib/api";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { confirmarAsistencia } from "@/lib/confirmarAsistencia";
import { trackEvent } from "@/lib/analytics";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Oferta {
  id: string;
  nombre: string;
  precio_clase_minor?: number | null;
  politica_reserva?: string;
  duracion_minutos?: number | null;
}
interface Sucursal {
  id: string;
  nombre: string;
  zona_horaria: string;
}
interface Sesion {
  id: string;
  oferta: string | null;
  oferta_id: string | null;
  oferta_lugares: number;
  oferta_precio_clase: number | null;
  instructor: string | null;
  instructor_id: string | null;
  sala: string | null;
  sucursal?: string | null;
  sucursal_id?: string | null;
  recurso_id: string | null;
  inicia_en: string;
  termina_en: string;
  zona_horaria: string;
  capacidad: number | null;
  ocupados: number;
  en_espera: number;
  estado: string;
  // Cita 1 a 1: a quién se atiende y en qué va (null en clases).
  tipo?: "clase" | "cita";
  cita?: CitaTitular | null;
  // Clase recurrente de la que salió y su fecha en ella (2.5).
  serie_id?: string | null;
  fecha_serie?: string | null;
  serie_dias?: number[] | null;
}
interface Recurso {
  id: string;
  nombre: string;
  sucursal_id: string | null;
  activo: boolean;
}
interface Reserva {
  transferencias?: {
    id: string;
    de: string | null;
    a: string | null;
    por: string | null;
    fecha: string;
  }[];
  id: string;
  estado: string;
  canal: string;
  lugar: number | null;
  persona: string | null;
  primera_vez: boolean;
  unidades: number;
  asistencia: string | null;
}
interface ReglaCanal {
  id: string;
  canal: string;
  cupos: number;
  liberar_horas_antes: number;
  activa: boolean;
}
interface Checkin {
  id: string;
  proveedor: string;
  usuario: string | null;
  estado: string;
  registrado_en: string;
}

const sesion = useSesionTenantStore();
const { t } = useI18n();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("agenda.gestionar"));
// Borrar va aparte de gestionar (ADR 0077).
const puedeEliminar = computed(() => sesion.puede("agenda.eliminar"));
// Lugares y precio por clase viven en la oferta: los edita quien gestiona el catálogo.
const puedeCatalogo = computed(() => sesion.puede("catalogo.gestionar"));
const puedeReservar = computed(() => sesion.puede("reservas.gestionar"));
const puedeMarcar = computed(() => sesion.puede("asistencia.marcar"));
const puedeCheckin = computed(() => sesion.puede("checkins.registrar"));
// La agenda se ve con solo agenda.ver: el catálogo, las sucursales y los alumnos se
// cargan aparte y solo si el rol los puede ver.
const puedeVerCatalogo = computed(() => sesion.puede("catalogo.ver"));
const puedeVerSucursales = computed(() => sesion.puede("sucursales.ver"));
const puedeVerMiembros = computed(() => sesion.puede("miembros.ver"));
const puedeVerReservas = computed(() => sesion.puede("reservas.ver"));

// Canales de reserva (booking source) para R20.
const CANALES = [
  "directo",
  "wellhub",
  "totalpass",
  "classpass",
  "otro",
] as const;

const ofertas = ref<Oferta[]>([]);
const sucursales = ref<Sucursal[]>([]);
const sesiones = ref<Sesion[]>([]);
const instructores = ref<{ id: string; nombre: string }[]>([]);
const recursos = ref<Recurso[]>([]);
// Horario de atención de cada profesional (sombrea lo que queda fuera en citas).
const ventanas = ref<VentanaAtencion[]>([]);
// Comida, vacaciones o cierres de la semana visible (2.2).
const bloqueos = ref<BloqueoAgenda[]>([]);
const cargando = ref(true);
const cargandoSesiones = ref(false);
const error = ref<string | null>(null);
// Lo que no se pudo cargar de las listas de apoyo (no lo borra recargar sesiones).
const errorReferencias = ref<string | null>(null);
// Fechas de una clase recurrente que no se pudieron generar (y por qué).
const avisoSerie = ref<string | null>(null);

// ---- Calendario (semana / dia / por profesional) ----
// Citas: el día en columnas por profesional (o la semana). Clases: la semana con
// cupos (o la lista del día).
type Vista = "semana" | "dia" | "profesionales" | "mes";
const opcionesVista = computed<Vista[]>(() =>
  sesion.esCitas
    ? ["profesionales", "semana", "mes"]
    : ["semana", "dia", "mes"],
);
const vista = ref<Vista>(sesion.esCitas ? "profesionales" : "semana");
// `?fecha=AAAA-MM-DD` abre la agenda en ese día (p. ej. «Ver en agenda» desde el
// detalle de un cliente); sin ella, hoy.
const fechaPedida = useRoute()?.query.fecha;
const fechaInicial =
  typeof fechaPedida === "string" && /^\d{4}-\d{2}-\d{2}$/.test(fechaPedida)
    ? new Date(`${fechaPedida}T12:00:00`)
    : new Date();
const semanaInicio = ref(lunesDe(fechaInicial));
// Vista mensual: primer día del mes que se muestra.
const mesInicio = ref(
  new Date(fechaInicial.getFullYear(), fechaInicial.getMonth(), 1),
);
const diaSel = ref(isoDe(fechaInicial));
const sucursalFiltro = ref("");
const instructorFiltro = ref("");
// Quien imparte (sin un rol del equipo) solo ve sus clases o citas: elegir a otro
// profesional dejaría la agenda vacía, así que no se ofrece.
const soloLoSuyo = computed(() => esInstructor(sesion.usuario));
// Por servicio o clase (2.6).
const ofertaFiltro = ref("");
// En el teléfono los filtros van plegados tras un botón: primero la fecha y las citas.
const filtrosAbiertos = ref(false);
const filtrosActivos = computed(
  () =>
    [sucursalFiltro.value, instructorFiltro.value, ofertaFiltro.value].filter(
      (v) => v !== "",
    ).length,
);

function pad2(n: number): string {
  return n < 10 ? `0${n}` : `${n}`;
}
function isoDe(d: Date): string {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}
function lunesDe(d: Date): Date {
  const base = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const dow = (base.getDay() + 6) % 7; // 0 = lunes
  base.setDate(base.getDate() - dow);
  return base;
}
function sumarDias(d: Date, n: number): Date {
  const r = new Date(d);
  r.setDate(r.getDate() + n);
  return r;
}

const dias = computed(() =>
  Array.from({ length: 7 }, (_, i) => {
    const fecha = sumarDias(semanaInicio.value, i);
    return {
      fecha,
      iso: isoDe(fecha),
      nombre: new Intl.DateTimeFormat("es-MX", { weekday: "short" }).format(
        fecha,
      ),
      dia: fecha.getDate(),
      esHoy: isoDe(fecha) === isoDe(new Date()),
    };
  }),
);

const rangoTexto = computed(() => {
  if (vista.value === "mes") {
    return new Intl.DateTimeFormat("es-MX", {
      month: "long",
      year: "numeric",
    }).format(mesInicio.value);
  }
  const a = semanaInicio.value;
  const b = sumarDias(a, 6);
  const fmt = (d: Date, opts: Intl.DateTimeFormatOptions): string =>
    new Intl.DateTimeFormat("es-MX", opts).format(d);
  return `${fmt(a, { day: "numeric", month: "short" })} – ${fmt(b, { day: "numeric", month: "short", year: "numeric" })}`;
});

function fechaLocalSesion(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: zona,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(iso));
}
function horaCorta(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}

// Lo que se ve: el servidor ensancha el rango un día antes y dos después (zonas
// horarias), así que se recorta a las fechas LOCALES del periodo. Calendario,
// indicadores y leyenda salen de esta misma lista (mismo periodo y filtros).
// Profesional y servicio se filtran aquí; la sucursal ya la filtra el servidor.
function visiblesEn(lista: Sesion[], desde: string, hasta: string): Sesion[] {
  return lista.filter((s) => {
    const fecha = fechaLocalSesion(s.inicia_en, s.zona_horaria);
    return (
      fecha >= desde &&
      fecha <= hasta &&
      (instructorFiltro.value === "" ||
        s.instructor_id === instructorFiltro.value) &&
      (ofertaFiltro.value === "" || s.oferta_id === ofertaFiltro.value)
    );
  });
}
const sesionesVisibles = computed(() =>
  visiblesEn(sesiones.value, rangoCarga.value.desde, rangoCarga.value.hasta),
);
// La semana anterior, con los mismos filtros: contra ella se comparan los
// indicadores (en citas, el mismo día de la semana pasada).
const sesionesPrevias = ref<Sesion[]>([]);
const rangoPrevio = computed(() => ({
  desde: isoDe(sumarDias(new Date(`${dias.value[0].iso}T12:00:00`), -7)),
  hasta: isoDe(sumarDias(new Date(`${dias.value[6].iso}T12:00:00`), -7)),
}));
const visiblesPrevias = computed(() =>
  visiblesEn(
    sesionesPrevias.value,
    rangoPrevio.value.desde,
    rangoPrevio.value.hasta,
  ),
);
function sesionesDe(iso: string): Sesion[] {
  return sesionesVisibles.value
    .filter((s) => fechaLocalSesion(s.inicia_en, s.zona_horaria) === iso)
    .sort((a, b) => a.inicia_en.localeCompare(b.inicia_en));
}
const diaSelInfo = computed(
  () => dias.value.find((d) => d.iso === diaSel.value) ?? dias.value[0],
);

// La tira de días del teléfono deja a la vista el día elegido (hoy puede ser domingo).
const tiraDias = ref<HTMLElement | null>(null);
watch(
  [diaSel, () => dias.value[0]?.iso, tiraDias],
  () => {
    const tira = tiraDias.value;
    const boton = tira?.querySelector<HTMLElement>('[aria-pressed="true"]');
    if (tira && boton) {
      tira.scrollLeft =
        boton.offsetLeft -
        tira.offsetLeft -
        (tira.clientWidth - boton.offsetWidth) / 2;
    }
  },
  { flush: "post" },
);

/**
 * Datos de una reserva del roster en una línea: el estado solo si no es el normal
 * (confirmada), en ámbar si pide atención; lo demás en gris.
 */
function lineaRoster(r: Reserva): { texto: string; color: string }[] {
  const out: { texto: string; color: string }[] = [];
  if (r.estado !== "confirmada") {
    out.push({
      texto: t(`agenda.roster.${r.estado}`),
      color:
        r.estado === "en_espera" || r.estado === "ofrecida"
          ? "var(--aviso)"
          : "var(--texto-suave)",
    });
  }
  if (r.asistencia) {
    out.push({
      texto: t(`agenda.roster.${r.asistencia}`),
      color: "var(--texto-suave)",
    });
  }
  if (r.canal && r.canal !== "directo") {
    out.push({
      texto: t(`agenda.canales.${r.canal}`),
      color: "var(--texto-suave)",
    });
  }
  if (r.primera_vez) {
    out.push({ texto: t("agenda.roster.primeraVez"), color: "var(--aviso)" });
  }
  if (r.lugar) {
    out.push({
      texto: t("agenda.lugares.lugarN", { n: r.lugar }),
      color: "var(--texto-suave)",
    });
  }
  return out;
}

function completo(s: Sesion): boolean {
  return s.capacidad !== null && s.ocupados >= s.capacidad;
}

// ---- Cuadricula horaria (vista semana escritorio) ----
const HORA_ALTO = 52; // px por hora

function minutosLocal(iso: string, zona: string): number {
  const partes = new Intl.DateTimeFormat("en-GB", {
    timeZone: zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).formatToParts(new Date(iso));
  const h = Number(partes.find((p) => p.type === "hour")?.value ?? "0");
  const m = Number(partes.find((p) => p.type === "minute")?.value ?? "0");
  return h * 60 + m;
}
function duracionMin(s: Sesion): number {
  return Math.round(
    (new Date(s.termina_en).getTime() - new Date(s.inicia_en).getTime()) /
      60000,
  );
}

// Rango de horas visible: por defecto 8–21, ampliado para abarcar todas las clases.
const rango = computed(() => {
  const visibles = sesionesVisibles.value.filter((s) =>
    dias.value.some(
      (d) => d.iso === fechaLocalSesion(s.inicia_en, s.zona_horaria),
    ),
  );
  let ini = 8 * 60;
  let fin = 21 * 60;
  for (const s of visibles) {
    const desde = minutosLocal(s.inicia_en, s.zona_horaria);
    const hasta = desde + Math.max(15, duracionMin(s));
    ini = Math.min(ini, Math.floor(desde / 60) * 60);
    fin = Math.max(fin, Math.ceil(hasta / 60) * 60);
  }
  return { inicioMin: ini, finMin: Math.min(fin, 24 * 60) };
});
const horas = computed(() => {
  const out: { min: number; etiqueta: string }[] = [];
  for (let m = rango.value.inicioMin; m < rango.value.finMin; m += 60) {
    out.push({ min: m, etiqueta: `${pad2(Math.floor(m / 60))}:00` });
  }
  return out;
});
const totalAlto = computed(
  () => ((rango.value.finMin - rango.value.inicioMin) / 60) * HORA_ALTO,
);

type Bloque = {
  sesion: Sesion;
  top: number;
  alto: number;
  izq: number;
  ancho: number;
};

// Posiciona las clases de un día y reparte en carriles las que se solapan.
function bloquesDe(iso: string): Bloque[] {
  const items = sesionesDe(iso)
    .map((s) => {
      const ini = minutosLocal(s.inicia_en, s.zona_horaria);
      return { s, ini, fin: ini + Math.max(15, duracionMin(s)) };
    })
    .sort((a, b) => a.ini - b.ini || a.fin - b.fin);

  const bloques: Bloque[] = [];
  let grupo: { s: Sesion; ini: number; fin: number; carril: number }[] = [];
  let grupoFin = -1;

  const cerrar = (): void => {
    const carriles: number[] = []; // fin de la última clase en cada carril
    for (const it of grupo) {
      let carril = carriles.findIndex((f) => f <= it.ini);
      if (carril === -1) {
        carril = carriles.length;
        carriles.push(it.fin);
      } else {
        carriles[carril] = it.fin;
      }
      it.carril = carril;
    }
    const n = Math.max(1, carriles.length);
    for (const it of grupo) {
      bloques.push({
        sesion: it.s,
        top: ((it.ini - rango.value.inicioMin) / 60) * HORA_ALTO,
        alto: Math.max(22, ((it.fin - it.ini) / 60) * HORA_ALTO - 2),
        izq: (it.carril / n) * 100,
        ancho: (1 / n) * 100,
      });
    }
  };

  for (const it of items) {
    if (grupo.length > 0 && it.ini >= grupoFin) {
      cerrar();
      grupo = [];
      grupoFin = -1;
    }
    grupo.push({ ...it, carril: 0 });
    grupoFin = Math.max(grupoFin, it.fin);
  }
  if (grupo.length > 0) {
    cerrar();
  }
  return bloques;
}

// Estado visual de la clase (color + etiqueta): cancelada / completa / programada.
// Color estable por TIPO de clase (identidad visual; el color lo lleva la clase,
// no la decoración). Hash del nombre de la oferta → paleta de acabados.
// Color del servicio: el mismo en todas las vistas de la agenda.
function colorTipo(s: Sesion): string {
  return tonoServicio(s.oferta_id, catalogo.value, s.oferta).tinta;
}
function pctOcupacion(s: Sesion): number | null {
  return s.capacidad !== null && s.capacidad > 0
    ? Math.round((s.ocupados / s.capacidad) * 100)
    : null;
}
// Estado perceptible por ocupación: disponible / casi (>=80%) / llena / cancelada.
function estadoAgenda(
  s: Sesion,
): "cancelada" | "llena" | "casi" | "disponible" {
  if (s.estado !== "programada") {
    return "cancelada";
  }
  if (completo(s)) {
    return "llena";
  }
  const p = pctOcupacion(s);
  return p !== null && p >= 80 ? "casi" : "disponible";
}
const COLOR_ESTADO: Record<string, string> = {
  disponible: "var(--exito)",
  casi: "#f59e0b",
  llena: "var(--error)",
  cancelada: "var(--texto-suave)",
};

async function cargarReferencias(): Promise<void> {
  cargando.value = true;
  errorReferencias.value = null;
  const pedir = async <T,>(
    ruta: string,
    params?: Record<string, string>,
  ): Promise<T> =>
    (await api.get<{ data: T }>(`${base.value}${ruta}`, { params })).data.data;
  // Cada lista por separado: si una falla, las demás y la agenda siguen.
  const tareas: Promise<unknown>[] = [
    // Profesionales (id + nombre) para filtrar y para las columnas por profesional.
    pedir<{ id: string; nombre: string }[]>("/instructores").then((d) => {
      instructores.value = d;
    }),
  ];
  if (puedeVerCatalogo.value) {
    tareas.push(
      pedir<Oferta[]>("/ofertas").then((d) => {
        ofertas.value = d;
      }),
    );
  }
  if (puedeVerSucursales.value) {
    tareas.push(
      pedir<Sucursal[]>("/sucursales").then((d) => {
        sucursales.value = d;
      }),
    );
  }
  if (sesion.esCitas) {
    tareas.push(
      pedir<VentanaAtencion[]>("/horarios-atencion").then((d) => {
        ventanas.value = d;
      }),
    );
  }
  if (puedeGestionar.value) {
    tareas.push(
      pedir<Recurso[]>("/recursos").then((d) => {
        recursos.value = d.filter((x) => x.activo);
      }),
    );
  }
  const fallida = (await Promise.allSettled(tareas)).find(
    (r): r is PromiseRejectedResult => r.status === "rejected",
  );
  if (fallida !== undefined) {
    errorReferencias.value = mensajeDeError(fallida.reason);
  }
  cargando.value = false;
}

async function cargarSesiones(): Promise<void> {
  cargandoSesiones.value = true;
  error.value = null;
  try {
    const params: Record<string, string> = { ...rangoCarga.value };
    if (sucursalFiltro.value !== "") {
      params.sucursal_id = sucursalFiltro.value;
    }
    const [{ data }, previas] = await Promise.all([
      api.get<{ data: Sesion[] }>(`${base.value}/sesiones`, { params }),
      // Para la tendencia de los indicadores; si falla, solo no se muestra.
      vista.value === "mes"
        ? Promise.resolve(null)
        : api
            .get<{ data: Sesion[] }>(`${base.value}/sesiones`, {
              params: { ...params, ...rangoPrevio.value },
            })
            .catch(() => null),
    ]);
    sesiones.value = data.data;
    sesionesPrevias.value = previas?.data.data ?? [];
    if (sesion.esCitas) {
      const b = await api.get<{ data: BloqueoAgenda[] }>(
        `${base.value}/bloqueos`,
        { params: { desde: params.desde, hasta: params.hasta } },
      );
      bloqueos.value = b.data.data;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargandoSesiones.value = false;
  }
}

// Lo que se pide al servidor: la semana, o las semanas completas del mes.
const rangoCarga = computed(() => {
  if (vista.value !== "mes") {
    return { desde: dias.value[0].iso, hasta: dias.value[6].iso };
  }
  const inicio = lunesDe(mesInicio.value);
  const ultimo = new Date(
    mesInicio.value.getFullYear(),
    mesInicio.value.getMonth() + 1,
    0,
  );
  return {
    desde: isoDe(inicio),
    hasta: isoDe(sumarDias(lunesDe(ultimo), 6)),
  };
});

// Recarga las sesiones al cambiar de rango (semana o mes) o de sucursal.
watch(
  [() => rangoCarga.value.desde, () => rangoCarga.value.hasta, sucursalFiltro],
  cargarSesiones,
);

function irSemana(delta: number): void {
  // Preserva el día de la semana seleccionado (para que la vista de día en móvil
  // avance al día equivalente de la nueva semana, no se quede en la anterior).
  const offset = dias.value.findIndex((d) => d.iso === diaSel.value);
  semanaInicio.value = sumarDias(semanaInicio.value, delta * 7);
  diaSel.value = isoDe(sumarDias(semanaInicio.value, offset >= 0 ? offset : 0));
}
function irHoy(): void {
  semanaInicio.value = lunesDe(new Date());
  diaSel.value = isoDe(new Date());
  mesInicio.value = new Date(
    new Date().getFullYear(),
    new Date().getMonth(),
    1,
  );
}
function irMes(delta: number): void {
  mesInicio.value = new Date(
    mesInicio.value.getFullYear(),
    mesInicio.value.getMonth() + delta,
    1,
  );
}
// Del mes a un día: la vista de día (clases) o por profesional (citas).
function verDia(iso: string): void {
  const [a, m, d] = iso.split("-").map(Number);
  semanaInicio.value = lunesDe(new Date(a, m - 1, d));
  diaSel.value = iso;
  vista.value = sesion.esCitas ? "profesionales" : "dia";
}
// Por profesional se navega por DÍA (cambia de semana al cruzarla).
function irDia(delta: number): void {
  const [a, m, d] = diaSel.value.split("-").map(Number);
  const nuevo = new Date(a, m - 1, d + delta);
  if (isoDe(lunesDe(nuevo)) !== isoDe(semanaInicio.value)) {
    semanaInicio.value = lunesDe(nuevo);
  }
  diaSel.value = isoDe(nuevo);
}
function irPaso(delta: number): void {
  if (vista.value === "profesionales") {
    irDia(delta);
  } else if (vista.value === "mes") {
    irMes(delta);
  } else {
    irSemana(delta);
  }
}
const diaTexto = computed(() => {
  const [a, m, d] = diaSel.value.split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(a, m - 1, d));
});

// ---- Agenda visual: catálogo (colores), zona, profesionales e indicadores ----
// Servicios y sucursales para filtros, colores y zona: los del catálogo si el rol
// los puede ver; si no, los que aparecen en las sesiones que ve.
const ofertasAgenda = computed<{ id: string; nombre: string }[]>(() =>
  puedeVerCatalogo.value
    ? ofertas.value
    : unicos(sesiones.value, (s) =>
        s.oferta_id !== null && s.oferta !== null
          ? { id: s.oferta_id, nombre: s.oferta }
          : null,
      ),
);
const sucursalesAgenda = computed<Sucursal[]>(() =>
  puedeVerSucursales.value
    ? sucursales.value
    : unicos(sesiones.value, (s) =>
        s.sucursal_id && s.sucursal
          ? {
              id: s.sucursal_id,
              nombre: s.sucursal,
              zona_horaria: s.zona_horaria,
            }
          : null,
      ),
);
function unicos<T extends { id: string }>(
  lista: Sesion[],
  tomar: (s: Sesion) => T | null,
): T[] {
  const vistos = new Map<string, T>();
  for (const s of lista) {
    const x = tomar(s);
    if (x !== null && !vistos.has(x.id)) {
      vistos.set(x.id, x);
    }
  }
  return [...vistos.values()].sort((a, b) => a.id.localeCompare(b.id));
}
const catalogo = computed(() => ofertasAgenda.value.map((o) => o.id));
const zonaAgenda = computed(
  () =>
    sucursalesAgenda.value.find((s) => s.id === sucursalFiltro.value)
      ?.zona_horaria ??
    sesiones.value[0]?.zona_horaria ??
    sucursalesAgenda.value[0]?.zona_horaria ??
    "America/Mexico_City",
);
// Leyenda: solo los servicios/clases que aparecen en lo que se está viendo.
const leyenda = computed(() => {
  const presentes = new Set(sesionesVisibles.value.map((s) => s.oferta_id));
  return ofertasAgenda.value
    .filter((o) => presentes.has(o.id))
    .map((o) => ({
      id: o.id,
      nombre: o.nombre,
      ...tonoServicio(o.id, catalogo.value),
    }));
});
const profesionalesVisibles = computed(() =>
  instructorFiltro.value === ""
    ? instructores.value
    : instructores.value.filter((i) => i.id === instructorFiltro.value),
);
function dineroMx(minor: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    maximumFractionDigits: 0,
  }).format(minor / 100);
}
/**
 * Cómo va un indicador contra la semana anterior: porcentaje de cambio, o puntos
 * si el indicador ya es un porcentaje. Sin dato anterior (o en cero), no hay.
 */
function tendencia(
  actual: number | null,
  previo: number | null,
  mejorSiSube: boolean,
  puntos = false,
): Tendencia | undefined {
  if (actual === null || previo === null || (!puntos && previo === 0)) {
    return undefined;
  }
  const cambio = puntos
    ? actual - previo
    : Math.round(((actual - previo) / previo) * 100);
  const direccion = cambio > 0 ? "sube" : cambio < 0 ? "baja" : "igual";
  return {
    direccion,
    texto: puntos
      ? t("agendaVisual.kpis.puntos", { n: Math.abs(cambio) })
      : `${Math.abs(cambio)}%`,
    buena:
      direccion === "igual" ? null : (direccion === "sube") === mejorSiSube,
    titulo: sesion.esCitas
      ? t("agendaVisual.kpis.vsDia")
      : t("agendaVisual.kpis.vsSemana"),
  };
}

const tarjetasKpi = computed<Indicador[]>(() => {
  const ahora = new Date();
  if (sesion.esCitas) {
    const delDia = (lista: Sesion[], iso: string): Sesion[] =>
      lista.filter(
        (s) =>
          s.tipo === "cita" &&
          fechaLocalSesion(s.inicia_en, s.zona_horaria) === iso,
      );
    const k = kpisCitas(delDia(sesionesVisibles.value, diaSel.value), ahora);
    const mismoDiaAntes = isoDe(
      sumarDias(new Date(`${diaSel.value}T12:00:00`), -7),
    );
    const previas = delDia(visiblesPrevias.value, mismoDiaAntes);
    return [
      {
        clave: "citas",
        icono: "agenda",
        tono: "azul",
        valor: String(k.citas),
        etiqueta: t("agendaVisual.kpis.citas"),
        tendencia: tendencia(
          k.citas,
          sesionesPrevias.value.length > 0 ? previas.length : null,
          true,
        ),
      },
      {
        clave: "local",
        icono: "personas",
        tono: "verde",
        valor: String(k.enLocal),
        etiqueta: t("agendaVisual.kpis.enLocal"),
      },
      {
        clave: "cobrar",
        icono: "dinero",
        tono: "naranja",
        valor: dineroMx(k.porCobrarMinor),
        etiqueta: t("agendaVisual.kpis.porCobrar", { n: k.pendientesPago }),
      },
      {
        clave: "ausentes",
        icono: "ausente",
        tono: "morado",
        valor: String(k.noAsistieron),
        etiqueta: t("agendaVisual.kpis.noAsistieron"),
      },
    ];
  }
  const k = kpisClases(sesionesVisibles.value, ahora);
  const hayPrevias = sesionesPrevias.value.length > 0;
  const p = kpisClases(visiblesPrevias.value, ahora);
  return [
    {
      clave: "clases",
      icono: "agenda",
      tono: "azul",
      valor: String(k.clases),
      etiqueta: t("agendaVisual.kpis.clases"),
      tendencia: hayPrevias ? tendencia(k.clases, p.clases, true) : undefined,
    },
    {
      clave: "ocupacion",
      icono: "pulso",
      tono: "verde",
      valor: k.ocupacionPct !== null ? `${k.ocupacionPct}%` : "—",
      etiqueta: t("agendaVisual.kpis.ocupacion"),
      tendencia: hayPrevias
        ? tendencia(k.ocupacionPct, p.ocupacionPct, true, true)
        : undefined,
    },
    {
      clave: "reservados",
      icono: "personas",
      tono: "morado",
      valor: String(k.reservados),
      etiqueta: t("agendaVisual.kpis.reservados"),
      tendencia: hayPrevias
        ? tendencia(k.reservados, p.reservados, true)
        : undefined,
    },
    {
      clave: "espera",
      icono: "reloj",
      tono: "naranja",
      valor: String(k.enEspera),
      etiqueta: t("agendaVisual.kpis.enEspera"),
      // Más gente esperando es demanda sin atender: pide abrir otra clase.
      tendencia: hayPrevias
        ? tendencia(k.enEspera, p.enEspera, false)
        : undefined,
    },
    {
      clave: "libres",
      icono: "etiqueta",
      tono: "rosa",
      valor: String(k.libresPorLlenar),
      etiqueta: t("agendaVisual.kpis.libres"),
    },
  ];
});

// ---- Panel de detalle (reservas + asistencia + check-ins) ----
const detalle = ref<Sesion | null>(null);
const roster = ref<Reserva[]>([]);
const cargandoRoster = ref(false);
const reservarModel = ref({
  miembroId: "",
  esperar: false,
  canal: "directo",
  lugar: null as number | null,
});
// Config de la oferta en el drawer: mapa de lugares (R4) + precio de clase (R30).
const lugaresModel = ref({ lugares: 0, precio: 0 });
const guardandoLugares = ref(false);
// Transferir (regalar) el lugar de una reserva a otro miembro (R9): selector inline por fila.
const transferirModel = ref({ reservaId: "", personaId: "" });
// Cupos por canal / marketplace (R20): reglas de la oferta de la sesion abierta.
const reglasCanal = ref<ReglaCanal[]>([]);
const reglaCanalModel = ref({
  canal: "wellhub",
  cupos: 0,
  liberar_horas_antes: 0,
});
const guardandoCanal = ref(false);
const accionando = ref(false);
const checkins = ref<Checkin[]>([]);
const checkinModel = ref({ proveedor: "wellhub", codigo: "" });
const registrandoCheckin = ref(false);
const okCheckin = ref(false);

// Staff de la sesión (R17): asignación con rol y sustitución.
interface StaffSesion {
  id: string;
  usuario: string | null;
  rol: string;
  sustituye_a: number | null;
}
const staffSesion = ref<StaffSesion[]>([]);
const staffModel = ref({ usuarioId: "", rol: "instructor", sustituyeA: "" });
const asignandoStaff = ref(false);

// Pestaña del detalle de una clase y su ocupación (mismo patrón que una cita).
type PestanaClase = "asistentes" | "lugares" | "equipo" | "checkins";
const pestanaClase = ref<PestanaClase>("asistentes");
const pestanasClase = computed<PestanaClase[]>(() => {
  const d = detalle.value;
  const lista: PestanaClase[] = ["asistentes"];
  if (d?.oferta_id && (puedeGestionar.value || puedeCatalogo.value)) {
    lista.push("lugares");
  }
  if (puedeGestionar.value) {
    lista.push("equipo");
  }
  if (puedeCheckin.value) {
    lista.push("checkins");
  }
  return lista;
});
const ICONO_PESTANA_CLASE: Record<PestanaClase, string> = {
  asistentes: "personas",
  lugares: "cuadricula",
  equipo: "instructores",
  checkins: "hecho",
};
const fechaClase = computed(() => {
  const d = detalle.value;
  return d === null
    ? ""
    : new Intl.DateTimeFormat("es-MX", {
        timeZone: d.zona_horaria,
        weekday: "long",
        day: "numeric",
        month: "long",
      }).format(new Date(d.inicia_en));
});
const minutosClase = computed(() => {
  const d = detalle.value;
  return d === null
    ? 0
    : Math.round(
        (new Date(d.termina_en).getTime() - new Date(d.inicia_en).getTime()) /
          60000,
      );
});
const ocupacionClase = computed(() => {
  const d = detalle.value;
  if (d === null || d.capacidad === null || d.capacidad === 0) {
    return null;
  }
  return Math.min(100, Math.round((d.ocupados / d.capacidad) * 100));
});

async function abrirDetalle(s: Sesion): Promise<void> {
  detalle.value = s;
  pestanaClase.value = "asistentes";
  cambiandoHorario.value = false;
  cambiandoSerie.value = false;
  cancelandoSesion.value = false;
  roster.value = [];
  checkins.value = [];
  staffSesion.value = [];
  reservarModel.value = {
    miembroId: "",
    esperar: false,
    canal: "directo",
    lugar: null,
  };
  lugaresModel.value = {
    lugares: s.oferta_lugares ?? 0,
    precio: (s.oferta_precio_clase ?? 0) / 100,
  };
  transferirModel.value = { reservaId: "", personaId: "" };
  reglasCanal.value = [];
  reglaCanalModel.value = {
    canal: "wellhub",
    cupos: 0,
    liberar_horas_antes: 0,
  };
  checkinModel.value = { proveedor: "wellhub", codigo: "" };
  staffModel.value = { usuarioId: "", rol: "instructor", sustituyeA: "" };
  okCheckin.value = false;
  await cargarRoster(s.id);
  if (puedeCheckin.value) {
    await cargarCheckins(s.id);
  }
  if (puedeGestionar.value) {
    await cargarStaffSesion(s.id);
    if (s.oferta_id !== null) {
      await cargarReglasCanal(s.oferta_id);
    }
  }
}

async function cargarReglasCanal(ofertaId: string): Promise<void> {
  try {
    const { data } = await api.get<{ data: ReglaCanal[] }>(
      `${base.value}/ofertas/${ofertaId}/capacidad-canal`,
    );
    reglasCanal.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function guardarReglaCanal(): Promise<void> {
  if (detalle.value === null || detalle.value.oferta_id === null) {
    return;
  }
  guardandoCanal.value = true;
  error.value = null;
  try {
    await api.put(
      `${base.value}/ofertas/${detalle.value.oferta_id}/capacidad-canal`,
      {
        canal: reglaCanalModel.value.canal,
        cupos: Number(reglaCanalModel.value.cupos) || 0,
        liberar_horas_antes:
          Number(reglaCanalModel.value.liberar_horas_antes) || 0,
      },
    );
    reglaCanalModel.value = {
      canal: "wellhub",
      cupos: 0,
      liberar_horas_antes: 0,
    };
    await cargarReglasCanal(detalle.value.oferta_id);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardandoCanal.value = false;
  }
}

async function eliminarReglaCanal(r: ReglaCanal): Promise<void> {
  if (detalle.value === null || detalle.value.oferta_id === null) {
    return;
  }
  guardandoCanal.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/capacidad-canal/${r.id}`);
    await cargarReglasCanal(detalle.value.oferta_id);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardandoCanal.value = false;
  }
}

async function cargarStaffSesion(id: string): Promise<void> {
  try {
    const { data } = await api.get<{ data: StaffSesion[] }>(
      `${base.value}/sesiones/${id}/staff`,
    );
    staffSesion.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
// Instructor principal de la clase (cuenta para choques de agenda y nómina).
const cambiandoInstructor = ref(false);
async function cambiarInstructor(instructorId: string): Promise<void> {
  if (detalle.value === null) {
    return;
  }
  cambiandoInstructor.value = true;
  error.value = null;
  try {
    const { data } = await api.put<{
      data: { instructor: string | null; instructor_id: string | null };
    }>(`${base.value}/sesiones/${detalle.value.id}/instructor`, {
      instructor_id: instructorId !== "" ? instructorId : null,
    });
    detalle.value.instructor = data.data.instructor;
    detalle.value.instructor_id = data.data.instructor_id;
    await cargarSesiones();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cambiandoInstructor.value = false;
  }
}

async function asignarStaff(id: string): Promise<void> {
  if (staffModel.value.usuarioId === "") {
    return;
  }
  asignandoStaff.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/sesiones/${id}/staff`, {
      usuario_id: staffModel.value.usuarioId,
      rol: staffModel.value.rol,
      sustituye_a:
        staffModel.value.sustituyeA !== "" ? staffModel.value.sustituyeA : null,
    });
    staffModel.value = { usuarioId: "", rol: "instructor", sustituyeA: "" };
    await cargarStaffSesion(id);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    asignandoStaff.value = false;
  }
}
// ---- Nueva cita (negocios de citas): desde el botón o un hueco de la agenda ----
const mostrarNuevaCita = ref(false);
const inicialCita = ref({
  fecha: "",
  hora: "",
  instructorId: null as string | null,
  sucursalId: null as string | null,
});
function abrirNuevaCita(datos?: {
  instructorId: string | null;
  hora: string;
}): void {
  const ahora = new Date();
  const minutos =
    Math.ceil((ahora.getHours() * 60 + ahora.getMinutes()) / 15) * 15;
  inicialCita.value = {
    fecha: diaSel.value,
    hora:
      datos?.hora ??
      `${pad2(Math.floor(minutos / 60) % 24)}:${pad2(minutos % 60)}`,
    instructorId: datos?.instructorId ?? (instructorFiltro.value || null),
    sucursalId: sucursalFiltro.value || null,
  };
  mostrarNuevaCita.value = true;
}
async function alAgendarCita(): Promise<void> {
  mostrarNuevaCita.value = false;
  await cargarSesiones();
}

// Detalle: una cita abre su panel (llegó, cobrar, cancelar); una clase, el de clase.
const citaAbierta = ref<Sesion | null>(null);
const puedeCobrar = computed(() => sesion.puede("ordenes.gestionar"));
function abrirDesdeAgenda(s: SesionAgenda): void {
  const original = sesiones.value.find((x) => x.id === s.id);
  if (original === undefined) {
    return;
  }
  if (original.tipo === "cita") {
    citaAbierta.value = original;
  } else {
    void abrirDetalle(original);
  }
}
async function alCambiarCita(): Promise<void> {
  const id = citaAbierta.value?.id;
  await cargarSesiones();
  citaAbierta.value = sesiones.value.find((x) => x.id === id) ?? null;
}

function cerrarDetalle(): void {
  detalle.value = null;
}

async function cargarRoster(id: string): Promise<void> {
  // Quién va a la clase: solo con permiso de ver reservas.
  if (!puedeVerReservas.value) {
    roster.value = [];
    return;
  }
  cargandoRoster.value = true;
  try {
    const { data } = await api.get<{ data: Reserva[] }>(
      `${base.value}/sesiones/${id}/reservas`,
    );
    roster.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargandoRoster.value = false;
  }
}
async function cargarCheckins(id: string): Promise<void> {
  try {
    const { data } = await api.get<{ data: Checkin[] }>(
      `${base.value}/sesiones/${id}/checkins`,
    );
    checkins.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function refrescarTras(sesionId: string): Promise<void> {
  await Promise.all([cargarRoster(sesionId), cargarSesiones()]);
  // Refresca el encabezado del panel (ocupados/cupo) con la sesion actualizada.
  const actualizada = sesiones.value.find((s) => s.id === sesionId);
  if (actualizada !== undefined && detalle.value !== null) {
    detalle.value = actualizada;
  }
}

async function reservar(id: string): Promise<void> {
  if (reservarModel.value.miembroId === "") {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/sesiones/${id}/reservas`, {
      persona_id: reservarModel.value.miembroId,
      esperar: reservarModel.value.esperar,
      canal: reservarModel.value.canal,
      lugar: reservarModel.value.lugar,
    });
    reservarModel.value.miembroId = "";
    reservarModel.value.lugar = null;
    await refrescarTras(id);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}
// Lugares (R4): lugares ya tomados en la sesion (desde el roster cargado).
const lugaresTomados = computed(() => {
  const s = new Set<number>();
  for (const r of roster.value) {
    if (r.lugar !== null && r.estado !== "cancelada") {
      s.add(r.lugar);
    }
  }
  return s;
});

async function guardarLugares(): Promise<void> {
  if (detalle.value === null || detalle.value.oferta_id === null) {
    return;
  }
  guardandoLugares.value = true;
  error.value = null;
  try {
    const precioMinor = Math.round(
      (Number(lugaresModel.value.precio) || 0) * 100,
    );
    await api.put(`${base.value}/ofertas/${detalle.value.oferta_id}`, {
      lugares: Number(lugaresModel.value.lugares) || 0,
      precio_clase_minor: precioMinor > 0 ? precioMinor : null,
    });
    detalle.value.oferta_lugares = Number(lugaresModel.value.lugares) || 0;
    detalle.value.oferta_precio_clase = precioMinor > 0 ? precioMinor : null;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardandoLugares.value = false;
  }
}

async function marcar(
  reservaId: string,
  estado: "presente" | "ausente",
  sesionId: string,
): Promise<void> {
  const r = roster.value.find((x) => x.id === reservaId);
  if (
    !(await confirmarAsistencia(
      t,
      r?.persona ?? "",
      r?.asistencia ?? null,
      estado,
    ))
  ) {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/reservas/${reservaId}/asistencia`, {
      estado,
    });
    await cargarRoster(sesionId);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}
async function aceptar(reservaId: string, sesionId: string): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/reservas/${reservaId}/aceptar`, {});
    await refrescarTras(sesionId);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}
// Cancelaciones que se están confirmando (con su efecto a la vista).
const cancelandoReserva = ref<string | null>(null);
const cancelandoSesion = ref(false);
// Cambiar el horario de la clase completa (2.1): todas sus reservas la siguen.
const cambiandoHorario = ref(false);
// "Esta y las siguientes" de una clase recurrente (2.5).
const cambiandoSerie = ref(false);
async function horarioCambiado(): Promise<void> {
  cambiandoHorario.value = false;
  cambiandoSerie.value = false;
  cerrarDetalle();
  await cargarSesiones();
}
async function cancelarReserva(
  reservaId: string,
  sesionId: string,
  por: "cliente" | "negocio" | null = null,
): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(
      `${base.value}/reservas/${reservaId}/cancelar`,
      por ? { por } : {},
    );
    cancelandoReserva.value = null;
    await refrescarTras(sesionId);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}
function abrirTransferir(reservaId: string): void {
  transferirModel.value = { reservaId, personaId: "" };
}
async function transferir(reservaId: string, sesionId: string): Promise<void> {
  if (transferirModel.value.personaId === "") {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/reservas/${reservaId}/transferir`, {
      persona_id: transferirModel.value.personaId,
    });
    transferirModel.value = { reservaId: "", personaId: "" };
    await cargarRoster(sesionId);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}
async function cancelarSesion(id: string): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/sesiones/${id}/cancelar`, {});
    cancelandoSesion.value = false;
    cerrarDetalle();
    await cargarSesiones();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}
async function registrarCheckin(id: string): Promise<void> {
  registrandoCheckin.value = true;
  okCheckin.value = false;
  error.value = null;
  try {
    await api.post(`${base.value}/checkins`, {
      proveedor: checkinModel.value.proveedor,
      sesion_id: id,
      codigo: checkinModel.value.codigo,
    });
    checkinModel.value.codigo = "";
    okCheckin.value = true;
    await cargarCheckins(id);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    registrandoCheckin.value = false;
  }
}

// ---- Nueva clase (modal) ----
const mostrarNueva = ref(false);
// Tres formas de cargar: una sola clase; la misma hora en varios días; o cada día
// con su propia hora (p. ej. Nivel 1 el lunes a las 18:00 y el sábado a las 10:00).
type ModoCarga = "una" | "misma" | "porDia";
const MODOS: ModoCarga[] = ["una", "misma", "porDia"];
const modo = ref<ModoCarga>("una");
const esRecurrente = computed(() => modo.value !== "una");
const form = ref({
  ofertaId: "",
  sucursalId: "",
  instructorId: "",
  recursoId: "",
  fecha: "",
  hora: "",
  duracion: "60",
  capacidad: "",
  dias: [] as number[],
  // "Horario por día": un renglón por día con su hora.
  horarios: [] as { dia: number; hora: string }[],
  repetirHasta: "",
}); // Con una sucursal fija (la de la barra o la única), sus selectores sobran: el
// filtro y la nueva clase la usan.
const { mostrarSelect: elegirSucursal, actual: sucursalActual } =
  useSucursalOperativa({
    filtro: sucursalFiltro,
    campo: toRef(form.value, "sucursalId"),
  });

// Año de la fecha elegida: por defecto la recurrencia llega hasta el 31-dic de ese año.
const anioRecurrente = computed(() =>
  form.value.fecha !== ""
    ? form.value.fecha.slice(0, 4)
    : String(new Date().getFullYear()),
);
// Renglones repetidos (mismo día y hora) en "Horario por día".
const horarioRepetido = computed(() => {
  const vistos = new Set<string>();
  return form.value.horarios.some((h) => {
    const clave = `${h.dia}-${h.hora}`;
    const ya = vistos.has(clave);
    vistos.add(clave);
    return ya;
  });
});
const listoParaCrear = computed(() => {
  const f = form.value;
  if (f.ofertaId === "" || f.sucursalId === "" || f.fecha === "") {
    return false;
  }
  if (modo.value === "una") {
    return f.hora !== "";
  }
  if (modo.value === "misma") {
    return f.hora !== "" && f.dias.length > 0;
  }
  return (
    f.horarios.length > 0 &&
    f.horarios.every((h) => h.hora !== "") &&
    !horarioRepetido.value
  );
});
function agregarHorario(): void {
  const ultimo = form.value.horarios.at(-1);
  form.value.horarios.push({
    dia: ultimo ? (ultimo.dia % 7) + 1 : diaInicial(),
    hora: ultimo?.hora ?? form.value.hora,
  });
}
function quitarHorario(i: number): void {
  form.value.horarios.splice(i, 1);
}
function diaInicial(): number {
  return form.value.fecha !== "" ? diaIsoDe(form.value.fecha) : 1;
}
// Recursos/salas de la sucursal elegida (para asignar sala a la clase).
const recursosDeSucursal = computed(() =>
  form.value.sucursalId === ""
    ? recursos.value
    : recursos.value.filter(
        (r) =>
          r.sucursal_id === null || r.sucursal_id === form.value.sucursalId,
      ),
);
const creando = ref(false);

// Conflictos (instructor/sala/recurso) verificados ANTES de guardar (rework Agenda).
interface Conflicto {
  tipo: string;
  campo: string;
  mensaje: string;
  sesion: string | null;
}
const conflictos = ref<Conflicto[]>([]);
let tempConf: ReturnType<typeof setTimeout> | undefined;

async function verificarConflictos(): Promise<void> {
  conflictos.value = [];
  // La recurrencia (varias fechas) no se pre-verifica; la validación dura ocurre al guardar.
  if (
    form.value.sucursalId === "" ||
    form.value.fecha === "" ||
    form.value.hora === "" ||
    esRecurrente.value
  ) {
    return;
  }
  try {
    const { data } = await api.post<{ data: { conflictos: Conflicto[] } }>(
      `${base.value}/sesiones/verificar`,
      {
        sucursal_id: form.value.sucursalId,
        instructor_id:
          form.value.instructorId !== "" ? form.value.instructorId : null,
        recurso_id: form.value.recursoId !== "" ? form.value.recursoId : null,
        inicia_en_local: `${form.value.fecha} ${form.value.hora}:00`,
        duracion_minutos: Number(form.value.duracion),
      },
    );
    conflictos.value = data.data.conflictos;
  } catch {
    // Silencioso: si la verificación falla, la validación al guardar sigue protegiendo.
  }
}

watch(
  () => [
    form.value.sucursalId,
    form.value.instructorId,
    form.value.recursoId,
    form.value.fecha,
    form.value.hora,
    form.value.duracion,
    esRecurrente.value,
  ],
  () => {
    clearTimeout(tempConf);
    tempConf = setTimeout(verificarConflictos, 350);
  },
);

// Días de la semana en ISO (1 = lunes … 7 = domingo) para el selector de recurrencia.
const DIAS_SEMANA = [
  { n: 1, etiqueta: "L" },
  { n: 2, etiqueta: "M" },
  { n: 3, etiqueta: "M" },
  { n: 4, etiqueta: "J" },
  { n: 5, etiqueta: "V" },
  { n: 6, etiqueta: "S" },
  { n: 7, etiqueta: "D" },
];
function diaIsoDe(ymd: string): number {
  const [a, m, d] = ymd.split("-").map(Number);
  return ((new Date(a, m - 1, d).getDay() + 6) % 7) + 1;
}
function alternarDia(n: number): void {
  const i = form.value.dias.indexOf(n);
  if (i === -1) {
    form.value.dias.push(n);
  } else {
    form.value.dias.splice(i, 1);
  }
}
// Al pasar a una forma recurrente, parte del día (y la hora) de la fecha elegida.
watch(modo, (m) => {
  if (
    m === "misma" &&
    form.value.dias.length === 0 &&
    form.value.fecha !== ""
  ) {
    form.value.dias = [diaIsoDe(form.value.fecha)];
  }
  if (m === "porDia" && form.value.horarios.length === 0) {
    form.value.horarios = [{ dia: diaInicial(), hora: form.value.hora }];
  }
});

async function crearSesion(): Promise<void> {
  creando.value = true;
  error.value = null;
  avisoSerie.value = null;
  try {
    if (esRecurrente.value) {
      await crearRecurrente();
    } else {
      await api.post(`${base.value}/sesiones`, {
        oferta_id: form.value.ofertaId,
        sucursal_id: form.value.sucursalId,
        instructor_id:
          form.value.instructorId !== "" ? form.value.instructorId : null,
        recurso_id: form.value.recursoId !== "" ? form.value.recursoId : null,
        inicia_en_local: `${form.value.fecha} ${form.value.hora}:00`,
        duracion_minutos: Number(form.value.duracion),
        capacidad:
          form.value.capacidad !== "" ? Number(form.value.capacidad) : null,
      });
    }
    trackEvent("class_schedule_created", { recurring: esRecurrente.value });
    form.value.fecha = "";
    form.value.hora = "";
    form.value.capacidad = "";
    form.value.recursoId = "";
    modo.value = "una";
    form.value.dias = [];
    form.value.horarios = [];
    form.value.repetirHasta = "";
    mostrarNueva.value = false;
    await cargarSesiones();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    creando.value = false;
  }
}

// Crea las clases que se repiten: una plantilla por cada hora distinta (con los días
// que tienen esa hora) y materializa sus sesiones del rango.
async function crearRecurrente(): Promise<void> {
  const desde = form.value.fecha;
  const porHora = new Map<string, number[]>();
  if (modo.value === "misma") {
    porHora.set(form.value.hora, [...form.value.dias]);
  } else {
    for (const h of form.value.horarios) {
      porHora.set(h.hora, [...(porHora.get(h.hora) ?? []), h.dia]);
    }
  }
  // Por defecto (sin fecha final) se generan las clases hasta el 31-dic del año
  // de la fecha elegida y la plantilla queda acotada ahí (no se extiende sola).
  const hasta =
    form.value.repetirHasta !== ""
      ? form.value.repetirHasta
      : `${desde.slice(0, 4)}-12-31`;

  let creadas = 0;
  const omitidas: { fecha: string; motivo: string }[] = [];
  for (const [hora, dias] of porHora) {
    const { data } = await api.post<{ data: { id: string } }>(
      `${base.value}/plantillas-horario`,
      {
        oferta_id: form.value.ofertaId,
        sucursal_id: form.value.sucursalId,
        instructor_id:
          form.value.instructorId !== "" ? form.value.instructorId : null,
        // La sala elegida también aplica a las clases que se repiten.
        recurso_id: form.value.recursoId !== "" ? form.value.recursoId : null,
        dias_semana: [...new Set(dias)].sort((a, b) => a - b),
        hora_local: hora,
        duracion_minutos: Number(form.value.duracion),
        capacidad:
          form.value.capacidad !== "" ? Number(form.value.capacidad) : null,
        vigente_desde: desde,
        vigente_hasta: hasta,
      },
    );
    const generacion = await api.post<{
      data: { creadas: number; omitidas?: { fecha: string; motivo: string }[] };
    }>(`${base.value}/plantillas-horario/${data.data.id}/generar`, {
      desde,
      hasta,
    });
    creadas += generacion.data.data.creadas;
    omitidas.push(...(generacion.data.data.omitidas ?? []));
  }
  if (omitidas.length > 0) {
    avisoSerie.value = t("agendaVisual.serieOmitidas", {
      creadas,
      n: omitidas.length,
      detalle: omitidas
        .slice(0, 5)
        .map((o) => `${o.fecha} (${o.motivo})`)
        .join("; "),
    });
  }
}

onMounted(async () => {
  await cargarReferencias();
  await cargarSesiones();
});
</script>

<template>
  <section class="tu-pagina">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion
        :titulo="$t('agenda.titulo')"
        :subtitulo="$t('agenda.subtitulo')"
      />
      <div class="flex flex-wrap items-center gap-2">
        <!-- En el teléfono, solo la acción principal: lo demás está en el menú. -->
        <BotonImportar
          v-if="puedeEntrar('importar-clases', sesion)"
          class="max-sm:hidden"
          ruta="importar-clases"
          :texto="$t('importarClases.titulo')"
        />
        <!-- De paso, a donde se configuran (cada una guarda sus datos). -->
        <RouterLink
          v-if="puedeEntrar('horarios', sesion)"
          :to="{ name: 'horarios' }"
          class="tu-btn tu-btn-fantasma max-sm:hidden"
          >{{ $t("agenda.disponibilidadEquipo") }}</RouterLink
        >
        <RouterLink
          v-if="puedeEntrar('reglas-agenda', sesion)"
          :to="{ name: 'reglas-agenda', hash: '#politicas' }"
          class="tu-btn tu-btn-fantasma max-sm:hidden"
          >{{ $t("agenda.reglasReserva") }}</RouterLink
        >
        <!-- Citas: el negocio agenda al cliente. Clases: se programa una clase. -->
        <button
          v-if="sesion.esCitas && puedeReservar"
          class="tu-btn tu-btn-primario tu-btn-crear"
          @click="abrirNuevaCita()"
        >
          {{ $t("agendaVisual.nuevaCita.boton") }}
        </button>
        <button
          v-else-if="!sesion.esCitas && puedeGestionar"
          class="tu-btn tu-btn-primario tu-btn-crear"
          @click="mostrarNueva = true"
        >
          {{ $t("agenda.nuevaClase") }}
        </button>
      </div>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p v-if="errorReferencias" class="mt-4 text-sm" style="color: var(--error)">
      {{ errorReferencias }}
    </p>
    <p
      v-if="avisoSerie"
      class="mt-4 text-sm"
      role="status"
      style="color: var(--aviso)"
    >
      {{ avisoSerie }}
    </p>

    <!-- En el teléfono, los indicadores y la leyenda van después de las citas. -->
    <div v-if="!cargando" class="flex flex-col">
      <!-- Barra de herramientas: filtros + navegacion + vista -->
      <div class="mt-6 flex flex-wrap items-center gap-2 sm:gap-3">
        <button
          v-if="
            elegirSucursal ||
            (instructores.length > 0 && !soloLoSuyo) ||
            ofertasAgenda.length > 1
          "
          type="button"
          class="tu-btn tu-btn-fantasma sm:hidden"
          data-prueba="mostrar-filtros"
          :aria-expanded="filtrosAbiertos"
          :style="
            filtrosAbiertos || filtrosActivos
              ? {
                  borderColor: 'var(--primario)',
                  color: 'var(--primario-fuerte)',
                }
              : {}
          "
          @click="filtrosAbiertos = !filtrosAbiertos"
        >
          <IconoNav nombre="ajustes" :tam="16" />
          {{ $t("tabla.filtros") }}
          <span
            v-if="filtrosActivos"
            class="rounded-full px-1.5 text-xs"
            :style="{
              background: 'var(--primario)',
              color: 'var(--primario-contraste)',
            }"
            >{{ filtrosActivos }}</span
          >
        </button>
        <span
          v-if="elegirSucursal"
          class="tu-select-icono"
          :class="filtrosAbiertos ? 'max-sm:w-full' : 'max-sm:hidden'"
        >
          <IconoNav nombre="ubicacion" :tam="18" />
          <select
            v-model="sucursalFiltro"
            class="tu-input w-auto max-sm:w-full"
            :aria-label="$t('agenda.nueva.sucursal')"
          >
            <option value="">{{ $t("agenda.todasSucursales") }}</option>
            <option v-for="s in sucursalesAgenda" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </span>
        <span
          v-if="instructores.length > 0 && !soloLoSuyo"
          class="tu-select-icono"
          :class="filtrosAbiertos ? 'max-sm:w-full' : 'max-sm:hidden'"
        >
          <IconoNav nombre="instructores" :tam="18" />
          <select
            v-model="instructorFiltro"
            class="tu-input w-auto max-sm:w-full"
            :aria-label="$t('agenda.nueva.instructor')"
          >
            <option value="">
              {{
                $t("agendaVisual.todosLos", {
                  grupo: plural(sesion.terminologia.instructor).toLowerCase(),
                })
              }}
            </option>
            <option v-for="i in instructores" :key="i.id" :value="i.id">
              {{ i.nombre }}
            </option>
          </select>
        </span>

        <span
          v-if="ofertasAgenda.length > 1"
          class="tu-select-icono"
          :class="filtrosAbiertos ? 'max-sm:w-full' : 'max-sm:hidden'"
        >
          <IconoNav nombre="etiqueta" :tam="18" />
          <select
            v-model="ofertaFiltro"
            class="tu-input w-auto max-sm:w-full"
            :aria-label="$t('agendaOperacion.servicio')"
          >
            <option value="">{{ $t("agendaOperacion.todosServicios") }}</option>
            <option v-for="o in ofertasAgenda" :key="o.id" :value="o.id">
              {{ o.nombre }}
            </option>
          </select>
        </span>

        <div
          class="flex items-center gap-1 sm:ml-auto max-sm:order-first max-sm:mr-auto"
        >
          <button
            class="tu-btn tu-btn-fantasma ag-paso"
            :aria-label="
              vista === 'profesionales'
                ? $t('agendaVisual.diaAnterior')
                : vista === 'mes'
                  ? $t('agendaVisual.mes.anterior')
                  : $t('agenda.semanaAnterior')
            "
            @click="irPaso(-1)"
          >
            <IconoNav nombre="chevron" :tam="18" class="rotate-180" />
          </button>
          <button class="tu-btn tu-btn-fantasma ag-hoy" @click="irHoy">
            {{ $t("agenda.hoy") }}
          </button>
          <button
            class="tu-btn tu-btn-fantasma ag-paso"
            :aria-label="
              vista === 'profesionales'
                ? $t('agendaVisual.diaSiguiente')
                : vista === 'mes'
                  ? $t('agendaVisual.mes.siguiente')
                  : $t('agenda.semanaSiguiente')
            "
            @click="irPaso(1)"
          >
            <IconoNav nombre="chevron" :tam="18" />
          </button>
          <span
            class="text-sm font-medium ml-2 hidden sm:inline first-letter:uppercase"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ vista === "profesionales" ? diaTexto : rangoTexto }}</span
          >
        </div>
      </div>

      <!-- Alternar vista según la modalidad (citas: por profesional / semana;
           clases: semana / día). En móvil, las clases siempre se ven por día. -->
      <div class="mt-3">
        <div
          class="tu-segmentado"
          :class="{ 'hidden lg:inline-flex': !sesion.esCitas }"
          role="group"
        >
          <!-- En el teléfono las citas se ven por día (por profesional y por
               semana se ven igual), así que solo se ofrece «Día» y «Mes». -->
          <button
            v-for="op in opcionesVista"
            :key="op"
            type="button"
            :class="{ 'max-lg:hidden': sesion.esCitas && op === 'semana' }"
            :aria-pressed="vista === op"
            @click="vista = op"
          >
            <template v-if="sesion.esCitas && op === 'profesionales'">
              <span class="lg:hidden">{{ $t("agendaVisual.vistas.dia") }}</span>
              <span class="max-lg:hidden">{{
                $t("agendaVisual.vistas.profesionales")
              }}</span>
            </template>
            <template v-else>{{ $t(`agendaVisual.vistas.${op}`) }}</template>
          </button>
        </div>
      </div>

      <!-- Indicadores del día (citas) o de la semana (clases). -->
      <AgendaKpis
        v-if="vista !== 'mes'"
        class="mt-4 max-lg:order-last"
        :tarjetas="tarjetasKpi"
      />

      <!-- Leyenda: el color identifica el servicio o la clase. -->
      <div
        v-if="leyenda.length > 0"
        class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs max-lg:order-last"
        :style="{ color: 'var(--texto-suave)' }"
      >
        <span class="font-medium" :style="{ color: 'var(--texto)' }">{{
          sesion.esCitas
            ? $t("agendaVisual.leyendaServicios")
            : $t("agendaVisual.leyendaClases")
        }}</span>
        <span
          v-for="l in leyenda"
          :key="l.id"
          class="inline-flex items-center gap-1.5"
          ><span
            class="inline-block w-2.5 h-2.5 rounded-full"
            :style="{ background: l.tinta }"
          ></span
          >{{ l.nombre }}</span
        >
      </div>

      <p
        v-if="cargandoSesiones"
        class="mt-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>

      <!-- ===== Vista MES: el mes en semanas; tocar un día lleva a ese día ===== -->
      <AgendaMes
        v-if="vista === 'mes'"
        class="mt-4"
        :mes="mesInicio"
        :sesiones="sesionesVisibles"
        :catalogo="catalogo"
        :seleccionada="citaAbierta?.id ?? detalle?.id ?? null"
        @abrir="abrirDesdeAgenda"
        @dia="verDia"
      />

      <!-- ===== Vista POR PROFESIONAL (citas): el día en columnas ===== -->
      <AgendaProfesionales
        v-if="vista === 'profesionales'"
        class="mt-4 hidden lg:block"
        :fecha="diaSel"
        :zona="zonaAgenda"
        :sesiones="sesionesVisibles"
        :profesionales="profesionalesVisibles"
        :ventanas="ventanas"
        :bloqueos="bloqueos"
        :catalogo="catalogo"
        :seleccionada="citaAbierta?.id ?? detalle?.id ?? null"
        :puede-crear="puedeReservar"
        @abrir="abrirDesdeAgenda"
        @crear="abrirNuevaCita"
      />

      <!-- ===== Vista SEMANA de clases: franjas con cupos ===== -->
      <div
        v-if="vista === 'semana' && !sesion.esCitas"
        class="mt-4 hidden lg:block"
      >
        <AgendaClasesSemana
          :dias="dias"
          :sesiones="sesionesVisibles"
          :catalogo="catalogo"
          :seleccionada="citaAbierta?.id ?? detalle?.id ?? null"
          @abrir="abrirDesdeAgenda"
        />
      </div>

      <!-- ===== Vista SEMANA de citas (escritorio): cuadricula horaria ===== -->
      <div
        v-if="vista === 'semana' && sesion.esCitas"
        class="mt-4 hidden lg:block"
      >
        <!-- Leyenda: el color del bloque = tipo de clase; el punto = estado de ocupación. -->
        <div
          class="flex flex-wrap items-center gap-4 mb-2 text-xs"
          :style="{ color: 'var(--texto-suave)' }"
        >
          <span class="inline-flex items-center gap-1.5"
            ><span
              class="tu-estado-dot"
              :style="{ background: COLOR_ESTADO.disponible }"
            />{{ $t("agenda.estados.disponible") }}</span
          >
          <span class="inline-flex items-center gap-1.5"
            ><span
              class="tu-estado-dot"
              :style="{ background: COLOR_ESTADO.casi }"
            />{{ $t("agenda.estados.casi") }}</span
          >
          <span class="inline-flex items-center gap-1.5"
            ><span
              class="tu-estado-dot"
              :style="{ background: COLOR_ESTADO.llena }"
            />{{ $t("agenda.estados.llena") }}</span
          >
          <span class="inline-flex items-center gap-1.5"
            ><span class="tu-espera-dot" />{{
              $t("agenda.estados.espera")
            }}</span
          >
        </div>

        <div
          class="rounded-xl border overflow-hidden"
          :style="{
            borderColor: 'var(--borde)',
            background: 'var(--superficie)',
          }"
        >
          <!-- Encabezado de dias -->
          <div
            class="grid"
            style="grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr))"
          >
            <div class="border-b" :style="{ borderColor: 'var(--borde)' }" />
            <div
              v-for="d in dias"
              :key="d.iso"
              class="px-1 py-2 text-center border-b border-l"
              :style="{
                borderColor: 'var(--borde)',
                background: d.esHoy ? 'var(--primario-suave)' : 'transparent',
              }"
            >
              <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                {{ d.nombre }}
              </div>
              <div
                class="text-base font-semibold"
                :style="{
                  color: d.esHoy ? 'var(--primario-fuerte)' : 'var(--texto)',
                }"
              >
                {{ d.dia }}
              </div>
            </div>
          </div>
          <!-- Cuerpo: eje de horas + 7 columnas -->
          <div
            class="grid"
            style="grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr))"
          >
            <div class="relative" :style="{ height: totalAlto + 'px' }">
              <div
                v-for="h in horas"
                :key="h.min"
                class="absolute right-1.5 text-[11px]"
                :style="{
                  top: ((h.min - rango.inicioMin) / 60) * HORA_ALTO + 'px',
                  color: 'var(--texto-suave)',
                }"
              >
                {{ h.etiqueta }}
              </div>
            </div>
            <div
              v-for="d in dias"
              :key="d.iso"
              class="relative border-l"
              :style="{
                borderColor: 'var(--borde)',
                height: totalAlto + 'px',
                background: d.esHoy
                  ? 'color-mix(in srgb, var(--primario) 6%, transparent)'
                  : 'transparent',
              }"
            >
              <div
                v-for="h in horas"
                :key="h.min"
                class="absolute left-0 right-0 border-t"
                :style="{
                  top: ((h.min - rango.inicioMin) / 60) * HORA_ALTO + 'px',
                  borderColor: 'var(--borde)',
                  opacity: 0.5,
                }"
              />
              <button
                v-for="b in bloquesDe(d.iso)"
                :key="b.sesion.id"
                class="tu-bloque"
                :class="{
                  'tu-bloque--cancelada':
                    estadoAgenda(b.sesion) === 'cancelada',
                }"
                :style="{
                  top: b.top + 'px',
                  height: b.alto + 'px',
                  left: `calc(${b.izq}% + 2px)`,
                  width: `calc(${b.ancho}% - 4px)`,
                  background:
                    estadoAgenda(b.sesion) === 'cancelada'
                      ? 'var(--superficie-2)'
                      : `color-mix(in srgb, ${colorTipo(b.sesion)} 11%, var(--superficie))`,
                  borderLeft: `3px solid ${estadoAgenda(b.sesion) === 'cancelada' ? 'var(--texto-suave)' : colorTipo(b.sesion)}`,
                }"
                @click="abrirDesdeAgenda(b.sesion)"
              >
                <div class="flex items-center gap-1">
                  <span class="font-semibold text-[11px] leading-tight">{{
                    horaCorta(b.sesion.inicia_en, b.sesion.zona_horaria)
                  }}</span>
                  <span
                    v-if="b.sesion.en_espera > 0"
                    class="tu-espera-dot"
                    :title="$t('agenda.estados.espera')"
                  ></span>
                </div>
                <div class="text-[12px] font-medium leading-tight truncate">
                  {{
                    b.sesion.tipo === "cita"
                      ? (b.sesion.cita?.cliente ?? "—")
                      : (b.sesion.oferta ?? "—")
                  }}
                </div>
                <div
                  class="text-[10px] leading-tight truncate flex items-center gap-1"
                  :style="{ opacity: 0.85 }"
                >
                  <span
                    class="tu-estado-dot"
                    :style="{
                      background: COLOR_ESTADO[estadoAgenda(b.sesion)],
                    }"
                  ></span>
                  {{
                    b.sesion.capacidad !== null
                      ? `${b.sesion.ocupados}/${b.sesion.capacidad}`
                      : b.sesion.ocupados
                  }}<span v-if="b.sesion.instructor">
                    · {{ b.sesion.instructor }}</span
                  >
                </div>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ===== Vista DIA (movil siempre salvo en el mes; escritorio si vista dia) ===== -->
      <div
        :class="
          vista === 'mes'
            ? 'hidden'
            : vista === 'dia'
              ? 'mt-4'
              : 'mt-4 lg:hidden'
        "
      >
        <!-- El día elegido, completo; luego la tira de días -->
        <p
          class="mb-2 text-sm font-medium first-letter:uppercase"
          data-prueba="dia-completo"
        >
          {{ diaTexto }}
        </p>
        <div ref="tiraDias" class="flex gap-1.5 overflow-x-auto pb-2">
          <button
            v-for="d in dias"
            :key="d.iso"
            class="flex-1 min-w-[3rem] rounded-xl border py-2 text-center"
            :aria-pressed="diaSel === d.iso"
            :style="
              diaSel === d.iso
                ? {
                    background: 'var(--primario)',
                    color: '#fff',
                    borderColor: 'var(--primario)',
                  }
                : {
                    borderColor: 'var(--borde)',
                    background: 'var(--superficie)',
                  }
            "
            @click="diaSel = d.iso"
          >
            <div class="text-xs opacity-80">{{ d.nombre }}</div>
            <div class="text-base font-semibold">{{ d.dia }}</div>
          </button>
        </div>

        <ul class="mt-3 space-y-2">
          <li v-for="s in sesionesDe(diaSelInfo.iso)" :key="s.id">
            <button
              class="tu-card w-full text-left p-3"
              :class="{ 'opacity-60': s.estado !== 'programada' }"
              :style="{
                borderLeft: `4px solid ${estadoAgenda(s) === 'cancelada' ? 'var(--texto-suave)' : colorTipo(s)}`,
              }"
              @click="abrirDesdeAgenda(s)"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="font-semibold">
                    {{ horaCorta(s.inicia_en, s.zona_horaria) }} ·
                    {{
                      s.tipo === "cita"
                        ? `${s.cita?.cliente ?? "—"} · ${s.oferta ?? "—"}`
                        : (s.oferta ?? "—")
                    }}
                  </div>
                  <div
                    v-if="s.instructor || s.sala"
                    class="text-sm truncate"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ [s.instructor, s.sala].filter(Boolean).join(" · ") }}
                  </div>
                </div>
                <span class="flex flex-col items-end gap-1 shrink-0">
                  <template v-if="s.tipo === 'cita'">
                    <span class="inline-flex items-center gap-1.5 text-xs">
                      <span
                        class="tu-estado-dot"
                        :style="{
                          background:
                            COLOR_ESTADO_CITA[estadoCita(s, new Date())],
                        }"
                      ></span>
                      {{
                        $t(
                          `agendaVisual.estadosCita.${estadoCita(s, new Date())}`,
                        )
                      }}
                    </span>
                    <span
                      v-if="
                        pagoCita(s) === 'por_cobrar' ||
                        pagoCita(s) === 'por_pagar'
                      "
                      class="text-[11px]"
                      :style="{ color: 'var(--aviso)' }"
                      >{{ $t(`agendaOperacion.pago.${pagoCita(s)}`) }}</span
                    >
                  </template>
                  <span v-else class="inline-flex items-center gap-1.5 text-xs">
                    <span
                      class="tu-estado-dot"
                      :style="{ background: COLOR_ESTADO[estadoAgenda(s)] }"
                    ></span>
                    {{ $t(`agenda.estados.${estadoAgenda(s)}`) }}
                  </span>
                  <span
                    v-if="s.en_espera > 0"
                    class="text-[11px]"
                    :style="{ color: 'var(--aviso)' }"
                    >{{
                      $t("agenda.estados.esperaN", { n: s.en_espera })
                    }}</span
                  >
                </span>
              </div>
              <div
                v-if="pctOcupacion(s) !== null"
                class="mt-2 flex items-center gap-2"
              >
                <div class="tu-ocupa flex-1">
                  <span
                    :style="{
                      width: Math.min(100, pctOcupacion(s) ?? 0) + '%',
                      background: COLOR_ESTADO[estadoAgenda(s)],
                    }"
                  ></span>
                </div>
                <span
                  class="text-xs shrink-0"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ s.ocupados }}/{{ s.capacidad }}</span
                >
              </div>
            </button>
          </li>
        </ul>
        <p
          v-if="sesionesDe(diaSelInfo.iso).length === 0"
          class="mt-6 text-center text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("agenda.sinClasesDia") }}
        </p>
      </div>
    </div>

    <!-- ===== Detalle de una clase (mismo patrón que el de una cita) ===== -->
    <ModalDialogo
      :abierto="detalle !== null"
      :titulo="$t('detalleClase.titulo')"
      icono="agenda"
      tam="xl"
      @cerrar="cerrarDetalle"
    >
      <div v-if="detalle" class="dcl" data-prueba="detalle-clase">
        <!-- Qué clase, cuándo y cómo va -->
        <div class="dcl-cabeza">
          <div class="min-w-0">
            <p class="dcl-nombre">{{ detalle.oferta ?? "—" }}</p>
            <p
              class="first-letter:uppercase"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ fechaClase }} ·
              {{ horaCorta(detalle.inicia_en, detalle.zona_horaria) }} –
              {{ horaCorta(detalle.termina_en, detalle.zona_horaria) }}
            </p>
          </div>
          <div class="dcl-ocupacion">
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("detalleClase.ocupacion") }}
            </p>
            <p class="dcl-cifra">
              <template v-if="detalle.capacidad !== null">{{
                $t("detalleClase.deLugares", {
                  n: detalle.ocupados,
                  total: detalle.capacidad,
                })
              }}</template>
              <template v-else>{{
                $t("detalleClase.inscritos", { n: detalle.ocupados })
              }}</template>
            </p>
            <div
              v-if="ocupacionClase !== null"
              class="dcl-barra"
              role="progressbar"
              :aria-valuenow="ocupacionClase"
              aria-valuemin="0"
              aria-valuemax="100"
            >
              <span
                :style="{
                  width: `${ocupacionClase}%`,
                  background: completo(detalle)
                    ? 'var(--aviso)'
                    : 'var(--primario)',
                }"
              />
            </div>
          </div>
          <div class="dcl-estados">
            <span
              class="tu-pildora"
              :style="{
                '--tono':
                  detalle.estado === 'programada'
                    ? 'var(--exito)'
                    : 'var(--texto-suave)',
              }"
              >{{
                detalle.estado === "programada"
                  ? $t("detalleClase.programada")
                  : $t("detalleClase.cancelada")
              }}</span
            >
            <span
              v-if="completo(detalle)"
              class="tu-pildora"
              :style="{ '--tono': 'var(--aviso)' }"
              >{{ $t("agenda.completo") }}</span
            >
            <span
              v-if="detalle.en_espera > 0"
              class="tu-pildora"
              :style="{ '--tono': 'var(--aviso)' }"
              >{{ $t("detalleClase.enEspera", { n: detalle.en_espera }) }}</span
            >
          </div>
        </div>

        <!-- Quién la da, dónde y cuánto dura -->
        <dl class="tu-detalle-franja">
          <div>
            <span class="tu-cuadro-icono" aria-hidden="true">
              <IconoNav nombre="instructores" :tam="20" />
            </span>
            <div class="min-w-0">
              <dt>{{ $t("detalleClase.imparte") }}</dt>
              <dd>{{ detalle.instructor ?? $t("detalleClase.sinAsignar") }}</dd>
            </div>
          </div>
          <div>
            <span class="tu-cuadro-icono" aria-hidden="true">
              <IconoNav nombre="recursos" :tam="20" />
            </span>
            <div class="min-w-0">
              <dt>{{ $t("detalleClase.sala") }}</dt>
              <dd>{{ detalle.sala ?? "—" }}</dd>
            </div>
          </div>
          <div>
            <span class="tu-cuadro-icono" aria-hidden="true">
              <IconoNav nombre="ubicacion" :tam="20" />
            </span>
            <div class="min-w-0">
              <dt>{{ $t("detalleClase.sucursal") }}</dt>
              <dd>{{ detalle.sucursal ?? "—" }}</dd>
            </div>
          </div>
          <div>
            <span class="tu-cuadro-icono" aria-hidden="true">
              <IconoNav nombre="reloj" :tam="20" />
            </span>
            <div class="min-w-0">
              <dt>{{ $t("detalleClase.duracion") }}</dt>
              <dd>{{ $t("detalleClase.minutos", { n: minutosClase }) }}</dd>
            </div>
          </div>
        </dl>

        <!-- Cambiar el horario o la serie, o cancelar: en lugar de las pestañas -->
        <section
          v-if="cambiandoSerie && detalle.serie_id && detalle.fecha_serie"
          class="tu-detalle-seccion"
        >
          <CambiarSerie
            :base="base"
            :serie-id="detalle.serie_id"
            :fecha="detalle.fecha_serie"
            :zona="detalle.zona_horaria"
            :inicia-en="detalle.inicia_en"
            :duracion="minutosClase"
            :profesionales="instructores"
            :profesional-id="detalle.instructor_id"
            :dias="detalle.serie_dias ?? []"
            @hecho="horarioCambiado"
            @cerrar="cambiandoSerie = false"
          />
        </section>
        <section v-else-if="cambiandoHorario" class="tu-detalle-seccion">
          <CambiarHorario
            :url="`${base}/sesiones/${detalle.id}/reprogramar`"
            :zona="detalle.zona_horaria"
            :inicia-en="detalle.inicia_en"
            @hecho="horarioCambiado"
            @cerrar="cambiandoHorario = false"
          />
        </section>
        <!-- Antes: cuántas reservas se cancelan y cuántos créditos regresan. -->
        <section v-else-if="cancelandoSesion" class="tu-detalle-seccion">
          <ConfirmarCancelacion
            :url="`${base}/sesiones/${detalle.id}/cancelacion`"
            :ocupado="accionando"
            @confirmar="cancelarSesion(detalle!.id)"
            @cerrar="cancelandoSesion = false"
          />
        </section>

        <template v-else>
          <div
            v-if="pestanasClase.length > 1"
            class="tu-pestanas"
            role="tablist"
          >
            <button
              v-for="p in pestanasClase"
              :key="p"
              type="button"
              role="tab"
              class="inline-flex items-center gap-2"
              :aria-selected="pestanaClase === p"
              :aria-pressed="pestanaClase === p"
              @click="pestanaClase = p"
            >
              <IconoNav :nombre="ICONO_PESTANA_CLASE[p]" :tam="18" />
              {{ $t(`detalleClase.pestanas.${p}`) }}
            </button>
          </div>

          <!-- Asistentes: reservar y la lista -->
          <template v-if="pestanaClase === 'asistentes'">
            <section
              v-if="
                detalle.estado === 'programada' &&
                puedeReservar &&
                puedeVerMiembros
              "
              class="tu-detalle-seccion"
            >
              <header>
                <h3>{{ $t("detalleClase.reservarTitulo") }}</h3>
              </header>
              <!-- Reservar -->
              <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="reservar(detalle.id)"
              >
                <div class="flex-1 min-w-[160px]">
                  <label class="tu-label" for="rm">{{
                    $t("agenda.reservar.miembro")
                  }}</label>
                  <BuscarPersona
                    v-model="reservarModel.miembroId"
                    campo-id="rm"
                    :buscar-en="`${base}/miembros`"
                    :parametros="{ tipo: 'miembro' }"
                  />
                </div>
                <div class="min-w-[120px]">
                  <label class="tu-label" for="rcanal">{{
                    $t("agenda.reservar.canal")
                  }}</label>
                  <select
                    id="rcanal"
                    v-model="reservarModel.canal"
                    class="tu-input"
                  >
                    <option v-for="c in CANALES" :key="c" :value="c">
                      {{ $t(`agenda.canales.${c}`) }}
                    </option>
                  </select>
                </div>
                <label class="flex items-center gap-1.5 text-sm pb-2.5">
                  <input v-model="reservarModel.esperar" type="checkbox" />
                  {{ $t("agenda.reservar.esperar") }}
                </label>
                <!-- Mapa de lugares (R4): elige el lugar si la clase los asigna -->
                <div v-if="detalle.oferta_lugares > 0" class="w-full">
                  <label class="tu-label">{{
                    $t("agenda.lugares.elige")
                  }}</label>
                  <div class="flex flex-wrap gap-1.5">
                    <button
                      v-for="n in detalle.oferta_lugares"
                      :key="n"
                      type="button"
                      class="h-9 w-9 rounded-md border text-sm font-semibold transition disabled:opacity-40"
                      :style="
                        reservarModel.lugar === n
                          ? {
                              background: 'var(--primario)',
                              color: '#fff',
                              borderColor: 'var(--primario)',
                            }
                          : { borderColor: 'var(--borde)' }
                      "
                      :disabled="lugaresTomados.has(n)"
                      :title="
                        lugaresTomados.has(n)
                          ? $t('agenda.lugares.ocupado')
                          : ''
                      "
                      @click="
                        reservarModel.lugar =
                          reservarModel.lugar === n ? null : n
                      "
                    >
                      {{ n }}
                    </button>
                  </div>
                </div>
                <button
                  class="tu-btn tu-btn-primario"
                  type="submit"
                  :disabled="accionando || reservarModel.miembroId === ''"
                >
                  {{ $t("agenda.reservar.reservar") }}
                </button>
              </form>
            </section>

            <!-- Quién viene: asistencia, lista de espera y cambios de su lugar -->
            <section class="tu-detalle-seccion" data-prueba="roster">
              <header>
                <h3>{{ $t("detalleClase.asistentesTitulo") }}</h3>
                <p>{{ $t("detalleClase.asistentesAyuda") }}</p>
              </header>
              <p
                v-if="!puedeVerReservas"
                class="mt-2 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("agenda.roster.sinPermiso") }}
              </p>
              <p
                v-else-if="cargandoRoster"
                class="mt-2 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("comun.cargando") }}
              </p>
              <p
                v-else-if="roster.length === 0"
                class="mt-2 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("agenda.roster.vacio") }}
              </p>
              <ul v-else class="dcl-roster">
                <li v-for="r in roster" :key="r.id" class="text-sm">
                  <div class="flex items-center justify-between gap-2">
                    <span class="flex min-w-0 items-center gap-3">
                      <AvatarIniciales :nombre="r.persona" tam="md" />
                      <span class="min-w-0">
                        <details
                          v-if="r.transferencias?.length"
                          class="text-xs mb-1"
                          @click.stop
                        >
                          <summary
                            class="cursor-pointer"
                            style="color: var(--acento)"
                          >
                            {{ $t("agenda.roster.transferido") }}
                          </summary>
                          <ul class="mt-2 space-y-2">
                            <li
                              v-for="cambio in r.transferencias"
                              :key="cambio.id"
                            >
                              {{ cambio.de }} → {{ cambio.a }}<br />
                              {{
                                new Date(cambio.fecha).toLocaleString("es-MX")
                              }}
                              ·
                              {{ cambio.por ?? $t("agenda.roster.sistema") }}
                            </li>
                          </ul>
                        </details>
                        <span class="block truncate font-medium">{{
                          r.persona ?? "—"
                        }}</span>
                        <span
                          v-if="lineaRoster(r).length > 0"
                          class="block text-xs"
                        >
                          <span
                            v-for="(d, i) in lineaRoster(r)"
                            :key="d.texto"
                            :style="{ color: d.color }"
                            >{{ i > 0 ? " · " : "" }}{{ d.texto }}</span
                          >
                        </span>
                      </span>
                    </span>
                    <span class="flex items-center gap-2 shrink-0">
                      <button
                        v-if="r.estado === 'ofrecida' && puedeReservar"
                        class="tu-enlace"
                        :disabled="accionando"
                        @click="aceptar(r.id, detalle.id)"
                      >
                        {{ $t("agenda.roster.aceptar") }}
                      </button>
                      <span
                        v-if="r.estado === 'confirmada' && puedeMarcar"
                        class="tu-segmentado"
                        role="group"
                        :aria-label="$t('detalleClase.asistencia')"
                      >
                        <button
                          type="button"
                          :aria-pressed="r.asistencia === 'presente'"
                          :disabled="accionando || r.asistencia === 'presente'"
                          @click="marcar(r.id, 'presente', detalle.id)"
                        >
                          {{ $t("agendaVisual.cita.marcarLlegada") }}
                        </button>
                        <button
                          type="button"
                          :aria-pressed="r.asistencia === 'ausente'"
                          :disabled="accionando || r.asistencia === 'ausente'"
                          @click="marcar(r.id, 'ausente', detalle.id)"
                        >
                          {{ $t("agendaVisual.cita.noAsistio") }}
                        </button>
                      </span>
                      <button
                        v-if="
                          puedeReservar &&
                          !r.asistencia &&
                          (r.estado === 'confirmada' ||
                            r.estado === 'ofrecida') &&
                          puedeVerMiembros
                        "
                        class="tu-enlace"
                        :disabled="accionando"
                        @click="abrirTransferir(r.id)"
                      >
                        {{ $t("agenda.roster.transferir") }}
                      </button>
                      <button
                        v-if="
                          puedeReservar &&
                          r.estado !== 'cancelada' &&
                          !r.asistencia
                        "
                        class="tu-enlace"
                        style="color: var(--error)"
                        :disabled="accionando"
                        :aria-expanded="cancelandoReserva === r.id"
                        @click="
                          cancelandoReserva =
                            cancelandoReserva === r.id ? null : r.id
                        "
                      >
                        {{ $t("agenda.roster.cancelarReserva") }}
                      </button>
                    </span>
                  </div>
                  <!-- Selector inline para transferir (regalar) el lugar a otro miembro -->
                  <form
                    v-if="transferirModel.reservaId === r.id"
                    class="mt-2 flex flex-wrap items-end gap-2 rounded-md p-2"
                    :style="{ background: 'var(--fondo-suave)' }"
                    @submit.prevent="transferir(r.id, detalle.id)"
                  >
                    <div class="min-w-0 grow">
                      <label class="tu-label" :for="`tr-${r.id}`">{{
                        $t("agenda.roster.transferirA")
                      }}</label>
                      <BuscarPersona
                        v-model="transferirModel.personaId"
                        :campo-id="`tr-${r.id}`"
                        :buscar-en="`${base}/miembros`"
                        :parametros="{ tipo: 'miembro' }"
                      />
                    </div>
                    <button
                      class="tu-btn tu-btn-primario"
                      type="submit"
                      :disabled="accionando || transferirModel.personaId === ''"
                    >
                      {{ $t("agenda.roster.confirmarTransfer") }}
                    </button>
                    <button
                      class="tu-btn tu-btn-fantasma"
                      type="button"
                      :disabled="accionando"
                      @click="
                        transferirModel = { reservaId: '', personaId: '' }
                      "
                    >
                      {{ $t("comun.cancelar") }}
                    </button>
                  </form>
                  <ConfirmarCancelacion
                    v-if="cancelandoReserva === r.id"
                    :url="`${base}/reservas/${r.id}/cancelacion`"
                    con-quien
                    :ocupado="accionando"
                    @confirmar="
                      (por) => cancelarReserva(r.id, detalle!.id, por)
                    "
                    @cerrar="cancelandoReserva = null"
                  />
                </li>
              </ul>
            </section>
          </template>

          <!-- Lugares y canales -->
          <template v-else-if="pestanaClase === 'lugares'">
            <!-- Cupos por canal / marketplace (R20) -->
            <section
              v-if="puedeGestionar && detalle.oferta_id"
              class="tu-detalle-seccion"
            >
              <h3>
                {{ $t("agenda.cuposCanal.titulo") }}
              </h3>
              <p class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("agenda.cuposCanal.ayuda") }}
              </p>

              <ul v-if="reglasCanal.length > 0" class="mt-2 space-y-1">
                <li
                  v-for="rc in reglasCanal"
                  :key="rc.id"
                  class="flex items-center justify-between gap-2 text-sm"
                >
                  <span>
                    <span class="tu-badge">{{
                      $t(`agenda.canales.${rc.canal}`)
                    }}</span>
                    {{ $t("agenda.cuposCanal.cupos", { n: rc.cupos }) }}
                    <span
                      v-if="rc.liberar_horas_antes > 0"
                      :style="{ color: 'var(--texto-suave)' }"
                      >·
                      {{
                        $t("agenda.cuposCanal.libera", {
                          h: rc.liberar_horas_antes,
                        })
                      }}</span
                    >
                  </span>
                  <button
                    v-if="puedeEliminar"
                    class="tu-enlace"
                    style="color: var(--error)"
                    type="button"
                    :disabled="guardandoCanal"
                    @click="eliminarReglaCanal(rc)"
                  >
                    {{ $t("comun.eliminar") }}
                  </button>
                </li>
              </ul>

              <form
                class="mt-2 flex flex-wrap items-end gap-2"
                @submit.prevent="guardarReglaCanal"
              >
                <div class="min-w-[110px]">
                  <label class="tu-label" for="rc-canal">{{
                    $t("agenda.reservar.canal")
                  }}</label>
                  <select
                    id="rc-canal"
                    v-model="reglaCanalModel.canal"
                    class="tu-input"
                  >
                    <option
                      v-for="c in CANALES.filter((x) => x !== 'directo')"
                      :key="c"
                      :value="c"
                    >
                      {{ $t(`agenda.canales.${c}`) }}
                    </option>
                  </select>
                </div>
                <div class="w-20">
                  <label class="tu-label" for="rc-cupos">{{
                    $t("agenda.cuposCanal.campoCupos")
                  }}</label>
                  <input
                    id="rc-cupos"
                    v-model.number="reglaCanalModel.cupos"
                    type="number"
                    min="0"
                    class="tu-input"
                  />
                </div>
                <div class="w-24">
                  <label class="tu-label" for="rc-libera">{{
                    $t("agenda.cuposCanal.campoLibera")
                  }}</label>
                  <input
                    id="rc-libera"
                    v-model.number="reglaCanalModel.liberar_horas_antes"
                    type="number"
                    min="0"
                    class="tu-input"
                  />
                </div>
                <button
                  class="tu-btn tu-btn-fantasma"
                  type="submit"
                  :disabled="guardandoCanal"
                >
                  {{ $t("agenda.cuposCanal.guardar") }}
                </button>
              </form>
            </section>

            <!-- Mapa de lugares por clase (R4) -->
            <section
              v-if="puedeCatalogo && detalle.oferta_id"
              class="tu-detalle-seccion"
            >
              <h3>
                {{ $t("agenda.lugares.titulo") }}
              </h3>
              <p class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("agenda.lugares.ayuda") }}
              </p>
              <form
                class="mt-2 flex flex-wrap items-end gap-2"
                @submit.prevent="guardarLugares"
              >
                <div class="w-28">
                  <label class="tu-label" for="lug-n">{{
                    $t("agenda.lugares.numero")
                  }}</label>
                  <input
                    id="lug-n"
                    v-model.number="lugaresModel.lugares"
                    type="number"
                    min="0"
                    class="tu-input"
                  />
                </div>
                <div class="w-32">
                  <label class="tu-label" for="lug-precio">{{
                    $t("agenda.lugares.precio")
                  }}</label>
                  <input
                    id="lug-precio"
                    v-model.number="lugaresModel.precio"
                    type="number"
                    min="0"
                    step="1"
                    class="tu-input"
                    :placeholder="$t('agenda.lugares.precioPh')"
                  />
                </div>
                <button
                  class="tu-btn tu-btn-fantasma"
                  type="submit"
                  :disabled="guardandoLugares"
                >
                  {{ $t("agenda.lugares.guardar") }}
                </button>
              </form>
              <p class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("agenda.lugares.precioAyuda") }}
              </p>
            </section>
          </template>

          <!-- Check-ins de bienestar -->
          <section
            v-else-if="pestanaClase === 'checkins'"
            class="tu-detalle-seccion"
          >
            <h3>{{ $t("checkins.titulo") }}</h3>
            <form
              class="mt-2 flex flex-wrap items-end gap-2"
              @submit.prevent="registrarCheckin(detalle.id)"
            >
              <div class="min-w-[130px]">
                <label class="tu-label" for="cp">{{
                  $t("checkins.proveedor")
                }}</label>
                <select
                  id="cp"
                  v-model="checkinModel.proveedor"
                  class="tu-input"
                >
                  <option value="wellhub">
                    {{ $t("integraciones.proveedores.wellhub") }}
                  </option>
                  <option value="totalpass">
                    {{ $t("integraciones.proveedores.totalpass") }}
                  </option>
                </select>
              </div>
              <div class="flex-1 min-w-[150px]">
                <label class="tu-label" for="cc">{{
                  $t("checkins.codigo")
                }}</label>
                <input
                  id="cc"
                  v-model="checkinModel.codigo"
                  class="tu-input"
                  autocomplete="off"
                  required
                />
              </div>
              <button
                class="tu-btn tu-btn-primario"
                type="submit"
                :disabled="registrandoCheckin || checkinModel.codigo === ''"
              >
                {{
                  registrandoCheckin
                    ? $t("checkins.registrando")
                    : $t("checkins.registrar")
                }}
              </button>
            </form>
            <p
              v-if="okCheckin"
              class="mt-2 text-sm"
              :style="{ color: 'var(--exito)' }"
            >
              {{ $t("checkins.ok") }}
            </p>
            <ul v-if="checkins.length > 0" class="mt-3 space-y-2">
              <li
                v-for="c in checkins"
                :key="c.id"
                class="flex items-center justify-between gap-2 text-sm"
              >
                <span class="truncate">{{ c.usuario ?? "—" }}</span>
                <span class="tu-badge tu-badge-exito shrink-0">{{
                  $t(`checkins.${c.estado}`)
                }}</span>
              </li>
            </ul>
          </section>

          <!-- Equipo: quién la da y sustituciones (R17) -->
          <section v-else class="tu-detalle-seccion">
            <div v-if="detalle.estado === 'programada'" class="mb-4">
              <label class="tu-label" for="inst-clase">{{
                $t("instructorClase.titulo")
              }}</label>
              <select
                id="inst-clase"
                class="tu-input"
                :value="detalle.instructor_id ?? ''"
                :disabled="cambiandoInstructor"
                @change="
                  cambiarInstructor(($event.target as HTMLSelectElement).value)
                "
              >
                <option value="">{{ $t("instructorClase.ninguno") }}</option>
                <option v-for="i in instructores" :key="i.id" :value="i.id">
                  {{ i.nombre }}
                </option>
              </select>
            </div>
            <h3>{{ $t("agenda.staff.titulo") }}</h3>
            <ul v-if="staffSesion.length > 0" class="mt-2 space-y-1.5">
              <li
                v-for="a in staffSesion"
                :key="a.id"
                class="flex items-center gap-2 text-sm"
              >
                <span>{{ a.usuario ?? "—" }}</span>
                <span class="tu-badge">{{
                  $t(`agenda.staff.rol.${a.rol}`)
                }}</span>
              </li>
            </ul>
            <form
              v-if="detalle.estado === 'programada' && instructores.length > 0"
              class="mt-3 grid grid-cols-2 gap-2 items-end"
              @submit.prevent="asignarStaff(detalle.id)"
            >
              <div class="col-span-2">
                <label class="tu-label" for="stu">{{
                  $t("agenda.staff.persona")
                }}</label>
                <select
                  id="stu"
                  v-model="staffModel.usuarioId"
                  class="tu-input"
                  required
                >
                  <option value="" disabled>
                    {{ $t("agenda.reservar.elegir") }}
                  </option>
                  <option v-for="i in instructores" :key="i.id" :value="i.id">
                    {{ i.nombre }}
                  </option>
                </select>
              </div>
              <div>
                <label class="tu-label" for="srol">{{
                  $t("agenda.staff.rolLabel")
                }}</label>
                <select id="srol" v-model="staffModel.rol" class="tu-input">
                  <option value="instructor">
                    {{ $t("agenda.staff.rol.instructor") }}
                  </option>
                  <option value="asistente">
                    {{ $t("agenda.staff.rol.asistente") }}
                  </option>
                  <option value="sustituto">
                    {{ $t("agenda.staff.rol.sustituto") }}
                  </option>
                </select>
              </div>
              <div v-if="staffModel.rol === 'sustituto'">
                <label class="tu-label" for="ssub">{{
                  $t("agenda.staff.sustituye")
                }}</label>
                <select
                  id="ssub"
                  v-model="staffModel.sustituyeA"
                  class="tu-input"
                >
                  <option value="">—</option>
                  <option v-for="i in instructores" :key="i.id" :value="i.id">
                    {{ i.nombre }}
                  </option>
                </select>
              </div>
              <div class="col-span-2 flex justify-end">
                <button
                  class="tu-btn tu-btn-fantasma text-sm"
                  type="submit"
                  :disabled="asignandoStaff || staffModel.usuarioId === ''"
                >
                  {{ $t("agenda.staff.asignar") }}
                </button>
              </div>
            </form>
          </section>
        </template>
      </div>

      <!-- Cambiar horario o serie y cancelar a la izquierda; «Listo» cierra -->
      <template #pie>
        <div
          v-if="detalle && puedeGestionar && detalle.estado === 'programada'"
          class="mr-auto flex flex-wrap gap-2"
        >
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="accionando"
            :aria-expanded="cambiandoHorario"
            @click="
              cambiandoSerie = false;
              cancelandoSesion = false;
              cambiandoHorario = !cambiandoHorario;
            "
          >
            <IconoNav nombre="agenda" :tam="18" />
            {{
              detalle.serie_id
                ? $t("detalleClase.moverEsta")
                : $t("reprogramar.cambiarHorario")
            }}
          </button>
          <button
            v-if="detalle.serie_id && detalle.fecha_serie"
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="accionando"
            :aria-expanded="cambiandoSerie"
            @click="
              cambiandoHorario = false;
              cancelandoSesion = false;
              cambiandoSerie = !cambiandoSerie;
            "
          >
            {{ $t("detalleClase.moverSerie") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            style="color: var(--error)"
            :disabled="accionando"
            :aria-expanded="cancelandoSesion"
            @click="
              cambiandoHorario = false;
              cambiandoSerie = false;
              cancelandoSesion = !cancelandoSesion;
            "
          >
            <IconoNav nombre="cerrar" :tam="18" />
            {{ $t("agenda.sesion.cancelar") }}
          </button>
        </div>
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          @click="cerrarDetalle"
        >
          {{ $t("detalleClase.listo") }}
        </button>
      </template>
    </ModalDialogo>

    <!-- ===== Modal Nueva clase ===== -->
    <ModalDialogo
      :abierto="mostrarNueva"
      :titulo="$t('agenda.nueva.titulo')"
      @cerrar="mostrarNueva = false"
    >
      <p
        v-if="ofertas.length === 0"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("agenda.nueva.sinOfertas") }}
      </p>
      <p
        v-else-if="sucursales.length === 0"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("agenda.nueva.sinSucursales") }}
      </p>
      <form
        v-else
        id="form-nueva-clase"
        class="grid gap-4 sm:grid-cols-2"
        @submit.prevent="crearSesion"
      >
        <!-- Cómo se carga: primero se elige, y según eso cambia el formulario. -->
        <div class="sm:col-span-2">
          <div class="tu-segmentado" role="group">
            <button
              v-for="op in MODOS"
              :key="op"
              type="button"
              :aria-pressed="modo === op"
              @click="modo = op"
            >
              {{ $t(`nuevaClase.modos.${op}`) }}
            </button>
          </div>
          <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t(`nuevaClase.ayuda.${modo}`) }}
          </p>
        </div>

        <div>
          <label class="tu-label" for="ao">{{
            $t("agenda.nueva.oferta")
          }}</label>
          <select id="ao" v-model="form.ofertaId" class="tu-input" required>
            <option value="" disabled>
              {{ $t("agenda.reservar.elegir") }}
            </option>
            <option v-for="o in ofertas" :key="o.id" :value="o.id">
              {{ o.nombre }}
            </option>
          </select>
        </div>
        <div v-if="elegirSucursal">
          <label class="tu-label" for="as">{{
            $t("agenda.nueva.sucursal")
          }}</label>
          <select id="as" v-model="form.sucursalId" class="tu-input" required>
            <option value="" disabled>
              {{ $t("agenda.reservar.elegir") }}
            </option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>
        <LeyendaSucursal v-else />
        <div v-if="instructores.length > 0">
          <label class="tu-label" for="ai">{{
            $t("agenda.nueva.instructor")
          }}</label>
          <select id="ai" v-model="form.instructorId" class="tu-input">
            <option value="">{{ $t("agenda.nueva.sinInstructor") }}</option>
            <option v-for="i in instructores" :key="i.id" :value="i.id">
              {{ i.nombre }}
            </option>
          </select>
        </div>
        <div v-if="recursosDeSucursal.length > 0">
          <label class="tu-label" for="arec">{{
            $t("agenda.nueva.sala")
          }}</label>
          <select id="arec" v-model="form.recursoId" class="tu-input">
            <option value="">{{ $t("agenda.nueva.sinSala") }}</option>
            <option v-for="r in recursosDeSucursal" :key="r.id" :value="r.id">
              {{ r.nombre }}
            </option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-3 sm:col-span-2 sm:grid-cols-4">
          <div>
            <label class="tu-label" for="af">{{
              esRecurrente ? $t("nuevaClase.desde") : $t("nuevaClase.fecha")
            }}</label>
            <input
              id="af"
              v-model="form.fecha"
              class="tu-input"
              type="date"
              required
            />
          </div>
          <div v-if="modo !== 'porDia'">
            <label class="tu-label" for="ah">{{ $t("nuevaClase.hora") }}</label>
            <input
              id="ah"
              v-model="form.hora"
              class="tu-input"
              type="time"
              step="300"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="ad">{{
              $t("agenda.nueva.duracion")
            }}</label>
            <input
              id="ad"
              v-model="form.duracion"
              class="tu-input"
              type="number"
              min="1"
            />
          </div>
          <div>
            <label class="tu-label" for="ac">{{
              $t("agenda.nueva.capacidad")
            }}</label>
            <input
              id="ac"
              v-model="form.capacidad"
              class="tu-input"
              type="number"
              min="1"
            />
          </div>
        </div>

        <!-- Misma hora en varios días -->
        <div v-if="modo === 'misma'" class="sm:col-span-2">
          <span class="tu-label">{{ $t("agenda.nueva.diasSemana") }}</span>
          <div class="mt-1 flex flex-wrap gap-1.5">
            <button
              v-for="d in DIAS_SEMANA"
              :key="d.n"
              type="button"
              class="h-9 w-9 rounded-full text-sm font-semibold"
              :aria-pressed="form.dias.includes(d.n)"
              :aria-label="$t('nuevaClase.nombresDias').split(',')[d.n - 1]"
              :style="
                form.dias.includes(d.n)
                  ? {
                      background: 'var(--primario)',
                      color: 'var(--primario-contraste)',
                    }
                  : {
                      background: 'var(--superficie-2)',
                      color: 'var(--texto)',
                    }
              "
              @click="alternarDia(d.n)"
            >
              {{ d.etiqueta }}
            </button>
          </div>
        </div>

        <!-- Cada día con su hora -->
        <div v-if="modo === 'porDia'" class="sm:col-span-2">
          <span class="tu-label">{{ $t("nuevaClase.horarios") }}</span>
          <ul class="mt-1 space-y-2">
            <li
              v-for="(h, i) in form.horarios"
              :key="i"
              class="flex flex-wrap items-center gap-2"
            >
              <select
                v-model.number="h.dia"
                class="tu-input w-auto"
                :aria-label="$t('nuevaClase.dia')"
              >
                <option v-for="d in DIAS_SEMANA" :key="d.n" :value="d.n">
                  {{ $t("nuevaClase.nombresDias").split(",")[d.n - 1] }}
                </option>
              </select>
              <input
                v-model="h.hora"
                class="tu-input w-auto"
                type="time"
                step="300"
                required
                :aria-label="$t('nuevaClase.hora')"
              />
              <button
                v-if="form.horarios.length > 1"
                type="button"
                class="tu-enlace text-sm"
                @click="quitarHorario(i)"
              >
                {{ $t("nuevaClase.quitar") }}
              </button>
            </li>
          </ul>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma mt-2 text-sm"
            @click="agregarHorario"
          >
            {{ $t("nuevaClase.agregarDia") }}
          </button>
          <p
            v-if="horarioRepetido"
            class="mt-1 text-xs"
            :style="{ color: 'var(--error)' }"
          >
            {{ $t("nuevaClase.repetido") }}
          </p>
        </div>

        <div v-if="esRecurrente" class="sm:col-span-2 sm:max-w-xs">
          <label class="tu-label" for="arh">{{
            $t("agenda.nueva.repetirHasta")
          }}</label>
          <input
            id="arh"
            v-model="form.repetirHasta"
            class="tu-input"
            type="date"
            :min="form.fecha || undefined"
          />
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("agenda.nueva.repetirAyuda", { anio: anioRecurrente }) }}
          </p>
        </div>

        <!-- Conflictos detectados ANTES de guardar (instructor/sala ocupados). -->
        <div
          v-if="conflictos.length > 0"
          class="rounded-lg p-3 text-sm sm:col-span-2"
          :style="{ background: 'var(--aviso-suave)', color: 'var(--aviso)' }"
        >
          <p class="font-semibold">{{ $t("agenda.nueva.conflictos") }}</p>
          <ul class="mt-1 list-disc pl-5">
            <li v-for="(c, i) in conflictos" :key="i">{{ c.mensaje }}</li>
          </ul>
        </div>
      </form>

      <template v-if="ofertas.length > 0 && sucursales.length > 0" #pie>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          @click="mostrarNueva = false"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="submit"
          form="form-nueva-clase"
          :disabled="creando || !listoParaCrear || conflictos.length > 0"
        >
          {{ creando ? $t("agenda.nueva.creando") : $t("agenda.nueva.crear") }}
        </button>
      </template>
    </ModalDialogo>

    <PanelCita
      :abierto="citaAbierta !== null"
      :base="base"
      :sesion="citaAbierta"
      :catalogo="catalogo"
      :puede-marcar="puedeMarcar"
      :puede-cobrar="puedeCobrar"
      :puede-cancelar="puedeReservar"
      :profesionales="instructores"
      @cerrar="citaAbierta = null"
      @cambiada="alCambiarCita"
    />
    <PanelNuevaCita
      v-if="sesion.esCitas"
      :abierto="mostrarNuevaCita"
      :base="base"
      :ofertas="ofertas"
      :sucursales="sucursalActual ? [sucursalActual] : sucursales"
      :profesionales="instructores"
      :inicial="inicialCita"
      :ventanas="ventanas"
      :bloqueos="bloqueos"
      :zona="zonaAgenda"
      @cerrar="mostrarNuevaCita = false"
      @agendada="alAgendarCita"
    />
  </section>
</template>

<style scoped>
/* Detalle de una clase: mismo patrón que el de una cita (PanelCita). */
.dcl {
  display: grid;
  gap: 1.4rem;
}
.dcl-cabeza {
  display: grid;
  gap: 1rem;
  align-items: start;
}
@media (min-width: 768px) {
  .dcl-cabeza {
    grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) auto;
  }
  .dcl-ocupacion {
    padding-left: 1.25rem;
    border-left: 1px solid var(--borde);
  }
}
.dcl-nombre {
  font-size: 1.3rem;
  font-weight: 600;
  line-height: 1.25;
}
.dcl-cifra {
  font-size: 1.15rem;
  font-weight: 600;
}
.dcl-barra {
  height: 0.4rem;
  margin-top: 0.4rem;
  border-radius: 999px;
  background: var(--superficie-2);
  overflow: hidden;
}
.dcl-barra > span {
  display: block;
  height: 100%;
  border-radius: inherit;
}
.dcl-estados {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
@media (min-width: 768px) {
  .dcl-estados {
    flex-direction: column;
    align-items: flex-end;
  }
}
.dcl-roster {
  display: grid;
}
.dcl-roster > li {
  padding: 0.6rem 0;
}
.dcl-roster > li + li {
  border-top: 1px solid var(--borde);
}
/* Navegación de fechas: botones de borde del mismo alto que los filtros. */
.ag-paso {
  padding: 0.55rem;
}
.ag-hoy {
  padding: 0.55rem 1rem;
}
.tu-bloque {
  position: absolute;
  border-radius: 0.5rem;
  padding: 0.2rem 0.4rem;
  overflow: hidden;
  cursor: pointer;
  text-align: left;
  border-left: 3px solid var(--primario);
  background: var(--primario-suave);
  color: var(--texto);
  transition:
    filter 0.1s ease,
    transform 0.05s ease;
}
.tu-bloque:hover {
  filter: brightness(0.97);
}
.tu-bloque:active {
  transform: scale(0.99);
}
.tu-bloque--cancelada {
  background: var(--superficie-2);
  border-color: var(--texto-suave);
  color: var(--texto-suave);
  text-decoration: line-through;
}
.tu-ocupa {
  height: 5px;
  border-radius: 9999px;
  background: var(--borde);
  overflow: hidden;
}
.tu-ocupa > span {
  display: block;
  height: 100%;
  border-radius: 9999px;
}
.tu-estado-dot {
  display: inline-block;
  height: 6px;
  width: 6px;
  border-radius: 9999px;
  flex-shrink: 0;
}
.tu-espera-dot {
  display: inline-block;
  height: 6px;
  width: 6px;
  border-radius: 9999px;
  background: var(--aviso);
  flex-shrink: 0;
}
</style>
