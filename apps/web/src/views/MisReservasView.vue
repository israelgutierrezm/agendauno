<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute, useRouter } from "vue-router";

import AgendarCitaCuenta from "@/components/AgendarCitaCuenta.vue";
import AgregarCalendario from "@/components/AgregarCalendario.vue";
import CalendarioVistas, {
  type EventoPeriodo,
} from "@/components/CalendarioVistas.vue";
import ConfirmarCancelacion from "@/components/ConfirmarCancelacion.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import HistorialReservas, {
  type ItemHistorial,
} from "@/components/HistorialReservas.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import ReprogramarMiReserva from "@/components/ReprogramarMiReserva.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  cuandoCorto,
  presentarCobertura,
  useMiCuenta,
  type Clase,
  type Reserva,
} from "@/lib/miCuenta";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Reservas del portal, por tarea:
 * - Reservar: las clases a las que puede entrar, en lista (por día) o calendario,
 *   con filtros (actividad, instructor, sucursal) y, en cada una, si su plan la
 *   incluye (lo dice el servidor con la misma regla que al reservar). En negocios
 *   de citas, «Agendar» con el formulario de la cita.
 * - Próximas: sus reservas por venir.
 * - Historial: lo que tomó, faltó o canceló, con su reseña y «Reservar de nuevo».
 * Tocar una abre su detalle: agregar a mi calendario, cambiar horario, cancelar,
 * reservar o entrar a la lista de espera.
 */
type Pestana = "reservar" | "proximas" | "historial";
const PESTANAS: Pestana[] = ["reservar", "proximas", "historial"];
// La lista crece de a poco: con muchas clases en el periodo no es un muro.
const POR_TANDA = 20;

interface Evento {
  id: string;
  mia: boolean;
  titulo: string;
  inicia: string;
  termina: string | null;
  zona: string;
  sucursal: string | null;
  instructor: string | null;
  estado: string;
  reserva?: Reserva;
  clase?: Clase;
}
interface Sede {
  id: string;
  nombre: string;
}

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();
const cuenta = useMiCuenta();

// La pestaña va en la dirección (?vista=…): los accesos del Inicio llevan a la suya
// y recargar la conserva.
function pestanaDe(v: unknown): Pestana {
  return PESTANAS.includes(v as Pestana) ? (v as Pestana) : "reservar";
}
const pestana = ref<Pestana>(pestanaDe(route.query.vista));
function irA(p: Pestana): void {
  pestana.value = p;
  void router.replace({ query: { ...route.query, vista: p } });
}

/**
 * Las clases se piden por el periodo que se ve (GET /mi/agenda con desde/hasta y,
 * si eligió una, la sucursal): al moverse de semana o de mes se vuelven a pedir.
 * Cada pedido lleva su número: una respuesta vieja no pisa a la del periodo actual.
 */
const periodo = ref<{ desde: string; hasta: string } | null>(null);
const sucursalId = ref("");
const actividadId = ref("");
const instructorId = ref("");
const busqueda = ref("");
const normalizar = (s: string) =>
  s
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase();
const soloIncluidas = ref(false);
// «Reservar de nuevo» desde el historial: esa clase.
const ofertaElegida = ref<{ id: string; nombre: string } | null>(null);
const sedes = ref<Sede[]>([]);
const clasesPeriodo = ref<Clase[]>([]);
const cargandoClases = ref(false);
const errorClases = ref<string | null>(null);
const truncado = ref(false);
const limite = ref(POR_TANDA);
let pedido = 0;

