<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import BuscarPersona from "@/components/BuscarPersona.vue";
import AvatarIniciales from "@/components/AvatarIniciales.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useDescartarCambios } from "@/lib/editorFormulario";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

interface Grupo {
  id: string;
  nombre: string;
  oferta: string | null;
  inscritos: number;
  activo: boolean;
  // Cerrado: ya no inscribe a nadie; su historial se conserva.
  cerrado_en?: string | null;
}
interface Plantilla {
  id: string;
  oferta: string | null;
  dias_semana: number[];
  hora_local: string;
}
interface Inscripcion {
  id: string;
  persona: string | null;
  persona_id?: string | null;
  activo: boolean;
  // Su vigencia (R03): desde qué día cuenta y desde cuál ya no viene.
  alta_desde?: string | null;
  baja_desde?: string | null;
}
// Cómo quedó cada fecha al inscribir: reservada, en espera o sin reservar y por qué.
interface FechaInscripcion {
  sesion_id: string;
  inicia_en: string;
  estado: "reservada" | "en_espera" | "no_reservada";
  motivo: string | null;
}
interface ResultadoInscripcion {
  reservadas: number;
  en_espera: number;
  no_reservadas: number;
  sesiones: FechaInscripcion[];
}
interface ResultadoBaja {
  canceladas: number;
  no_canceladas: { inicia_en: string; motivo: string }[];
}

const { t } = useI18n();
const toast = useToastStore();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("agenda.gestionar"));
// Fechas en la zona del negocio (ADR 0099).
const hoy = computed(() => hoyEnNegocio(sesion.zonaHoraria));
function fechaHora(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: sesion.zonaHoraria || undefined,
  }).format(new Date(iso));
}
// Un día del negocio (AAAA-MM-DD), tal cual, sin moverlo de zona.
function dia(fecha: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    timeZone: "UTC",
  }).format(new Date(`${fecha.slice(0, 10)}T12:00:00Z`));
}

const grupos = ref<Grupo[]>([]);
// Búsqueda (por grupo o clase), estado y páginas de 20.
const POR_PAGINA = 20;
const buscar = ref("");
const filtroEstado = ref<"activos" | "inactivos" | "">("activos");
const pagina = ref(1);
const visibles = computed(() => {
  const q = buscar.value.trim().toLowerCase();
  return grupos.value.filter(
    (g) =>
      (filtroEstado.value === "" ||
        (filtroEstado.value === "activos") === g.activo) &&
      (q === "" ||
        g.nombre.toLowerCase().includes(q) ||
        (g.oferta ?? "").toLowerCase().includes(q)),
  );
});
const ultimaPagina = computed(() =>
  Math.max(1, Math.ceil(visibles.value.length / POR_PAGINA)),
);
const enPagina = computed(() =>
  visibles.value.slice(
    (pagina.value - 1) * POR_PAGINA,
    pagina.value * POR_PAGINA,
  ),
);
watch([buscar, filtroEstado], () => {
  pagina.value = 1;
});
const plantillas = ref<Plantilla[]>([]);

const cargando = ref(true);
const error = ref<string | null>(null);

const nuevo = ref({ nombre: "", plantillaId: "" });
const creando = ref(false);
const abierto = ref(false);
// Cada error donde se hizo la acción (una sola vez): crear, en su panel; inscribir,
// junto al grupo elegido. El de la página es solo el de cargar.
const errorCrear = ref<string | null>(null);
const errorDetalle = ref<string | null>(null);

// Con lo capturado sin guardar, cerrar o salir pregunta antes (patrón de formularios).
const descartar = useDescartarCambios({
  abierto,
  valor: () => nuevo.value,
  guardando: creando,
});
function abrir(): void {
  nuevo.value = { nombre: "", plantillaId: "" };
  errorCrear.value = null;
  descartar.fijar();
  abierto.value = true;
}
function cerrar(): void {
  void descartar.cerrar();
}

