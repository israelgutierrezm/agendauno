<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AgendarCitaCuenta from "@/components/AgendarCitaCuenta.vue";
import AgregarCalendario from "@/components/AgregarCalendario.vue";
import CalendarioVistas, {
  type EventoPeriodo,
} from "@/components/CalendarioVistas.vue";
import CalificarClases from "@/components/CalificarClases.vue";
import ConfirmarCancelacion from "@/components/ConfirmarCancelacion.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import ReprogramarMiReserva from "@/components/ReprogramarMiReserva.vue";
import { api, mensajeDeError } from "@/lib/api";
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

/**
 * Las clases disponibles se piden por el periodo que se ve (GET /mi/agenda con
 * desde/hasta y, si eligió una, la sucursal): al moverse de semana o de mes se
 * vuelven a pedir, así ningún periodo se ve vacío porque las primeras N clases del
 * negocio eran de otras fechas o sedes. Cada pedido lleva su número: una respuesta
 * vieja no pisa a la del periodo actual.
 */
interface Sede {
  id: string;
  nombre: string;
}
const periodo = ref<{ desde: string; hasta: string } | null>(null);
const sucursalId = ref("");
const sedes = ref<Sede[]>([]);
const clasesPeriodo = ref<Clase[]>([]);
const cargandoClases = ref(false);
const errorClases = ref<string | null>(null);
const truncado = ref(false);
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
  void cargarClases();
}
watch(sucursalId, () => void cargarClases());

const abierto = ref<Evento | null>(null);
const accion = ref<"reprogramar" | "cancelar" | null>(null);
const agendando = ref(false);
const aviso = ref<string | null>(null);

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
  const libres: Evento[] = clasesPeriodo.value
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

// Lo mismo, como lo pinta el calendario: las suyas resaltadas.
const enCalendario = computed<EventoPeriodo[]>(() =>
  eventos.value.map((e) => ({
    id: e.id,
    titulo: e.titulo,
    inicia: e.inicia,
    termina: e.termina,
    zona: e.zona,
    detalle: [e.sucursal, e.instructor].filter(Boolean).join(" · "),
    estado: e.mia ? t("portal.reservas.reservada") : estadoTexto(e),
    tono: e.mia ? "primario" : llena(e) ? "aviso" : "suave",
    destacado: e.mia,
  })),
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
      @abrir="abrirPorId"
      @rango="alCambiarPeriodo"
      @reintentar="cargarClases"
    >
      <template #barra>
        <div class="flex flex-wrap items-center gap-3">
          <label v-if="sedes.length > 1" class="text-sm">
            <span class="sr-only">{{ $t("portal.reservas.sucursal") }}</span>
            <select id="mr-sede" v-model="sucursalId" class="tu-input w-auto">
              <option value="">
                {{ $t("portal.reservas.todasSucursales") }}
              </option>
              <option v-for="s in sedes" :key="s.id" :value="s.id">
                {{ s.nombre }}
              </option>
            </select>
          </label>
          <RouterLink :to="{ name: 'mi-perfil' }" class="tu-enlace text-sm">{{
            $t("portal.reservas.sincronizar")
          }}</RouterLink>
        </div>
      </template>

      <!-- LISTA: las mías y las disponibles -->
      <template #lista>
        <div class="grid gap-4 lg:grid-cols-2">
          <div class="tu-card p-5">
            <h2 class="font-semibold">{{ $t("portal.reservas.mias") }}</h2>
            <ul
              v-if="eventos.some((e) => e.mia)"
              class="mt-3 divide-y divide-[var(--borde)]"
            >
              <li v-for="e in eventos.filter((x) => x.mia)" :key="e.id">
                <button type="button" class="mr-fila" @click="abrir(e)">
                  <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{
                      e.titulo
                    }}</span>
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
            <h2 class="font-semibold">
              {{ $t("portal.reservas.disponibles") }}
            </h2>
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
                    <span class="block truncate font-medium">{{
                      e.titulo
                    }}</span>
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
      </template>
    </CalendarioVistas>

    <!-- Calificar lo que ya tomó -->
    <CalificarClases
      v-if="!cuenta.cargando.value && cuenta.personaId.value !== null"
      class="mt-6"
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
</style>
