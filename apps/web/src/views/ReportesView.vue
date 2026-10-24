<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute, useRouter } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import EmbudoNegocio, { type Embudo } from "@/components/EmbudoNegocio.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import MapaDemanda from "@/components/MapaDemanda.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { puedeEntrar } from "@/lib/acceso";
import { api, mensajeDeError } from "@/lib/api";
import {
  fechaEnUrl,
  opcionEnUrl,
  sinValorPorOmision,
  useFiltrosEnUrl,
} from "@/lib/filtrosEnUrl";
import { dinero as dineroDelPais } from "@/lib/formato";
import { hoyEnNegocio, inicioDeMesEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { plural } from "@/lib/terminologia";

const { t } = useI18n();

interface Negocio {
  moneda: string;
  ingresos_minor: number;
  ordenes_pagadas: number;
  clases: number;
  ocupacion_pct: number | null;
  // Horas agendadas entre las disponibles (ADR 0081): la de negocios de citas.
  ocupacion_agenda_pct?: number | null;
  no_show_pct: number | null;
  // Las bases de los porcentajes (ADR 0166): se muestran junto al %.
  confirmadas?: number;
  capacidad_total?: number;
  presentes?: number;
  ausentes?: number;
  alumnos_activos: number;
  arpu_minor: number | null;
  // Por moneda (nunca se suman): vendido, cobrado, devuelto y neto.
  dinero_por_moneda?: {
    moneda: string;
    ventas_minor: number;
    cobrado_minor: number;
    devuelto_minor: number;
    neto_minor: number;
  }[];
}
interface SucursalReporte {
  id: string;
  nombre: string;
  region: string | null;
  moneda: string | null;
  miembros_activos: number;
  sesiones_proximas: number;
}
// `GET /reportes/sucursales`: las sucursales, quienes no tienen una y los totales.
interface ReporteSucursales {
  sucursales: SucursalReporte[];
  sin_sucursal: { miembros_activos: number };
  totales: {
    sucursales: number;
    miembros_activos: number;
    sesiones_proximas: number;
  };
}
interface RentabilidadOferta {
  id: string | null;
  oferta: string;
  precio_clase_minor: number | null;
  sesiones: number;
  asistentes: number;
  ingreso_minor: number;
  costo_minor: number;
  margen_minor: number;
  sin_costo_unitario: number;
}
interface Rentabilidad {
  moneda: string;
  totales: {
    asistentes: number;
    ingreso_minor: number;
    costo_minor: number;
    margen_minor: number;
    sin_costo_unitario: number;
  };
  ofertas: RentabilidadOferta[];
}
interface DemandaCelda {
  dia: number;
  hora: number;
  sesiones: number;
  capacidad: number;
  confirmadas: number;
  espera: number;
  ocupacion_pct: number | null;
  // Ocupación de la agenda de quienes atienden (ADR 0081).
  disponible_min?: number;
  agendado_min?: number;
  utilizacion_pct?: number | null;
}
interface DemandaActividad {
  id: string | null;
  actividad: string;
  sesiones: number;
  capacidad: number;
  confirmadas: number;
  espera: number;
  ocupacion_pct: number | null;
}
interface Demanda {
  totales: {
    sesiones: number;
    capacidad: number;
    confirmadas: number;
    espera: number;
    ocupacion_pct: number | null;
    disponible_min?: number;
    agendado_min?: number;
    utilizacion_pct?: number | null;
  };
  matriz: DemandaCelda[];
  actividades: DemandaActividad[];
}
// `GET /reportes/equipo` (ADR 0081): la agenda de cada quien atiende.
interface CifrasEquipo {
  clases: number;
  citas: number;
  agendado_min: number;
  disponible_min: number;
  agendado_en_horario_min: number;
  presentes: number;
  ausentes: number;
  canceladas: number;
  ingreso_minor: number;
  costo_minor: number;
  margen_minor: number;
  ocupacion_pct: number | null;
  inasistencia_pct: number | null;
}
interface ProfesionalEquipo extends CifrasEquipo {
  id: string | null;
  nombre: string | null;
  foto_url: string | null;
}
interface Equipo {
  moneda: string;
  totales: CifrasEquipo;
  profesionales: ProfesionalEquipo[];
}
// Lo vendido cuenta por la fecha de la compra; lo cobrado, devuelto y neto, por la
// fecha en que el dinero entró o salió (como el corte de caja).
interface DineroTendencia {
  ventas: number;
  ventas_minor: number;
  cobrado_minor: number;
  devuelto_minor: number;
  neto_minor: number;
}
interface PuntoSerie extends DineroTendencia {
  fecha: string;
}
interface Tendencias {
  agrupacion: string;
  moneda: string;
  serie: PuntoSerie[];
  por_producto: {
    producto: string;
    ventas_minor: number;
    unidades: number;
  }[];
  totales: DineroTendencia & { ticket_promedio_minor: number | null };
  // Dinero en otras monedas (de antes de elegir una sola): aparte, nunca sumado.
  otras_monedas: (Omit<DineroTendencia, "ventas"> & { moneda: string })[];
}
interface Cohorte {
  mes: string;
  tamano: number;
  retencion: (number | null)[];
}
interface Cohortes {
  meses: number;
  cohortes: Cohorte[];
  conversion: { registrados: number; compraron: number; activos: number };
  // Cómo conocieron al negocio las altas de la ventana (ADR 0067).
  origenes?: { origen: string; total: number }[];
  origenes_sin_dato?: number;
}

const sesion = useSesionTenantStore();
const route = useRoute();
const router = useRouter() as ReturnType<typeof useRouter> | undefined;
// Desde Equipo › Rendimiento (`?vista=equipo`): solo el reporte del equipo.
const soloRendimiento = computed(() => route?.query.vista === "equipo");
// Quien trabaja solo: el reporte del equipo es su propia agenda (sin pago ni margen).
const trabajaSolo = computed(() => sesion.conEquipo === false);
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// El mes en curso y «hoy» del negocio (su zona horaria), no del navegador.
function inicioMes(): string {
  return inicioDeMesEnNegocio(sesion.zonaHoraria);
}

const desde = ref(inicioMes());
const hasta = ref(hoyEnNegocio(sesion.zonaHoraria));
const negocio = ref<Negocio | null>(null);
const sucursales = ref<SucursalReporte[]>([]);
const sinSucursal = ref(0);
const totalesSucursales = ref<ReporteSucursales["totales"] | null>(null);
const rentabilidad = ref<Rentabilidad | null>(null);
const demanda = ref<Demanda | null>(null);
const equipo = ref<Equipo | null>(null);
const tendencias = ref<Tendencias | null>(null);
const agrupacion = ref<"dia" | "semana" | "mes">("dia");
const exportando = ref(false);
const cohortes = ref<Cohortes | null>(null);
const embudo = ref<Embudo | null>(null);
// Porcentaje de cada origen sobre quienes dijeron cómo nos conocieron.
function pctOrigen(total: number): string {
  const conDato = (cohortes.value?.origenes ?? []).reduce(
    (n, o) => n + o.total,
    0,
  );
  return conDato > 0 ? `${Math.round((total / conDato) * 100)} %` : "—";
}

// Etiqueta corta del mes de una cohorte ('2026-09' → 'sep 26').
function mesCorto(iso: string): string {
  const [y, m] = iso.split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    month: "short",
    year: "2-digit",
  }).format(new Date(y, m - 1, 1));
}
// % de conversión de un paso respecto a los registrados.
function pctConv(n: number): string {
  const base = cohortes.value?.conversion.registrados ?? 0;
  return base > 0 ? `${Math.round((n / base) * 100)}%` : "—";
}
const cargando = ref(true);
// Un error por pestaña: el de Ingresos no se queda en Resumen, y cada una se reintenta.
const errores = ref<Partial<Record<Pestana, string>>>({});
const error = computed(() => errores.value[pestana.value] ?? null);

