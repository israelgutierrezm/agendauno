<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Dar de baja una membresía (plan de mejoras B04). Antes de confirmar se ve lo mismo
 * que hará el servidor: cuándo termina (al acabar el periodo pagado; en mora, hoy), si
 * está en permanencia y su penalización pactada, y la mensualidad vencida, que se
 * cobra aparte salvo que se decida anularla. Quien puede exenta la penalización.
 */
interface Simulacion {
  efectiva_el: string;
  en_permanencia: boolean;
  permanencia_hasta: string | null;
  modo: "sin_penalizacion" | "monto_fijo" | "meses_restantes" | null;
  meses_restantes: number;
  precio_base_minor: number | null;
  // Qué porcentaje de lo que falte se cobra (100 = todo).
  porcentaje?: number | null;
  penalizacion_minor: number;
  adeudo_minor: number;
  moneda: string;
  condiciones: string | null;
}

const props = defineProps<{
  base: string;
  acuerdoId: string;
  producto: string;
}>();
const emit = defineEmits<{ hecho: [aviso: string]; cerrar: [] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const puedeExentar = computed(() => sesion.puede("cuotas.exentar"));

const sim = ref<Simulacion | null>(null);
const error = ref<string | null>(null);
const enviando = ref(false);
const motivo = ref("");
const exentar = ref(false);
const conservarAdeudo = ref(true);

function dinero(minor: number): string {
  return dineroDelPais(minor, sim.value?.moneda ?? sesion.moneda, sesion.pais);
}
function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${iso}T12:00:00Z`));
}

const aCobrar = computed(() => {
  const s = sim.value;
  if (!s) {
    return 0;
  }
  return (
    (exentar.value ? 0 : s.penalizacion_minor) +
    (conservarAdeudo.value ? s.adeudo_minor : 0)
  );
});
// Exentar la penalización o perdonar la mensualidad vencida piden motivo.
const perdona = computed(
  () => !conservarAdeudo.value && (sim.value?.adeudo_minor ?? 0) > 0,
);
const motivoFalta = computed(
  () => (exentar.value || perdona.value) && motivo.value.trim() === "",
);

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: Simulacion }>(
      `${props.base}/acuerdos/${props.acuerdoId}/baja`,
    );
    sim.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
});

async function confirmarBaja(): Promise<void> {
  if (!sim.value || motivoFalta.value) {
    return;
  }
  enviando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: { efectiva_el: string } }>(
      `${props.base}/acuerdos/${props.acuerdoId}/baja`,
      {
        motivo: motivo.value.trim() || null,
        exentar_penalizacion: exentar.value,
        conservar_adeudo: conservarAdeudo.value,
      },
    );
    emit(
      "hecho",
      t("bajaMembresia.hecha", {
        producto: props.producto,
        fecha: fecha(data.data.efectiva_el),
      }),
    );
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    enviando.value = false;
  }
}
</script>

<template>
  <div
    class="w-full space-y-3 rounded-xl border p-4 text-sm"
    :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
    data-prueba="dar-de-baja"
  >
    <p v-if="!sim && !error" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <template v-if="sim">
      <p class="font-medium" data-prueba="baja-efectiva">
        {{ $t("bajaMembresia.termina", { fecha: fecha(sim.efectiva_el) }) }}
      </p>
      <p v-if="sim.en_permanencia" data-prueba="baja-permanencia">
        {{
          $t(
            "bajaMembresia.enPermanencia",
            {
              fecha: fecha(sim.permanencia_hasta ?? sim.efectiva_el),
              n: sim.meses_restantes,
            },
            sim.meses_restantes,
          )
        }}
        <template
          v-if="sim.modo === 'meses_restantes' && sim.precio_base_minor"
        >
          {{
            (sim.porcentaje ?? 100) < 100
              ? $t("bajaMembresia.penalizacionMesesPorcentaje", {
                  n: sim.meses_restantes,
                  precio: dinero(sim.precio_base_minor),
                  porcentaje: sim.porcentaje,
                  total: dinero(sim.penalizacion_minor),
                })
              : $t("bajaMembresia.penalizacionMeses", {
                  n: sim.meses_restantes,
                  precio: dinero(sim.precio_base_minor),
                  total: dinero(sim.penalizacion_minor),
                })
          }}
        </template>
        <template v-else-if="sim.modo === 'monto_fijo'">
          {{
            $t("bajaMembresia.penalizacionFija", {
              total: dinero(sim.penalizacion_minor),
            })
          }}
        </template>
        <template v-else>{{ $t("bajaMembresia.sinPenalizacion") }}</template>
      </p>
      <p
        v-else-if="sim.permanencia_hasta"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("bajaMembresia.cumplida") }}
      </p>
      <details v-if="sim.condiciones" class="text-xs">
        <summary
          class="cursor-pointer"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("bajaMembresia.condiciones") }}
        </summary>
        <p class="mt-1">{{ sim.condiciones }}</p>
      </details>

      <label
        v-if="puedeExentar && sim.adeudo_minor > 0"
        class="flex items-start gap-2"
      >
        <input v-model="conservarAdeudo" type="checkbox" class="mt-1" />
        <span>
          {{ $t("bajaMembresia.adeudo", { monto: dinero(sim.adeudo_minor) }) }}
          <span
            class="block text-xs"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ $t("bajaMembresia.adeudoAyuda") }}</span
          >
        </span>
      </label>
      <label
        v-if="puedeExentar && sim.penalizacion_minor > 0"
        class="flex items-center gap-2"
      >
        <input
          v-model="exentar"
          type="checkbox"
          data-prueba="exentar-penalizacion"
        />
        {{ $t("bajaMembresia.exentar") }}
      </label>
      <div>
        <label class="tu-label" for="baja-motivo">{{
          exentar || perdona
            ? $t("bajaMembresia.motivoObligatorio")
            : $t("bajaMembresia.motivo")
        }}</label>
        <input
          id="baja-motivo"
          v-model="motivo"
          class="tu-input"
          maxlength="300"
          :required="exentar || perdona"
        />
      </div>
      <p class="font-medium" data-prueba="baja-total">
        {{
          aCobrar > 0
            ? $t("bajaMembresia.aCobrar", { monto: dinero(aCobrar) })
            : $t("bajaMembresia.nadaQueCobrar")
        }}
      </p>
    </template>
    <AvisoTono v-if="error" tono="peligro" role="alert">{{ error }}</AvisoTono>
    <div class="flex flex-wrap gap-2">
      <button
        v-if="sim"
        type="button"
        class="tu-btn tu-btn-solido tu-tono-peligro"
        :disabled="enviando || motivoFalta"
        data-prueba="confirmar-baja"
        @click="confirmarBaja"
      >
        {{ $t("bajaMembresia.confirmar") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="enviando"
        @click="emit('cerrar')"
      >
        {{ $t("cancelacion.volver") }}
      </button>
    </div>
  </div>
</template>