async function cargarClases(): Promise<void> {
  if (sesion.esCitas || periodo.value === null) {
    return;
  }
  const mio = ++pedido;
  cargandoClases.value = true;
  errorClases.value = null;
  try {
    const { data } = await api.get<{
      data: Clase[];
      meta?: { sucursales?: Sede[]; truncado?: boolean };
    }>(`${cuenta.base.value}/mi/agenda`, {
      params: {
        desde: periodo.value.desde,
        hasta: periodo.value.hasta,
        sucursal_id: sucursalId.value || undefined,
      },
    });
    if (mio !== pedido) {
      return; // Ya se movió a otro periodo o sede.
    }
    clasesPeriodo.value = data.data;
    sedes.value = data.meta?.sucursales ?? sedes.value;
    truncado.value = data.meta?.truncado === true;
  } catch (e) {
    if (mio === pedido) {
      clasesPeriodo.value = [];
      errorClases.value = mensajeDeError(e);
    }
  } finally {
    if (mio === pedido) {
      cargandoClases.value = false;
    }
  }
}
function alCambiarPeriodo(r: { desde: string; hasta: string }): void {
  periodo.value = { desde: r.desde, hasta: r.hasta };
  limite.value = POR_TANDA;
  void cargarClases();
}
watch(sucursalId, () => void cargarClases());
watch(
  [
    sucursalId,
    actividadId,
    instructorId,
    soloIncluidas,
    ofertaElegida,
    busqueda,
  ],
  () => {
    limite.value = POR_TANDA;
  },
);

// Las opciones de los filtros salen de las clases del periodo (y la elegida se
// queda aunque en este periodo no haya).
function opciones(
  id: (c: Clase) => string | null | undefined,
  nombre: (c: Clase) => string | null | undefined,
  elegido: string,
): Sede[] {
  const mapa = new Map<string, string>();
  for (const c of clasesPeriodo.value) {
    const k = id(c);
    if (k) {
      mapa.set(k, nombre(c) ?? "—");
    }
  }
  if (elegido !== "" && !mapa.has(elegido)) {
    mapa.set(elegido, "—");
  }
  return [...mapa]
    .map(([k, v]) => ({ id: k, nombre: v }))
    .sort((a, b) => a.nombre.localeCompare(b.nombre, "es"));
}
const actividades = computed(() =>
  opciones(
    (c) => c.actividad_id,
    (c) => c.actividad,
    actividadId.value,
  ),
);
const instructores = computed(() =>
  opciones(
    (c) => c.instructor_id,
    (c) => c.instructor,
    instructorId.value,
  ),
);
const hayCobertura = computed(() =>
  clasesPeriodo.value.some((c) => c.cobertura),
);
const filtrando = computed(
  () =>
    sucursalId.value !== "" ||
    actividadId.value !== "" ||
    busqueda.value.trim() !== "" ||
    instructorId.value !== "" ||
    soloIncluidas.value ||
    ofertaElegida.value !== null,
);

function pasaFiltros(c: Clase): boolean {
  return (
    normalizar(
      [c.oferta, c.sucursal, c.instructor].filter(Boolean).join(" "),
    ).includes(normalizar(busqueda.value.trim())) &&
    (actividadId.value === "" || c.actividad_id === actividadId.value) &&
    (instructorId.value === "" || c.instructor_id === instructorId.value) &&
    (ofertaElegida.value === null || c.oferta_id === ofertaElegida.value.id) &&
    (!soloIncluidas.value ||
      presentarCobertura(c.cobertura, t)?.reservable !== false)
  );
}
function quitarFiltros(): void {
  sucursalId.value = "";
  busqueda.value = "";
  actividadId.value = "";
  instructorId.value = "";
  soloIncluidas.value = false;
  ofertaElegida.value = null;
}

const abierto = ref<Evento | null>(null);
const accion = ref<"reprogramar" | "cancelar" | null>(null);
const aviso = ref<string | null>(null);
// «Agendar de nuevo» una cita: el mismo servicio, sede y profesional.
const citaInicial = ref<{
  servicio: string | null;
  sucursal: string | null;
  profesional: string | null;
} | null>(null);
const formularioCita = ref(0);

function deReserva(r: Reserva): Evento {
  return {
    id: r.id,
    mia: true,
    // Si es para otra persona, se dice para quién.
    titulo: r.asiste
      ? t("perfilPublico.agendar.tituloPara", {
          oferta: r.oferta ?? "—",
          nombre: r.asiste,
        })
      : (r.oferta ?? "—"),
    inicia: r.inicia_en ?? "",
    termina: r.termina_en ?? null,
    zona: r.zona_horaria ?? "America/Mexico_City",
    sucursal: r.sucursal,
    instructor: r.instructor ?? null,
    estado: r.estado,
    reserva: r,
  };
}
function deClase(c: Clase): Evento {
  return {
    id: c.id,
    mia: false,
    titulo: c.oferta ?? "—",
    inicia: c.inicia_en,
    termina: c.termina_en ?? null,
    zona: c.zona_horaria,
    sucursal: c.sucursal,
    instructor: c.instructor ?? null,
    estado: "disponible",
    clase: c,
  };
}

