<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AgendaMes from "@/components/AgendaMes.vue";
import AgendarCitaCuenta from "@/components/AgendarCitaCuenta.vue";
import AgregarCalendario from "@/components/AgregarCalendario.vue";
import CalificarClases from "@/components/CalificarClases.vue";
import ConfirmarCancelacion from "@/components/ConfirmarCancelacion.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import ReprogramarMiReserva from "@/components/ReprogramarMiReserva.vue";
import type { SesionAgenda } from "@/lib/agenda";
import {
  cuandoCorto,
  useMiCuenta,
  type Clase,
  type Reserva,
} from "@/lib/miCuenta";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Reservas del portal: sus reservas y (en negocios de clases) las clases a las que
 * puede entrar, en lista o en calendario (día, semana, mes). Tocar una abre su
 * detalle: agregar a mi calendario, cambiar horario, cancelar, reservar o entrar a
 * la lista de espera. En negocios de citas, "Agendar una cita".
 */
type Vista = "lista" | "dia" | "semana" | "mes";
const VISTAS: Vista[] = ["lista", "dia", "semana", "mes"];

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

const { t } = useI18n();
const sesion = useSesionTenantStore();
const cuenta = useMiCuenta();

const CLAVE_VISTA = "tu.portal.vista";
function vistaGuardada(): Vista {
  try {
    const v = localStorage.getItem(CLAVE_VISTA);
    return VISTAS.includes(v as Vista) ? (v as Vista) : "lista";
  } catch {
    return "lista";
  }
}
const vista = ref<Vista>(vistaGuardada());
watch(vista, (v) => {
  try {
    localStorage.setItem(CLAVE_VISTA, v);
  } catch {
    // Sin almacenamiento: solo no se recuerda.
  }
});

const hoy = () => {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), d.getDate());
};
const fecha = ref(hoy());
const abierto = ref<Evento | null>(null);
const accion = ref<"reprogramar" | "cancelar" | null>(null);
const agendando = ref(false);
const aviso = ref<string | null>(null);

function isoDe(d: Date): string {
  const p = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
function fechaLocal(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: zona,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(iso));
}
function hora(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}
function lunesDe(d: Date): Date {
  const r = new Date(d);
  r.setDate(r.getDate() - ((r.getDay() + 6) % 7));
  return r;
}

const eventos = computed<Evento[]>(() => {
  const mias: Evento[] = cuenta.proximas.value.map((r) => ({
    id: r.id,
    mia: true,
    titulo: r.oferta ?? "—",
    inicia: r.inicia_en ?? "",
    termina: r.termina_en ?? null,
    zona: r.zona_horaria ?? "America/Mexico_City",
    sucursal: r.sucursal,
    instructor: r.instructor ?? null,
    estado: r.estado,
    reserva: r,
  }));
  const libres: Evento[] = cuenta.clases.value
    .filter((c) => !cuenta.reservadas.value.has(c.id))
    .map((c) => ({
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
    }));
  return [...mias, ...libres].sort((a, b) => a.inicia.localeCompare(b.inicia));
});

function delDia(iso: string): Evento[] {
  return eventos.value.filter((e) => fechaLocal(e.inicia, e.zona) === iso);
}
const semana = computed(() => {
  const lunes = lunesDe(fecha.value);
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(lunes);
    d.setDate(lunes.getDate() + i);
    return {
      iso: isoDe(d),
      nombre: new Intl.DateTimeFormat("es-MX", {
        weekday: "short",
        day: "numeric",
      }).format(d),
      esHoy: isoDe(d) === isoDe(hoy()),
      eventos: delDia(isoDe(d)),
    };
  });
});
const mes = computed(
  () => new Date(fecha.value.getFullYear(), fecha.value.getMonth(), 1),
);
// Para la vista mensual (mismo componente que la agenda del negocio).
const comoSesiones = computed<SesionAgenda[]>(() =>
  eventos.value.map(
    (e) =>
      ({
        id: e.id,
        tipo: "clase",
        oferta: e.titulo,
        oferta_id: e.titulo,
        oferta_precio_clase: null,
        instructor: e.instructor,
        instructor_id: null,
        sala: null,
        inicia_en: e.inicia,
        termina_en: e.termina ?? e.inicia,
        zona_horaria: e.zona,
        capacidad: null,
        ocupados: 0,
        en_espera: 0,
        estado: "programada",
      }) as unknown as SesionAgenda,
  ),
);
const destacadas = computed(
  () => new Set(eventos.value.filter((e) => e.mia).map((e) => e.id)),
);

