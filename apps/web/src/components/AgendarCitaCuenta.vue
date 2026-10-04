<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";

import CalendarioDias from "@/components/CalendarioDias.vue";
import ElegirProfesional from "@/components/ElegirProfesional.vue";
import ServicioIncluye from "@/components/ServicioIncluye.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Agendar una cita desde la cuenta del cliente: servicio, profesional, sede, día y
 * hora libre. Si el servicio se paga para reservar, la cita queda apartada hasta
 * pagarla (aparece en "Mis reservas"). Si el negocio manda avisos por WhatsApp y el
 * cliente aún no los aceptó (y tiene celular), se le ofrecen al agendar (ADR 0069).
 *
 * Al agendar, la confirmación se queda a la vista (servicio, cuándo, sede, quién lo
 * atiende y si queda pendiente de pago) hasta que la persona pulsa «Listo» o agenda
 * otra; `agendada` avisa al contenedor para que recargue, `cerrar` para que cierre.
 */
const props = defineProps<{
  /** «Agendar de nuevo» desde el historial: el mismo servicio, sede y profesional. */
  inicial?: {
    servicio?: string | null;
    sucursal?: string | null;
    profesional?: string | null;
  } | null;
}>();
const emit = defineEmits<{ agendada: []; cerrar: [] }>();

interface Servicio {
  id: string;
  nombre: string;
  // Paquete: qué incluye y cuánto costaría por separado.
  incluye?: string[];
  precio_por_separado_minor?: number | null;
  precio_minor: number | null;
  moneda: string;
  duracion_minutos: number | null;
  // Se descuenta de su bono o membresía (ADR 0091).
  con_plan?: boolean;
}
interface Opcion {
  id: string;
  nombre: string;
  zona_horaria?: string | null;
  foto_url?: string | null;
}
interface Slot {
  inicia: string;
  termina: string;
}
// Lo que se agendó, para la confirmación.
interface Confirmacion {
  servicio: string;
  inicia: string;
  sucursal: string | null;
  profesional: string | null;
  pendientePago: boolean;
}
// Sin preferencia: el negocio asigna a quien esté libre a esa hora.
const CUALQUIERA = "cualquiera";

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const servicios = ref<Servicio[]>([]);
const profesionales = ref<Opcion[]>([]);
const sucursales = ref<Opcion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const confirmacion = ref<Confirmacion | null>(null);

const servicioId = ref("");
const profesionalId = ref("");
const sucursalId = ref("");
const fecha = ref("");
const slots = ref<Slot[]>([]);
const buscando = ref(false);
const slotSel = ref("");
const agendando = ref(false);
const ofrecerWhatsApp = ref(false);
const aceptaWhatsApp = ref(false);

// «Ver horarios de» (el mismo selector que la página pública): "" = todo el equipo.
const verHorariosDe = computed<string>({
  get: () => (profesionalId.value === CUALQUIERA ? "" : profesionalId.value),
  set: (id) => {
    profesionalId.value = id === "" ? CUALQUIERA : id;
  },
});

const servicio = computed(
  () => servicios.value.find((s) => s.id === servicioId.value) ?? null,
);
const sucursal = computed(
  () => sucursales.value.find((s) => s.id === sucursalId.value) ?? null,
);
const listo = computed(
  () =>
    servicio.value !== null &&
    profesionalId.value !== "" &&
    sucursalId.value !== "" &&
    fecha.value !== "",
);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

function hora(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    hour: "2-digit",
    minute: "2-digit",
    timeZone: sucursal.value?.zona_horaria ?? undefined,
  }).format(new Date(iso));
}

/** «lun 5 de oct, 10:00» en la hora de la sede. */
function cuando(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: sucursal.value?.zona_horaria ?? undefined,
  }).format(new Date(iso));
}