const mias = computed<Evento[]>(() => cuenta.proximas.value.map(deReserva));
const libres = computed<Evento[]>(() =>
  clasesPeriodo.value
    .filter((c) => !cuenta.reservadas.value.has(c.id) && pasaFiltros(c))
    .map(deClase)
    .sort((a, b) => a.inicia.localeCompare(b.inicia)),
);
// En la lista, por día; se muestran de a tandas.
const enLista = computed(() => libres.value.slice(0, limite.value));
const porDia = computed(() => {
  const grupos: { dia: string; eventos: Evento[] }[] = [];
  for (const e of enLista.value) {
    const dia = new Intl.DateTimeFormat("es-MX", {
      timeZone: e.zona,
      weekday: "long",
      day: "numeric",
      month: "long",
    }).format(new Date(e.inicia));
    const ultimo = grupos.at(-1);
    if (ultimo?.dia === dia) {
      ultimo.eventos.push(e);
    } else {
      grupos.push({ dia, eventos: [e] });
    }
  }
  return grupos;
});

// En el calendario: las disponibles y, resaltadas, las suyas.
const enCalendario = computed<EventoPeriodo[]>(() =>
  [...mias.value, ...libres.value]
    .sort((a, b) => a.inicia.localeCompare(b.inicia))
    .map((e) => ({
      id: e.id,
      titulo: e.titulo,
      inicia: e.inicia,
      termina: e.termina,
      zona: e.zona,
      detalle: [e.sucursal, e.instructor].filter(Boolean).join(" · "),
      estado: e.mia ? t("portal.reservas.reservada") : lugares(e),
      tono: e.mia ? "primario" : llena(e) ? "aviso" : "suave",
      destacado: e.mia,
    })),
);

function abrir(e: Evento): void {
  abierto.value = e;
  accion.value = null;
}
function abrirPorId(id: string): void {
  const e = [...mias.value, ...libres.value].find((x) => x.id === id);
  if (e) {
    abrir(e);
  }
}
function cerrar(): void {
  abierto.value = null;
  accion.value = null;
}

/** «18:30» en la hora de la sede (la lista ya va por día). */
function hora(e: Evento): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: e.zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(e.inicia));
}
function fecha(e: Evento, parte: "day" | "month"): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: e.zona,
    ...(parte === "day" ? { day: "numeric" } : { month: "short" }),
  }).format(new Date(e.inicia));
}
function ocupacion(e: Evento): number {
  const c = e.clase;
  return c?.capacidad && c.capacidad > 0
    ? Math.min(100, (c.ocupados / c.capacidad) * 100)
    : 0;
}
function lugares(e: Evento): string {
  const c = e.clase;
  if (!c || c.capacidad === null) {
    return "";
  }
  return c.ocupados >= c.capacidad
    ? t("portal.reservas.llena")
    : t("portal.reservas.lugares", {
        libres: c.capacidad - c.ocupados,
        total: c.capacidad,
      });
}
function llena(e: Evento): boolean {
  const c = e.clase;
  return !!c && c.capacidad !== null && c.ocupados >= c.capacidad;
}
function cobertura(e: Evento) {
  return presentarCobertura(e.clase?.cobertura, t);
}
// Se puede reservar si su plan la incluye o es de pago (sin dato, se intenta).
function reservable(e: Evento): boolean {
  return cobertura(e)?.reservable !== false;
}
function estadoMia(e: Evento): { texto: string; tono: string } {
  return {
    texto: t(`miCuenta.${e.estado}`),
    tono: e.estado === "confirmada" ? "var(--exito)" : "var(--aviso)",
  };
}

