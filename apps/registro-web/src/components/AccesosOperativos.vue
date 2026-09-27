<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";
import IconoNav from "@/components/IconoNav.vue";
import { esVisible, hojas, MENU } from "@/lib/menu";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const sesion = useSesionTenantStore();
const accesos = computed(() => {
  const rutas = [
    "agenda",
    "miembros",
    sesion.modalidad === "citas" ? "horarios" : "recepcion",
    "ventas",
  ];
  return rutas.flatMap((ruta) => {
    const item = hojas(MENU).find((hoja) => hoja.ruta === ruta);
    if (!item || !esVisible(item, sesion)) {
      return [];
    }
    // Suelto, fuera del menú: "Alumnos" o "Clientes", no "Directorio".
    const termino = item.termino ?? item.terminoSuelto;
    return [
      {
        ...item,
        texto:
          termino !== undefined
            ? plural(sesion.terminologia[termino])
            : undefined,
      },
    ];
  });
});
</script>

<template>
  <section v-if="accesos.length" class="mt-6" aria-labelledby="accesos-titulo">
    <h2 id="accesos-titulo" class="text-base font-medium">
      {{ $t("accesos.titulo") }}
    </h2>
    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <RouterLink
        v-for="item in accesos"
        :key="item.clave"
        :to="{ name: item.ruta }"
        class="tu-card acceso-operativo group flex items-start gap-3 p-4"
      >
        <span
          class="acceso-icono flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
          aria-hidden="true"
          ><IconoNav :nombre="item.icono ?? 'punto'" :tam="21"
        /></span>
        <span class="min-w-0 flex-1">
          <span class="block font-medium">{{
            item.texto ?? $t(item.etiqueta)
          }}</span>
          <span
            class="mt-1 block text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            >{{
              $t(
                `accesos.${item.clave}${item.clave === "agenda" ? (sesion.modalidad === "citas" ? "Citas" : "Clases") : ""}`,
              )
            }}</span
          >
        </span>
        <span class="acceso-flecha mt-2" aria-hidden="true">→</span>
      </RouterLink>
    </div>
  </section>
</template>

<style scoped>
.acceso-operativo {
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease;
}
.acceso-operativo:hover {
  border-color: var(--primario);
  background: color-mix(in srgb, var(--primario) 4%, var(--superficie));
}
.acceso-operativo:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
.acceso-icono {
  color: var(--primario-fuerte);
  background: color-mix(in srgb, var(--primario) 10%, var(--superficie));
}
.acceso-flecha {
  color: var(--texto-suave);
}
@media (prefers-reduced-motion: reduce) {
  .acceso-operativo {
    transition: none;
  }
}
</style>