/** "2026-10-05T10:00" en la hora local de la sede, como lo espera el API. */
function horaLocal(iso: string): string {
  const partes = new Intl.DateTimeFormat("sv-SE", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
    timeZone: sucursal.value?.zona_horaria ?? undefined,
  }).format(new Date(iso));
  return partes.replace(" ", "T");
}

// Cada búsqueda lleva su número: una respuesta tardía de una selección anterior no
// reemplaza los horarios de la selección actual.
let busqueda = 0;
async function buscarHorarios(): Promise<void> {
  const actual = ++busqueda;
  slots.value = [];
  slotSel.value = "";
  if (!listo.value || servicio.value === null) {
    buscando.value = false;
    return;
  }
  buscando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: { slots: Slot[] } }>(
      `${base.value}/mi/citas/disponibilidad`,
      {
        params: {
          ...(profesionalId.value !== CUALQUIERA
            ? { instructor_id: profesionalId.value }
            : {}),
          sucursal_id: sucursalId.value,
          fecha: fecha.value,
          // El servicio aporta su duración y su preparación/limpieza.
          oferta_id: servicio.value.id,
          duracion_minutos: servicio.value.duracion_minutos ?? 60,
        },
      },
    );
    if (actual === busqueda) {
      slots.value = data.data.slots;
    }
  } catch (e) {
    if (actual === busqueda) {
      error.value = mensajeDeError(e);
    }
  } finally {
    if (actual === busqueda) {
      buscando.value = false;
    }
  }
}
watch([servicioId, profesionalId, sucursalId, fecha], buscarHorarios);

async function agendar(): Promise<void> {
  if (servicio.value === null || slotSel.value === "") {
    return;
  }
  agendando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{
      data: { estado: string; profesional?: { nombre: string } | null };
    }>(`${base.value}/mi/citas`, {
      oferta_id: servicio.value.id,
      sucursal_id: sucursalId.value,
      ...(profesionalId.value !== CUALQUIERA
        ? { instructor_id: profesionalId.value }
        : {}),
      inicia_en_local: horaLocal(slotSel.value),
      duracion_minutos: servicio.value.duracion_minutos ?? 60,
      ...(ofrecerWhatsApp.value && aceptaWhatsApp.value
        ? { acepta_whatsapp: true }
        : {}),
    });
    // Ya los aceptó: no se le vuelve a preguntar.
    if (ofrecerWhatsApp.value && aceptaWhatsApp.value) {
      ofrecerWhatsApp.value = false;
    }
    // Con «cualquiera», el negocio dice quién la atenderá.
    confirmacion.value = {
      servicio: servicio.value.nombre,
      inicia: slotSel.value,
      sucursal: sucursal.value?.nombre ?? null,
      profesional:
        data.data.profesional?.nombre ??
        profesionales.value.find((p) => p.id === profesionalId.value)?.nombre ??
        null,
      pendientePago: data.data.estado === "pendiente_pago",
    };
    emit("agendada");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    agendando.value = false;
  }
}

/** Vuelve al formulario para agendar otra cita (la anterior ya quedó). */
function otraCita(): void {
  confirmacion.value = null;
  void buscarHorarios();
}

