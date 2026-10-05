<script setup lang="ts">
import { computed, ref, useId } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { aceptaArchivo } from "@/lib/archivos";

/**
 * Zona para subir un archivo, la misma en toda la app (como en Acadion): se arrastra
 * encima o se elige con un clic o con el teclado. Al soltar, el navegador no filtra
 * por tipo, así que aquí se revisa contra `accept`; el archivo válido se emite y
 * quien la usa decide qué hacer (subirlo, revisarlo…). Con `cargado` muestra el
 * nombre del archivo elegido; `compacta` la pone en una fila (para formularios).
 * Los errores de tipo y de peso se dicen debajo de la zona, siempre igual.
 */
const props = withDefaults(
  defineProps<{
    accept: string;
    // «Arrastra … aquí o» + «elígelo» (en color de enlace).
    texto?: string;
    elige?: string;
    // Lo que dice mientras se arrastra encima.
    suelta?: string;
    // Formatos y peso, o lo que ayude a elegir el archivo correcto.
    ayuda?: string;
    // Nombre del archivo ya elegido (o null).
    cargado?: string | null;
    ocupado?: boolean;
    ocupadoTexto?: string;
    deshabilitado?: boolean;
    compacta?: boolean;
    // Resaltada desde fuera (p. ej. arrastrando sobre la tarjeta que la contiene).
    resaltada?: boolean;
    icono?: "subir" | "imagen" | "archivo";
    id?: string;
    // Peso máximo (bytes) y mensajes propios de tipo y de peso.
    maxBytes?: number;
    errorTipo?: string;
    errorPeso?: string;
  }>(),
  {
    texto: undefined,
    elige: undefined,
    suelta: undefined,
    ayuda: undefined,
    cargado: null,
    ocupado: false,
    ocupadoTexto: undefined,
    deshabilitado: false,
    compacta: false,
    resaltada: false,
    icono: "subir",
    id: undefined,
    maxBytes: undefined,
    errorTipo: undefined,
    errorPeso: undefined,
  },
);
const emit = defineEmits<{ archivo: [archivo: File] }>();
const { t } = useI18n();

const entrada = ref<HTMLInputElement | null>(null);
// Contador: entrar a un hijo (ícono, texto) dispara «dragleave» en la zona.
const dentro = ref(0);
const error = ref<string | null>(null);
const idAyuda = useId();
const habilitada = computed(() => !props.deshabilitado && !props.ocupado);
const arrastrando = computed(
  () => (dentro.value > 0 || props.resaltada) && habilitada.value,
);

function abrir(): void {
  if (habilitada.value) {
    entrada.value?.click();
  }
}

function recibir(archivo: File | undefined): void {
  error.value = null;
  if (archivo === undefined || !habilitada.value) {
    return;
  }
  if (!aceptaArchivo(archivo, props.accept)) {
    error.value = props.errorTipo ?? t("zonaArchivo.tipo");
    return;
  }
  if (props.maxBytes !== undefined && archivo.size > props.maxBytes) {
    error.value = props.errorPeso ?? t("zonaArchivo.peso");
    return;
  }
  emit("archivo", archivo);
}

// Quien la contiene puede pasarle lo que se soltó fuera de ella (toda una tarjeta
// que acepta la foto) o abrir el selector desde otro control.
defineExpose({ abrir, recibir });

function alElegir(e: Event): void {
  const input = e.target as HTMLInputElement;
  recibir(input.files?.[0]);
  // Así se puede volver a elegir el mismo archivo.
  input.value = "";
}

function alEntrar(): void {
  dentro.value += 1;
}
function alSalir(): void {
  dentro.value = Math.max(0, dentro.value - 1);
}
function alSoltar(e: DragEvent): void {
  dentro.value = 0;
  recibir(e.dataTransfer?.files?.[0]);
}
</script>

<template>
  <div>
    <div
      class="tu-zona-archivo"
      :class="{
        'tu-zona-archivo-compacta': compacta,
        'tu-zona-archivo-activa': arrastrando,
        'tu-zona-archivo-ocupada': ocupado,
      }"
      role="button"
      :tabindex="habilitada ? 0 : -1"
      :aria-disabled="!habilitada"
      :aria-busy="ocupado"
      :aria-describedby="idAyuda"
      data-prueba="zona-archivo"
      @click="abrir"
      @keydown.enter.prevent="abrir"
      @keydown.space.prevent="abrir"
      @dragenter.prevent="alEntrar"
      @dragover.prevent
      @dragleave.prevent="alSalir"
      @drop.prevent.stop="alSoltar"
    >
      <span class="tu-zona-archivo-icono">
        <IconoNav :nombre="cargado && !ocupado ? 'hecho' : icono" :tam="22" />
      </span>
      <span class="tu-zona-archivo-textos">
        <span class="tu-zona-archivo-texto" aria-live="polite">
          <template v-if="ocupado">{{
            ocupadoTexto ?? $t("zonaArchivo.subiendo")
          }}</template>
          <template v-else-if="arrastrando">{{
            suelta ?? $t("zonaArchivo.suelta")
          }}</template>
          <template v-else-if="cargado">{{ cargado }}</template>
          <template v-else>
            {{ texto ?? $t("zonaArchivo.arrastra") }}
            <span class="tu-zona-archivo-elige">{{
              elige ?? $t("zonaArchivo.elige")
            }}</span>
          </template>
        </span>
        <span :id="idAyuda" class="tu-zona-archivo-ayuda">{{
          cargado && !ocupado ? $t("zonaArchivo.cambiar") : ayuda
        }}</span>
      </span>
      <input
        :id="id"
        ref="entrada"
        type="file"
        :accept="accept"
        class="sr-only"
        tabindex="-1"
        :disabled="!habilitada"
        @click.stop
        @change="alElegir"
      />
    </div>
    <p
      v-if="error"
      class="mt-1.5 text-sm"
      role="alert"
      style="color: var(--error)"
    >
      {{ error }}
    </p>
  </div>
</template>
