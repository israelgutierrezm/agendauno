<script setup lang="ts">
import axios from "axios";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import {
  type NivelPlan,
  type PlanCitas,
  NIVELES_PLAN,
  precioPlan,
} from "@/lib/suscripcion";
import { useToastStore } from "@/stores/toast";

/**
 * El plan de un negocio de citas desde su ficha en el superadmin (ADR 0107): verlo y
 * cambiarlo con las mismas reglas que su dueño (subir cobra la diferencia de los días
 * que faltan; bajar o pasar a anual, desde el siguiente periodo). Como cortesía, se
 * puede subir sin cobrar esa diferencia. Subir cobrando se confirma antes. El
 * resultado va en un aviso flotante: la ficha se recarga al cambiar.
 */
const props = defineProps<{
  apiUrl: string;
  token: string;
  slug: string;
  plan: PlanCitas;
}>();
const emit = defineEmits<{ cambiado: [] }>();

const { t } = useI18n();
const toast = useToastStore();

const nivel = ref<NivelPlan>(props.plan.nivel);
const profesionales = ref(props.plan.profesionales);
const periodicidad = ref<"mensual" | "anual">(props.plan.periodicidad);
const cobrarDiferencia = ref(true);
const guardando = ref(false);

watch(
  () => props.plan,
  (p) => {
    nivel.value = p.nivel;
    profesionales.value = p.profesionales;
    periodicidad.value = p.periodicidad;
  },
);
// Individual es de un profesional.
watch(nivel, (n) => {
  if (n === "individual") {
    profesionales.value = 1;
  } else if (profesionales.value < 2) {
    profesionales.value = Math.max(2, props.plan.profesionales_actuales);
  }
});

const precio = computed(() => {
  const mensual = precioPlan(props.plan, nivel.value, profesionales.value);
  if (mensual === null) {
    return null;
  }
  return periodicidad.value === "anual"
    ? mensual * props.plan.meses_anual
    : mensual;
});