onMounted(async () => {
  try {
    const { data } = await api.get<{
      data: {
        servicios: Servicio[];
        sucursales: Opcion[];
        instructores: Opcion[];
      };
    }>(`${base.value}/mi/citas/opciones`);
    servicios.value = data.data.servicios;
    sucursales.value = data.data.sucursales;
    profesionales.value = data.data.instructores;
    // Con varios, se parte de todo el equipo; con uno, es esa persona.
    profesionalId.value =
      profesionales.value.length > 1
        ? CUALQUIERA
        : (profesionales.value[0]?.id ?? "");
    if (sucursales.value.length === 1) {
      sucursalId.value = sucursales.value[0].id;
    }
    // Lo mismo que la vez anterior, si sigue disponible.
    const antes = props.inicial;
    if (
      antes?.servicio &&
      servicios.value.some((s) => s.id === antes.servicio)
    ) {
      servicioId.value = antes.servicio;
    }
    if (
      antes?.sucursal &&
      sucursales.value.some((s) => s.id === antes.sucursal)
    ) {
      sucursalId.value = antes.sucursal;
    }
    if (
      antes?.profesional &&
      profesionales.value.some((p) => p.id === antes.profesional)
    ) {
      profesionalId.value = antes.profesional;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
  try {
    const { data } = await api.get<{
      data: {
        whatsapp_disponible?: boolean;
        acepta_whatsapp?: boolean;
        whatsapp_con_celular?: boolean;
      };
    }>(`${base.value}/mi/privacidad`);
    ofrecerWhatsApp.value =
      data.data.whatsapp_disponible === true &&
      data.data.acepta_whatsapp !== true &&
      data.data.whatsapp_con_celular === true;
  } catch {
    ofrecerWhatsApp.value = false;
  }
});
</script>

<template>
  <div>
    <p v-if="cargando" class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="servicios.length === 0 || profesionales.length === 0"
      class="text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("citaCuenta.sinServicios") }}
    </p>
    <!-- Confirmación: se queda a la vista hasta «Listo» o «Agendar otra». -->
    <div
      v-else-if="confirmacion"
      class="space-y-4"
      role="status"
      data-prueba="cita-confirmada"
    >
      <p class="font-medium">
        {{
          confirmacion.pendientePago
            ? $t("citaCuenta.apartada")
            : $t("citaCuenta.agendada")
        }}
      </p>
      <dl class="cc-resumen">
        <div>
          <dt>{{ $t("citaCuenta.servicio") }}</dt>
          <dd>{{ confirmacion.servicio }}</dd>
        </div>
        <div>
          <dt>{{ $t("citaCuenta.cuando") }}</dt>
          <dd class="first-letter:uppercase">
            {{ cuando(confirmacion.inicia) }}
          </dd>
        </div>
        <div v-if="confirmacion.sucursal">
          <dt>{{ $t("citaCuenta.sede") }}</dt>
          <dd>{{ confirmacion.sucursal }}</dd>
        </div>
        <div v-if="confirmacion.profesional">
          <dt>{{ $t("citaCuenta.profesional") }}</dt>
          <dd>{{ confirmacion.profesional }}</dd>
        </div>
        <div>
          <dt>{{ $t("citaCuenta.estado") }}</dt>
          <dd>
            <span
              class="tu-pildora"
              :style="{
                '--tono': confirmacion.pendientePago
                  ? 'var(--aviso)'
                  : 'var(--exito)',
              }"
              >{{
                confirmacion.pendientePago
                  ? $t("citaCuenta.pendientePago")
                  : $t("citaCuenta.confirmada")
              }}</span
            >
          </dd>
        </div>
      </dl>
      <p
        v-if="confirmacion.pendientePago"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("citaCuenta.pendientePagoAyuda") }}
      </p>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" class="tu-btn tu-btn-fantasma" @click="otraCita">
          {{ $t("citaCuenta.otra") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          data-prueba="cita-listo"
          @click="emit('cerrar')"
        >
          {{ $t("citaCuenta.listo") }}
        </button>
      </div>
    </div>
    <form v-else class="space-y-4" @submit.prevent="agendar">
      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="tu-label" for="cc-servicio">{{
            $t("citaCuenta.servicio")
          }}</label>
          <select
            id="cc-servicio"
            v-model="servicioId"
            class="tu-input"
            required
          >
            <option value="" disabled>{{ $t("citaCuenta.elegir") }}</option>
            <option v-for="s in servicios" :key="s.id" :value="s.id">
              {{ s.nombre }}
              <template v-if="s.con_plan">
                · {{ $t("citaCuenta.conTuBono") }}</template
              >
              <template v-else-if="s.precio_minor">
                · {{ dinero(s.precio_minor, s.moneda) }}</template
              >
            </option>
          </select>
          <p
            v-if="servicio?.con_plan"
            class="mt-1 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            data-prueba="con-bono"
          >
            {{ $t("citaCuenta.conTuBonoAyuda") }}
          </p>
          <ServicioIncluye
            v-else-if="servicio"
            class="mt-1"
            :incluye="servicio.incluye"
            :precio-minor="servicio.precio_minor"
            :por-separado-minor="servicio.precio_por_separado_minor"
            :moneda="servicio.moneda"
          />
        </div>
        <div v-if="sucursales.length > 1">
          <label class="tu-label" for="cc-sede">{{
            $t("citaCuenta.sede")
          }}</label>
          <select id="cc-sede" v-model="sucursalId" class="tu-input" required>
            <option value="" disabled>{{ $t("citaCuenta.elegir") }}</option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>
      </div>

      <!-- Ver horarios de: todo el equipo o alguien, por su foto. -->
      <ElegirProfesional
        v-if="profesionales.length > 1"
        v-model="verHorariosDe"
        :profesionales="profesionales"
      />

      <!-- Días desde hoy; los que no tienen atención no se eligen (ADR 0065). -->
      <div v-if="sucursalId !== ''">
        <p class="tu-label">{{ $t("citaCuenta.dia") }}</p>
        <CalendarioDias
          v-model="fecha"
          :ruta="`${base}/mi/citas/dias`"
          :sucursal-id="sucursalId"
          :instructor-id="
            profesionalId !== CUALQUIERA && profesionalId !== ''
              ? profesionalId
              : null
          "
          :zona="sucursal?.zona_horaria ?? 'America/Mexico_City'"
        />
      </div>

      <div v-if="listo">
        <p class="tu-label">{{ $t("citaCuenta.hora") }}</p>
        <p
          v-if="buscando"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>
        <p
          v-else-if="slots.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("citaCuenta.sinHorarios") }}
        </p>
        <div v-else class="cc-horas" role="group">
          <button
            v-for="s in slots"
            :key="s.inicia"
            type="button"
            class="cc-hora"
            :class="{ 'cc-hora--activa': slotSel === s.inicia }"
            :aria-pressed="slotSel === s.inicia"
            @click="slotSel = s.inicia"
          >
            {{ hora(s.inicia) }}
          </button>
        </div>
      </div>

      <p v-if="error" class="text-sm" style="color: var(--error)">
        {{ error }}
      </p>
      <label v-if="ofrecerWhatsApp" class="flex items-center gap-2 text-sm">
        <input
          v-model="aceptaWhatsApp"
          type="checkbox"
          data-prueba="acepta-whatsapp"
        />
        {{ $t("perfilPublico.agendar.aceptaWhatsApp") }}
      </label>
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="agendando || slotSel === ''"
      >
        {{ $t("citaCuenta.agendar") }}
      </button>
    </form>
  </div>
</template>

<style scoped>
/* Horas en cuadrícula pareja, como en la página pública. */
.cc-horas {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(5.5rem, 1fr));
  gap: 0.6rem;
}
.cc-hora {
  min-height: 44px;
  padding: 0.6rem 0.5rem;
  border: 1px solid var(--borde);
  border-radius: 10px;
  font-size: 0.9rem;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  background: var(--superficie);
  cursor: pointer;
  transition:
    border-color 150ms ease,
    background-color 150ms ease;
}
.cc-hora:hover {
  border-color: var(--primario);
  background: var(--primario-suave);
}
.cc-hora:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
.cc-hora--activa,
.cc-hora--activa:hover {
  border-color: var(--primario);
  background: var(--primario);
  color: var(--primario-contraste);
}
/* Resumen de la cita agendada. */
.cc-resumen {
  display: grid;
  gap: 0.6rem;
  padding: 0.9rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
}
.cc-resumen > div {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  font-size: 0.9rem;
}
.cc-resumen dt {
  color: var(--texto-suave);
}
.cc-resumen dd {
  text-align: right;
  font-weight: 500;
}
</style>