// Tras reservar, cancelar o cambiar, el cupo del periodo cambió: se vuelve a pedir.
async function reservar(e: Evento, esperar: boolean): Promise<void> {
  if (e.clase && (await cuenta.reservar(e.clase, esperar))) {
    aviso.value = esperar
      ? t("miCuenta.enListaEspera")
      : t("miCuenta.reservado");
    cerrar();
    void cargarClases();
  }
}
async function cancelar(e: Evento): Promise<void> {
  if (e.reserva && (await cuenta.cancelar(e.reserva))) {
    cerrar();
    void cargarClases();
  }
}
async function aceptar(e: Evento): Promise<void> {
  if (e.reserva && (await cuenta.aceptar(e.reserva))) {
    cerrar();
    void cargarClases();
  }
}
async function reprogramada(): Promise<void> {
  aviso.value = t("miReprogramar.hecho");
  cerrar();
  await cuenta.cargar(true);
  void cargarClases();
}

// Desde el historial: la misma clase en Reservar, o la misma cita en Agendar.
function reservarDeNuevo(i: ItemHistorial): void {
  if (i.tipo === "cita" || sesion.esCitas) {
    citaInicial.value = {
      servicio: i.oferta_id,
      sucursal: i.sucursal_id,
      profesional: i.instructor_id,
    };
    formularioCita.value++;
  } else if (i.oferta_id) {
    quitarFiltros();
    ofertaElegida.value = { id: i.oferta_id, nombre: i.oferta ?? "—" };
  }
  irA("reservar");
}
function citaAgendada(): void {
  void cuenta.cargar(true);
}
function citaLista(): void {
  citaInicial.value = null;
  formularioCita.value++;
  irA("proximas");
}