const etiquetaFecha = computed(() => {
  const f = (o: Intl.DateTimeFormatOptions, d: Date) =>
    new Intl.DateTimeFormat("es-MX", o).format(d);
  if (vista.value === "dia") {
    return f({ weekday: "long", day: "numeric", month: "long" }, fecha.value);
  }
  if (vista.value === "semana") {
    const a = lunesDe(fecha.value);
    const b = new Date(a);
    b.setDate(a.getDate() + 6);
    return `${f({ day: "numeric", month: "short" }, a)} – ${f({ day: "numeric", month: "short" }, b)}`;
  }
  return f({ month: "long", year: "numeric" }, fecha.value);
});
function mover(delta: number): void {
  const d = new Date(fecha.value);
  if (vista.value === "dia") {
    d.setDate(d.getDate() + delta);
  } else if (vista.value === "semana") {
    d.setDate(d.getDate() + delta * 7);
  } else {
    d.setDate(1);
    d.setMonth(d.getMonth() + delta);
  }
  fecha.value = d;
}
function irFecha(iso: string): void {
  const [a, m, d] = iso.split("-").map(Number);
  fecha.value = new Date(a, m - 1, d);
}
function irDia(iso: string): void {
  irFecha(iso);
  vista.value = "dia";
}
// Si el día o la semana que se ve está vacío: la siguiente fecha con algo.
const siguienteConAlgo = computed<string | null>(() => {
  const desde =
    vista.value === "semana" ? lunesDe(fecha.value) : new Date(fecha.value);
  desde.setDate(desde.getDate() + (vista.value === "semana" ? 7 : 1));
  const limite = isoDe(desde);
  const fechas = eventos.value
    .map((e) => fechaLocal(e.inicia, e.zona))
    .filter((iso) => iso >= limite)
    .sort();
  return fechas[0] ?? null;
});
const semanaVacia = computed(() =>
  semana.value.every((d) => d.eventos.length === 0),
);

function abrir(e: Evento): void {
  abierto.value = e;
  accion.value = null;
}
function abrirPorId(id: string): void {
  const e = eventos.value.find((x) => x.id === id);
  if (e) {
    abrir(e);
  }
}
function cerrar(): void {
  abierto.value = null;
  accion.value = null;
}

function estadoTexto(e: Evento): string {
  if (!e.mia) {
    const c = e.clase;
    if (c && c.capacidad !== null && c.ocupados >= c.capacidad) {
      return t("portal.reservas.llena");
    }
    return c && c.capacidad !== null
      ? t("portal.reservas.lugares", {
          libres: c.capacidad - c.ocupados,
          total: c.capacidad,
        })
      : "";
  }
  return t(`miCuenta.${e.estado}`);
}
function llena(e: Evento): boolean {
  const c = e.clase;
  return !!c && c.capacidad !== null && c.ocupados >= c.capacidad;
}

async function reservar(e: Evento, esperar: boolean): Promise<void> {
  if (e.clase && (await cuenta.reservar(e.clase, esperar))) {
    aviso.value = esperar
      ? t("miCuenta.enListaEspera")
      : t("miCuenta.reservado");
    cerrar();
  }
}
async function cancelar(e: Evento): Promise<void> {
  if (e.reserva && (await cuenta.cancelar(e.reserva))) {
    cerrar();
  }
}
async function aceptar(e: Evento): Promise<void> {
  if (e.reserva && (await cuenta.aceptar(e.reserva))) {
    cerrar();
  }
}
async function reprogramada(): Promise<void> {
  aviso.value = t("miReprogramar.hecho");
  cerrar();
  await cuenta.cargar(true);
}