const seleccionado = ref<Grupo | null>(null);
// Un grupo no tiene tope de lugares: sus inscritos se piden por páginas y se buscan.
const inscripciones = ref<Inscripcion[]>([]);
const totalInscritos = ref(0);
const buscarInscrito = ref("");
const cargandoInscritos = ref(false);
// La primera página no se pudo consultar: no es «aún no hay inscritos».
const errorInscritos = ref(false);
const INSCRITOS_POR_PAGINA = 20;
const miembroId = ref("");
const inscribiendo = ref(false);
// Lo que pasó con cada fecha al inscribir (resumen y, aparte, fecha por fecha).
const resultado = ref<ResultadoInscripcion | null>(null);
const viendoFechas = ref(false);
const resumenResultado = computed(() => {
  const r = resultado.value;
  if (r === null) return "";
  if (r.sesiones.length === 0) return t("cursos.sinFechas");
  const partes = [
    r.reservadas > 0
      ? t("cursos.resumen.reservadas", { n: r.reservadas }, r.reservadas)
      : null,
    r.en_espera > 0
      ? t("cursos.resumen.enEspera", { n: r.en_espera }, r.en_espera)
      : null,
    r.no_reservadas > 0
      ? t(
          "cursos.resumen.noReservadas",
          { n: r.no_reservadas },
          r.no_reservadas,
        )
      : null,
  ].filter((x): x is string => x !== null);
  return t("cursos.resumenInscripcion", { detalle: partes.join(" · ") });
});
// Sin ninguna fecha reservada ni en espera, es un aviso (no un éxito completo).
const resultadoIncompleto = computed(
  () =>
    resultado.value !== null &&
    resultado.value.reservadas + resultado.value.en_espera === 0,
);