/** Hoy en el calendario local (AAAA-MM-DD). */
function hoyIso(): string {
  const d = new Date();
  const dos = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`;
}

/**
 * ¿El cambio cobra hoy? La misma regla del servidor: con un periodo pagado que cubre
 * hoy, un plan que cuesta más al mes aplica ya y se cobra la diferencia (salvo que se
 * quite «Cobrar la diferencia»).
 */
const cobraHoy = computed(() => {
  const hasta = props.plan.cubierto_hasta;
  if (
    !cobrarDiferencia.value ||
    hasta === null ||
    hasta.slice(0, 10) < hoyIso()
  ) {
    return false;
  }
  const actual = precioPlan(
    props.plan,
    props.plan.nivel,
    props.plan.profesionales,
  );
  const nuevo = precioPlan(props.plan, nivel.value, profesionales.value);
  return nuevo !== null && nuevo > (actual ?? 0);
});

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
    currencyDisplay: "code",
  }).format(minor / 100);
}
function fecha(iso: string): string {
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(a, m - 1, d),
  );
}

async function guardar(): Promise<void> {
  if (
    cobraHoy.value &&
    !(await confirmar(
      t("suscripcion.plataforma.confirmarCobro", {
        plan: t(
          "suscripcion.plan.resumen",
          {
            nivel: t(`suscripcion.niveles.${nivel.value}`),
            n: profesionales.value,
          },
          profesionales.value,
        ),
      }),
      { aceptar: t("suscripcion.plataforma.confirmarCobroAceptar") },
    ))
  ) {
    return;
  }
  guardando.value = true;
  try {
    const { data } = await axios.put<{
      data: {
        aplica: "ahora" | "siguiente";
        ajuste: { monto_minor: number; moneda: string } | null;
      };
    }>(
      `${props.apiUrl}/api/v1/plataforma/estudios/${props.slug}/plan`,
      {
        nivel: nivel.value,
        profesionales: profesionales.value,
        periodicidad: periodicidad.value,
        cobrar_diferencia: cobrarDiferencia.value,
      },
      {
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${props.token}`,
        },
      },
    );
    const { aplica, ajuste } = data.data;
    toast.exito(
      aplica === "siguiente"
        ? t("suscripcion.plan.aplicaSiguiente")
        : ajuste === null
          ? t("suscripcion.plan.aplicaAhora")
          : t("suscripcion.plan.aplicaAhoraCobro", {
              monto: dinero(ajuste.monto_minor, ajuste.moneda),
            }),
    );
    emit("cambiado");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <form class="space-y-3" data-prueba="plan-estudio" @submit.prevent="guardar">
    <h3
      class="text-xs font-medium uppercase tracking-wide"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("suscripcion.plataforma.plan") }}
    </h3>
    <p class="text-sm">
      {{
        $t(
          "suscripcion.plan.resumen",
          {
            nivel: $t(`suscripcion.niveles.${plan.nivel}`),
            n: plan.profesionales,
          },
          plan.profesionales,
        )
      }}
      · {{ $t(`suscripcion.periodicidad.${plan.periodicidad}`) }}
    </p>
    <ul class="text-xs space-y-0.5" :style="{ color: 'var(--texto-suave)' }">
      <li v-if="plan.en_prueba">{{ $t("suscripcion.plan.pruebaPro") }}</li>
      <li v-if="plan.cubierto_hasta">
        {{
          $t("suscripcion.plan.cubiertoHasta", {
            fecha: fecha(plan.cubierto_hasta),
          })
        }}
      </li>
      <li v-if="plan.siguiente">
        {{
          $t("suscripcion.plan.siguiente", {
            nivel: $t(`suscripcion.niveles.${plan.siguiente.nivel}`),
            n: plan.siguiente.profesionales,
            periodicidad: $t(
              `suscripcion.periodicidad.${plan.siguiente.periodicidad}`,
            ).toLowerCase(),
          })
        }}
      </li>
      <li>
        {{
          $t("suscripcion.plan.profesionales", {
            actuales: plan.profesionales_actuales,
            limite: plan.limite_profesionales ?? plan.max_profesionales,
          })
        }}
      </li>
    </ul>
    <div class="grid gap-3 grid-cols-1 sm:grid-cols-3">
      <label class="block">
        <span class="tu-label">{{
          $t("suscripcion.plataforma.tarifas.nivel")
        }}</span>
        <select v-model="nivel" class="tu-input">
          <option v-for="n in NIVELES_PLAN" :key="n" :value="n">
            {{ $t(`suscripcion.niveles.${n}`) }}
          </option>
        </select>
      </label>
      <label class="block">
        <span class="tu-label">{{
          $t("suscripcion.plataforma.tarifas.profesionales")
        }}</span>
        <input
          v-model.number="profesionales"
          class="tu-input"
          type="number"
          :min="nivel === 'individual' ? 1 : 2"
          :max="nivel === 'individual' ? 1 : plan.max_profesionales"
          :disabled="nivel === 'individual'"
        />
      </label>
      <label class="block">
        <span class="tu-label">{{
          $t("suscripcion.plataforma.periodicidad")
        }}</span>
        <select v-model="periodicidad" class="tu-input">
          <option value="mensual">
            {{ $t("suscripcion.periodicidad.mensual") }}
          </option>
          <option value="anual">
            {{ $t("suscripcion.periodicidad.anual") }}
          </option>
        </select>
      </label>
    </div>
    <p
      v-if="precio !== null"
      class="text-xs"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ dinero(precio, plan.moneda_tarifa) }}
      {{
        periodicidad === "anual"
          ? $t("suscripcion.plan.porAno")
          : $t("suscripcion.plan.porMes")
      }}
    </p>
    <label class="flex items-start gap-2 text-sm">
      <input v-model="cobrarDiferencia" type="checkbox" class="mt-1" />
      <span>
        {{ $t("suscripcion.plataforma.cobrarDiferencia") }}
        <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("suscripcion.plataforma.cobrarDiferenciaAyuda") }}
        </span>
      </span>
    </label>
    <button
      class="tu-btn tu-btn-primario text-sm"
      type="submit"
      :disabled="guardando || precio === null"
    >
      {{ $t("suscripcion.plan.cambiar") }}
    </button>
  </form>
</template>
