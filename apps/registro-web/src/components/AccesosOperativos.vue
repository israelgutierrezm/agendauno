<script setup lang="ts">
import { computed } from "vue";
import TarjetaAcceso from "@/components/TarjetaAcceso.vue";
import { PALETA_SERVICIO } from "@/lib/agenda";
import { esVisible, hojas, MENU } from "@/lib/menu";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const sesion = useSesionTenantStore();
// Un color por acceso (la paleta de la agenda), como en los Inicios del portal.
const TONOS: Record<string, string> = {
  agenda: PALETA_SERVICIO[0].tinta,
  miembros: PALETA_SERVICIO[2].tinta,
  recepcion: PALETA_SERVICIO[1].tinta,
  horarios: PALETA_SERVICIO[5].tinta,
  ventas: PALETA_SERVICIO[4].tinta,
};
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
    <ul class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <li v-for="item in accesos" :key="item.clave">
        <TarjetaAcceso
          :to="{ name: item.ruta }"
          :icono="item.icono ?? 'punto'"
          :titulo="item.texto ?? $t(item.etiqueta)"
          :valor="
            $t(
              `accesos.${item.clave}${item.clave === 'agenda' ? (sesion.modalidad === 'citas' ? 'Citas' : 'Clases') : ''}`,
            )
          "
          :tono="TONOS[item.clave]"
        />
      </li>
    </ul>
  </section>
</template>