onMounted(() => void cuenta.asegurar());
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('portal.reservas.titulo')"
      :subtitulo="
        $t(
          sesion.esCitas
            ? 'portal.reservas.descripcionCitas'
            : 'portal.reservas.descripcionClases',
        )
      "
    />

    <div class="tu-pestanas mt-4" role="tablist">
      <button
        v-for="p in PESTANAS"
        :key="p"
        type="button"
        role="tab"
        :aria-selected="pestana === p"
        :data-prueba="`pestana-${p}`"
        @click="irA(p)"
      >
        {{
          p === "reservar" && sesion.esCitas
            ? $t("portal.reservas.pestanas.agendar")
            : $t(`portal.reservas.pestanas.${p}`)
        }}<span
          v-if="p === 'proximas' && mias.length > 0"
          class="tu-badge ml-2"
          >{{ mias.length }}</span
        >
      </button>
    </div>

    <p
      v-if="cuenta.error.value"
      class="mt-3 text-sm"
      style="color: var(--error)"
    >
      {{ cuenta.error.value }}
    </p>
    <p
      v-if="aviso"
      class="mt-3 text-sm"
      role="status"
      :style="{ color: 'var(--exito)' }"
    >
      {{ aviso }}
    </p>

    <!-- RESERVAR: citas -->
    <p
      v-if="pestana === 'proximas' && cuenta.cargando.value"
      class="mt-5 text-sm"
      style="color: var(--texto-suave)"
    >
      {{ $t("comun.cargando") }}
    </p>
    <div
      v-else-if="
        pestana === 'proximas' && cuenta.error.value && !cuenta.cargado.value
      "
      class="mt-5 tu-card p-5"
      role="alert"
    >
      <p class="text-sm" style="color: var(--error)">
        {{ cuenta.error.value }}
      </p>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma mt-3"
        @click="cuenta.cargar(true)"
      >
        {{ $t("comun.reintentar") }}
      </button>
    </div>
    <div
      v-else-if="pestana === 'reservar' && sesion.esCitas"
      class="mt-4 tu-card p-5 mr-cita"
    >
      <AgendarCitaCuenta
        :key="formularioCita"
        :inicial="citaInicial"
        @agendada="citaAgendada"
        @cerrar="citaLista"
      />
    </div>

    <!-- RESERVAR: clases -->
    <template v-else-if="pestana === 'reservar'">
      <p
        v-if="truncado && !errorClases"
        class="mt-3 text-sm"
        :style="{ color: 'var(--aviso)' }"
      >
        {{ $t("portal.reservas.demasiadas") }}
      </p>

      <CalendarioVistas
        class="mt-4"
        clave="tu.portal.vista"
        :eventos="enCalendario"
        :cargando="cuenta.cargando.value || cargandoClases"
        :error="errorClases"
        detallado
        @abrir="abrirPorId"
        @rango="alCambiarPeriodo"
        @reintentar="cargarClases"
      >
        <template #filtros>
          <div class="mr-filtros tu-card">
            <label class="mr-busqueda">
              <span class="sr-only">{{ $t("portal.reservas.buscar") }}</span>
              <input
                v-model="busqueda"
                type="search"
                class="tu-input"
                :placeholder="$t('portal.reservas.buscarPlaceholder')"
              />
            </label>
            <select
              v-if="actividades.length > 1 || actividadId"
              v-model="actividadId"
              class="tu-input"
              :aria-label="$t('portal.reservas.actividad')"
              data-prueba="filtro-actividad"
            >
              <option value="">
                {{ $t("portal.reservas.todasActividades") }}
              </option>
              <option v-for="a in actividades" :key="a.id" :value="a.id">
                {{ a.nombre }}
              </option>
            </select>
            <select
              v-if="instructores.length > 1 || instructorId"
              v-model="instructorId"
              class="tu-input"
              :aria-label="$t('portal.reservas.instructor')"
              data-prueba="filtro-instructor"
            >
              <option value="">
                {{ $t("portal.reservas.todosInstructores") }}
              </option>
              <option v-for="i in instructores" :key="i.id" :value="i.id">
                {{ i.nombre }}
              </option>
            </select>
            <select
              v-if="sedes.length > 1 || sucursalId"
              id="mr-sede"
              v-model="sucursalId"
              class="tu-input"
              :aria-label="$t('portal.reservas.sucursal')"
            >
              <option value="">
                {{ $t("portal.reservas.todasSucursales") }}
              </option>
              <option v-for="s in sedes" :key="s.id" :value="s.id">
                {{ s.nombre }}
              </option>
            </select>
            <label
              v-if="hayCobertura"
              class="flex items-center gap-2 text-sm"
              data-prueba="filtro-incluidas"
            >
              <input v-model="soloIncluidas" type="checkbox" />
              {{ $t("portal.reservas.soloIncluidas") }}
            </label>
            <span
              v-if="ofertaElegida"
              class="flex items-center gap-2 text-sm"
              data-prueba="filtro-clase"
            >
              {{
                $t("portal.reservas.claseElegida", {
                  nombre: ofertaElegida.nombre,
                })
              }}
              <button
                type="button"
                class="tu-enlace"
                @click="ofertaElegida = null"
              >
                {{ $t("portal.reservas.quitarFiltro") }}
              </button>
            </span>
            <button
              v-if="filtrando"
              type="button"
              class="tu-enlace text-sm"
              @click="quitarFiltros"
            >
              {{ $t("portal.reservas.limpiar") }}
            </button>
          </div>
        </template>

        <!-- LISTA: las disponibles, por día -->
        <template #lista>
          <div class="mr-disponibles">
            <template v-if="porDia.length > 0">
              <section v-for="g in porDia" :key="g.dia" class="mr-jornada">
                <div class="mr-fecha" aria-hidden="true">
                  <strong>{{ fecha(g.eventos[0]!, "day") }}</strong
                  ><span>{{ fecha(g.eventos[0]!, "month") }}</span>
                </div>
                <div class="min-w-0">
                  <h2 class="mr-dia first-letter:uppercase">{{ g.dia }}</h2>
                  <ul>
                    <li
                      v-for="e in g.eventos"
                      :key="e.id"
                      class="mr-clase tu-card"
                      data-prueba="clase-disponible"
                    >
                      <button type="button" class="mr-info" @click="abrir(e)">
                        <span class="mr-hora"
                          >{{ hora(e)
                          }}<small v-if="e.termina"
                            >– {{ hora({ ...e, inicia: e.termina }) }}</small
                          ></span
                        >
                        <span class="block font-semibold">{{ e.titulo }}</span>
                        <span class="mr-detalle">{{
                          [e.instructor, e.sucursal].filter(Boolean).join(" · ")
                        }}</span>
                        <span class="mr-estado">
                          <span
                            v-if="cobertura(e)"
                            class="tu-pildora"
                            :style="{ '--tono': cobertura(e)!.tono }"
                            data-prueba="cobertura"
                            >{{ cobertura(e)!.texto }}</span
                          >
                          <span
                            v-if="lugares(e)"
                            :style="{
                              color: llena(e)
                                ? 'var(--aviso)'
                                : 'var(--texto-suave)',
                            }"
                            >{{ lugares(e) }}</span
                          >
                        </span>
                        <span
                          v-if="e.clase?.capacidad"
                          class="mr-ocupacion"
                          aria-hidden="true"
                          ><i
                            :style="{
                              width: `${ocupacion(e)}%`,
                              background: llena(e)
                                ? 'var(--aviso)'
                                : 'var(--primario)',
                            }"
                        /></span>
                      </button>
                      <button
                        v-if="reservable(e)"
                        type="button"
                        class="tu-btn shrink-0 text-sm"
                        :class="
                          llena(e) ? 'tu-btn-fantasma' : 'tu-btn-primario'
                        "
                        :disabled="cuenta.accionando.value"
                        @click="reservar(e, llena(e))"
                      >
                        {{
                          llena(e)
                            ? $t("miCuenta.listaEspera")
                            : $t("miCuenta.reservar")
                        }}
                      </button>
                    </li>
                  </ul>
                </div>
              </section>
              <div v-if="libres.length > enLista.length" class="p-4">
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma w-full text-sm"
                  @click="limite += POR_TANDA"
                >
                  {{ $t("portal.reservas.verMas") }}
                </button>
              </div>
            </template>
            <div v-else class="mr-vacio tu-card">
              <IconoNav nombre="agenda" :tam="36" />
              <p :style="{ color: 'var(--texto-suave)' }">
                {{
                  filtrando
                    ? $t("portal.reservas.sinFiltradas")
                    : $t("miCuenta.sinClases")
                }}
              </p>
              <button
                v-if="filtrando"
                type="button"
                class="tu-enlace mt-2"
                @click="quitarFiltros"
              >
                {{ $t("portal.reservas.quitarFiltro") }}
              </button>
            </div>
          </div>
        </template>
      </CalendarioVistas>
    </template>

    <!-- PRÓXIMAS -->
    <div v-else-if="pestana === 'proximas'" class="mt-4 mr-proximas">
      <ul v-if="mias.length > 0" class="mr-proximas-lista">
        <li
          v-for="e in mias"
          :key="e.id"
          class="mr-clase tu-card"
          data-prueba="reserva-proxima"
        >
          <button type="button" class="mr-info" @click="abrir(e)">
            <span class="mr-proxima-fecha" aria-hidden="true"
              ><strong>{{ fecha(e, "day") }}</strong
              ><span>{{ fecha(e, "month") }}</span></span
            >
            <span class="block font-semibold">{{ e.titulo }}</span>
            <span class="mr-detalle first-letter:uppercase"
              >{{ cuandoCorto(e.inicia, e.zona)
              }}{{ e.sucursal ? ` · ${e.sucursal}` : ""
              }}{{ e.instructor ? ` · ${e.instructor}` : "" }}</span
            >
            <span class="mr-estado">
              <span
                class="tu-pildora"
                :style="{ '--tono': estadoMia(e).tono }"
                >{{ estadoMia(e).texto }}</span
              >
            </span>
            <span class="mr-ver-detalle"
              ><IconoNav nombre="chevron" :tam="16" />{{
                $t("portal.reservas.verDetalle")
              }}</span
            >
          </button>
        </li>
      </ul>
      <div v-else class="mr-vacio tu-card">
        <IconoNav nombre="agenda" :tam="36" />
        <p :style="{ color: 'var(--texto-suave)' }">
          {{ $t("portal.reservas.sinProximas") }}
        </p>
        <button
          type="button"
          class="tu-btn tu-btn-primario mt-3 text-sm"
          @click="irA('reservar')"
        >
          {{
            sesion.esCitas
              ? $t("portal.reservas.irAgendar")
              : $t("portal.reservas.irReservar")
          }}
        </button>
      </div>
      <div
        class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-xs"
        :style="{
          color: 'var(--texto-suave)',
          borderTop: '1px solid var(--borde)',
        }"
      >
        <span v-if="cuenta.politica.value">{{
          $t("miCuenta.politica", {
            horas: cuenta.politica.value.horas_limite,
          })
        }}</span>
        <RouterLink :to="{ name: 'mi-perfil' }" class="tu-enlace">{{
          $t("portal.reservas.sincronizar")
        }}</RouterLink>
      </div>
    </div>

    <!-- HISTORIAL -->
    <HistorialReservas
      v-else
      class="mt-4"
      @reservar-de-nuevo="reservarDeNuevo"
    />

    <!-- Detalle de una reserva o una clase -->
    <PanelLateral
      :abierto="abierto !== null"
      :titulo="abierto?.titulo ?? $t('portal.reservas.detalle')"
      @cerrar="cerrar"
    >
      <template v-if="abierto">
        <p class="first-letter:uppercase">
          {{ cuandoCorto(abierto.inicia, abierto.zona) }}
        </p>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ abierto.sucursal ?? "" }}
          <template v-if="abierto.instructor">
            · {{ $t("portal.reservas.con", { nombre: abierto.instructor }) }}
          </template>
        </p>
        <p class="mt-3 flex flex-wrap items-center gap-3 text-sm">
          <span
            v-if="abierto.mia"
            class="tu-pildora"
            :style="{ '--tono': estadoMia(abierto).tono }"
            >{{ estadoMia(abierto).texto }}</span
          >
          <span
            v-else-if="cobertura(abierto)"
            class="tu-pildora"
            :style="{ '--tono': cobertura(abierto)!.tono }"
            >{{ cobertura(abierto)!.texto }}</span
          >
          <span
            v-if="!abierto.mia && lugares(abierto)"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ lugares(abierto) }}</span
          >
        </p>
        <p
          v-if="!abierto.mia && cobertura(abierto)?.motivo"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ cobertura(abierto)!.motivo }}
        </p>

        <!-- Suya -->
        <div v-if="abierto.mia && abierto.reserva" class="mt-5 space-y-3">
          <AgregarCalendario
            :evento="{
              uid: `reserva-${abierto.id}`,
              titulo: abierto.titulo,
              inicio: abierto.inicia,
              fin: abierto.termina,
              lugar: [sesion.estudio?.nombre, abierto.sucursal]
                .filter(Boolean)
                .join(' · '),
            }"
          />
          <div class="flex flex-wrap gap-2">
            <button
              v-if="abierto.estado === 'ofrecida'"
              class="tu-btn tu-btn-primario"
              :disabled="cuenta.accionando.value"
              @click="aceptar(abierto)"
            >
              {{ $t("miCuenta.aceptarPlaza") }}
            </button>
            <RouterLink
              v-if="
                abierto.estado === 'pendiente_pago' &&
                abierto.reserva.orden_id !== null
              "
              :to="{ name: 'mis-pagos' }"
              class="tu-btn tu-btn-primario"
              >{{ $t("miCuentaExtra.pagar") }}</RouterLink
            >
            <button
              v-if="
                abierto.estado === 'confirmada' ||
                abierto.estado === 'pendiente_pago'
              "
              class="tu-btn tu-btn-fantasma"
              :aria-expanded="accion === 'reprogramar'"
              @click="accion = accion === 'reprogramar' ? null : 'reprogramar'"
            >
              {{ $t("miReprogramar.boton") }}
            </button>
            <button
              class="tu-btn tu-btn-fantasma"
              style="color: var(--error)"
              :aria-expanded="accion === 'cancelar'"
              @click="accion = accion === 'cancelar' ? null : 'cancelar'"
            >
              {{ $t("miCuenta.cancelar") }}
            </button>
          </div>
          <ReprogramarMiReserva
            v-if="accion === 'reprogramar'"
            :base="cuenta.base.value"
            :reserva-id="abierto.id"
            :zona="abierto.zona"
            :inicia-en="abierto.inicia"
            @hecho="reprogramada"
            @cerrar="accion = null"
          />
          <ConfirmarCancelacion
            v-if="accion === 'cancelar'"
            :url="`${cuenta.base.value}/mi/reservas/${abierto.id}/cancelacion`"
            :ocupado="cuenta.accionando.value"
            @confirmar="cancelar(abierto)"
            @cerrar="accion = null"
          />
        </div>

        <!-- Disponible: reservar, o ver los planes que la incluyen -->
        <div v-else class="mt-5">
          <button
            v-if="reservable(abierto)"
            class="tu-btn"
            :class="llena(abierto) ? 'tu-btn-fantasma' : 'tu-btn-primario'"
            :disabled="cuenta.accionando.value"
            @click="reservar(abierto, llena(abierto))"
          >
            {{
              llena(abierto)
                ? $t("miCuenta.listaEspera")
                : $t("miCuenta.reservar")
            }}
          </button>
          <RouterLink
            v-else
            :to="{ name: 'mis-pagos' }"
            class="tu-btn tu-btn-fantasma"
            data-prueba="ver-planes"
            >{{ $t("portal.reservas.verPlanes") }}</RouterLink
          >
        </div>
      </template>
    </PanelLateral>
  </section>