// Qué muestra la gráfica: el dinero que entró menos devoluciones, o lo vendido.
const metrica = ref<"neto" | "ventas">("neto");
// El periodo, la agrupación y la métrica, en la dirección: recargar o compartir el
// enlace abre el mismo reporte. Antes de vigilarlos, para no pedir de más.
// Lo de siempre (este mes, por día, neto) no se escribe: un enlace guardado sigue
// mostrando el mes en curso.
useFiltrosEnUrl({
  desde: sinValorPorOmision(fechaEnUrl(desde), inicioMes),
  hasta: sinValorPorOmision(fechaEnUrl(hasta), () =>
    hoyEnNegocio(sesion.zonaHoraria),
  ),
  agrupar: sinValorPorOmision(
    opcionEnUrl(agrupacion, ["dia", "semana", "mes"]),
    () => "dia",
  ),
  metrica: sinValorPorOmision(
    opcionEnUrl(metrica, ["neto", "ventas"]),
    () => "neto",
  ),
});
function valorDe(p: PuntoSerie): number {
  return metrica.value === "neto" ? p.neto_minor : p.ventas_minor;
}
// Escala de la gráfica: lo positivo arriba de la línea del cero y lo negativo (un
// neto con más devoluciones que cobros) debajo, cada parte en su proporción.
const escala = computed(() => {
  const valores = tendencias.value?.serie.map((p) => valorDe(p)) ?? [];
  const arriba = Math.max(0, ...valores);
  const abajo = Math.max(0, ...valores.map((v) => -v));
  return { arriba, abajo, total: arriba + abajo || 1 };
});
const zonaArriba = computed(
  () => `${(escala.value.arriba / escala.value.total) * 100}%`,
);
const zonaAbajo = computed(
  () => `${(escala.value.abajo / escala.value.total) * 100}%`,
);
function barra(p: PuntoSerie): string {
  const v = valorDe(p);
  const zona = v >= 0 ? escala.value.arriba : escala.value.abajo;
  return zona > 0 ? `${(Math.abs(v) / zona) * 100}%` : "0";
}
// Sin ningún movimiento: ni ventas, ni cobros, ni devoluciones, ni otras monedas.
// Un periodo solo con devoluciones SÍ tiene algo que mostrar (y exportar).
const serieVacia = computed(
  () =>
    tendencias.value === null ||
    (tendencias.value.serie.every(
      (p) =>
        p.ventas_minor === 0 && p.cobrado_minor === 0 && p.devuelto_minor === 0,
    ) &&
      tendencias.value.otras_monedas.length === 0),
);
function fechaBucket(iso: string): string {
  const d = new Date(`${iso}T00:00:00`);
  return agrupacion.value === "mes"
    ? new Intl.DateTimeFormat("es-MX", {
        month: "short",
        year: "2-digit",
      }).format(d)
    : new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
      }).format(d);
}

// En citas cada cita tiene cupo 1: el mapa mide la agenda del equipo (horas
// agendadas entre las disponibles), no el cupo (ADR 0081).
const porAgenda = computed(() => sesion.modalidad === "citas");
const celdasDemanda = computed(() =>
  (demanda.value?.matriz ?? []).filter((c) =>
    porAgenda.value
      ? (c.disponible_min ?? 0) > 0 || c.sesiones > 0
      : c.sesiones > 0,
  ),
);
// «3 clases · 2 citas», solo lo que hubo.
function sesionesTexto(c: CifrasEquipo): string {
  const partes = [
    c.clases > 0 ? t("reportes.equipo.clases", c.clases) : "",
    c.citas > 0 ? t("reportes.equipo.citas", c.citas) : "",
  ].filter((p) => p !== "");
  return partes.length > 0 ? partes.join(" · ") : "—";
}
// Minutos en horas con un decimal ('90' → '1.5 h').
function horas(minutos: number): string {
  return `${new Intl.NumberFormat("es-MX", { maximumFractionDigits: 1 }).format(minutos / 60)} h`;
}
// Fondo del heatmap: más ocupación = acento más intenso (12%..92%).
function colorOcupacion(pct: number | null): string {
  if (pct === null) {
    return "transparent";
  }
  const mezcla = Math.round(12 + Math.min(100, pct) * 0.8);
  return `color-mix(in srgb, var(--primario) ${mezcla}%, transparent)`;
}

function dinero(minor: number | null, moneda: string): string {
  if (minor === null) {
    return "—";
  }
  // Con los números del país del negocio («$ 1.250,50» en Colombia).
  return dineroDelPais(minor, moneda, sesion.pais);
}
function pct(v: number | null): string {
  return v !== null ? `${v}%` : "—";
}

const tarjetas = computed<Indicador[]>(() => {
  const n = negocio.value;
  if (n === null) {
    return [];
  }
  const lista: Omit<Indicador, "etiqueta">[] = [
    {
      clave: "ingresos",
      valor: dinero(n.ingresos_minor, n.moneda),
      icono: "dinero",
      tono: "verde",
    },
    {
      clave: "ocupacion",
      valor: pct(
        porAgenda.value ? (n.ocupacion_agenda_pct ?? null) : n.ocupacion_pct,
      ),
      // De dónde sale: lugares ocupados de los que hubo.
      detalle:
        !porAgenda.value && (n.capacidad_total ?? 0) > 0
          ? t("operacion.reportes.lugaresDe", {
              n: n.confirmadas ?? 0,
              total: n.capacidad_total ?? 0,
            })
          : undefined,
      icono: "pulso",
      tono: "morado",
    },
    {
      clave: "noShow",
      valor: pct(n.no_show_pct),
      detalle:
        (n.presentes ?? 0) + (n.ausentes ?? 0) > 0
          ? t("operacion.reportes.faltasDe", {
              n: n.ausentes ?? 0,
              total: (n.presentes ?? 0) + (n.ausentes ?? 0),
            })
          : undefined,
      icono: "ausente",
      tono: "rosa",
    },
    {
      clave: "alumnos",
      valor: String(n.alumnos_activos),
      icono: "personas",
      tono: "azul",
    },
    {
      clave: "arpu",
      valor: dinero(n.arpu_minor, n.moneda),
      icono: "facturas",
      tono: "naranja",
    },
    {
      clave: "clases",
      valor: String(n.clases),
      icono: "agenda",
      tono: "cielo",
    },
  ];
  return lista.map((k) => ({
    ...k,
    etiqueta:
      k.clave === "arpu"
        ? t("adminUsabilidad.ingresoPromedio", {
            persona: (
              sesion.terminologia?.miembro ??
              (sesion.esCitas ? "Cliente" : "Alumno")
            ).toLowerCase(),
          })
        : t(`reportes.metricas.${k.clave}`),
  }));
});

