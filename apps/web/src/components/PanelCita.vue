<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import CambiarHorario from "@/components/CambiarHorario.vue";
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
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Detalle de una CITA para recepción: quién, qué servicio, con quién, a qué hora y
 * cómo va (pagada, por cobrar, llegó…), con las acciones del día: marcar llegada o
 * inasistencia, cobrar en caja y cancelar (libera el horario del profesional).
 *
 * Lo que mueve dinero o créditos se confirma antes (cobrar, marcar asistencia) y se
 * puede corregir después: la asistencia (llegó ↔ no asistió, el crédito se ajusta) y
 * la forma de pago de un cobro en caja, si el negocio lo permite (ADR 0086).
 */
const props = defineProps<{
  abierto: boolean;
  base: string;
  sesion: SesionAgenda | null;
  catalogo: string[];
  puedeMarcar: boolean;
  puedeCobrar: boolean;
  puedeCancelar: boolean;
  // Con quién se puede mover la cita (2.1).
  profesionales?: { id: string; nombre: string }[];
}>();

const emit = defineEmits<{ cerrar: []; cambiada: [] }>();

const { t, te } = useI18n();
const toast = useToastStore();
const sesionStore = useSesionTenantStore();

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
function nombreMetodo(m: string | null | undefined): string {
  const llave = `agendaVisual.cita.metodos.${m ?? "manual"}`;
  return te(llave) ? t(llave) : (m ?? "");
}
const pago = computed(() => {
  if (cita.value === null || cita.value.orden_id == null) {
    return t("agendaVisual.cita.pagoMembresia");
  }
  if (cita.value.estado === "pendiente_pago") {
    return t("agendaVisual.cita.pagoEnLinea");
  }
  if (cita.value.por_cobrar === true) {
    return t("agendaVisual.cita.pagoEnCaja");
  }
  return cita.value.pago?.metodo
    ? t("agendaVisual.cita.pagadaCon", {
        metodo: nombreMetodo(cita.value.pago.metodo),
      })
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
async function marcar(asistencia: "presente" | "ausente"): Promise<void> {
  const c = cita.value;
  if (c === null) {
    return;
  }
  const cliente = c.cliente ?? t("agendaVisual.profesionales.sinCliente");
  const nombre = (e: string): string =>
    e === "presente"
      ? t("agendaVisual.cita.marcarLlegada")
      : t("agendaVisual.cita.noAsistio");
  // Marcar o corregir mueve créditos: se confirma con su efecto a la vista.
  const mensaje =
    c.asistencia && c.asistencia !== asistencia
      ? t("agendaVisual.cita.confirmarCorreccion", {
          cliente,
          antes: nombre(c.asistencia),
          ahora: nombre(asistencia),
        })
      : asistencia === "presente"
        ? t("agendaVisual.cita.confirmarLlegada", { cliente })
        : t("agendaVisual.cita.confirmarNoAsistio", { cliente });
  if (
    !(await confirmar(mensaje, {
      aceptar: nombre(asistencia),
      peligro: asistencia === "ausente",
    }))
  ) {
    return;
  }
  void accion(
    () =>
      api.post(`${props.base}/reservas/${c.reserva_id}/asistencia`, {
        estado: asistencia,
      }),
    c.asistencia
      ? t("agendaVisual.cita.okCorregida")
      : asistencia === "presente"
        ? t("agendaVisual.cita.okLlego")
        : t("agendaVisual.cita.okNoAsistio"),
  );
}
// Ya marcada: corregir al otro estado (el crédito se ajusta en el saldo).
const corregibleAsistencia = computed(
  () =>
    props.puedeMarcar &&
    cita.value !== null &&
    cita.value.estado === "confirmada" &&
    (cita.value.asistencia === "presente" ||
      cita.value.asistencia === "ausente"),
);
async function cobrar(): Promise<void> {
  const c = cita.value;
  if (c === null || c.orden_id == null) {
    return;
  }
  // Un cobro en caja no se deshace: se confirma monto, forma y a quién.
  if (
    !(await confirmar(
      t("agendaVisual.cita.confirmarCobro", {
        monto: precio.value ?? "",
        metodo: nombreMetodo(metodo.value),
        cliente: c.cliente ?? t("agendaVisual.profesionales.sinCliente"),
      }),
      { aceptar: t("agendaVisual.cita.cobrar", { monto: precio.value ?? "" }) },
    ))
  ) {
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
// Corregir la forma de pago de un cobro en caja (ADR 0086): mismo monto.
const corrigiendoPago = ref(false);
const metodoCorregido = ref<(typeof METODOS)[number]>("efectivo");
const puedeCorregirPago = computed(
  () =>
    cita.value?.pago?.en_caja === true &&
    cita.value.pago.corregible &&
    sesionStore.puede("ordenes.gestionar"),
);
function abrirCorreccionPago(): void {
  const actual = cita.value?.pago?.metodo;
  metodoCorregido.value = METODOS.find((m) => m !== actual) ?? METODOS[0];
  corrigiendoPago.value = true;
}
async function corregirPago(): Promise<void> {
  const p = cita.value?.pago;
  if (!p || metodoCorregido.value === p.metodo) {
    corrigiendoPago.value = false;
    return;
  }
  if (
    !(await confirmar(
      t("agendaVisual.cita.confirmarMetodo", {
        antes: nombreMetodo(p.metodo),
        ahora: nombreMetodo(metodoCorregido.value),
      }),
      { aceptar: t("agendaVisual.cita.guardarMetodo") },
    ))
  ) {
    return;
  }
  await accion(
    () =>
      api.put(`${props.base}/pagos/${p.id}/metodo`, {
        metodo: metodoCorregido.value,
      }),
    t("agendaVisual.cita.okMetodo"),
  );
  corrigiendoPago.value = false;
}

// Reprogramar (2.1): misma reserva y pagos, otro horario.
const reprogramando = ref(false);
function reprogramada(datos: { antes: string; ahora: string }): void {
  reprogramando.value = false;
  const zona = props.sesion?.zona_horaria ?? "America/Mexico_City";
  const f = (iso: string): string =>
    new Intl.DateTimeFormat("es-MX", {
      timeZone: zona,
      weekday: "short",
      day: "numeric",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso));
  toast.exito(
    t("reprogramar.hecho", { antes: f(datos.antes), ahora: f(datos.ahora) }),
  );
  emit("cambiada");
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

      <!-- Acciones de quien lo abre (p. ej. agregar a mi calendario) -->
      <slot name="acciones" />

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
        <template v-if="cita?.asiste">
          <dt>{{ $t("perfilPublico.agendar.asiste") }}</dt>
          <dd data-prueba="asiste">{{ cita.asiste }}</dd>
        </template>
        <template v-if="cita?.nota">
          <dt>{{ $t("perfilPublico.agendar.notaDelCliente") }}</dt>
          <dd data-prueba="nota-cliente">{{ cita.nota }}</dd>
        </template>
      </dl>

      <!-- Corregir la forma de pago (cobro en caja, si el negocio lo permite) -->
      <div v-if="puedeCorregirPago">
        <button
          v-if="!corrigiendoPago"
          type="button"
          class="tu-enlace text-sm"
          data-prueba="corregir-pago"
          @click="abrirCorreccionPago"
        >
          {{ $t("agendaVisual.cita.corregirMetodo") }}
        </button>
        <div v-else class="space-y-2">
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("agendaVisual.cita.corregirMetodoAyuda") }}
          </p>
          <div class="flex items-stretch gap-2">
            <select
              v-model="metodoCorregido"
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
              @click="corregirPago"
            >
              {{ $t("agendaVisual.cita.guardarMetodo") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="accionando"
              @click="corrigiendoPago = false"
            >
              {{ $t("comun.cancelar") }}
            </button>
          </div>
        </div>
      </div>

      <!-- Acciones del día -->
      <div v-if="activa" class="pc-acciones">
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

        <!-- Cambios de la cita, aparte de lo del día -->
        <div class="pc-secundarias">
          <button
            v-if="puedeCancelar && cita !== null && !cita.asistencia"
            type="button"
            class="tu-btn tu-btn-fantasma w-full"
            :aria-expanded="reprogramando"
            @click="reprogramando = !reprogramando"
          >
            {{ $t("reprogramar.titulo") }}
          </button>
          <CambiarHorario
            v-if="reprogramando && cita !== null && sesion !== null"
            :url="`${base}/reservas/${cita.reserva_id}/reprogramar`"
            :zona="sesion.zona_horaria"
            :inicia-en="sesion.inicia_en"
            :profesionales="profesionales"
            :profesional-id="sesion.instructor_id"
            @hecho="reprogramada"
            @cerrar="reprogramando = false"
          />
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

      <!-- Ya marcada: se puede corregir (el crédito se ajusta en su saldo) -->
      <div
        v-else-if="corregibleAsistencia"
       
        data-prueba="corregir-asistencia"
      >
        <button
          type="button"
          class="tu-btn tu-btn-fantasma w-full"
          :disabled="accionando"
          @click="
            marcar(cita?.asistencia === 'presente' ? 'ausente' : 'presente')
          "
        >
          {{
            cita?.asistencia === "presente"
              ? $t("agendaVisual.cita.corregirANoAsistio")
              : $t("agendaVisual.cita.corregirALlego")
          }}
        </button>
      </div>
    </div>
  </PanelLateral>
</template>

<style scoped>
/* Sin `margin`: lo pone el contenedor (space-y); un margin aquí lo anularía. */
.pc-datos {
  display: grid;
  grid-template-columns: 6.5rem minmax(0, 1fr);
  gap: 0.6rem 0.75rem;
  padding: 0.9rem;
  border-radius: 0.75rem;
  background: var(--superficie-2);
  font-size: 0.875rem;
}
.pc-datos dt {
  color: var(--texto-suave);
}
.pc-datos dd {
  font-weight: 500;
}
.pc-acciones {
  display: grid;
  gap: 0.6rem;
}
/* Reprogramar y cancelar: aparte de lo del día, tras una línea. */
.pc-secundarias {
  display: grid;
  gap: 0.6rem;
  margin-top: 0.4rem;
  padding-top: 1rem;
  border-top: 1px solid var(--borde);
}
.pc-secundarias:empty {
  display: none;
}
</style>