</template>

<style scoped>
/* Filtros de Reservar: se acomodan en varias líneas en el teléfono. */
.mr-filtros {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.75rem;
  min-width: 0;
  padding: 0.85rem;
}
.mr-busqueda {
  flex: 1 1 230px;
}
.mr-filtros > select {
  width: auto;
  max-width: 100%;
}
/* Encabezado de cada día de la lista. */
.mr-dia {
  margin-bottom: 0.65rem;
  font-size: 0.9rem;
  font-weight: 500;
  color: var(--texto-suave);
}
/* Una clase: lo que es a la izquierda y la acción a la derecha; en el teléfono,
   la acción baja sin desbordar el ancho. */
.mr-clase {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 1rem;
  padding: 1rem 1.25rem;
  margin-top: 0.6rem;
}
.mr-info {
  display: block;
  flex: 1 1 14rem;
  min-width: 0;
  text-align: left;
}
.mr-info:hover .font-semibold {
  color: var(--enlace);
}
.mr-detalle {
  display: block;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
.mr-estado {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem 0.75rem;
  margin-top: 0.3rem;
  font-size: 0.8rem;
}
.mr-cita {
  width: 100%;
}
.mr-jornada {
  display: grid;
  grid-template-columns: 3.5rem minmax(0, 1fr);
  gap: 1rem;
  margin-bottom: 1.5rem;
}
.mr-fecha {
  align-self: start;
  padding: 0.6rem 0.2rem;
  border-radius: 12px;
  text-align: center;
  background: var(--primario-suave);
  color: color-mix(in srgb, var(--acento), var(--texto) 35%);
}
.mr-fecha strong,
.mr-proxima-fecha strong {
  display: block;
  font-size: 1.5rem;
  line-height: 1.2;
}
.mr-fecha span,
.mr-proxima-fecha > span {
  font-size: 0.75rem;
  text-transform: uppercase;
}
.mr-hora {
  display: block;
  margin-bottom: 0.4rem;
  color: color-mix(in srgb, var(--acento), var(--texto) 35%);
  font-size: 0.85rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.mr-hora small {
  font-size: inherit;
  font-weight: 400;
  margin-left: 0.3rem;
}
.mr-ocupacion {
  display: block;
  width: 100px;
  height: 4px;
  border-radius: 3px;
  background: var(--borde);
  margin-top: 0.6rem;
  overflow: hidden;
}
.mr-ocupacion i {
  display: block;
  height: 100%;
}
.mr-proximas-lista {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}
.mr-proximas .mr-clase {
  margin: 0;
}
.mr-proxima-fecha {
  display: block;
  float: left;
  text-align: center;
  padding: 0.5rem;
  width: 3.4rem;
  margin-right: 1rem;
  margin-bottom: 0.5rem;
  border-radius: 10px;
  background: var(--primario-suave);
  color: color-mix(in srgb, var(--acento), var(--texto) 35%);
}
.mr-ver-detalle {
  display: flex;
  gap: 0.25rem;
  align-items: center;
  margin-top: 0.65rem;
  font-size: 0.8rem;
  color: var(--enlace);
}
.mr-vacio {
  padding: 2rem;
  text-align: center;
}
.mr-vacio > svg {
  margin: 0 auto 1rem;
  color: var(--texto-suave);
}
@media (max-width: 700px) {
  .mr-jornada {
    grid-template-columns: 2.7rem minmax(0, 1fr);
    gap: 0.6rem;
  }
  .mr-clase {
    padding: 0.85rem;
  }
  .mr-proximas-lista {
    grid-template-columns: 1fr;
  }
  .mr-filtros > select {
    flex: 1 1 150px;
  }
}
</style>
