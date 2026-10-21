<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";

/**
 * Contestar por WhatsApp fuera de las 24 horas (ADR 0200): con una plantilla aprobada
 * del número del negocio. Se elige, se llenan sus datos ({{1}}, {{2}}…) y se ve cómo
 * le llegará. Meta la cobra a la cuenta de WhatsApp del negocio.
 */
interface Plantilla {
  nombre: string;
  idioma: string;
  categoria: string;
  encabezado: string | null;
  cuerpo: string;
  pie: string | null;
  variables: number;
}

const props = defineProps<{ base: string; conversacionId: string }>();
const emit = defineEmits<{ enviada: [] }>();

const { t, te } = useI18n();
const plantillas = ref<Plantilla[] | null>(null);
const elegida = ref("");
const valores = ref<string[]>([]);
const enviando = ref(false);
const error = ref<string | null>(null);

const plantilla = computed(
  () =>
    plantillas.value?.find(
      (p) => `${p.nombre}|${p.idioma}` === elegida.value,
    ) ?? null,
);
watch(plantilla, (p) => {
  valores.value = Array.from({ length: p?.variables ?? 0 }, () => "");
});
const lista = computed(
  () =>
    plantilla.value !== null &&
    valores.value.every((v) => v.trim() !== "" && !/[\n\t]| {5,}/.test(v)),
);
// Cómo le llegará: con los datos escritos en su lugar.
const vistaPrevia = computed(() => {
  const p = plantilla.value;
  if (p === null) {
    return "";
  }
  const cuerpo = p.cuerpo.replace(/\{\{(\d+)\}\}/g, (marca, n: string) => {
    const valor = valores.value[Number(n) - 1]?.trim();
    return valor ? valor : marca;
  });
  return [p.encabezado, cuerpo, p.pie].filter(Boolean).join("\n\n");
});

function categoria(c: string): string {
  const llave = `bandejaMensajes.plantillas.categorias.${c}`;
  return te(llave) ? t(llave) : c;
}

async function cargar(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Plantilla[] }>(
      `${props.base}/bandeja/plantillas`,
    );
    plantillas.value = data.data;
  } catch (err) {
    plantillas.value = [];
    error.value = mensajeDeError(err, t("bandejaMensajes.plantillas.error"));
  }
}

async function enviar(): Promise<void> {
  const p = plantilla.value;
  if (p === null || !lista.value || enviando.value) {
    return;
  }
  enviando.value = true;
  error.value = null;
  try {
    await api.post(
      `${props.base}/bandeja/conversaciones/${props.conversacionId}/plantilla`,
      {
        nombre: p.nombre,
        idioma: p.idioma,
        variables: valores.value.map((v) => v.trim()),
      },
    );
    elegida.value = "";
    emit("enviada");
  } catch (err) {
    error.value = mensajeDeError(err, t("bandejaMensajes.error"));
  } finally {
    enviando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <form
    class="pb-plantilla"
    data-prueba="plantilla-bandeja"
    @submit.prevent="enviar"
  >
    <p v-if="plantillas === null" class="tu-hint">
      {{ $t("bandejaMensajes.plantillas.cargando") }}
    </p>
    <p
      v-else-if="plantillas.length === 0"
      class="tu-hint"
      data-prueba="sin-plantillas"
    >
      {{ error ?? $t("bandejaMensajes.plantillas.ninguna") }}
    </p>
    <template v-else>
      <label class="tu-label" for="pb-elegir">{{
        $t("bandejaMensajes.plantillas.elegir")
      }}</label>
      <select id="pb-elegir" v-model="elegida" class="tu-input">
        <option value="">
          {{ $t("bandejaMensajes.plantillas.ningunaElegida") }}
        </option>
        <option
          v-for="p in plantillas"
          :key="`${p.nombre}|${p.idioma}`"
          :value="`${p.nombre}|${p.idioma}`"
        >
          {{ p.nombre }} · {{ p.idioma }} · {{ categoria(p.categoria) }}
        </option>
      </select>
      <template v-if="plantilla">
        <div v-for="(_, i) in valores" :key="i">
          <label class="tu-label" :for="`pb-valor-${i}`">{{
            $t("bandejaMensajes.plantillas.dato", { n: i + 1 })
          }}</label>
          <input
            :id="`pb-valor-${i}`"
            v-model="valores[i]"
            class="tu-input"
            maxlength="1000"
          />
        </div>
        <p class="tu-label">{{ $t("bandejaMensajes.plantillas.asiLlega") }}</p>
        <p class="pb-vista" data-prueba="vista-previa">{{ vistaPrevia }}</p>
        <p class="tu-hint">{{ $t("bandejaMensajes.plantillas.cobro") }}</p>
      </template>
      <p v-if="error" class="tu-hint" role="alert">{{ error }}</p>
      <button
        type="submit"
        class="tu-btn tu-btn-primario text-sm"
        :disabled="!lista || enviando"
      >
        {{
          enviando
            ? $t("bandejaMensajes.enviando")
            : $t("bandejaMensajes.plantillas.enviar")
        }}
      </button>
    </template>
  </form>
</template>

<style scoped>
.pb-plantilla {
  display: grid;
  gap: 0.5rem;
}
.pb-vista {
  white-space: pre-wrap;
  border-radius: 0.5rem;
  padding: 0.5rem 0.75rem;
  background: var(--fondo);
  border: 1px solid var(--borde);
}
</style>
