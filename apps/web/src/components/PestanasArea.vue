<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute, useRouter } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import { destinoDe, ubicacion, vistasVisibles, type Vista } from "@/lib/menu";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Las vistas del área actual (Agenda: Calendario, Recepción…), arriba de la pantalla.
 * Son enlaces: cada vista tiene su dirección, así que recargar o volver conserva la
 * vista. Solo se muestran las permitidas; con una sola, no hay pestañas. En un
 * teléfono, una lista con la vista activa. Configuración usa su propia navegación.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();

const actual = computed(() => ubicacion(route));
const vistas = computed(() =>
  actual.value ? vistasVisibles(actual.value.area, sesion) : [],
);
const mostrar = computed(
  () =>
    actual.value !== null &&
    actual.value.grupo.portal !== true &&
    actual.value.area.clave !== "configuracion" &&
    vistas.value.length > 1,
);

function etiqueta(x: Vista): string {
  return x.termino !== undefined
    ? plural(sesion.terminologia[x.termino])
    : t(x.etiqueta);
}
const nombreArea = computed(() => {
  const a = actual.value?.area;
  if (!a) {
    return "";
  }
  return a.termino !== undefined
    ? plural(sesion.terminologia[a.termino])
    : t(a.etiqueta);
});

function irA(clave: string): void {
  const x = vistas.value.find((v) => v.clave === clave);
  if (x) {
    void router.push(destinoDe(x));
  }
}
</script>

<template>
  <nav
    v-if="mostrar && actual"
    class="pa"
    :aria-label="nombreArea"
    data-prueba="pestanas-area"
  >
    <span class="pa-area">
      <IconoNav :nombre="actual.area.icono" :tam="18" />
      {{ nombreArea }}
    </span>
    <div class="tu-pestanas pa-pestanas">
      <RouterLink
        v-for="x in vistas"
        :key="x.clave"
        :to="destinoDe(x)"
        active-class=""
        exact-active-class=""
        :aria-current="x.clave === actual.vista.clave ? 'page' : undefined"
        >{{ etiqueta(x) }}</RouterLink
      >
    </div>
    <select
      class="tu-input pa-lista"
      :value="actual.vista.clave"
      :aria-label="nombreArea"
      @change="irA(($event.target as HTMLSelectElement).value)"
    >
      <option v-for="x in vistas" :key="x.clave" :value="x.clave">
        {{ etiqueta(x) }}
      </option>
    </select>
  </nav>
</template>

<style scoped>
.pa {
  display: flex;
  align-items: flex-end;
  gap: 1.25rem;
  padding: 1rem 1rem 0;
}
.pa-area {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding-bottom: 0.65rem;
  color: var(--texto-suave);
  font-size: 0.85rem;
  font-weight: 600;
  white-space: nowrap;
}
.pa-pestanas {
  flex: 1;
}
.pa-lista {
  display: none;
}
@media (min-width: 640px) {
  .pa {
    padding: 1.25rem 1.5rem 0;
  }
}
/* En el teléfono: la vista activa en una lista, no una fila apretada. */
@media (max-width: 639px) {
  .pa {
    align-items: center;
    gap: 0.75rem;
  }
  .pa-area {
    padding-bottom: 0;
  }
  .pa-pestanas {
    display: none;
  }
  .pa-lista {
    display: block;
    flex: 1;
    width: auto;
  }
}
</style>