onMounted(() => void cuenta.asegurar());
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 py-8">
    <EncabezadoSeccion :titulo="$t('portal.reservas.titulo')">
      <template #acciones>
        <button
          v-if="sesion.esCitas"
          type="button"
          class="tu-btn tu-btn-primario"
          @click="agendando = true"
        >
          {{ $t("portal.reservas.agendarCita") }}
        </button>
      </template>
    </EncabezadoSeccion>

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

    <!-- Vista y fechas -->
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
      <div v-if="vista !== 'lista'" class="flex items-center gap-2">
        <button
          class="tu-icono-btn"
          :aria-label="$t('portal.reservas.anterior')"
          @click="mover(-1)"
        >
          <IconoNav nombre="chevron" :tam="18" class="rotate-180" />
        </button>
        <button
          class="tu-btn tu-btn-fantasma px-3 py-1.5"
          @click="fecha = hoy()"
        >
          {{ $t("portal.reservas.hoy") }}
        </button>
        <button
          class="tu-icono-btn"
          :aria-label="$t('portal.reservas.siguiente')"
          @click="mover(1)"
        >
          <IconoNav nombre="chevron" :tam="18" />
        </button>
        <span
          class="ml-1 text-sm font-medium first-letter:uppercase"
          :style="{ color: 'var(--texto-suave)' }"
          >{{ etiquetaFecha }}</span
        >
      </div>
      <RouterLink
        v-else
        :to="{ name: 'mi-perfil' }"
        class="tu-enlace text-sm"
        >{{ $t("portal.reservas.sincronizar") }}</RouterLink
      >
      <div class="tu-segmentado" role="group">
        <button
          v-for="v in VISTAS"
          :key="v"
          type="button"
          :aria-pressed="vista === v"
          @click="vista = v"
        >
          {{ $t(`portal.reservas.vistas.${v}`) }}
        </button>
      </div>
    </div>

    <p
      v-if="cuenta.cargando.value"
      class="mt-6"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <!-- LISTA: las mías y las disponibles -->
      <div v-if="vista === 'lista'" class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="tu-card p-5">
          <h2 class="font-semibold">{{ $t("portal.reservas.mias") }}</h2>
          <ul
            v-if="eventos.some((e) => e.mia)"
            class="mt-3 divide-y divide-[var(--borde)]"
          >
            <li v-for="e in eventos.filter((x) => x.mia)" :key="e.id">
              <button type="button" class="mr-fila" @click="abrir(e)">
                <span class="min-w-0 flex-1">
                  <span class="block truncate font-medium">{{ e.titulo }}</span>
                  <span
                    class="block text-sm first-letter:uppercase"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ cuandoCorto(e.inicia, e.zona)
                    }}{{ e.sucursal ? ` · ${e.sucursal}` : "" }}</span
                  >
                </span>
                <span
                  class="shrink-0 text-xs"
                  :style="{
                    color:
                      e.estado === 'confirmada'
                        ? 'var(--texto-suave)'
                        : 'var(--aviso)',
                  }"
                  >{{ estadoTexto(e) }}</span
                >
              </button>
            </li>
          </ul>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("miCuenta.sinReservas") }}
          </p>
          <p
            v-if="cuenta.politica.value"
            class="mt-3 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("miCuenta.politica", {
                horas: cuenta.politica.value.horas_limite,
              })
            }}
          </p>
        </div>

        <div v-if="!sesion.esCitas" class="tu-card p-5">
          <h2 class="font-semibold">{{ $t("portal.reservas.disponibles") }}</h2>
          <ul
            v-if="eventos.some((e) => !e.mia)"
            class="mt-3 divide-y divide-[var(--borde)]"
          >
            <li
              v-for="e in eventos.filter((x) => !x.mia)"
              :key="e.id"
              class="flex items-center gap-2"
            >
              <button type="button" class="mr-fila" @click="abrir(e)">
                <span class="min-w-0 flex-1">
                  <span class="block truncate font-medium">{{ e.titulo }}</span>
                  <span
                    class="block text-sm first-letter:uppercase"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ cuandoCorto(e.inicia, e.zona)
                    }}{{ e.sucursal ? ` · ${e.sucursal}` : "" }}</span
                  >
                </span>
                <span
                  class="shrink-0 text-xs"
                  :style="{
                    color: llena(e) ? 'var(--aviso)' : 'var(--texto-suave)',
                  }"
                  >{{ estadoTexto(e) }}</span
                >
              </button>
              <button
                type="button"
                class="tu-btn shrink-0 text-sm"
                :class="llena(e) ? 'tu-btn-fantasma' : 'tu-btn-primario'"
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
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("miCuenta.sinClases") }}
          </p>
        </div>
      </div>

      <!-- DÍA -->
      <div v-else-if="vista === 'dia'" class="mt-4 tu-card p-5">
        <ul
          v-if="delDia(isoDe(fecha)).length > 0"
          class="divide-y divide-[var(--borde)]"
        >
          <li v-for="e in delDia(isoDe(fecha))" :key="e.id">
            <button type="button" class="mr-fila" @click="abrir(e)">
              <span class="mr-hora">{{ hora(e.inicia, e.zona) }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ e.titulo }}</span>
                <span
                  class="block text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{
                    [e.sucursal, e.instructor].filter(Boolean).join(" · ")
                  }}</span
                >
              </span>
              <span
                class="shrink-0 text-xs"
                :style="{
                  color: e.mia ? 'var(--primario)' : 'var(--texto-suave)',
                }"
                >{{
                  e.mia ? $t("portal.reservas.reservada") : estadoTexto(e)
                }}</span
              >
            </button>
          </li>
        </ul>
        <p v-else class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("portal.reservas.sinNada") }}
          <button
            v-if="siguienteConAlgo"
            type="button"
            class="tu-enlace ml-1"
            @click="irFecha(siguienteConAlgo)"
          >
            {{ $t("portal.reservas.irSiguiente") }}
          </button>
        </p>
      </div>

      <!-- SEMANA -->
      <p
        v-if="!cuenta.cargando.value && vista === 'semana' && semanaVacia"
        class="mt-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("portal.reservas.sinNada") }}
        <button
          v-if="siguienteConAlgo"
          type="button"
          class="tu-enlace ml-1"
          @click="irFecha(siguienteConAlgo)"
        >
          {{ $t("portal.reservas.irSiguiente") }}
        </button>
      </p>
      <div
        v-if="vista === 'semana'"
        class="mt-4 grid gap-3 md:grid-cols-7"
      >
        <div v-for="d in semana" :key="d.iso" class="tu-card min-h-[8rem] p-3">
          <button
            type="button"
            class="text-sm font-semibold capitalize"
            :style="{ color: d.esHoy ? 'var(--primario)' : 'var(--texto)' }"
            @click="irDia(d.iso)"
          >
            {{ d.nombre }}
          </button>
          <ul class="mt-2 space-y-1">
            <li v-for="e in d.eventos" :key="e.id">
              <button
                type="button"
                class="mr-chip"
                :class="{ 'mr-chip-mia': e.mia }"
                @click="abrir(e)"
              >
                <span class="mr-hora">{{ hora(e.inicia, e.zona) }}</span>
                <span class="truncate">{{ e.titulo }}</span>
              </button>
            </li>
          </ul>
        </div>
      </div>

      <!-- MES -->
      <AgendaMes
        v-if="vista === 'mes'"
        class="mt-4"
        :mes="mes"
        :sesiones="comoSesiones"
        :catalogo="[]"
        :destacadas="destacadas"
        :seleccionada="abierto?.id ?? null"
        @abrir="(s) => abrirPorId(s.id)"
        @dia="irDia"
      />

      <!-- Calificar lo que ya tomó -->
      <CalificarClases v-if="cuenta.personaId.value !== null" class="mt-6" />
    </template>

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
        <p
          class="mt-3 text-sm font-medium"
          :style="{
            color:
              abierto.mia && abierto.estado !== 'confirmada'
                ? 'var(--aviso)'
                : 'var(--texto)',
          }"
        >
          {{ estadoTexto(abierto) }}
        </p>

        <!-- Suya -->
        <div v-if="abierto.mia && abierto.reserva" class="mt-5 space-y-3">
          <AgregarCalendario
            :evento="{
              uid: abierto.id,
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

        <!-- Disponible -->
        <div v-else class="mt-5">
          <button
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
        </div>
      </template>
    </PanelLateral>

    <ModalDialogo
      :abierto="agendando"
      :titulo="$t('portal.reservas.agendarCita')"
      @cerrar="agendando = false"
    >
      <AgendarCitaCuenta
        @agendada="
          agendando = false;
          cuenta.cargar(true);
        "
      />
    </ModalDialogo>
  </section>
</template>

<style scoped>
.mr-fila {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.75rem;
  padding: 0.7rem 0;
  text-align: left;
}
.mr-fila:hover .font-medium {
  color: var(--primario);
}
.mr-hora {
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.mr-chip {
  display: flex;
  width: 100%;
  gap: 0.35rem;
  min-width: 0;
  padding: 0.2rem 0.4rem;
  border-radius: 0.35rem;
  font-size: 0.75rem;
  text-align: left;
  background: var(--superficie-2);
}
.mr-chip-mia {
  background: color-mix(in srgb, var(--primario) 14%, var(--superficie));
  box-shadow: inset 3px 0 0 var(--primario);
  font-weight: 600;
}
</style>
