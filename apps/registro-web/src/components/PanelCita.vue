<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import ConfirmarCancelacion from "@/components/ConfirmarCancelacion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import {
  aHora,
  COLOR_ESTADO_CITA,
  duracionMin,
  estadoCita,
  minutosLocal,
  tonoServicio,
  type SesionAgenda,
} from "@/lib/agenda";
import { api, mensajeDeError } from "@/lib/api";
import { useToastStore } from "@/stores/toast";

/**
 * Detalle de una CITA para recepción: quién, qué servicio, con quién, a qué hora y
 * cómo va (pagada, por cobrar, llegó…), con las acciones del día: marcar llegada o
 * inasistencia, cobrar en caja y cancelar (libera el horario del profesional).
 */
const props = defineProps<{
  abierto: boolean;
  base: string;
  sesion: SesionAgenda | null;
  catalogo: string[];
  puedeMarcar: boolean;
  puedeCobrar: boolean;
  puedeCancelar: boolean;
}>();

const emit = defineEmits<{ cerrar: []; cambiada: [] }>();

const { t } = useI18n();
const toast = useToastStore();

const METODOS = ["efectivo", "transferencia", "manual"] as const;
const metodo = ref<(typeof METODOS)[number]>("efectivo");
const accionando = ref(false);

watch(
  () => props.abierto,
  () => {
    metodo.value = "efectivo";
  },
);

