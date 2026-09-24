<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AgendaClasesSemana from "@/components/AgendaClasesSemana.vue";
import AgendaKpis from "@/components/AgendaKpis.vue";
import AgendaProfesionales from "@/components/AgendaProfesionales.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import PanelCita from "@/components/PanelCita.vue";
import PanelNuevaCita from "@/components/PanelNuevaCita.vue";
import {
  kpisCitas,
  kpisClases,
  tonoServicio,
  type CitaTitular,
  type SesionAgenda,
  type VentanaAtencion,
} from "@/lib/agenda";
import { api, mensajeDeError } from "@/lib/api";
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
}
interface Recurso {
  id: string;
  nombre: string;
  sucursal_id: string | null;
  activo: boolean;
}
interface Miembro {
  id: string;
  nombre: string;
  nombre_completo: string;
}
interface Reserva {
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
// Lugares y precio por clase viven en la oferta: los edita quien gestiona el catálogo.
const puedeCatalogo = computed(() => sesion.puede("catalogo.gestionar"));
const puedeReservar = computed(() => sesion.puede("reservas.gestionar"));
const puedeMarcar = computed(() => sesion.puede("asistencia.marcar"));
const puedeCheckin = computed(() => sesion.puede("checkins.registrar"));

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
const miembros = ref<Miembro[]>([]);
const instructores = ref<{ id: string; nombre: string }[]>([]);
const recursos = ref<Recurso[]>([]);
// Horario de atención de cada profesional (sombrea lo que queda fuera en citas).
const ventanas = ref<VentanaAtencion[]>([]);
const cargando = ref(true);
const cargandoSesiones = ref(false);
const error = ref<string | null>(null);

// ---- Calendario (semana / dia / por profesional) ----
// Citas: el día en columnas por profesional (o la semana). Clases: la semana con
// cupos (o la lista del día).
type Vista = "semana" | "dia" | "profesionales";
const opcionesVista = computed<Vista[]>(() =>
  sesion.esCitas ? ["profesionales", "semana"] : ["semana", "dia"],
);
const vista = ref<Vista>(sesion.esCitas ? "profesionales" : "semana");
const semanaInicio = ref(lunesDe(new Date()));
const diaSel = ref(isoDe(new Date()));
const sucursalFiltro = ref("");
const instructorFiltro = ref("");

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
function nombreMiembro(m: Miembro): string {
  return m.nombre_completo || m.nombre;
}

// Instructor se filtra en cliente (el server ya filtra por sucursal + rango).
const sesionesVisibles = computed(() =>
  instructorFiltro.value === ""
    ? sesiones.value
    : sesiones.value.filter((s) => s.instructor_id === instructorFiltro.value),
);
function sesionesDe(iso: string): Sesion[] {
  return sesionesVisibles.value
    .filter((s) => fechaLocalSesion(s.inicia_en, s.zona_horaria) === iso)
    .sort((a, b) => a.inicia_en.localeCompare(b.inicia_en));
}
const diaSelInfo = computed(
  () => dias.value.find((d) => d.iso === diaSel.value) ?? dias.value[0],
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
  error.value = null;
  try {
    const [o, s, m] = await Promise.all([
      api.get<{ data: Oferta[] }>(`${base.value}/ofertas`),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
      api.get<{ data: Miembro[] }>(`${base.value}/miembros`, {
        params: { tipo: "miembro" },
      }),
    ]);
    ofertas.value = o.data.data;
    sucursales.value = s.data.data;
    miembros.value = m.data.data;
    // Profesionales (id + nombre) para filtrar y para las columnas por profesional.
    const i = await api.get<{ data: { id: string; nombre: string }[] }>(
      `${base.value}/instructores`,
    );
    instructores.value = i.data.data;
    if (sesion.esCitas) {
      const v = await api.get<{ data: VentanaAtencion[] }>(
        `${base.value}/horarios-atencion`,
      );
      ventanas.value = v.data.data;
    }
    if (puedeGestionar.value) {
      const r = await api.get<{ data: Recurso[] }>(`${base.value}/recursos`);
      recursos.value = r.data.data.filter((x) => x.activo);
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function cargarSesiones(): Promise<void> {
  cargandoSesiones.value = true;
  error.value = null;
  try {
    const params: Record<string, string> = {
      desde: dias.value[0].iso,
      hasta: dias.value[6].iso,
    };
    if (sucursalFiltro.value !== "") {
      params.sucursal_id = sucursalFiltro.value;
    }
    const { data } = await api.get<{ data: Sesion[] }>(
      `${base.value}/sesiones`,
      { params },
    );
    sesiones.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargandoSesiones.value = false;
  }
}

// Recarga las sesiones al cambiar de semana o de sucursal.
watch([semanaInicio, sucursalFiltro], cargarSesiones);

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
const catalogo = computed(() => ofertas.value.map((o) => o.id));
const zonaAgenda = computed(
  () =>
    sucursales.value.find((s) => s.id === sucursalFiltro.value)?.zona_horaria ??
    sesiones.value[0]?.zona_horaria ??
    sucursales.value[0]?.zona_horaria ??
    "America/Mexico_City",
);
// Leyenda: solo los servicios/clases que aparecen en lo que se está viendo.
const leyenda = computed(() => {
  const presentes = new Set(sesionesVisibles.value.map((s) => s.oferta_id));
  return ofertas.value
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
const tarjetasKpi = computed(() => {
  const ahora = new Date();
  if (sesion.esCitas) {
    const k = kpisCitas(
      sesionesVisibles.value.filter(
        (s) =>
          s.tipo === "cita" &&
          fechaLocalSesion(s.inicia_en, s.zona_horaria) === diaSel.value,
      ),
      ahora,
    );
    return [
      {
        clave: "citas",
        valor: String(k.citas),
        etiqueta: t("agendaVisual.kpis.citas"),
      },
      {
        clave: "local",
        valor: String(k.enLocal),
        etiqueta: t("agendaVisual.kpis.enLocal"),
      },
      {
        clave: "cobrar",
        valor: dineroMx(k.porCobrarMinor),
        etiqueta: t("agendaVisual.kpis.porCobrar", { n: k.pendientesPago }),
      },
      {
        clave: "ausentes",
        valor: String(k.noAsistieron),
        etiqueta: t("agendaVisual.kpis.noAsistieron"),
      },
    ];
  }
  const k = kpisClases(sesionesVisibles.value, ahora);
  return [
    {
      clave: "clases",
      valor: String(k.clases),
      etiqueta: t("agendaVisual.kpis.clases"),
    },
    {
      clave: "ocupacion",
      valor: k.ocupacionPct !== null ? `${k.ocupacionPct}%` : "—",
      etiqueta: t("agendaVisual.kpis.ocupacion"),
    },
    {
      clave: "reservados",
      valor: String(k.reservados),
      etiqueta: t("agendaVisual.kpis.reservados"),
    },
    {
      clave: "espera",
      valor: String(k.enEspera),
      etiqueta: t("agendaVisual.kpis.enEspera"),
    },
    {
      clave: "libres",
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

async function abrirDetalle(s: Sesion): Promise<void> {
  detalle.value = s;
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
async function cancelarReserva(
  reservaId: string,
  sesionId: string,
): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/reservas/${reservaId}/cancelar`, {});
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
// El usuario elige primero el tipo de carga; según eso se muestra el formulario
// correcto (una sola clase vs. varias que se repiten cada semana).
const modo = ref<"una" | "varias">("una");
const esRecurrente = computed(() => modo.value === "varias");
const form = ref({
  ofertaId: "",
  sucursalId: "",
  instructorId: "",
  recursoId: "",
  fecha: "",
  duracion: "60",
  capacidad: "",
  dias: [] as number[],
  repetirHasta: "",
});
// Año de la fecha elegida: por defecto la recurrencia llega hasta el 31-dic de ese año.
const anioRecurrente = computed(() =>
  form.value.fecha !== ""
    ? form.value.fecha.slice(0, 4)
    : String(new Date().getFullYear()),
);
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
        inicia_en_local: form.value.fecha.replace("T", " ") + ":00",
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
// Al pasar a "varias", prefija el día de la semana de la fecha elegida.
watch(modo, () => {
  if (
    esRecurrente.value &&
    form.value.dias.length === 0 &&
    form.value.fecha !== ""
  ) {
    form.value.dias = [diaIsoDe(form.value.fecha.slice(0, 10))];
  }
});

async function crearSesion(): Promise<void> {
  creando.value = true;
  error.value = null;
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
        inicia_en_local: form.value.fecha.replace("T", " ") + ":00",
        duracion_minutos: Number(form.value.duracion),
        capacidad:
          form.value.capacidad !== "" ? Number(form.value.capacidad) : null,
      });
    }
    trackEvent("class_schedule_created", { recurring: esRecurrente.value });
    form.value.fecha = "";
    form.value.capacidad = "";
    form.value.recursoId = "";
    modo.value = "una";
    form.value.dias = [];
    form.value.repetirHasta = "";
    mostrarNueva.value = false;
    await cargarSesiones();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    creando.value = false;
  }
}

// Crea una plantilla de horario recurrente y materializa sus sesiones del rango.
async function crearRecurrente(): Promise<void> {
  const fechaYmd = form.value.fecha.slice(0, 10);
  const hora = form.value.fecha.slice(11, 16);
  const dias =
    form.value.dias.length > 0
      ? [...form.value.dias].sort((a, b) => a - b)
      : [diaIsoDe(fechaYmd)];
  // Por defecto (sin fecha final) se generan las clases hasta el 31-dic del año
  // de la fecha elegida y la plantilla queda acotada ahí (no se extiende sola).
  const finAnio = `${fechaYmd.slice(0, 4)}-12-31`;
  const hasta =
    form.value.repetirHasta !== "" ? form.value.repetirHasta : finAnio;

  const { data } = await api.post<{ data: { id: string } }>(
    `${base.value}/plantillas-horario`,
    {
      oferta_id: form.value.ofertaId,
      sucursal_id: form.value.sucursalId,
      instructor_id:
        form.value.instructorId !== "" ? form.value.instructorId : null,
      // La sala elegida también aplica a las clases que se repiten.
      recurso_id: form.value.recursoId !== "" ? form.value.recursoId : null,
      dias_semana: dias,
      hora_local: hora,
      duracion_minutos: Number(form.value.duracion),
      capacidad:
        form.value.capacidad !== "" ? Number(form.value.capacidad) : null,
      vigente_desde: fechaYmd,
      vigente_hasta: hasta,
    },
  );

  await api.post(`${base.value}/plantillas-horario/${data.data.id}/generar`, {
    desde: fechaYmd,
    hasta,
  });
}

onMounted(async () => {
  await cargarReferencias();
  await cargarSesiones();
});
</script>

<template>
  <section class="px-4 sm:px-6 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion :titulo="$t('agenda.titulo')" />
      <!-- Citas: el negocio agenda al cliente. Clases: se programa una clase. -->
      <button
        v-if="sesion.esCitas && puedeReservar"
        class="tu-btn tu-btn-primario"
        @click="abrirNuevaCita()"
      >
        {{ $t("agendaVisual.nuevaCita.boton") }}
      </button>
      <button
        v-else-if="!sesion.esCitas && puedeGestionar"
        class="tu-btn tu-btn-primario"
        @click="mostrarNueva = true"
      >
        {{ $t("agenda.nuevaClase") }}
      </button>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Barra de herramientas: filtros + navegacion + vista -->
      <div class="mt-6 flex flex-wrap items-center gap-2 sm:gap-3">
        <select
          v-model="sucursalFiltro"
          class="tu-input w-auto"
          :aria-label="$t('agenda.nueva.sucursal')"
        >
          <option value="">{{ $t("agenda.todasSucursales") }}</option>
          <option v-for="s in sucursales" :key="s.id" :value="s.id">
            {{ s.nombre }}
          </option>
        </select>
        <select
          v-if="instructores.length > 0"
          v-model="instructorFiltro"
          class="tu-input w-auto"
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

        <div class="flex items-center gap-1 ml-auto">
          <button
            class="tu-icono-btn"
            :aria-label="
              vista === 'profesionales'
                ? $t('agendaVisual.diaAnterior')
                : $t('agenda.semanaAnterior')
            "
            @click="irPaso(-1)"
          >
            <IconoNav nombre="chevron" :tam="18" class="rotate-180" />
          </button>
          <button class="tu-btn tu-btn-fantasma px-3 py-1.5" @click="irHoy">
            {{ $t("agenda.hoy") }}
          </button>
          <button
            class="tu-icono-btn"
            :aria-label="
              vista === 'profesionales'
                ? $t('agendaVisual.diaSiguiente')
                : $t('agenda.semanaSiguiente')
            "
            @click="irPaso(1)"
          >
            <IconoNav nombre="chevron" :tam="18" />
          </button>
          <span
            class="text-sm font-medium ml-1 hidden sm:inline first-letter:uppercase"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ vista === "profesionales" ? diaTexto : rangoTexto }}</span
          >
        </div>

        <!-- Alternar vista según la modalidad (citas: por profesional / semana;
             clases: semana / día). En móvil, las clases siempre se ven por día. -->
        <div
          class="tu-segmentado"
          :class="{ 'hidden lg:inline-flex': !sesion.esCitas }"
          role="group"
        >
          <button
            v-for="op in opcionesVista"
            :key="op"
            type="button"
            :aria-pressed="vista === op"
            @click="vista = op"
          >
            {{ $t(`agendaVisual.vistas.${op}`) }}
          </button>
        </div>
      </div>

      <!-- Indicadores del día (citas) o de la semana (clases). -->
      <AgendaKpis class="mt-4" :tarjetas="tarjetasKpi" />

      <!-- Leyenda: el color identifica el servicio o la clase. -->
      <div
        v-if="leyenda.length > 0"
        class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
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

      <!-- ===== Vista POR PROFESIONAL (citas): el día en columnas ===== -->
      <AgendaProfesionales
        v-if="vista === 'profesionales'"
        class="mt-4"
        :fecha="diaSel"
        :zona="zonaAgenda"
        :sesiones="sesionesVisibles"
        :profesionales="profesionalesVisibles"
        :ventanas="ventanas"
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

      <!-- ===== Vista DIA (movil siempre; escritorio si vista dia) ===== -->
      <div
        v-if="vista !== 'profesionales'"
        :class="vista === 'dia' ? 'mt-4' : 'mt-4 lg:hidden'"
      >
        <!-- Tira de dias -->
        <div class="flex gap-1.5 overflow-x-auto pb-2">
          <button
            v-for="d in dias"
            :key="d.iso"
            class="flex-1 min-w-[3rem] rounded-xl border py-2 text-center"
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
                  <span class="inline-flex items-center gap-1.5 text-xs">
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
    </template>

    <!-- ===== Panel de detalle (drawer) ===== -->
    <div v-if="detalle" class="fixed inset-0 z-50 flex justify-end">
      <div class="absolute inset-0 bg-black/50" @click="cerrarDetalle" />
      <aside
        class="relative w-full max-w-md h-full overflow-y-auto p-5"
        :style="{ background: 'var(--superficie)' }"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold">
              {{ detalle.oferta ?? "—" }}
            </h2>
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ horaCorta(detalle.inicia_en, detalle.zona_horaria) }}–{{
                horaCorta(detalle.termina_en, detalle.zona_horaria)
              }}
              <span v-if="detalle.instructor"> · {{ detalle.instructor }}</span>
              <span v-if="detalle.sala"> · {{ detalle.sala }}</span>
            </p>
            <span
              class="tu-badge mt-1 inline-block"
              :class="completo(detalle) ? 'tu-badge-aviso' : 'tu-badge-exito'"
            >
              {{
                detalle.capacidad !== null
                  ? `${detalle.ocupados}/${detalle.capacidad}`
                  : `${detalle.ocupados}`
              }}
              <span v-if="completo(detalle)">
                · {{ $t("agenda.completo") }}</span
              >
            </span>
          </div>
          <button
            class="tu-icono-btn"
            :aria-label="$t('agenda.cerrarDetalle')"
            @click="cerrarDetalle"
          >
            <IconoNav nombre="cerrar" :tam="18" />
          </button>
        </div>

        <div v-if="detalle.estado === 'programada'" class="mt-4">
          <!-- Reservar -->
          <form
            v-if="puedeReservar && miembros.length > 0"
            class="flex flex-wrap items-end gap-2"
            @submit.prevent="reservar(detalle.id)"
          >
            <div class="flex-1 min-w-[160px]">
              <label class="tu-label" for="rm">{{
                $t("agenda.reservar.miembro")
              }}</label>
              <select
                id="rm"
                v-model="reservarModel.miembroId"
                class="tu-input"
                required
              >
                <option value="" disabled>
                  {{ $t("agenda.reservar.elegir") }}
                </option>
                <option v-for="m in miembros" :key="m.id" :value="m.id">
                  {{ nombreMiembro(m) }}
                </option>
              </select>
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
              <label class="tu-label">{{ $t("agenda.lugares.elige") }}</label>
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
                    lugaresTomados.has(n) ? $t('agenda.lugares.ocupado') : ''
                  "
                  @click="
                    reservarModel.lugar = reservarModel.lugar === n ? null : n
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
        </div>

        <!-- Roster -->
        <div
          class="mt-4 border-t pt-4"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <h3 class="font-semibold text-sm">
            {{ $t("agenda.sesion.reservas") }}
          </h3>
          <p
            v-if="cargandoRoster"
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
          <ul v-else class="mt-2 space-y-2">
            <li v-for="r in roster" :key="r.id" class="text-sm">
              <div class="flex items-center justify-between gap-2">
                <span class="min-w-0">
                  <span class="block truncate font-medium">{{
                    r.persona ?? "—"
                  }}</span>
                  <span v-if="lineaRoster(r).length > 0" class="block text-xs">
                    <span
                      v-for="(d, i) in lineaRoster(r)"
                      :key="d.texto"
                      :style="{ color: d.color }"
                      >{{ i > 0 ? " · " : "" }}{{ d.texto }}</span
                    >
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
                  <template v-if="r.estado === 'confirmada'">
                    <button
                      v-if="puedeMarcar"
                      class="tu-enlace"
                      :disabled="accionando"
                      @click="marcar(r.id, 'presente', detalle.id)"
                    >
                      {{ $t("agenda.roster.marcarPresente") }}
                    </button>
                    <button
                      v-if="puedeMarcar"
                      class="tu-enlace"
                      :disabled="accionando"
                      @click="marcar(r.id, 'ausente', detalle.id)"
                    >
                      {{ $t("agenda.roster.marcarAusente") }}
                    </button>
                  </template>
                  <button
                    v-if="
                      puedeReservar &&
                      !r.asistencia &&
                      (r.estado === 'confirmada' || r.estado === 'ofrecida') &&
                      miembros.length > 0
                    "
                    class="tu-enlace"
                    :disabled="accionando"
                    @click="abrirTransferir(r.id)"
                  >
                    {{ $t("agenda.roster.transferir") }}
                  </button>
                  <button
                    v-if="puedeReservar && r.estado !== 'cancelada'"
                    class="tu-enlace"
                    style="color: var(--error)"
                    :disabled="accionando"
                    @click="cancelarReserva(r.id, detalle.id)"
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
                  <select
                    :id="`tr-${r.id}`"
                    v-model="transferirModel.personaId"
                    class="tu-input"
                    required
                  >
                    <option value="" disabled>
                      {{ $t("agenda.reservar.elegir") }}
                    </option>
                    <option v-for="m in miembros" :key="m.id" :value="m.id">
                      {{ nombreMiembro(m) }}
                    </option>
                  </select>
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
                  @click="transferirModel = { reservaId: '', personaId: '' }"
                >
                  {{ $t("comun.cancelar") }}
                </button>
              </form>
            </li>
          </ul>
        </div>

        <!-- Cupos por canal / marketplace (R20) -->
        <div
          v-if="puedeGestionar && detalle.oferta_id"
          class="mt-4 border-t pt-4"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <h3 class="font-semibold text-sm">
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
        </div>

        <!-- Mapa de lugares por clase (R4) -->
        <div
          v-if="puedeCatalogo && detalle.oferta_id"
          class="mt-4 border-t pt-4"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <h3 class="font-semibold text-sm">
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
        </div>

        <!-- Check-ins de bienestar -->
        <div
          v-if="puedeCheckin"
          class="mt-4 border-t pt-4"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <h3 class="font-semibold text-sm">{{ $t("checkins.titulo") }}</h3>
          <form
            class="mt-2 flex flex-wrap items-end gap-2"
            @submit.prevent="registrarCheckin(detalle.id)"
          >
            <div class="min-w-[130px]">
              <label class="tu-label" for="cp">{{
                $t("checkins.proveedor")
              }}</label>
              <select id="cp" v-model="checkinModel.proveedor" class="tu-input">
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
        </div>

        <!-- Staff / sustituciones (R17) -->
        <div
          v-if="puedeGestionar"
          class="mt-4 border-t pt-4"
          :style="{ borderColor: 'var(--borde)' }"
        >
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
          <h3 class="font-semibold text-sm">{{ $t("agenda.staff.titulo") }}</h3>
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
        </div>

        <!-- Cancelar clase -->
        <div
          v-if="puedeGestionar && detalle.estado === 'programada'"
          class="mt-5 border-t pt-4"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <button
            class="tu-enlace text-sm"
            style="color: var(--error)"
            :disabled="accionando"
            @click="cancelarSesion(detalle.id)"
          >
            {{ $t("agenda.sesion.cancelar") }}
          </button>
        </div>
      </aside>
    </div>

    <!-- ===== Modal Nueva clase ===== -->
    <div
      v-if="mostrarNueva"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
      <div class="absolute inset-0 bg-black/50" @click="mostrarNueva = false" />
      <div class="relative tu-card w-full max-w-lg p-6">
        <div class="flex items-center justify-between">
          <h2 class="font-light text-lg">{{ $t("agenda.nueva.titulo") }}</h2>
          <button
            class="tu-icono-btn"
            :aria-label="$t('agenda.cerrarDetalle')"
            @click="mostrarNueva = false"
          >
            ✕
          </button>
        </div>
        <p
          v-if="ofertas.length === 0"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("agenda.nueva.sinOfertas") }}
        </p>
        <p
          v-else-if="sucursales.length === 0"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("agenda.nueva.sinSucursales") }}
        </p>
        <form
          v-else
          class="mt-3 grid sm:grid-cols-2 gap-3"
          @submit.prevent="crearSesion"
        >
          <!-- Tipo de carga: primero se elige, y según eso cambia el formulario. -->
          <div
            class="sm:col-span-2 inline-flex rounded-lg border p-1"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <button
              v-for="op in ['una', 'varias'] as const"
              :key="op"
              type="button"
              class="px-3 py-1.5 rounded-md text-sm font-semibold"
              :style="{
                background: modo === op ? 'var(--primario)' : 'transparent',
                color:
                  modo === op ? 'var(--primario-contraste)' : 'var(--texto)',
              }"
              @click="modo = op"
            >
              {{
                op === "una"
                  ? $t("agenda.nueva.modoUna")
                  : $t("agenda.nueva.modoVarias")
              }}
            </button>
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
          <div>
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
          <div>
            <label class="tu-label" for="af">{{
              esRecurrente
                ? $t("agenda.nueva.primeraClase")
                : $t("agenda.nueva.fecha")
            }}</label>
            <input
              id="af"
              v-model="form.fecha"
              class="tu-input"
              type="datetime-local"
              required
            />
          </div>
          <div class="grid grid-cols-2 gap-2">
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
          <div v-if="instructores.length > 0" class="sm:col-span-2">
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
          <div v-if="recursosDeSucursal.length > 0" class="sm:col-span-2">
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

          <!-- Recurrencia (solo en modo "varias"): "una clase todos los martes" (R5). -->
          <div
            v-if="esRecurrente"
            class="sm:col-span-2 rounded-lg border p-3 space-y-3"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <div>
              <span class="tu-label">{{ $t("agenda.nueva.diasSemana") }}</span>
              <div class="flex gap-1 mt-1">
                <button
                  v-for="d in DIAS_SEMANA"
                  :key="d.n"
                  type="button"
                  class="h-9 w-9 rounded-full text-sm font-semibold"
                  :style="
                    form.dias.includes(d.n)
                      ? { background: 'var(--primario)', color: '#fff' }
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
            <div>
              <label class="tu-label" for="arh">{{
                $t("agenda.nueva.repetirHasta")
              }}</label>
              <input
                id="arh"
                v-model="form.repetirHasta"
                class="tu-input"
                type="date"
              />
              <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("agenda.nueva.repetirAyuda", { anio: anioRecurrente }) }}
              </p>
            </div>
          </div>

          <!-- Conflictos detectados ANTES de guardar (instructor/sala ocupados). -->
          <div
            v-if="conflictos.length > 0"
            class="sm:col-span-2 rounded-lg p-3 text-sm"
            :style="{ background: 'var(--aviso-suave)', color: 'var(--aviso)' }"
          >
            <p class="font-semibold">{{ $t("agenda.nueva.conflictos") }}</p>
            <ul class="mt-1 list-disc pl-5">
              <li v-for="(c, i) in conflictos" :key="i">{{ c.mensaje }}</li>
            </ul>
          </div>

          <div class="sm:col-span-2 flex justify-end gap-2">
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
              :disabled="
                creando ||
                form.ofertaId === '' ||
                form.sucursalId === '' ||
                form.fecha === '' ||
                (esRecurrente && form.dias.length === 0) ||
                conflictos.length > 0
              "
            >
              {{
                creando ? $t("agenda.nueva.creando") : $t("agenda.nueva.crear")
              }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <PanelCita
      :abierto="citaAbierta !== null"
      :base="base"
      :sesion="citaAbierta"
      :catalogo="catalogo"
      :puede-marcar="puedeMarcar"
      :puede-cobrar="puedeCobrar"
      :puede-cancelar="puedeReservar"
      @cerrar="citaAbierta = null"
      @cambiada="alCambiarCita"
    />
    <PanelNuevaCita
      v-if="sesion.esCitas"
      :abierto="mostrarNuevaCita"
      :base="base"
      :ofertas="ofertas"
      :sucursales="sucursales"
      :profesionales="instructores"
      :clientes="miembros"
      :inicial="inicialCita"
      @cerrar="mostrarNuevaCita = false"
      @agendada="alAgendarCita"
    />
  </section>
</template>

<style scoped>
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
.tu-bloque--programada {
  background: var(--primario-suave);
  border-color: var(--primario);
}
.tu-bloque--completa {
  background: rgba(245, 158, 11, 0.18);
  border-color: #f59e0b;
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
.tu-punto {
  display: inline-block;
  width: 0.85rem;
  height: 0.85rem;
  border-radius: 0.25rem;
}
</style>
