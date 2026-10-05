<script setup lang="ts">
import { computed } from "vue";

import { GENEROS, hoyIso } from "@/lib/datosPersonales";

/**
 * Los dos campos opcionales de una persona, iguales en el alta, la edición y «Mi
 * perfil»: fecha de nacimiento y género (lista breve e incluyente).
 */
const props = withDefaults(defineProps<{ id?: string; ayuda?: boolean }>(), {
  id: "dp",
  ayuda: false,
});
const fecha = defineModel<string>("fecha", { default: "" });
const genero = defineModel<string>("genero", { default: "" });

const maximo = computed(() => hoyIso());
const idFecha = computed(() => `${props.id}-nacimiento`);
const idGenero = computed(() => `${props.id}-genero`);
</script>

<template>
  <div>
    <div class="grid gap-4 sm:grid-cols-2">
      <div>
        <label class="tu-label" :for="idFecha">{{
          $t("datosPersonales.fechaNacimiento")
        }}</label>
        <input
          :id="idFecha"
          v-model="fecha"
          class="tu-input"
          type="date"
          min="1900-01-02"
          :max="maximo"
          autocomplete="bday"
          data-prueba="fecha-nacimiento"
        />
      </div>
      <div>
        <label class="tu-label" :for="idGenero">{{
          $t("datosPersonales.genero")
        }}</label>
        <select
          :id="idGenero"
          v-model="genero"
          class="tu-input"
          data-prueba="genero"
        >
          <option value="">{{ $t("datosPersonales.sinGenero") }}</option>
          <option v-for="g in GENEROS" :key="g" :value="g">
            {{ $t(`datosPersonales.generos.${g}`) }}
          </option>
        </select>
      </div>
    </div>
    <p v-if="ayuda" class="tu-hint mt-1">{{ $t("datosPersonales.ayuda") }}</p>
  </div>
</template>