// Cada reporte guarda cuál fue su última petición: si mientras llegaba se pidió otra
// (otro periodo, otra agrupación), la respuesta vieja no pisa la nueva.
const ultimas: Record<string, number> = {};
async function pedir<T>(
  reporte: string,
  params?: Record<string, unknown>,
): Promise<T | null> {
  const n = (ultimas[reporte] = (ultimas[reporte] ?? 0) + 1);
  const { data } = await api.get<{ data: T }>(
    `${base.value}/reportes/${reporte}`,
    params === undefined ? undefined : { params },
  );
  return ultimas[reporte] === n ? data.data : null;
}
const periodo = () => ({ desde: desde.value, hasta: hasta.value });

async function cargarNegocio(): Promise<void> {
  const r = await pedir<Negocio>("negocio", periodo());
  if (r !== null) negocio.value = r;
}

async function cargarRentabilidad(): Promise<void> {
  const r = await pedir<Rentabilidad>("rentabilidad", periodo());
  if (r !== null) rentabilidad.value = r;
}

async function cargarDemanda(): Promise<void> {
  const r = await pedir<Demanda>("demanda", periodo());
  if (r !== null) demanda.value = r;
}

async function cargarEquipo(): Promise<void> {
  const r = await pedir<Equipo>("equipo", periodo());
  if (r !== null) equipo.value = r;
}

async function cargarTendencias(): Promise<void> {
  const r = await pedir<Tendencias>("tendencias", {
    ...periodo(),
    agrupacion: agrupacion.value,
  });
  if (r !== null) tendencias.value = r;
}

