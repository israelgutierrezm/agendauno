<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Corregir un cobro en caja hecho con error (ADR 0086 y 0087):
 * - la forma de pago (mismo monto), para quien cobra en caja;
 * - anularlo (el dinero nunca entró: la venta vuelve a estar por cobrar y se retira lo
 *   que dio), para quien hace devoluciones, con motivo.
 * Cada una se confirma antes y queda en la bitácora. El servidor decide si procede
 * (`corregible` / `anulable`) y lo vuelve a validar al guardar.
 *
 * También corrige una venta de mostrador (ADR 0089): con sus rutas, sus formas de pago
 * y el permiso de quien vende.
 */
const props = withDefaults(
  defineProps<{
    base: string;
    pago: {
      id: string;
      metodo: string | null;
      corregible: boolean;
      anulable: boolean;
    };
    // Rutas de corregir y anular (por omisión, las de un cobro en caja).
    urlMetodo?: string;
    urlAnular?: string;
    metodos?: readonly string[];
    permisoMetodo?: string;
    // Qué se anula: un cobro (vuelve a quedar por cobrar) o una venta de mostrador.
    contexto?: "cobro" | "venta";
  }>(),
  {
    urlMetodo: undefined,
    urlAnular: undefined,
    metodos: () => ["efectivo", "transferencia", "manual"],
    permisoMetodo: "ordenes.gestionar",
    contexto: "cobro",
  },
);
type TextoAnular = "anular" | "anularAyuda" | "confirmarAnular" | "okAnulado";
const k = (clave: TextoAnular): string =>
  props.contexto === "venta"
    ? `corregirCobro.venta.${clave}`
    : `corregirCobro.${clave}`;
const emit = defineEmits<{ cambiado: [tipo: "metodo" | "anulado"] }>();

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();

const METODOS = computed(() => props.metodos);
const puedeCorregir = computed(
  () => props.pago.corregible && sesion.puede(props.permisoMetodo),
);
const puedeAnular = computed(
  () => props.pago.anulable && sesion.puede("pagos.reembolsar"),
);

const abierta = ref<"metodo" | "anular" | null>(null);
const metodo = ref("efectivo");
const motivo = ref("");
const guardando = ref(false);

function nombreMetodo(m: string | null): string {
  const llave = `agendaVisual.cita.metodos.${m ?? "manual"}`;
  return te(llave) ? t(llave) : (m ?? "");
}
function abrir(cual: "metodo" | "anular"): void {
  abierta.value = cual;
  metodo.value =
    METODOS.value.find((m) => m !== props.pago.metodo) ??
    METODOS.value[0] ??
    "efectivo";
  motivo.value = "";
}

async function corregirMetodo(): Promise<void> {
  if (metodo.value === props.pago.metodo) {
    abierta.value = null;
    return;
  }
  if (
    !(await confirmar(
      t("corregirCobro.confirmarMetodo", {
        antes: nombreMetodo(props.pago.metodo),
        ahora: nombreMetodo(metodo.value),
      }),
      { aceptar: t("corregirCobro.guardarMetodo") },
    ))
  ) {
    return;
  }
  guardando.value = true;
  try {
    await api.put(
      props.urlMetodo ?? `${props.base}/pagos/${props.pago.id}/metodo`,
      {
        metodo: metodo.value,
      },
    );
    toast.exito(t("corregirCobro.okMetodo"));
    abierta.value = null;
    emit("cambiado", "metodo");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

async function anular(): Promise<void> {
  if (motivo.value.trim() === "") {
    return;
  }
  if (
    !(await confirmar(t(k("confirmarAnular")), {
      aceptar: t(k("anular")),
      peligro: true,
    }))
  ) {
    return;
  }
  guardando.value = true;
  try {
    await api.post(
      props.urlAnular ?? `${props.base}/pagos/${props.pago.id}/anular`,
      {
        motivo: motivo.value.trim(),
      },
    );
    toast.exito(t(k("okAnulado")));
    abierta.value = null;
    emit("cambiado", "anulado");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <div v-if="puedeCorregir || puedeAnular" class="cc">
    <p v-if="abierta === null" class="cc-enlaces">
      <button
        v-if="puedeCorregir"
        type="button"
        class="tu-enlace text-sm"
        data-prueba="corregir-pago"
        @click="abrir('metodo')"
      >
        {{ $t("corregirCobro.corregirMetodo") }}
      </button>
      <button
        v-if="puedeAnular"
        type="button"
        class="tu-enlace text-sm"
        style="color: var(--error)"
        data-prueba="anular-cobro"
        @click="abrir('anular')"
      >
        {{ $t(k("anular")) }}
      </button>
    </p>

    <div v-else-if="abierta === 'metodo'" class="space-y-2">
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("corregirCobro.corregirMetodoAyuda") }}
      </p>
      <div class="flex flex-wrap items-stretch gap-2">
        <select
          v-model="metodo"
          class="tu-input w-auto"
          :aria-label="$t('agendaVisual.cita.metodo')"
        >
          <option v-for="m in METODOS" :key="m" :value="m">
            {{ nombreMetodo(m) }}
          </option>
        </select>
        <button
          type="button"
          class="tu-btn tu-btn-primario flex-1"
          :disabled="guardando"
          @click="corregirMetodo"
        >
          {{ $t("corregirCobro.guardarMetodo") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          :disabled="guardando"
          @click="abierta = null"
        >
          {{ $t("comun.cancelar") }}
        </button>
      </div>
    </div>

    <form v-else class="space-y-2" @submit.prevent="anular">
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t(k("anularAyuda")) }}
      </p>
      <label class="block">
        <span class="tu-label">{{ $t("corregirCobro.motivo") }}</span>
        <input
          v-model="motivo"
          class="tu-input"
          required
          maxlength="255"
          :placeholder="$t('corregirCobro.motivoPh')"
          data-prueba="motivo-anular"
        />
      </label>
      <div class="flex flex-wrap gap-2">
        <button
          type="submit"
          class="tu-btn tu-btn-fantasma flex-1"
          style="color: var(--error)"
          :disabled="guardando || motivo.trim() === ''"
        >
          {{ $t(k("anular")) }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          :disabled="guardando"
          @click="abierta = null"
        >
          {{ $t("comun.cancelar") }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.cc-enlaces {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 1.25rem;
}
</style>