const cita = computed(() => props.sesion?.cita ?? null);
const estado = computed(() =>
  props.sesion !== null ? estadoCita(props.sesion, new Date()) : "cancelada",
);
const tono = computed(() =>
  props.sesion !== null
    ? tonoServicio(props.sesion.oferta_id, props.catalogo, props.sesion.oferta)
    : null,
);
const horario = computed(() => {
  const s = props.sesion;
  if (s === null) {
    return "";
  }
  const ini = minutosLocal(s.inicia_en, s.zona_horaria);
  const fecha = new Intl.DateTimeFormat("es-MX", {
    timeZone: s.zona_horaria,
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date(s.inicia_en));
  return `${fecha} · ${aHora(ini)}–${aHora(ini + duracionMin(s))}`;
});
const precio = computed(() =>
  props.sesion?.oferta_precio_clase
    ? new Intl.NumberFormat("es-MX", {
        style: "currency",
        currency: "MXN",
        maximumFractionDigits: 0,
      }).format(props.sesion.oferta_precio_clase / 100)
    : null,
);
// Falta cobrarla: pendiente de pago en línea o agendada por el negocio sin cobrar.
const porCobrar = computed(
  () =>
    cita.value !== null &&
    cita.value.orden_id != null &&
    (cita.value.estado === "pendiente_pago" || cita.value.por_cobrar === true),
);
const pago = computed(() => {
  if (cita.value === null || cita.value.orden_id == null) {
    return t("agendaVisual.cita.pagoMembresia");
  }
  if (cita.value.estado === "pendiente_pago") {
    return t("agendaVisual.cita.pagoEnLinea");
  }
  return cita.value.por_cobrar === true
    ? t("agendaVisual.cita.pagoEnCaja")
    : t("agendaVisual.cita.pagada");
});
const activa = computed(
  () =>
    estado.value !== "cancelada" &&
    estado.value !== "completada" &&
    estado.value !== "no_asistio",
);

async function accion(
  fn: () => Promise<unknown>,
  mensaje: string,
): Promise<void> {
  accionando.value = true;
  try {
    await fn();
    toast.exito(mensaje);
    emit("cambiada");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}
function marcar(asistencia: "presente" | "ausente"): void {
  const c = cita.value;
  if (c === null) {
    return;
  }
  void accion(
    () =>
      api.post(`${props.base}/reservas/${c.reserva_id}/asistencia`, {
        estado: asistencia,
      }),
    asistencia === "presente"
      ? t("agendaVisual.cita.okLlego")
      : t("agendaVisual.cita.okNoAsistio"),
  );
}
function cobrar(): void {
  const c = cita.value;
  if (c === null || c.orden_id == null) {
    return;
  }
  const orden = c.orden_id;
  void accion(
    () =>
      api.post(`${props.base}/ordenes/${orden}/liquidar`, {
        metodo: metodo.value,
      }),
    t("agendaVisual.cita.okCobrada"),
  );
}
// Confirmación en línea con el efecto a la vista (quién cancela y qué pasa).
const cancelando = ref(false);
function cancelar(por: "cliente" | "negocio" | null): void {
  const c = cita.value;
  if (c === null) {
    return;
  }
  void accion(
    () =>
      api.post(
        `${props.base}/reservas/${c.reserva_id}/cancelar`,
        por ? { por } : {},
      ),
    t("agendaVisual.cita.okCancelada"),
  ).then(() => {
    cancelando.value = false;
  });
}
</script>

<template>
  <PanelLateral
    :abierto="abierto"
    :titulo="$t('agendaVisual.cita.titulo')"
    @cerrar="emit('cerrar')"
  >
    <div v-if="sesion !== null" class="space-y-5 p-5">
      <div class="flex items-center gap-3">
        <AvatarIniciales :nombre="cita?.cliente" tam="lg" />
        <div class="min-w-0">
          <p class="text-xl font-semibold truncate">
            {{ cita?.cliente ?? $t("agendaVisual.profesionales.sinCliente") }}
          </p>
          <p
            class="text-sm first-letter:uppercase"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ horario }}
          </p>
          <p class="mt-1 flex flex-wrap items-center gap-x-3 text-sm">
            <span class="inline-flex items-center gap-1.5">
              <span
                class="h-2 w-2 rounded-full"
                :style="{ background: COLOR_ESTADO_CITA[estado] }"
                aria-hidden="true"
              ></span
              >{{ $t(`agendaVisual.estadosCita.${estado}`) }}</span
            >
            <span v-if="porCobrar" class="tu-badge tu-badge-aviso">{{
              $t("agendaVisual.cita.porCobrar")
            }}</span>
          </p>
        </div>
      </div>

      <dl class="pc-datos">
        <dt>{{ $t("agendaVisual.nuevaCita.servicio") }}</dt>
        <dd>
          <span
            class="inline-block w-2.5 h-2.5 rounded-sm mr-1.5"
            :style="{ background: tono?.tinta }"
            aria-hidden="true"
          ></span
          >{{ sesion.oferta ?? "—" }}
        </dd>
        <dt>{{ $t("agendaVisual.nuevaCita.profesional") }}</dt>
        <dd>{{ sesion.instructor ?? "—" }}</dd>
        <template v-if="precio">
          <dt>{{ $t("agendaVisual.cita.precio") }}</dt>
          <dd class="font-semibold">{{ precio }}</dd>
        </template>
        <dt>{{ $t("agendaVisual.cita.pago") }}</dt>
        <dd>{{ pago }}</dd>
      </dl>

      <!-- Acciones del día -->
      <div v-if="activa" class="space-y-2">
        <div v-if="porCobrar && puedeCobrar" class="flex items-stretch gap-2">
          <select
            v-model="metodo"
            class="tu-input w-auto"
            :aria-label="$t('agendaVisual.cita.metodo')"
          >
            <option v-for="m in METODOS" :key="m" :value="m">
              {{ $t(`agendaVisual.cita.metodos.${m}`) }}
            </option>
          </select>
          <button
            type="button"
            class="tu-btn tu-btn-primario flex-1"
            :disabled="accionando"
            @click="cobrar"
          >
            {{ $t("agendaVisual.cita.cobrar", { monto: precio ?? "" }) }}
          </button>
        </div>
        <div v-if="puedeMarcar" class="grid grid-cols-2 gap-2">
          <button
            v-if="cita?.asistencia !== 'presente'"
            type="button"
            class="tu-btn"
            :class="
              porCobrar && puedeCobrar ? 'tu-btn-fantasma' : 'tu-btn-primario'
            "
            :disabled="accionando"
            @click="marcar('presente')"
          >
            {{ $t("agendaVisual.cita.marcarLlegada") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="accionando"
            @click="marcar('ausente')"
          >
            {{ $t("agendaVisual.cita.noAsistio") }}
          </button>
        </div>
        <button
          v-if="puedeCancelar"
          type="button"
          class="tu-btn tu-btn-fantasma w-full"
          style="color: var(--error)"
          :disabled="accionando"
          :aria-expanded="cancelando"
          @click="cancelando = !cancelando"
        >
          {{ $t("agendaVisual.cita.cancelar") }}
        </button>
        <ConfirmarCancelacion
          v-if="cancelando && cita !== null"
          :url="`${base}/reservas/${cita.reserva_id}/cancelacion`"
          con-quien
          :ocupado="accionando"
          @confirmar="cancelar"
          @cerrar="cancelando = false"
        />
      </div>
    </div>
  </PanelLateral>
</template>

<style scoped>
.pc-datos {
  display: grid;
  grid-template-columns: 6.5rem minmax(0, 1fr);
  gap: 0.6rem 0.75rem;
  margin: 0;
  padding: 0.9rem;
  border-radius: 0.75rem;
  background: var(--superficie-2);
  font-size: 0.875rem;
}
.pc-datos dt {
  color: var(--texto-suave);
}
.pc-datos dd {
  margin: 0;
  font-weight: 500;
}
</style>