async function exportarTendencias(): Promise<void> {
  exportando.value = true;
  try {
    const { data } = await api.get<Blob>(`${base.value}/reportes/tendencias`, {
      params: {
        desde: desde.value,
        hasta: hasta.value,
        agrupacion: agrupacion.value,
        formato: "csv",
      },
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = `tendencias-${sesion.slug}.csv`;
    document.body.appendChild(enlace);
    enlace.click();
    document.body.removeChild(enlace);
    URL.revokeObjectURL(url);
  } catch (e) {
    errores.value = { ...errores.value, ingresos: mensajeDeError(e) };
  } finally {
    exportando.value = false;
  }
}

async function cargarCohortes(): Promise<void> {
  const r = await pedir<Cohortes>("cohortes", { meses: 6 });
  if (r !== null) cohortes.value = r;
}

async function cargarEmbudo(): Promise<void> {
  const r = await pedir<Embudo>("embudo", periodo());
  if (r !== null) embudo.value = r;
}

async function cargarSucursales(): Promise<void> {
  const r = await pedir<ReporteSucursales>("sucursales");
  if (r === null) return;
  sucursales.value = r.sucursales;
  sinSucursal.value = r.sin_sucursal.miembros_activos;
  totalesSucursales.value = r.totales;
}

// Lo que pide cada pestaña. Se carga al abrirla (no todo al entrar: cada reporte
// cuesta) y, si cambia el periodo, se vuelve a pedir al verla de nuevo.
const CARGAS: Record<Pestana, () => Promise<unknown>[]> = {
  resumen: () => [cargarNegocio()],
  ingresos: () => [cargarTendencias(), cargarRentabilidad()],
  ocupacion: () => [cargarDemanda()],
  clientes: () => [cargarCohortes()],
  embudo: () => [cargarEmbudo()],
  equipo: () =>
    soloRendimiento.value
      ? [cargarEquipo()]
      : [cargarEquipo(), cargarSucursales()],
};
const cargadas = new Set<Pestana>();
async function cargar(): Promise<void> {
  const actual = pestana.value;
  if (cargadas.has(actual)) {
    return;
  }
  cargadas.add(actual);
  cargando.value = true;
  const sinError = { ...errores.value };
  delete sinError[actual];
  errores.value = sinError;
  // Lo que sí llegó se muestra; si algo falló, la pestaña dice qué y se reintenta.
  const resultados = await Promise.allSettled(CARGAS[actual]());
  const fallo = resultados.find(
    (r): r is PromiseRejectedResult => r.status === "rejected",
  );
  if (fallo !== undefined) {
    cargadas.delete(actual);
    errores.value = {
      ...errores.value,
      [actual]: mensajeDeError(fallo.reason),
    };
  }
  cargando.value = false;
}
function reintentar(): void {
  cargadas.delete(pestana.value);
  void cargar();
}

/**
 * Vistas del área Reportes (el menú las muestra arriba, `?vista=`): Resumen, Ingresos,
 * Ocupación, el embudo y la agenda del equipo dependen del periodo elegido; los
 * alumnos (cohortes por mes de alta) y las sucursales (estado actual), no. El selector
 * de periodo solo aparece donde cuenta, y cada sección dice de qué son sus cifras.
 */
type Pestana =
  "resumen" | "ingresos" | "ocupacion" | "clientes" | "embudo" | "equipo";
const PESTANAS: Pestana[] = [
  "resumen",
  "ingresos",
  "ocupacion",
  "clientes",
  "embudo",
  "equipo",
];
// Ingresos, ocupación, clientes y el embudo son reportes avanzados: en citas, del plan Pro
// (ADR 0107; el servidor ya los niega).
const pestanas = computed<Pestana[]>(() =>
  sesion.estudio?.plan?.sin.includes("reportes_avanzados") === true
    ? PESTANAS.filter((p) => p === "resumen" || p === "equipo")
    : PESTANAS,
);
// La vista de la dirección (lib/menu.ts): la de equipo y sucursales es
// `vista=sucursales`; `vista=equipo` es Equipo › Rendimiento. Una que el plan no
// incluye abre el resumen.
const VISTA_A_PESTANA: Record<string, Pestana> = {
  ingresos: "ingresos",
  ocupacion: "ocupacion",
  clientes: "clientes",
  embudo: "embudo",
  sucursales: "equipo",
  equipo: "equipo",
};
const pestana = computed<Pestana>(() => {
  const vista = route?.query.vista;
  const elegida =
    typeof vista === "string" ? VISTA_A_PESTANA[vista] : undefined;
  return elegida !== undefined && pestanas.value.includes(elegida)
    ? elegida
    : "resumen";
});
// Una sección que el plan ya no incluye (un enlace de antes): la dirección dice lo que
// se ve (Resumen), para que el menú y las migas no marquen otra cosa.
watch(
  [() => route?.query.vista, pestanas],
  ([vista]) => {
    const elegida =
      typeof vista === "string" ? VISTA_A_PESTANA[vista] : undefined;
    if (elegida !== undefined && !pestanas.value.includes(elegida)) {
      const query = { ...route.query };
      delete query.vista;
      void router?.replace({ query, hash: route.hash });
    }
  },
  { immediate: true },
);
// Título y subtítulo de cada vista (Rendimiento, el suyo).
const titulo = computed(() => {
  if (soloRendimiento.value) {
    return t("reportes.equipo.rendimiento");
  }
  if (pestana.value === "clientes") {
    return plural(
      sesion.terminologia?.miembro ?? (sesion.esCitas ? "Cliente" : "Alumno"),
    );
  }
  return pestana.value === "equipo" && trabajaSolo.value
    ? t("operacion.reportes.pestanas.equipoSolo")
    : t(`operacion.reportes.pestanas.${pestana.value}`);
});
const subtitulo = computed(() => {
  if (soloRendimiento.value) {
    return trabajaSolo.value
      ? t("reportes.equipo.subtituloSolo")
      : t("reportes.equipo.subtitulo");
  }
  return pestana.value === "equipo" && trabajaSolo.value
    ? t("reportes.subtitulos.equipoSolo")
    : t(`reportes.subtitulos.${pestana.value}`);
});
const usaPeriodo = computed(
  () =>
    pestana.value === "resumen" ||
    pestana.value === "ingresos" ||
    pestana.value === "ocupacion" ||
    pestana.value === "embudo" ||
    pestana.value === "equipo",
);
const periodoTexto = computed(() => {
  const f = (ymd: string) =>
    new Intl.DateTimeFormat("es-MX", {
      day: "numeric",
      month: "short",
      year: "numeric",
    }).format(new Date(`${ymd}T12:00:00`));
  // Los días son los del negocio: se dice en qué hora (ADR 0166).
  return t("operacion.reportes.periodoZona", {
    desde: f(desde.value),
    hasta: f(hasta.value),
    zona: nombreZona(sesion.zonaHoraria),
  });
});

// «hora del centro»: el nombre de la zona del negocio, en español.
function nombreZona(zona: string): string {
  try {
    const parte = new Intl.DateTimeFormat("es-MX", {
      timeZone: zona,
      timeZoneName: "longGeneric",
    })
      .formatToParts(new Date())
      .find((p) => p.type === "timeZoneName");
    return parte?.value ?? zona;
  } catch {
    return zona;
  }
}

function esteMes(): void {
  desde.value = inicioMes();
  hasta.value = hoyEnNegocio(sesion.zonaHoraria);
}

// Otro periodo: lo que dependía de él se vuelve a pedir (Clientes, no).
watch([desde, hasta], () => {
  for (const p of [
    "resumen",
    "ingresos",
    "ocupacion",
    "embudo",
    "equipo",
  ] as const) {
    cargadas.delete(p);
  }
  void cargar();
});
watch(agrupacion, async () => {
  try {
    await cargarTendencias();
  } catch (e) {
    errores.value = { ...errores.value, ingresos: mensajeDeError(e) };
  }
});
// Cada vista pide lo suyo al abrirse (también al entrar por un enlace o recargar).
// Entre Reportes y Rendimiento la pestaña es la misma, pero cambia lo que se pide.
watch(
  [pestana, soloRendimiento],
  ([, rendimiento], [, antes]) => {
    if (rendimiento !== antes) {
      cargadas.delete("equipo");
    }
    void cargar();
  },
  { immediate: true },
);

// El asistente sobre los datos del negocio (ADR 0196): si se ofrece, a la mano.
const asistenteDisponible = ref(false);
async function revisarAsistente(): Promise<void> {
  if (!puedeEntrar("asistente-ia", sesion)) {
    return;
  }
  try {
    const { data } = await api.get<{ data: { disponible: boolean } }>(
      `/api/v1/app/${sesion.slug}/asistente`,
    );
    asistenteDisponible.value = data.data.disponible;
  } catch {
    asistenteDisponible.value = false;
  }
}

onMounted(() => {
  void revisarAsistente();
});
</script>

<template>
  <section class="tu-pagina">
    <!-- Las vistas (Resumen, Ingresos…) son las pestañas del área, arriba (lib/menu.ts). -->
    <EncabezadoSeccion :titulo="titulo" :subtitulo="subtitulo">
      <template v-if="asistenteDisponible && !soloRendimiento" #acciones>
        <RouterLink
          :to="{ name: 'asistente-ia' }"
          class="tu-btn tu-btn-secundario"
          data-prueba="ir-asistente"
          >{{ $t("asistenteIA.boton") }}</RouterLink
        >
      </template>
    </EncabezadoSeccion>

    <!-- Periodo -->
    <div v-if="usaPeriodo" class="mt-4 flex flex-wrap items-end gap-3">
      <div>
        <label class="tu-label" for="rd">{{ $t("reportes.desde") }}</label>
        <span class="tu-campo-icono">
          <IconoNav nombre="agenda" :tam="18" />
          <input id="rd" v-model="desde" type="date" class="tu-input w-auto" />
        </span>
      </div>
      <div>
        <label class="tu-label" for="rh">{{ $t("reportes.hasta") }}</label>
        <span class="tu-campo-icono">
          <IconoNav nombre="agenda" :tam="18" />
          <input id="rh" v-model="hasta" type="date" class="tu-input w-auto" />
        </span>
      </div>
      <button class="tu-btn tu-btn-secundario" type="button" @click="esteMes">
        <IconoNav nombre="agenda" :tam="18" />
        {{ $t("reportes.esteMes") }}
      </button>
    </div>

    <AvisoTono
      v-if="error && !cargando"
      tono="peligro"
      class="mt-4"
      role="alert"
      data-prueba="error-reporte"
    >
      {{ $t("reportes.noSeConsulto", { error }) }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          data-prueba="reintentar-reporte"
          @click="reintentar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <p
        v-if="usaPeriodo"
        class="mt-4 text-xs"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ periodoTexto }}
      </p>

      <!-- Métricas del negocio -->
      <TarjetasIndicadores
        v-if="pestana === 'resumen'"
        class="mt-4"
        :tarjetas="tarjetas"
      />
      <!-- El dinero del periodo por moneda: vendido, cobrado, devuelto y neto -->
      <div
        v-if="pestana === 'resumen' && negocio?.dinero_por_moneda?.length"
        class="mt-4 tu-card overflow-x-auto"
        data-prueba="dinero-por-moneda"
      >
        <table class="tu-tabla">
          <thead>
            <tr>
              <th>{{ $t("reportes.dinero.moneda") }}</th>
              <th class="text-right">{{ $t("reportes.dinero.vendido") }}</th>
              <th class="text-right">{{ $t("reportes.dinero.cobrado") }}</th>
              <th class="text-right">{{ $t("reportes.dinero.devuelto") }}</th>
              <th class="text-right">{{ $t("reportes.dinero.neto") }}</th>
            </tr>
          </thead>
          <tbody class="tu-tabla-cuerpo">
            <tr v-for="d in negocio.dinero_por_moneda" :key="d.moneda">
              <td>{{ d.moneda }}</td>
              <td class="text-right tabular-nums">
                {{ dinero(d.ventas_minor, d.moneda) }}
              </td>
              <td class="text-right tabular-nums">
                {{ dinero(d.cobrado_minor, d.moneda) }}
              </td>
              <td class="text-right tabular-nums">
                {{ dinero(d.devuelto_minor, d.moneda) }}
              </td>
              <td class="text-right tabular-nums font-medium">
                {{ dinero(d.neto_minor, d.moneda) }}
              </td>
            </tr>
          </tbody>
        </table>
        <p class="px-4 py-3 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.dinero.ayuda") }}
        </p>
      </div>

      <!-- Tendencias de ingresos (Etapa 2) -->
      <template v-if="pestana === 'ingresos'">
        <div class="mt-4 flex flex-wrap items-center gap-3">
          <h2 class="tu-titulo-seccion">
            {{ $t("reportes.tendencias.titulo") }}
          </h2>
          <div class="ml-auto flex items-center gap-2">
            <select
              v-model="agrupacion"
              class="tu-input w-auto"
              :aria-label="$t('reportes.tendencias.agrupacion')"
            >
              <option value="dia">{{ $t("reportes.tendencias.dia") }}</option>
              <option value="semana">
                {{ $t("reportes.tendencias.semana") }}
              </option>
              <option value="mes">{{ $t("reportes.tendencias.mes") }}</option>
            </select>
            <button
              class="tu-btn tu-btn-secundario"
              type="button"
              :disabled="exportando || !tendencias || serieVacia"
              data-prueba="exportar-tendencias"
              @click="exportarTendencias"
            >
              {{
                exportando
                  ? $t("reportes.tendencias.exportando")
                  : $t("reportes.tendencias.exportar")
              }}
            </button>
          </div>
        </div>

        <template v-if="tendencias">
          <!-- Totales del periodo -->
          <div
            class="mt-3 tu-card px-5 py-4 grid gap-4 sm:grid-cols-3"
            data-prueba="tendencias-totales"
          >
            <div>
              <div class="text-xl font-semibold tabular-nums">
                {{ dinero(tendencias.totales.neto_minor, tendencias.moneda) }}
              </div>
              <div
                class="text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("reportes.tendencias.cobradoNeto") }} ·
                {{
                  $t("reportes.tendencias.cobradoDevuelto", {
                    cobrado: dinero(
                      tendencias.totales.cobrado_minor,
                      tendencias.moneda,
                    ),
                    devuelto: dinero(
                      tendencias.totales.devuelto_minor,
                      tendencias.moneda,
                    ),
                  })
                }}
              </div>
            </div>
            <div>
              <div class="text-xl font-semibold tabular-nums">
                {{ dinero(tendencias.totales.ventas_minor, tendencias.moneda) }}
              </div>
              <div
                class="text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  $t(
                    "reportes.tendencias.vendidoN",
                    { n: tendencias.totales.ventas },
                    tendencias.totales.ventas,
                  )
                }}
              </div>
            </div>
            <div>
              <div class="text-xl font-semibold tabular-nums">
                {{
                  dinero(
                    tendencias.totales.ticket_promedio_minor,
                    tendencias.moneda,
                  )
                }}
              </div>
              <div
                class="text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("reportes.tendencias.ticket") }}
              </div>
            </div>
          </div>

          <!-- La gráfica: cobrado neto (por fecha del cobro) o vendido (por fecha de la compra) -->
          <div class="mt-4 tu-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div
                class="tu-segmentado"
                role="group"
                :aria-label="$t('reportes.tendencias.queMuestra')"
              >
                <button
                  type="button"
                  :aria-pressed="metrica === 'neto'"
                  data-prueba="metrica-neto"
                  @click="metrica = 'neto'"
                >
                  {{ $t("reportes.tendencias.cobradoNeto") }}
                </button>
                <button
                  type="button"
                  :aria-pressed="metrica === 'ventas'"
                  data-prueba="metrica-ventas"
                  @click="metrica = 'ventas'"
                >
                  {{ $t("reportes.tendencias.vendido") }}
                </button>
              </div>
              <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                {{
                  metrica === "neto"
                    ? $t("reportes.tendencias.ayudaNeto")
                    : $t("reportes.tendencias.ayudaVendido")
                }}
              </p>
            </div>
            <p
              v-if="serieVacia"
              class="mt-4 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("reportes.tendencias.vacio") }}
            </p>
            <template v-else>
              <!-- Cada barra desde la línea del cero: arriba lo positivo, abajo
                   (en rojo) un neto negativo. -->
              <div
                class="rt-grafica mt-4 h-40"
                data-prueba="tendencias-grafica"
              >
                <span
                  class="rt-cero"
                  :style="{ top: zonaArriba }"
                  aria-hidden="true"
                ></span>
                <div
                  v-for="p in tendencias.serie"
                  :key="p.fecha"
                  class="rt-columna"
                  :title="`${fechaBucket(p.fecha)} · ${dinero(valorDe(p), tendencias.moneda)}`"
                >
                  <div class="rt-arriba" :style="{ height: zonaArriba }">
                    <div
                      v-if="valorDe(p) > 0"
                      class="rt-barra rounded-t"
                      :style="{
                        height: barra(p),
                        background: 'var(--primario)',
                      }"
                    />
                  </div>
                  <div class="rt-abajo" :style="{ height: zonaAbajo }">
                    <div
                      v-if="valorDe(p) < 0"
                      class="rt-barra rounded-b"
                      :style="{ height: barra(p), background: 'var(--error)' }"
                      data-prueba="barra-negativa"
                    />
                  </div>
                </div>
              </div>
              <div
                class="flex justify-between text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
                <span>{{ fechaBucket(tendencias.serie[0].fecha) }}</span>
                <span>{{
                  fechaBucket(
                    tendencias.serie[tendencias.serie.length - 1].fecha,
                  )
                }}</span>
              </div>
            </template>
          </div>

          <!-- Desglose por producto -->
          <h3 class="mt-6 font-semibold">
            {{ $t("reportes.tendencias.porProducto") }}
          </h3>
          <p
            v-if="tendencias.por_producto.length === 0"
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("reportes.tendencias.sinProducto") }}
          </p>
          <div v-else class="mt-3 tu-card overflow-x-auto">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>
                    {{ $t("reportes.tendencias.colProducto") }}
                  </th>
                  <th class="text-right">
                    {{ $t("reportes.tendencias.colUnidades") }}
                  </th>
                  <th class="text-right">
                    {{ $t("reportes.tendencias.colVendido") }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in tendencias.por_producto" :key="p.producto">
                  <td class="font-semibold">{{ p.producto }}</td>
                  <td class="text-right">{{ p.unidades }}</td>
                  <td class="text-right">
                    {{ dinero(p.ventas_minor, tendencias.moneda) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Otras monedas (de antes de trabajar con una sola): aparte, nunca sumadas -->
          <template v-if="tendencias.otras_monedas.length > 0">
            <h3 class="mt-6 font-semibold">
              {{ $t("reportes.tendencias.otrasMonedas") }}
            </h3>
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.tendencias.otrasMonedasAyuda") }}
            </p>
            <div
              class="mt-3 tu-card overflow-x-auto"
              data-prueba="tendencias-otras-monedas"
            >
              <table class="tu-tabla">
                <thead>
                  <tr>
                    <th>{{ $t("reportes.dinero.moneda") }}</th>
                    <th class="text-right">
                      {{ $t("reportes.dinero.vendido") }}
                    </th>
                    <th class="text-right">
                      {{ $t("reportes.dinero.cobrado") }}
                    </th>
                    <th class="text-right">
                      {{ $t("reportes.dinero.devuelto") }}
                    </th>
                    <th class="text-right">
                      {{ $t("reportes.dinero.neto") }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="m in tendencias.otras_monedas" :key="m.moneda">
                    <td>{{ m.moneda }}</td>
                    <td class="text-right tabular-nums">
                      {{ dinero(m.ventas_minor, m.moneda) }}
                    </td>
                    <td class="text-right tabular-nums">
                      {{ dinero(m.cobrado_minor, m.moneda) }}
                    </td>
                    <td class="text-right tabular-nums">
                      {{ dinero(m.devuelto_minor, m.moneda) }}
                    </td>
                    <td class="text-right tabular-nums font-medium">
                      {{ dinero(m.neto_minor, m.moneda) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </template>
      </template>

      <p
        v-if="pestana === 'clientes' && !cohortes && !error"
        class="mt-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("operacion.reportes.sinClientes") }}
      </p>
      <!-- Conversión + cohortes de retención (Etapa 2) -->
      <template v-if="pestana === 'clientes' && cohortes">
        <h2 class="tu-titulo-seccion mt-6">
          {{ $t("reportes.conversion.titulo") }}
        </h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.conversion.subtitulo", { n: cohortes.meses }) }}
        </p>
        <div class="mt-3 tu-card px-5 py-4 grid grid-cols-3 gap-4">
          <div>
            <div class="text-xl font-semibold tabular-nums">
              {{ cohortes.conversion.registrados }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.conversion.registrados") }}
            </div>
          </div>
          <div>
            <div class="text-xl font-semibold tabular-nums">
              {{ cohortes.conversion.compraron }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.conversion.compraron") }} ·
              {{ pctConv(cohortes.conversion.compraron) }}
            </div>
          </div>
          <div>
            <div class="text-xl font-semibold tabular-nums">
              {{ cohortes.conversion.activos }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.conversion.activos") }} ·
              {{ pctConv(cohortes.conversion.activos) }}
            </div>
          </div>
        </div>

        <!-- Cómo nos conocieron (lo dicen al agendar en línea) -->
        <template v-if="(cohortes.origenes ?? []).length > 0">
          <h3 class="mt-6 font-semibold">
            {{ $t("operacion.reportes.origenes.titulo") }}
          </h3>
          <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{
              $t("operacion.reportes.origenes.subtitulo", {
                n: cohortes.meses,
                sinDato: cohortes.origenes_sin_dato ?? 0,
              })
            }}
          </p>
          <ul
            class="mt-3 tu-card px-5 py-2 divide-y divide-[var(--borde)]"
            data-prueba="origenes"
          >
            <li
              v-for="o in cohortes.origenes"
              :key="o.origen"
              class="flex items-center justify-between gap-3 py-2.5 text-sm"
            >
              <span>{{ $t(`perfilPublico.origenes.${o.origen}`) }}</span>
              <span class="tabular-nums font-medium">
                {{ o.total }}
                <span :style="{ color: 'var(--texto-suave)' }"
                  >· {{ pctOrigen(o.total) }}</span
                >
              </span>
            </li>
          </ul>
        </template>

        <h3 class="mt-6 font-semibold">{{ $t("reportes.cohortes.titulo") }}</h3>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.cohortes.subtitulo") }}
        </p>
        <div class="mt-3 tu-card overflow-x-auto">
          <table class="tu-tabla">
            <thead>
              <tr class="text-center" :style="{ color: 'var(--texto-suave)' }">
                <th class="text-left">
                  {{ $t("reportes.cohortes.colCohorte") }}
                </th>
                <th class="text-right">
                  {{ $t("reportes.cohortes.colAltas") }}
                </th>
                <th
                  v-for="k in cohortes.meses"
                  :key="k"
                  class="px-2 py-2 font-medium"
                >
                  {{ $t("reportes.cohortes.mesN", { n: k - 1 }) }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in cohortes.cohortes" :key="c.mes">
                <td class="font-semibold whitespace-nowrap">
                  {{ mesCorto(c.mes) }}
                </td>
                <td class="text-right">{{ c.tamano }}</td>
                <td
                  v-for="(r, i) in c.retencion"
                  :key="i"
                  class="px-1 py-1 text-center"
                >
                  <div
                    v-if="r !== null && c.tamano > 0"
                    class="rounded-lg py-1.5 text-xs font-semibold"
                    :style="{ background: colorOcupacion(r) }"
                  >
                    {{ r }}%
                  </div>
                  <span v-else :style="{ color: 'var(--borde)' }">·</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.cohortes.leyenda") }}
        </p>
      </template>

      <!-- Embudo del negocio (C08) -->
      <EmbudoNegocio
        v-if="pestana === 'embudo' && embudo"
        :embudo="embudo"
        :citas="porAgenda"
      />

      <!-- Por sucursal -->
      <!-- Agenda del equipo (ADR 0081) -->
      <template v-if="pestana === 'equipo'">
        <!-- En Rendimiento, el encabezado ya dice qué es y de qué trata. -->
        <template v-if="!soloRendimiento">
          <h2 class="tu-titulo-seccion mt-4">
            {{
              trabajaSolo
                ? $t("reportes.equipo.tituloSolo")
                : $t("reportes.equipo.titulo")
            }}
          </h2>
          <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{
              trabajaSolo
                ? $t("reportes.equipo.subtituloSolo")
                : $t("reportes.equipo.subtitulo")
            }}
          </p>
        </template>
        <!-- Si la pestaña falló, el aviso de arriba lo dice: no es «sin datos». -->
        <template v-if="!equipo || equipo.profesionales.length === 0">
          <p
            v-if="!error"
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("reportes.equipo.vacio") }}
          </p>
        </template>
        <template v-else>
          <div class="mt-3 tu-card overflow-x-auto">
            <table class="tu-tabla" data-prueba="equipo">
              <thead>
                <tr>
                  <th>
                    {{ $t("reportes.equipo.colProfesional") }}
                  </th>
                  <!-- La ocupación se mide contra el horario de atención: solo en citas. -->
                  <th v-if="sesion.esCitas">
                    {{ $t("reportes.equipo.colOcupacion") }}
                  </th>
                  <th class="text-right hidden sm:table-cell">
                    {{ $t("reportes.equipo.colSesiones") }}
                  </th>
                  <th class="text-right hidden md:table-cell">
                    {{ $t("reportes.equipo.colInasistencia") }}
                  </th>
                  <th class="text-right">
                    {{ $t("reportes.equipo.colValor") }}
                  </th>
                  <template v-if="!trabajaSolo">
                    <th class="text-right hidden sm:table-cell">
                      {{ $t("reportes.equipo.colPago") }}
                    </th>
                    <th class="text-right">
                      {{ $t("reportes.equipo.colMargen") }}
                    </th>
                  </template>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="p in equipo.profesionales"
                  :key="p.id ?? 'sin'"
                  data-prueba="profesional"
                >
                  <td>
                    <span class="flex items-center gap-2 font-semibold">
                      <img
                        v-if="p.foto_url"
                        :src="p.foto_url"
                        alt=""
                        class="h-7 w-7 rounded-full object-cover shrink-0"
                      />
                      {{ p.nombre ?? $t("reportes.equipo.sinProfesional") }}
                    </span>
                  </td>
                  <td v-if="sesion.esCitas" class="re-ocupacion">
                    <template v-if="p.ocupacion_pct !== null">
                      <span class="font-semibold tabular-nums">{{
                        pct(p.ocupacion_pct)
                      }}</span>
                      <span class="re-barra" aria-hidden="true"
                        ><span :style="{ width: `${p.ocupacion_pct}%` }"
                      /></span>
                      <span
                        class="block text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                        >{{ horas(p.agendado_en_horario_min) }} /
                        {{ horas(p.disponible_min) }}</span
                      >
                    </template>
                    <span
                      v-else
                      class="text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{ $t("reportes.equipo.sinHorario") }} ·
                      {{ horas(p.agendado_min) }}</span
                    >
                  </td>
                  <td class="text-right hidden sm:table-cell">
                    {{ sesionesTexto(p) }}
                  </td>
                  <td
                    class="text-right hidden md:table-cell"
                    :title="`${p.presentes} / ${p.ausentes}`"
                  >
                    {{ pct(p.inasistencia_pct) }}
                  </td>
                  <td class="text-right">
                    {{ dinero(p.ingreso_minor, equipo.moneda) }}
                  </td>
                  <template v-if="!trabajaSolo">
                    <td class="text-right hidden sm:table-cell">
                      {{ dinero(p.costo_minor, equipo.moneda) }}
                    </td>
                    <td
                      class="text-right font-semibold"
                      :style="{
                        color:
                          p.margen_minor >= 0 ? 'var(--exito)' : 'var(--error)',
                      }"
                    >
                      {{ dinero(p.margen_minor, equipo.moneda) }}
                    </td>
                  </template>
                </tr>
              </tbody>
              <tfoot v-if="!trabajaSolo">
                <tr>
                  <td>{{ $t("reportes.equipo.total") }}</td>
                  <td v-if="sesion.esCitas">
                    {{ pct(equipo.totales.ocupacion_pct) }}
                  </td>
                  <td class="text-right hidden sm:table-cell">
                    {{ sesionesTexto(equipo.totales) }}
                  </td>
                  <td class="text-right hidden md:table-cell">
                    {{ pct(equipo.totales.inasistencia_pct) }}
                  </td>
                  <td class="text-right">
                    {{ dinero(equipo.totales.ingreso_minor, equipo.moneda) }}
                  </td>
                  <td class="text-right hidden sm:table-cell">
                    {{ dinero(equipo.totales.costo_minor, equipo.moneda) }}
                  </td>
                  <td
                    class="text-right"
                    :style="{
                      color:
                        equipo.totales.margen_minor >= 0
                          ? 'var(--exito)'
                          : 'var(--error)',
                    }"
                  >
                    {{ dinero(equipo.totales.margen_minor, equipo.moneda) }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{
              sesion.esCitas
                ? $t("reportes.equipo.leyenda")
                : $t("reportes.equipo.leyendaClases")
            }}
          </p>
        </template>
      </template>

      <template v-if="pestana === 'equipo' && !soloRendimiento">
        <h2 class="tu-titulo-seccion mt-8">
          {{ $t("reportes.porSucursal") }}
        </h2>
        <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.reportes.estadoActual") }}
        </p>
        <template v-if="sucursales.length === 0">
          <p
            v-if="!error"
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("reportes.sinSucursales") }}
          </p>
        </template>
        <div v-else class="mt-3 tu-card overflow-x-auto">
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>
                  {{ $t("reportes.colSucursal") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ $t("reportes.colRegion") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ $t("reportes.colMoneda") }}
                </th>
                <th class="text-right">
                  {{ $t("reportes.colMiembros") }}
                </th>
                <th class="text-right">
                  {{ $t("reportes.colClases") }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in sucursales" :key="s.id">
                <td class="font-semibold">{{ s.nombre }}</td>
                <td
                  class="hidden sm:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ s.region ?? "—" }}
                </td>
                <td class="hidden sm:table-cell">
                  {{ s.moneda ?? "—" }}
                </td>
                <td class="text-right">{{ s.miembros_activos }}</td>
                <td class="text-right">{{ s.sesiones_proximas }}</td>
              </tr>
              <tr v-if="sinSucursal > 0">
                <td :style="{ color: 'var(--texto-suave)' }">
                  {{ $t("operacion.reportes.sinSucursal") }}
                </td>
                <td class="hidden sm:table-cell" />
                <td class="hidden sm:table-cell" />
                <td class="text-right">{{ sinSucursal }}</td>
                <td class="text-right">—</td>
              </tr>
            </tbody>
            <tfoot v-if="totalesSucursales">
              <tr>
                <td>{{ $t("operacion.reportes.total") }}</td>
                <td class="hidden sm:table-cell" />
                <td class="hidden sm:table-cell" />
                <td class="text-right">
                  {{ totalesSucursales.miembros_activos }}
                </td>
                <td class="text-right">
                  {{ totalesSucursales.sesiones_proximas }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>

      <!-- Rentabilidad por clase (R30) -->
      <template v-if="pestana === 'ingresos'">
        <h2 class="tu-titulo-seccion mt-8">
          {{
            $t(
              sesion.esCitas
                ? "reportes.rentabilidad.tituloCitas"
                : "reportes.rentabilidad.titulo",
            )
          }}
        </h2>
        <template v-if="!rentabilidad || rentabilidad.ofertas.length === 0">
          <p
            v-if="!error"
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t(
                sesion.esCitas
                  ? "reportes.rentabilidad.vacioCitas"
                  : "reportes.rentabilidad.vacio",
              )
            }}
          </p>
        </template>
        <template v-else>
          <div class="mt-3 tu-card overflow-x-auto">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>
                    {{
                      $t(
                        sesion.esCitas
                          ? "reportes.rentabilidad.colClaseCitas"
                          : "reportes.rentabilidad.colClase",
                      )
                    }}
                  </th>
                  <th class="text-right hidden sm:table-cell">
                    {{ $t("reportes.rentabilidad.colSesiones") }}
                  </th>
                  <th class="text-right">
                    {{
                      $t(
                        sesion.esCitas
                          ? "reportes.rentabilidad.colAsistentesCitas"
                          : "reportes.rentabilidad.colAsistentes",
                      )
                    }}
                  </th>
                  <th class="text-right">
                    {{ $t("reportes.rentabilidad.colIngreso") }}
                  </th>
                  <th class="text-right">
                    {{ $t("reportes.rentabilidad.colCosto") }}
                  </th>
                  <th class="text-right">
                    {{ $t("reportes.rentabilidad.colMargen") }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="o in rentabilidad.ofertas" :key="o.id ?? o.oferta">
                  <td class="font-semibold">{{ o.oferta }}</td>
                  <td class="text-right hidden sm:table-cell">
                    {{ o.sesiones }}
                  </td>
                  <td class="text-right">{{ o.asistentes }}</td>
                  <td class="text-right">
                    {{ dinero(o.ingreso_minor, rentabilidad.moneda) }}
                  </td>
                  <td class="text-right">
                    {{ dinero(o.costo_minor, rentabilidad.moneda) }}
                  </td>
                  <td
                    class="text-right font-semibold"
                    :style="{
                      color:
                        o.margen_minor >= 0 ? 'var(--exito)' : 'var(--error)',
                    }"
                  >
                    {{ dinero(o.margen_minor, rentabilidad.moneda) }}
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td>
                    {{ $t("reportes.rentabilidad.total") }}
                  </td>
                  <td class="hidden sm:table-cell"></td>
                  <td class="text-right">
                    {{ rentabilidad.totales.asistentes }}
                  </td>
                  <td class="text-right">
                    {{
                      dinero(
                        rentabilidad.totales.ingreso_minor,
                        rentabilidad.moneda,
                      )
                    }}
                  </td>
                  <td class="text-right">
                    {{
                      dinero(
                        rentabilidad.totales.costo_minor,
                        rentabilidad.moneda,
                      )
                    }}
                  </td>
                  <td
                    class="text-right"
                    :style="{
                      color:
                        rentabilidad.totales.margen_minor >= 0
                          ? 'var(--exito)'
                          : 'var(--error)',
                    }"
                  >
                    {{
                      dinero(
                        rentabilidad.totales.margen_minor,
                        rentabilidad.moneda,
                      )
                    }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p
            v-if="rentabilidad.totales.sin_costo_unitario > 0"
            class="mt-3 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("reportes.rentabilidad.sinCosto", {
                n: rentabilidad.totales.sin_costo_unitario,
              })
            }}
          </p>
        </template>
      </template>

      <!-- Demanda por horario (R31) -->
      <template v-if="pestana === 'ocupacion'">
        <div class="mt-4 tu-card p-5 sm:p-6">
          <template v-if="!demanda || celdasDemanda.length === 0">
            <h2 class="text-xl font-semibold">
              {{ $t("reportes.demanda.titulo") }}
            </h2>
            <EstadoVacio
              v-if="!error"
              icono="reportes"
              :titulo="$t('reportes.demanda.vacio')"
            />
          </template>
          <MapaDemanda
            v-else
            :celdas="celdasDemanda"
            :por-agenda="porAgenda"
            :promedio-pct="
              porAgenda
                ? (demanda.totales.utilizacion_pct ?? null)
                : demanda.totales.ocupacion_pct
            "
          />
          <p
            v-if="porAgenda && demanda?.totales.utilizacion_pct != null"
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            data-prueba="resumen-agenda"
          >
            {{
              $t("reportes.demanda.resumenAgenda", {
                pct: pct(demanda.totales.utilizacion_pct ?? null),
                agendadas: horas(demanda.totales.agendado_min ?? 0),
                disponibles: horas(demanda.totales.disponible_min ?? 0),
              })
            }}
          </p>
        </div>
        <template v-if="demanda && celdasDemanda.length > 0">
          <!-- Por actividad -->
          <h3 class="mt-6 text-lg font-semibold">
            {{ $t("reportes.demanda.porActividad") }}
          </h3>
          <div class="mt-3 tu-card overflow-x-auto">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>
                    {{ $t("reportes.demanda.colActividad") }}
                  </th>
                  <th class="text-right hidden sm:table-cell">
                    {{ $t("reportes.demanda.colSesiones") }}
                  </th>
                  <th class="text-right">
                    {{ $t("reportes.demanda.colConfirmadas") }}
                  </th>
                  <!-- Las citas no tienen lista de espera. -->
                  <th v-if="!sesion.esCitas" class="text-right">
                    {{ $t("reportes.demanda.colEspera") }}
                  </th>
                  <th class="text-right">
                    {{ $t("reportes.demanda.colOcupacion") }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in demanda.actividades" :key="a.id ?? a.actividad">
                  <td class="font-semibold">{{ a.actividad }}</td>
                  <td class="text-right hidden sm:table-cell">
                    {{ a.sesiones }}
                  </td>
                  <td class="text-right">{{ a.confirmadas }}</td>
                  <td
                    v-if="!sesion.esCitas"
                    class="text-right font-semibold"
                    :style="{
                      color: a.espera > 0 ? 'var(--aviso)' : 'inherit',
                    }"
                  >
                    {{ a.espera }}
                  </td>
                  <td class="text-right font-semibold">
                    {{ pct(a.ocupacion_pct) }}
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td>{{ $t("reportes.demanda.total") }}</td>
                  <td class="text-right hidden sm:table-cell">
                    {{ demanda.totales.sesiones }}
                  </td>
                  <td class="text-right">
                    {{ demanda.totales.confirmadas }}
                  </td>
                  <td v-if="!sesion.esCitas" class="text-right">
                    {{ demanda.totales.espera }}
                  </td>
                  <td class="text-right">
                    {{ pct(demanda.totales.ocupacion_pct) }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </template>
      </template>
    </template>
  </section>
</template>

<style scoped>
.re-ocupacion {
  min-width: 8rem;
}
.re-barra {
  display: block;
  height: 0.25rem;
  margin-top: 0.3rem;
  border-radius: 999px;
  background: var(--borde);
  overflow: hidden;
}
.re-barra > span {
  display: block;
  height: 100%;
  background: var(--primario);
}
/* Tendencias: columnas con su zona positiva y negativa, y la línea del cero. */
.rt-grafica {
  position: relative;
  display: flex;
  gap: 0.25rem;
  border-bottom: 1px solid var(--borde);
}
.rt-cero {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 1px solid var(--borde);
}
.rt-columna {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 2px;
}
.rt-arriba {
  display: flex;
  align-items: flex-end;
}
.rt-abajo {
  display: flex;
  align-items: flex-start;
}
.rt-barra {
  width: 100%;
  min-height: 3px;
  transition: height 0.2s;
}
</style>
