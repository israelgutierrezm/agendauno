<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { dinero } from "@/lib/miCuenta";

/**
 * Antes de reservar una clase, lo que va a pasar (plan de mejoras M05), dicho por el
 * servidor con la misma regla que al reservar: si queda reservada, en lista de espera o
 * no se puede (el motivo principal y la acción que sirve), con qué plan y cuánto
 * consume, dónde es, qué llevar y hasta cuándo se cancela sin costo, en la hora de la
 * sede. Confirmar es otro paso: aquí no se aparta nada.
 */
export type AccionAntes =
  | "ver_planes"
  | "revisar_vigencia"
  | "cambiar_sucursal"
  | "ver_reserva"
  | "otras_clases";

interface Antes {
  resultado: "reserva" | "lista_espera" | "no_disponible";
  codigo: string | null;
  motivo: string | null;
  accion: AccionAntes | null;
  mensaje: string | null;
  unidades: number;
  inicia_en: string;
  zona_horaria: string | null;
  sucursal: { nombre: string; mapa_url: string | null } | null;
  requisitos: string | null;
  que_llevar: string | null;
  plan: {
    nombre: string | null;
    ilimitado: boolean;
    vence: string | null;
    disponible_despues: number | null;
  } | null;
  de_pago: { precio_minor: number; moneda: string } | null;
  cancelacion: {
    limite: string;
    horas_limite: number;
    penaliza_tarde: boolean;
    cancela_tarde: boolean;
  };
}

const props = defineProps<{
  base: string;
  sesionId: string;
  ocupado?: boolean;
  /** Sin vista previa (falló): si se ofrece la lista de espera. */
  llena?: boolean;
}>();
const emit = defineEmits<{
  confirmar: [esperar: boolean];
  accion: [accion: AccionAntes];
}>();

const { t, te } = useI18n();
const antes = ref<Antes | null>(null);
const error = ref<string | null>(null);

// Una respuesta vieja (de otra clase) no pisa a la nueva.
let pedido = 0;
async function cargar(): Promise<void> {
  const mio = ++pedido;
  antes.value = null;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Antes }>(
      `${props.base}/mi/sesiones/${props.sesionId}/antes-de-reservar`,
    );
    if (mio === pedido) {
      antes.value = data.data;
    }
  } catch (e) {
    if (mio === pedido) {
      error.value = mensajeDeError(e);
    }
  }
}
watch(() => props.sesionId, cargar);
onMounted(cargar);

const creditos = (unidades: number) =>
  t(
    "portal.antesDeReservar.creditos",
    { n: unidades / 1000 },
    unidades === 1000 ? 1 : 2,
  );

/** Qué pasa con su plan (o el pago) si reserva. */
const consumo = computed(() => {
  const a = antes.value;
  if (!a || a.resultado === "no_disponible") {
    return null;
  }
  if (a.de_pago) {
    return t("portal.antesDeReservar.dePago", {
      precio: dinero(a.de_pago.precio_minor, a.de_pago.moneda),
    });
  }
  if (a.resultado === "lista_espera") {
    return t("portal.antesDeReservar.listaEspera");
  }
  const plan = a.plan?.nombre ?? t("portal.antesDeReservar.tuPlan");
  if (!a.plan || a.plan.ilimitado || a.unidades === 0) {
    return t("portal.antesDeReservar.conPlanIlimitado", { plan });
  }
  return t("portal.antesDeReservar.conPlan", {
    plan,
    consume: creditos(a.unidades),
    quedan: creditos(a.plan.disponible_despues ?? 0),
  });
});

function cuando(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: antes.value?.zona_horaria ?? undefined,
    weekday: "long",
    day: "numeric",
    month: "long",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}

/** Hasta cuándo cancela sin costo y qué pasa después (la política que se congela). */
const cancelacion = computed(() => {
  const a = antes.value;
  if (!a || a.resultado !== "reserva") {
    return null;
  }
  const c = a.cancelacion;
  const despues = !c.cancela_tarde
    ? t("portal.antesDeReservar.despuesSoloNegocio")
    : c.penaliza_tarde
      ? t("portal.antesDeReservar.despuesSeDescuenta")
      : t("portal.antesDeReservar.despuesSeDevuelve");
  if (new Date(c.limite).getTime() <= Date.now()) {
    return `${t("portal.antesDeReservar.yaPasoLimite")} ${despues}`;
  }
  return `${t("portal.antesDeReservar.cancelaHasta", { cuando: cuando(c.limite) })} ${despues}`;
});

