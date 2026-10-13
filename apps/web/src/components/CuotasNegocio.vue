<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { aMinor, ejemploPrecio, simboloMoneda } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Las cuotas del negocio (plan de mejoras B04): la inscripción (en la primera alta y al
 * regresar tras X meses sin plan) y la anualidad (en el aniversario de su alta o en una
 * fecha fija). Un monto vacío o en 0 = no se cobra. Cada plan dice si las cobra.
 */
interface Cuotas {
  inscripcion_minor: number;
  reingreso_meses: number;
  anualidad_minor: number;
  anualidad_modo: "aniversario" | "fecha_fija";
  anualidad_mes: number | null;
  anualidad_dia: number | null;
}

const emit = defineEmits<{ guardado: []; cerrar: [] }>();
const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const simbolo = computed(() => simboloMoneda(sesion.moneda, sesion.pais));

const cargando = ref(true);
const guardando = ref(false);
const error = ref<string | null>(null);
const form = ref({
  inscripcion: "",
  reingreso: "6",
  anualidad: "",
  modo: "aniversario" as Cuotas["anualidad_modo"],
  mes: "1",
  dia: "1",
});

function aTexto(minor: number): string {
  return minor > 0 ? String(minor / 100) : "";
}
// Vacío = 0; lo que no se puede leer sin adivinar se marca y no se guarda.
function monto(texto: string): number | null {
  return texto.trim() === "" ? 0 : aMinor(texto, sesion.pais);
}
const ilegible = computed(
  () =>
    monto(form.value.inscripcion) === null ||
    monto(form.value.anualidad) === null,
);
const MESES = Array.from({ length: 12 }, (_, i) => i + 1);
function nombreMes(m: number): string {
  return new Intl.DateTimeFormat("es-MX", {
    month: "long",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(2024, m - 1, 1)));
}

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: Cuotas }>(`${base.value}/cuotas`);
    const c = data.data;
    form.value = {
      inscripcion: aTexto(c.inscripcion_minor),
      reingreso: String(c.reingreso_meses),
      anualidad: aTexto(c.anualidad_minor),
      modo: c.anualidad_modo,
      mes: String(c.anualidad_mes ?? 1),
      dia: String(c.anualidad_dia ?? 1),
    };
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
});

async function guardar(): Promise<void> {
  if (ilegible.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  const fija = form.value.modo === "fecha_fija";
  try {
    await api.put(`${base.value}/cuotas`, {
      inscripcion_minor: monto(form.value.inscripcion) ?? 0,
      reingreso_meses: Math.max(0, Number(form.value.reingreso) || 0),
      anualidad_minor: monto(form.value.anualidad) ?? 0,
      anualidad_modo: form.value.modo,
      anualidad_mes: fija ? Number(form.value.mes) : null,
      anualidad_dia: fija ? Number(form.value.dia) : null,
    });
    emit("guardado");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <p v-if="cargando" :style="{ color: 'var(--texto-suave)' }">
    {{ $t("comun.cargando") }}
  </p>
  <form
    v-else
    class="space-y-5"
    data-prueba="cuotas-negocio"
    @submit.prevent="guardar"
  >
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("planes.cuotas.ayuda") }}
    </p>

    <fieldset class="space-y-3">
      <legend class="tu-label">{{ $t("planes.cuotas.inscripcion") }}</legend>
      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="tu-label" for="cu-inscripcion">{{
            $t("planes.cuotas.monto", { simbolo })
          }}</label>
          <input
            id="cu-inscripcion"
            v-model="form.inscripcion"
            class="tu-input"
            inputmode="decimal"
            :placeholder="$t('planes.cuotas.sinCobro')"
          />
        </div>
        <div>
          <label class="tu-label" for="cu-reingreso">{{
            $t("planes.cuotas.reingreso")
          }}</label>
          <input
            id="cu-reingreso"
            v-model="form.reingreso"
            class="tu-input"
            type="number"
            min="0"
            max="120"
          />
        </div>
      </div>
      <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("planes.cuotas.inscripcionAyuda") }}
      </p>
    </fieldset>

    <fieldset class="space-y-3">
      <legend class="tu-label">{{ $t("planes.cuotas.anualidad") }}</legend>
      <div class="sm:w-1/2">
        <label class="tu-label" for="cu-anualidad">{{
          $t("planes.cuotas.monto", { simbolo })
        }}</label>
        <input
          id="cu-anualidad"
          v-model="form.anualidad"
          class="tu-input"
          inputmode="decimal"
          :placeholder="$t('planes.cuotas.sinCobro')"
        />
      </div>
      <div class="flex flex-wrap gap-2" role="radiogroup">
        <label
          v-for="m in ['aniversario', 'fecha_fija'] as const"
          :key="m"
          class="cu-opcion"
        >
          <input v-model="form.modo" type="radio" :value="m" />
          {{ $t(`planes.cuotas.modos.${m}`) }}
        </label>
      </div>
      <div v-if="form.modo === 'fecha_fija'" class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="tu-label" for="cu-mes">{{
            $t("planes.cuotas.mes")
          }}</label>
          <select id="cu-mes" v-model="form.mes" class="tu-input">
            <option v-for="m in MESES" :key="m" :value="String(m)">
              {{ nombreMes(m) }}
            </option>
          </select>
        </div>
        <div>
          <label class="tu-label" for="cu-dia">{{
            $t("planes.cuotas.dia")
          }}</label>
          <input
            id="cu-dia"
            v-model="form.dia"
            class="tu-input"
            type="number"
            min="1"
            max="31"
          />
        </div>
      </div>
      <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{
          form.modo === "aniversario"
            ? $t("planes.cuotas.aniversarioAyuda")
            : $t("planes.cuotas.fechaFijaAyuda")
        }}
      </p>
    </fieldset>

    <AvisoTono v-if="ilegible" tono="peligro" role="alert">
      {{
        t("validacion.precioIlegible", { ejemplo: ejemploPrecio(sesion.pais) })
      }}
    </AvisoTono>
    <AvisoTono v-if="error" tono="peligro" role="alert">
      {{ error }}
    </AvisoTono>
    <div class="flex justify-end gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        @click="emit('cerrar')"
      >
        {{ $t("planes.editor.cancelar") }}
      </button>
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando || ilegible"
      >
        {{
          guardando
            ? $t("planes.editor.guardando")
            : $t("planes.editor.guardar")
        }}
      </button>
    </div>
  </form>
</template>

<style scoped>
.cu-opcion {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.7rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  font-size: 0.875rem;
  cursor: pointer;
}
.cu-opcion:has(input:checked) {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 8%, var(--superficie));
}
</style>
