<script setup lang="ts">
import { computed } from "vue";
import TarjetaOperacion, {
  type Ilustracion,
} from "@/components/TarjetaOperacion.vue";
import { esVisible, hojas, MENU } from "@/lib/menu";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const sesion = useSesionTenantStore();
// Un color y una ilustración por acceso.
const TONOS: Record<string, string> = {
  agenda: "azul",
  miembros: "morado",
  recepcion: "verde",
  horarios: "cielo",
  ventas: "naranja",
};
const ILUSTRACIONES: Record<string, Ilustracion> = {
  agenda: "agenda",
  miembros: "miembros",
  recepcion: "recepcion",
  horarios: "horarios",
  ventas: "ventas",
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
  <section v-if="accesos.length" class="mt-8" aria-labelledby="accesos-titulo">
    <span class="ao-etiqueta">{{ $t("accesos.etiqueta") }}</span>
    <h2 id="accesos-titulo" class="mt-2 text-2xl font-bold">
      {{ $t("accesos.titulo") }}
    </h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("accesos.subtitulo") }}
    </p>
    <ul class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <li v-for="item in accesos" :key="item.clave">
        <TarjetaOperacion
          :to="{ name: item.ruta! }"
          :icono="item.icono ?? 'punto'"
          :titulo="item.texto ?? $t(item.etiqueta)"
          :texto="
            $t(
              `accesos.${item.clave}${item.clave === 'agenda' ? (sesion.modalidad === 'citas' ? 'Citas' : 'Clases') : ''}`,
            )
          "
          :tono="TONOS[item.clave] ?? 'azul'"
          :ilustracion="ILUSTRACIONES[item.clave] ?? 'agenda'"
        />
      </li>
    </ul>
  </section>
</template>

<style scoped>
.ao-etiqueta {
  display: inline-block;
  padding: 0.25rem 0.7rem;
  border-radius: 0.5rem;
  background: var(--primario-suave);
  color: var(--primario);
  font-size: 0.8rem;
  font-weight: 600;
}
</style>