/** El motivo principal, en palabras del alumno (o el del servidor). */
const motivo = computed(() => {
  const a = antes.value;
  if (!a || a.resultado !== "no_disponible") {
    return null;
  }
  for (const clave of [
    `portal.cobertura.motivos.${a.motivo}`,
    `portal.antesDeReservar.motivos.${a.motivo}`,
  ]) {
    if (a.motivo && te(clave)) {
      return t(clave);
    }
  }
  return a.mensaje;
});
</script>

<template>
  <div class="space-y-3 text-sm" data-prueba="antes-de-reservar">
    <p v-if="!antes && !error" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("portal.antesDeReservar.calculando") }}
    </p>

    <!-- Sin vista previa: se puede intentar igual (el servidor valida al reservar). -->
    <template v-if="error">
      <p :style="{ color: 'var(--texto-suave)' }">{{ error }}</p>
      <button
        type="button"
        class="tu-btn"
        :class="llena ? 'tu-btn-fantasma' : 'tu-btn-primario'"
        :disabled="ocupado"
        @click="emit('confirmar', !!llena)"
      >
        {{ llena ? $t("miCuenta.listaEspera") : $t("miCuenta.reservar") }}
      </button>
    </template>

    <template v-if="antes">
      <p
        v-if="motivo"
        class="ar-motivo"
        role="status"
        data-prueba="antes-motivo"
      >
        {{ motivo }}
      </p>
      <dl class="ar-datos">
        <template v-if="consumo">
          <dt>{{ $t("portal.antesDeReservar.plan") }}</dt>
          <dd data-prueba="antes-consumo">{{ consumo }}</dd>
        </template>
        <template v-if="antes.sucursal">
          <dt>{{ $t("portal.antesDeReservar.donde") }}</dt>
          <dd>
            {{ antes.sucursal.nombre }}
            <a
              v-if="antes.sucursal.mapa_url"
              :href="antes.sucursal.mapa_url"
              target="_blank"
              rel="noopener"
              class="tu-enlace ml-1"
              >{{ $t("portal.antesDeReservar.comoLlegar") }}</a
            >
          </dd>
        </template>
        <template v-if="cancelacion">
          <dt>{{ $t("portal.antesDeReservar.cancelar") }}</dt>
          <dd data-prueba="antes-cancelacion">{{ cancelacion }}</dd>
        </template>
        <template v-if="antes.requisitos">
          <dt>{{ $t("portal.antesDeReservar.requisitos") }}</dt>
          <dd>{{ antes.requisitos }}</dd>
        </template>
        <template v-if="antes.que_llevar">
          <dt>{{ $t("portal.antesDeReservar.queLlevar") }}</dt>
          <dd>{{ antes.que_llevar }}</dd>
        </template>
      </dl>

      <div class="flex flex-wrap gap-2">
        <button
          v-if="antes.resultado !== 'no_disponible'"
          type="button"
          class="tu-btn tu-btn-primario"
          :disabled="ocupado"
          data-prueba="confirmar-reserva"
          @click="emit('confirmar', antes.resultado === 'lista_espera')"
        >
          {{
            antes.resultado === "lista_espera"
              ? $t("portal.antesDeReservar.confirmarEspera")
              : antes.de_pago
                ? $t("portal.antesDeReservar.confirmarPago")
                : $t("portal.antesDeReservar.confirmar")
          }}
        </button>
        <RouterLink
          v-else-if="
            antes.accion === 'ver_planes' || antes.accion === 'revisar_vigencia'
          "
          :to="{
            name: 'mis-pagos',
            hash: antes.accion === 'ver_planes' ? '#mp-comprar' : '#mp-planes',
          }"
          class="tu-btn tu-btn-primario"
          data-prueba="antes-accion"
          >{{
            $t(`portal.antesDeReservar.acciones.${antes.accion}`)
          }}</RouterLink
        >
        <button
          v-else-if="antes.accion"
          type="button"
          class="tu-btn tu-btn-fantasma"
          data-prueba="antes-accion"
          @click="emit('accion', antes.accion)"
        >
          {{ $t(`portal.antesDeReservar.acciones.${antes.accion}`) }}
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Etiqueta y dato: en dos columnas; en el teléfono, uno debajo del otro. */
.ar-datos {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.35rem 0.85rem;
}
.ar-datos dt {
  color: var(--texto-suave);
}
@media (max-width: 30rem) {
  .ar-datos {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.1rem;
  }
  .ar-datos dd {
    margin-bottom: 0.4rem;
  }
}
.ar-motivo {
  padding: 0.6rem 0.75rem;
  border-radius: 0.6rem;
  background: var(--superficie-2);
}
</style>
