<script setup lang="ts">
import { onMounted } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import ListaFormularios from "@/components/ListaFormularios.vue";
import MisDocumentos from "@/components/MisDocumentos.vue";
import { useMiCuenta } from "@/lib/miCuenta";

/**
 * Expediente del portal: lo que el negocio le pide firmar, sus documentos y los
 * formularios que debe llenar.
 */
const cuenta = useMiCuenta();

onMounted(() => void cuenta.asegurar());
</script>

<template>
  <section class="tu-pagina-cuenta">
    <EncabezadoSeccion :titulo="$t('portal.expediente.titulo')" />

    <p
      v-if="cuenta.error.value"
      class="mt-3 text-sm"
      style="color: var(--error)"
    >
      {{ cuenta.error.value }}
    </p>
    <p
      v-if="cuenta.cargando.value"
      class="mt-6"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <!-- Por firmar -->
      <div
        v-if="cuenta.waivers.value.length > 0"
        class="mt-5 tu-card p-5"
        :style="{ borderLeft: '3px solid var(--aviso)' }"
      >
        <h2 class="font-semibold">{{ $t("portal.expediente.firmar") }}</h2>
        <ul class="mt-3 space-y-4">
          <li v-for="w in cuenta.waivers.value" :key="w.id" class="text-sm">
            <p class="font-medium">{{ w.titulo }}</p>
            <p
              class="mt-1 whitespace-pre-line"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ w.contenido }}
            </p>
            <button
              class="tu-btn tu-btn-primario mt-2"
              :disabled="cuenta.accionando.value"
              @click="cuenta.aceptarWaiver(w)"
            >
              {{ $t("miCuenta.aceptarWaiver") }}
            </button>
          </li>
        </ul>
      </div>

      <MisDocumentos v-if="cuenta.personaId.value !== null" class="mt-5" />

      <div
        v-if="
          cuenta.formularios.value.length > 0 && cuenta.personaId.value !== null
        "
        class="mt-5 tu-card p-5"
      >
        <h2 class="font-semibold">{{ $t("portal.expediente.formularios") }}</h2>
        <ListaFormularios
          class="mt-2"
          :formularios="cuenta.formularios.value"
          :persona-id="cuenta.personaId.value"
          :puede-responder="true"
          @guardado="cuenta.cargarFormularios()"
        />
      </div>
    </template>
  </section>
</template>