// Abreviaturas que no se confunden (martes y miércoles empiezan igual).
const LETRAS = ["", "Lu", "Ma", "Mi", "Ju", "Vi", "Sá", "Do"];
function etiquetaPlantilla(p: Plantilla): string {
  const dias = [...p.dias_semana]
    .sort((a, b) => a - b)
    .map((d) => LETRAS[d])
    .join(" ");
  return `${p.oferta ?? "—"} · ${dias} ${p.hora_local}`;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [g, p] = await Promise.all([
      api.get<{ data: Grupo[] }>(`${base.value}/grupos`),
      // Un grupo nuevo sale de una serie que sigue vigente.
      api.get<{ data: Plantilla[] }>(`${base.value}/plantillas-horario`, {
        params: { estado: "vigentes" },
      }),
    ]);
    grupos.value = g.data.data;
    plantillas.value = p.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function crear(): Promise<void> {
  creando.value = true;
  errorCrear.value = null;
  try {
    await api.post(`${base.value}/grupos`, {
      nombre: nuevo.value.nombre,
      plantilla_id: nuevo.value.plantillaId,
    });
    descartar.fijar();
    abierto.value = false;
    toast.exito(t("cursos.creado", { nombre: nuevo.value.nombre.trim() }));
    await cargar();
  } catch (e) {
    errorCrear.value = mensajeDeError(e);
  } finally {
    creando.value = false;
  }
}

async function seleccionar(g: Grupo): Promise<void> {
  seleccionado.value = g;
  inscripciones.value = [];
  totalInscritos.value = 0;
  miembroId.value = "";
  resultado.value = null;
  errorDetalle.value = null;
  if (buscarInscrito.value !== "") {
    // Al limpiar la búsqueda se vuelven a pedir (ver el watch).
    buscarInscrito.value = "";
    return;
  }
  await cargarInscritos(1);
}

let turnoInscritos = 0;
async function cargarInscritos(pagina: number): Promise<void> {
  const g = seleccionado.value;
  if (g === null) return;
  const mio = ++turnoInscritos;
  cargandoInscritos.value = true;
  errorInscritos.value = false;
  const q = buscarInscrito.value.trim();
  try {
    const { data } = await api.get<{
      data: Inscripcion[];
      meta: { total: number };
    }>(`${base.value}/grupos/${g.id}/inscripciones`, {
      params: {
        page: pagina,
        per_page: INSCRITOS_POR_PAGINA,
        ...(q ? { q } : {}),
      },
    });
    if (mio !== turnoInscritos) return;
    inscripciones.value =
      pagina === 1 ? data.data : [...inscripciones.value, ...data.data];
    totalInscritos.value = data.meta.total;
  } catch (e) {
    if (mio !== turnoInscritos) return;
    if (pagina === 1) {
      inscripciones.value = [];
      errorInscritos.value = true;
    } else {
      errorDetalle.value = mensajeDeError(e);
    }
  } finally {
    if (mio === turnoInscritos) cargandoInscritos.value = false;
  }
}
function masInscritos(): void {
  void cargarInscritos(
    Math.floor(inscripciones.value.length / INSCRITOS_POR_PAGINA) + 1,
  );
}
let esperaInscrito: ReturnType<typeof setTimeout> | undefined;
watch(buscarInscrito, () => {
  clearTimeout(esperaInscrito);
  esperaInscrito = setTimeout(() => void cargarInscritos(1), 300);
});

async function inscribir(): Promise<void> {
  if (seleccionado.value === null || miembroId.value === "") {
    return;
  }
  // Inscribir reserva todas sus clases próximas con su plan: se confirma.
  if (
    !(await confirmar(
      t("cursos.confirmarInscribir", { grupo: seleccionado.value.nombre }),
      { aceptar: t("cursos.inscribir") },
    ))
  ) {
    return;
  }
  inscribiendo.value = true;
  resultado.value = null;
  errorDetalle.value = null;
  try {
    const { data } = await api.post<{ data: ResultadoInscripcion }>(
      `${base.value}/grupos/${seleccionado.value.id}/inscripciones`,
      { persona_id: miembroId.value },
    );
    miembroId.value = "";
    await seleccionar(seleccionado.value);
    // Después de recargar el grupo (que limpia el resultado anterior).
    resultado.value = data.data;
    await cargar();
  } catch (e) {
    errorDetalle.value = mensajeDeError(e);
  } finally {
    inscribiendo.value = false;
  }
}

// Volver a pedir los grupos y el elegido (su estado pudo cambiar).
async function recargarElegido(): Promise<void> {
  const id = seleccionado.value?.id;
  await cargar();
  const g = grupos.value.find((x) => x.id === id);
  if (g) await seleccionar(g);
}

// Salida de alguien: baja desde una fecha o cambio a otro grupo desde esa fecha.
const salida = ref<{
  inscripcion: Inscripcion;
  desde: string;
  destino: string;
} | null>(null);
const saliendo = ref(false);
const errorSalida = ref<string | null>(null);
const destinos = computed(() =>
  grupos.value.filter((g) => g.activo && g.id !== seleccionado.value?.id),
);
const nombreDestino = computed(
  () => grupos.value.find((g) => g.id === salida.value?.destino)?.nombre ?? "",
);
function abrirSalida(i: Inscripcion): void {
  salida.value = { inscripcion: i, desde: hoy.value, destino: "" };
  errorSalida.value = null;
  previa.value = null;
  aceptaParcial.value = false;
}

// Antes de cambiar de grupo (R08): lo que pasaría, fecha por fecha (la misma
// operación, deshecha). Si alguna fecha no se puede reservar, se confirma aparte.
interface VistaPreviaCambio {
  baja: ResultadoBaja;
  alta: ResultadoInscripcion;
  completo: boolean;
}
const previa = ref<VistaPreviaCambio | null>(null);
const cargandoPrevia = ref(false);
const aceptaParcial = ref(false);
let consultaPrevia = 0;
async function cargarPrevia(): Promise<void> {
  const s = salida.value;
  const g = seleccionado.value;
  previa.value = null;
  aceptaParcial.value = false;
  if (s === null || g === null || s.destino === "" || s.desde === "") return;
  const actual = ++consultaPrevia;
  cargandoPrevia.value = true;
  try {
    const { data } = await api.get<{ data: VistaPreviaCambio }>(
      `${base.value}/grupos/${g.id}/inscripciones/${s.inscripcion.id}/cambio`,
      { params: { grupo_id: s.destino, desde: s.desde } },
    );
    if (actual === consultaPrevia) previa.value = data.data;
  } catch (e) {
    if (actual === consultaPrevia) errorSalida.value = mensajeDeError(e);
  } finally {
    if (actual === consultaPrevia) cargandoPrevia.value = false;
  }
}
watch(
  () => [salida.value?.destino, salida.value?.desde],
  () => void cargarPrevia(),
);
const sinReservarEnDestino = computed(() =>
  (previa.value?.alta.sesiones ?? []).filter(
    (f) => f.estado === "no_reservada",
  ),
);
const fechasConProblema = computed(
  () =>
    sinReservarEnDestino.value.length +
    (previa.value?.baja.no_canceladas.length ?? 0),
);
const faltaConfirmarParcial = computed(
  () =>
    salida.value?.destino !== "" &&
    previa.value !== null &&
    !previa.value.completo &&
    !aceptaParcial.value,
);
function avisarNoCanceladas(b: ResultadoBaja): void {
  const n = b.no_canceladas.length;
  if (n > 0) toast.error(t("cursos.noCanceladas", { n }, n));
}
async function confirmarSalida(): Promise<void> {
  const s = salida.value;
  const g = seleccionado.value;
  if (s === null || g === null || s.desde === "") return;
  saliendo.value = true;
  errorSalida.value = null;
  const persona = s.inscripcion.persona ?? "";
  try {
    if (s.destino !== "") {
      const destino = nombreDestino.value;
      const { data } = await api.post<{ meta: { baja: ResultadoBaja } }>(
        `${base.value}/grupos/${g.id}/inscripciones/${s.inscripcion.id}/cambiar`,
        {
          grupo_id: s.destino,
          desde: s.desde,
          ...(aceptaParcial.value ? { parcial: true } : {}),
        },
      );
      toast.exito(t("cursos.cambiado", { persona, grupo: destino }));
      avisarNoCanceladas(data.meta.baja);
    } else {
      const { data } = await api.post<{ meta: ResultadoBaja }>(
        `${base.value}/grupos/${g.id}/inscripciones/${s.inscripcion.id}/baja`,
        { desde: s.desde },
      );
      const n = data.meta.canceladas;
      toast.exito(
        t("cursos.dadoDeBaja", { persona, fecha: dia(s.desde), n }, n),
      );
      avisarNoCanceladas(data.meta);
    }
    salida.value = null;
    await recargarElegido();
  } catch (e) {
    errorSalida.value = mensajeDeError(e);
    // Cambió algo desde la vista previa (otro inscrito, otro saldo): se vuelve a ver.
    if (s.destino !== "") void cargarPrevia();
  } finally {
    saliendo.value = false;
  }
}

// Cerrar el grupo: ya no inscribe y da de baja a quienes siguen desde una fecha.
const cierre = ref<{ desde: string } | null>(null);
const cerrando = ref(false);
const errorCierre = ref<string | null>(null);
function abrirCierre(): void {
  cierre.value = { desde: hoy.value };
  errorCierre.value = null;
}
async function confirmarCierre(): Promise<void> {
  const g = seleccionado.value;
  if (cierre.value === null || g === null || cierre.value.desde === "") return;
  cerrando.value = true;
  errorCierre.value = null;
  try {
    const { data } = await api.post<{
      meta: ResultadoBaja & { bajas: number };
    }>(`${base.value}/grupos/${g.id}/cerrar`, { desde: cierre.value.desde });
    const n = data.meta.bajas;
    toast.exito(t("cursos.cerrado", { grupo: g.nombre, n }, n));
    avisarNoCanceladas(data.meta);
    cierre.value = null;
    await recargarElegido();
  } catch (e) {
    errorCierre.value = mensajeDeError(e);
  } finally {
    cerrando.value = false;
  }
}
async function reabrir(): Promise<void> {
  const g = seleccionado.value;
  if (
    g === null ||
    !(await confirmar(t("cursos.confirmarReabrir", { grupo: g.nombre }), {
      aceptar: t("cursos.reabrir"),
    }))
  ) {
    return;
  }
  try {
    await api.post(`${base.value}/grupos/${g.id}/reabrir`);
    toast.exito(t("cursos.reabierto", { grupo: g.nombre }));
    await recargarElegido();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

onMounted(cargar);

// Indicadores de los grupos (patrón de los listados).
const indicadores = computed<Indicador[]>(() => {
  const activos = grupos.value.filter((g) => g.activo);
  const inscritos = activos.reduce((suma, g) => suma + g.inscritos, 0);
  return [
    {
      clave: "grupos",
      etiqueta: t("gruposVisual.kpi.grupos"),
      valor: String(activos.length),
      icono: "grupos",
    },
    {
      clave: "inscritos",
      etiqueta: t("gruposVisual.kpi.inscritos"),
      valor: String(inscritos),
      icono: "personas",
    },
    {
      clave: "promedio",
      etiqueta: t("gruposVisual.kpi.promedio"),
      valor: activos.length > 0 ? (inscritos / activos.length).toFixed(1) : "—",
      icono: "reportes",
    },
  ];
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('cursos.titulo')"
      :subtitulo="$t('gruposVisual.subtitulo')"
    >
      <template v-if="puedeGestionar && plantillas.length > 0" #acciones>
        <button
          class="tu-btn tu-btn-primario tu-btn-crear"
          type="button"
          @click="abrir"
        >
          {{ $t("cursos.crear") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>

    <template v-if="!cargando">
      <EstadoVacio
        v-if="grupos.length === 0 && !error"
        class="tu-card mt-6"
        icono="grupos"
        :titulo="$t('cursos.vacio')"
      />
      <template v-else-if="grupos.length > 0">
        <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

        <div class="mt-5 grid gap-5 lg:grid-cols-5">
          <!-- Grupos -->
          <div class="tu-card overflow-x-auto lg:col-span-3">
            <div class="flex flex-wrap items-center gap-2 p-3">
              <input
                v-model="buscar"
                type="search"
                class="tu-input max-w-xs"
                :placeholder="$t('operacion.listadosTres.buscarGrupo')"
                :aria-label="$t('operacion.listadosTres.buscarGrupo')"
                data-prueba="buscar-grupos"
              />
              <div
                class="tu-segmentado"
                role="group"
                data-prueba="estado-grupos"
              >
                <button
                  v-for="e in ['activos', 'inactivos', ''] as const"
                  :key="e"
                  type="button"
                  :aria-pressed="filtroEstado === e"
                  @click="filtroEstado = e"
                >
                  {{ $t(`operacion.listadosTres.estadoGrupo.${e || "todos"}`) }}
                </button>
              </div>
            </div>
            <p
              v-if="visibles.length === 0"
              class="px-4 py-8 text-center text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("operacion.listadosDos.sinResultados") }}
            </p>
            <table v-else class="tu-tabla">
              <thead>
                <tr>
                  <th>{{ $t("gruposVisual.col.grupo") }}</th>
                  <th class="text-right">
                    {{ $t("gruposVisual.col.inscritos") }}
                  </th>
                  <th>{{ $t("gruposVisual.col.estado") }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="g in enPagina"
                  :key="g.id"
                  class="gr-fila"
                  :class="{ 'gr-elegida': seleccionado?.id === g.id }"
                  :aria-selected="seleccionado?.id === g.id"
                  data-prueba="grupo"
                  @click="seleccionar(g)"
                >
                  <td>
                    <button
                      type="button"
                      class="gr-nombre"
                      @click.stop="seleccionar(g)"
                    >
                      {{ g.nombre }}
                    </button>
                    <span class="tu-sub">{{ g.oferta ?? "—" }}</span>
                  </td>
                  <td class="text-right tabular-nums">{{ g.inscritos }}</td>
                  <td>
                    <!-- Estado: punto + texto. -->
                    <span
                      class="tu-badge"
                      :class="{ 'tu-badge-exito': g.activo }"
                      >{{
                        g.activo
                          ? $t("gruposVisual.activo")
                          : $t("gruposVisual.cerrado")
                      }}</span
                    >
                  </td>
                </tr>
              </tbody>
            </table>
            <PaginacionListado
              v-if="ultimaPagina > 1"
              :page="pagina"
              :ultima-pagina="ultimaPagina"
              :total="visibles.length"
              :per-page="POR_PAGINA"
              @ir="pagina = $event"
            />
          </div>

          <!-- Grupo elegido: inscritos e inscribir -->
          <aside
            class="tu-card p-5 lg:col-span-2 h-fit"
            data-prueba="detalle-grupo"
          >
            <p
              v-if="!seleccionado"
              class="text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("gruposVisual.elige") }}
            </p>
            <template v-else>
              <div class="flex flex-wrap items-start justify-between gap-2">
                <h2 class="font-medium">{{ seleccionado.nombre }}</h2>
                <template v-if="puedeGestionar">
                  <button
                    v-if="seleccionado.activo"
                    type="button"
                    class="tu-enlace text-sm"
                    data-prueba="cerrar-grupo"
                    @click="abrirCierre"
                  >
                    {{ $t("cursos.cerrarGrupo") }}
                  </button>
                  <button
                    v-else
                    type="button"
                    class="tu-enlace text-sm"
                    data-prueba="reabrir-grupo"
                    @click="reabrir"
                  >
                    {{ $t("cursos.reabrir") }}
                  </button>
                </template>
              </div>
              <p
                class="text-sm"
                :style="{ color: 'var(--texto-suave)' }"
                data-prueba="total-inscritos"
              >
                {{
                  $t(
                    "cursos.inscritosTotal",
                    { n: totalInscritos },
                    totalInscritos,
                  )
                }}
              </p>

              <p
                v-if="!seleccionado.activo"
                class="mt-3 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
                data-prueba="grupo-cerrado"
              >
                {{
                  seleccionado.cerrado_en
                    ? $t("cursos.cerradoEl", {
                        fecha: dia(seleccionado.cerrado_en),
                      })
                    : $t("cursos.cerradoSinFecha")
                }}
              </p>
              <form
                v-if="puedeGestionar && seleccionado.activo"
                class="mt-4 flex flex-wrap items-end gap-2"
                @submit.prevent="inscribir"
              >
                <div class="flex-1 min-w-[180px]">
                  <label class="tu-label" for="im">{{
                    $t("cursos.inscribir")
                  }}</label>
                  <BuscarPersona
                    v-model="miembroId"
                    campo-id="im"
                    :buscar-en="`${base}/miembros`"
                    :parametros="{ tipo: 'miembro' }"
                  />
                </div>
                <button
                  class="tu-btn tu-btn-primario"
                  type="submit"
                  :disabled="inscribiendo || miembroId === ''"
                >
                  {{
                    inscribiendo
                      ? $t("cursos.inscribiendo")
                      : $t("cursos.inscribir")
                  }}
                </button>
              </form>
              <AvisoTono
                v-if="errorDetalle"
                tono="peligro"
                class="mt-3"
                role="alert"
                data-prueba="error-inscribir"
              >
                {{ errorDetalle }}
              </AvisoTono>
              <div
                v-if="resultado"
                class="mt-2 text-sm"
                role="status"
                data-prueba="resultado-inscripcion"
              >
                <p
                  :style="{
                    color: resultadoIncompleto
                      ? 'var(--aviso)'
                      : 'var(--exito)',
                  }"
                >
                  {{ resumenResultado }}
                </p>
                <button
                  v-if="resultado.sesiones.length > 0"
                  type="button"
                  class="tu-enlace"
                  data-prueba="ver-fechas"
                  @click="viendoFechas = true"
                >
                  {{ $t("cursos.verFechas") }}
                </button>
              </div>

              <!-- Con muchos inscritos, se busca en vez de recorrer la lista. -->
              <input
                v-if="totalInscritos > INSCRITOS_POR_PAGINA || buscarInscrito"
                v-model="buscarInscrito"
                type="search"
                class="tu-input mt-4"
                :placeholder="$t('cursos.buscarInscrito')"
                :aria-label="$t('cursos.buscarInscrito')"
                data-prueba="buscar-inscrito"
              />
              <ul
                v-if="inscripciones.length > 0"
                class="gr-inscritos mt-4"
                :class="{ 'opacity-60': cargandoInscritos }"
              >
                <li v-for="i in inscripciones" :key="i.id">
                  <AvatarIniciales :nombre="i.persona" tam="sm" />
                  <span class="min-w-0 flex-1 truncate">{{
                    i.persona ?? "—"
                  }}</span>
                  <span
                    v-if="!i.activo"
                    class="text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{
                      i.baja_desde
                        ? $t("cursos.bajaDesde", { fecha: dia(i.baja_desde) })
                        : $t("cursos.inscritoBaja")
                    }}</span
                  >
                  <span
                    v-else-if="i.alta_desde && i.alta_desde > hoy"
                    class="text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    data-prueba="alta-futura"
                    >{{
                      $t("cursos.empiezaEl", { fecha: dia(i.alta_desde) })
                    }}</span
                  >
                  <button
                    v-else-if="puedeGestionar && seleccionado.activo"
                    type="button"
                    class="tu-enlace text-xs"
                    data-prueba="dar-de-baja"
                    @click="abrirSalida(i)"
                  >
                    {{ $t("cursos.darDeBaja") }}
                  </button>
                </li>
              </ul>
              <p
                v-else-if="errorInscritos && !cargandoInscritos"
                class="mt-4 text-sm"
                role="alert"
                data-prueba="error-inscritos"
              >
                <span style="color: var(--error)">{{
                  $t("cursos.errorInscritos")
                }}</span>
                <button
                  type="button"
                  class="tu-enlace ml-2"
                  @click="cargarInscritos(1)"
                >
                  {{ $t("comun.reintentar") }}
                </button>
              </p>
              <p
                v-else-if="!cargandoInscritos"
                class="mt-4 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  buscarInscrito.trim()
                    ? $t("cursos.sinInscritosBusqueda")
                    : $t("cursos.sinInscritos")
                }}
              </p>
              <button
                v-if="inscripciones.length < totalInscritos"
                type="button"
                class="tu-enlace mt-3 text-sm"
                :disabled="cargandoInscritos"
                data-prueba="mas-inscritos"
                @click="masInscritos"
              >
                {{
                  $t(
                    "cursos.verMasInscritos",
                    { n: totalInscritos - inscripciones.length },
                    totalInscritos - inscripciones.length,
                  )
                }}
              </button>
            </template>
          </aside>
        </div>
      </template>
    </template>

    <!-- Cómo quedó cada fecha al inscribir -->
    <ModalDialogo
      :abierto="viendoFechas && resultado !== null"
      :titulo="$t('cursos.fechasTitulo')"
      icono="agenda"
      @cerrar="viendoFechas = false"
    >
      <div class="overflow-x-auto">
        <table class="tu-tabla" data-prueba="fechas-inscripcion">
          <thead>
            <tr>
              <th>{{ $t("cursos.col.fecha") }}</th>
              <th>{{ $t("cursos.col.estado") }}</th>
              <th>{{ $t("cursos.col.motivo") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in resultado?.sesiones ?? []" :key="f.sesion_id">
              <td class="whitespace-nowrap">{{ fechaHora(f.inicia_en) }}</td>
              <td class="whitespace-nowrap">
                <span
                  class="tu-badge"
                  :class="{
                    'tu-badge-exito': f.estado === 'reservada',
                    'tu-badge-aviso': f.estado !== 'reservada',
                  }"
                  >{{ $t(`cursos.estadoFecha.${f.estado}`) }}</span
                >
              </td>
              <td>{{ f.motivo ?? "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </ModalDialogo>

    <!-- Salida de alguien: baja desde una fecha o cambio de grupo -->
    <ModalDialogo
      :abierto="salida !== null"
      :titulo="
        $t('cursos.salidaTitulo', {
          persona: salida?.inscripcion.persona ?? '',
        })
      "
      tam="md"
      :ocupado="saliendo"
      @cerrar="salida = null"
    >
      <template v-if="salida">
        <AvisoTono
          v-if="errorSalida"
          tono="peligro"
          class="mb-4"
          role="alert"
          data-prueba="error-salida"
        >
          {{ errorSalida }}
        </AvisoTono>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="tu-label" for="salida-desde">{{
              $t("cursos.desde")
            }}</label>
            <input
              id="salida-desde"
              v-model="salida.desde"
              type="date"
              class="tu-input"
              :min="hoy"
              required
              data-prueba="salida-desde"
            />
          </div>
          <div>
            <label class="tu-label" for="salida-destino">{{
              $t("cursos.pasarA")
            }}</label>
            <select
              id="salida-destino"
              v-model="salida.destino"
              class="tu-input"
              data-prueba="salida-destino"
            >
              <option value="">{{ $t("cursos.soloBaja") }}</option>
              <option v-for="d in destinos" :key="d.id" :value="d.id">
                {{ d.nombre }}
              </option>
            </select>
          </div>
        </div>
        <p class="tu-hint mt-3">
          {{
            salida.destino
              ? $t("cursos.salidaAyudaCambio", { grupo: nombreDestino })
              : $t("cursos.salidaAyuda")
          }}
        </p>
        <!-- Lo que pasaría con el cambio, antes de hacerlo (R08). -->
        <div
          v-if="salida.destino"
          class="mt-3 text-sm"
          aria-live="polite"
          data-prueba="vista-previa-cambio"
        >
          <p v-if="cargandoPrevia" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("cursos.previa.calculando") }}
          </p>
          <template v-else-if="previa">
            <p>
              {{
                $t("cursos.previa.resumen", {
                  canceladas: previa.baja.canceladas,
                  grupo: nombreDestino,
                  reservadas: previa.alta.reservadas,
                  espera: previa.alta.en_espera,
                })
              }}
            </p>
            <AvisoTono
              v-if="!previa.completo"
              tono="aviso"
              class="mt-2"
              data-prueba="cambio-parcial"
            >
              <p>
                {{
                  $t(
                    "cursos.previa.parcial",
                    { n: fechasConProblema },
                    fechasConProblema,
                  )
                }}
              </p>
              <ul class="mt-1 list-disc pl-5">
                <li v-for="f in sinReservarEnDestino" :key="f.sesion_id">
                  {{ dia(f.inicia_en) }} · {{ f.motivo }}
                </li>
                <li
                  v-for="f in previa.baja.no_canceladas"
                  :key="`baja-${f.inicia_en}`"
                >
                  {{ dia(f.inicia_en) }} · {{ f.motivo }}
                </li>
              </ul>
              <label class="mt-2 flex items-start gap-2">
                <input
                  v-model="aceptaParcial"
                  type="checkbox"
                  class="mt-1"
                  data-prueba="aceptar-parcial"
                />
                {{ $t("cursos.previa.aceptarParcial") }}
              </label>
            </AvisoTono>
          </template>
        </div>
      </template>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          :disabled="saliendo"
          @click="salida = null"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          :disabled="
            saliendo ||
            !salida?.desde ||
            (salida?.destino !== '' &&
              (cargandoPrevia || faltaConfirmarParcial))
          "
          data-prueba="confirmar-salida"
          @click="confirmarSalida"
        >
          {{
            salida?.destino
              ? $t("cursos.cambiarDeGrupo")
              : $t("cursos.darDeBaja")
          }}
        </button>
      </template>
    </ModalDialogo>

    <!-- Cerrar el grupo -->
    <ModalDialogo
      :abierto="cierre !== null"
      :titulo="$t('cursos.cerrarTitulo', { grupo: seleccionado?.nombre ?? '' })"
      tam="md"
      :ocupado="cerrando"
      @cerrar="cierre = null"
    >
      <template v-if="cierre">
        <AvisoTono
          v-if="errorCierre"
          tono="peligro"
          class="mb-4"
          role="alert"
          data-prueba="error-cierre"
        >
          {{ errorCierre }}
        </AvisoTono>
        <label class="tu-label" for="cierre-desde">{{
          $t("cursos.desde")
        }}</label>
        <input
          id="cierre-desde"
          v-model="cierre.desde"
          type="date"
          class="tu-input max-w-xs"
          :min="hoy"
          required
          data-prueba="cierre-desde"
        />
        <p class="tu-hint mt-3">{{ $t("cursos.cerrarAyuda") }}</p>
      </template>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          :disabled="cerrando"
          @click="cierre = null"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-contorno tu-tono-peligro"
          :disabled="cerrando || !cierre?.desde"
          data-prueba="confirmar-cierre"
          @click="confirmarCierre"
        >
          {{ $t("cursos.cerrarGrupo") }}
        </button>
      </template>
    </ModalDialogo>

    <!-- Nuevo grupo (drawer lateral) -->
    <PanelLateral
      :abierto="abierto"
      :titulo="$t('cursos.nuevo')"
      :ocupado="creando"
      @cerrar="cerrar"
    >
      <form class="space-y-4" @submit.prevent="crear">
        <AvisoTono
          v-if="errorCrear"
          tono="peligro"
          role="alert"
          data-prueba="error-crear-grupo"
        >
          {{ errorCrear }}
        </AvisoTono>
        <div>
          <label class="tu-label" for="gn">{{ $t("cursos.nombre") }}</label>
          <input id="gn" v-model="nuevo.nombre" class="tu-input" required />
        </div>
        <div>
          <label class="tu-label" for="gp">{{ $t("cursos.horario") }}</label>
          <select id="gp" v-model="nuevo.plantillaId" class="tu-input" required>
            <option value="" disabled>{{ $t("cursos.horario") }}</option>
            <option v-for="p in plantillas" :key="p.id" :value="p.id">
              {{ etiquetaPlantilla(p) }}
            </option>
          </select>
        </div>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            :disabled="creando"
            @click="cerrar"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="
              creando || nuevo.nombre === '' || nuevo.plantillaId === ''
            "
            @click="crear"
          >
            {{ creando ? $t("cursos.creando") : $t("cursos.crear") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>

<style scoped>
.gr-fila {
  cursor: pointer;
}
.gr-fila:hover td,
.gr-elegida td {
  background: color-mix(in srgb, var(--texto-suave) 5%, transparent);
}
.gr-elegida td:first-child {
  box-shadow: inset 2px 0 0 var(--primario);
}
.gr-nombre {
  font-weight: 500;
  text-align: left;
}
.gr-inscritos {
  display: grid;
}
.gr-inscritos > li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0;
  border-top: 1px solid var(--borde);
  font-size: 0.875rem;
}
</style>
