<script setup lang="ts">
import { computed, ref } from "vue";

import CampanasNegocio from "@/components/CampanasNegocio.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { hoyEnNegocio, inicioDeMesEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Campañas (ADR 0167), en Captación: de dónde llegaron las visitas, las solicitudes y
 * las ventas de cada campaña (ADR 0144), en el periodo que se elige. Es el mismo
 * reporte que va dentro del embudo de Reportes.
 */
const sesion = useSesionTenantStore();
const hoy = computed(() => hoyEnNegocio(sesion.zonaHoraria));
// Por omisión, el mes en curso del negocio.
const desde = ref(inicioDeMesEnNegocio(sesion.zonaHoraria));
const hasta = ref(hoy.value);
const valido = computed(
  () => desde.value !== "" && hasta.value !== "" && desde.value <= hasta.value,
);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('nav.areas.campanas')"
      :subtitulo="$t('campanasVista.descripcion')"
    />

    <div class="mt-6 flex flex-wrap items-end gap-3">
      <div>
        <label class="tu-label" for="campanas-desde">{{
          $t("campanasVista.desde")
        }}</label>
        <input
          id="campanas-desde"
          v-model="desde"
          type="date"
          class="tu-input"
          :max="hasta"
          data-prueba="campanas-desde"
        />
      </div>
      <div>
        <label class="tu-label" for="campanas-hasta">{{
          $t("campanasVista.hasta")
        }}</label>
        <input
          id="campanas-hasta"
          v-model="hasta"
          type="date"
          class="tu-input"
          :min="desde"
          data-prueba="campanas-hasta"
        />
      </div>
    </div>
    <p
      v-if="!valido"
      class="tu-error-campo mt-2"
      role="alert"
      data-prueba="campanas-periodo"
    >
      {{ $t("campanasVista.periodoInvalido") }}
    </p>

    <CampanasNegocio v-else :desde="desde" :hasta="hasta" />
  </section>
</template>
