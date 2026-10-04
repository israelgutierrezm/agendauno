<script setup lang="ts">
import { computed } from "vue";
import TarjetaOperacion, {
  type Ilustracion,
} from "@/components/TarjetaOperacion.vue";
import { puedeEntrar } from "@/lib/acceso";
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
  cobranza: "naranja",
};
// Su imagen, si se agregó (la Agenda puede tener una por modalidad).
const IMAGENES = computed<Record<string, string[]>>(() => ({
  agenda: [`acceso-agenda-${sesion.modalidad}`, "acceso-agenda"],
  miembros: ["acceso-clientes"],
  recepcion: ["acceso-recepcion"],
  horarios: ["acceso-horarios"],
  ventas: ["acceso-vender"],
  cobranza: ["acceso-vender"],
}));
const ILUSTRACIONES: Record<string, Ilustracion> = {
  agenda: "agenda",
  miembros: "miembros",
  recepcion: "recepcion",
  horarios: "horarios",
  ventas: "ventas",
  cobranza: "ventas",
};
// Los accesos: la ruta (también su regla de acceso), su ícono y su nombre.
const DEFINICION: Record<
  string,
  { icono: string; etiqueta: string; termino?: "miembro" }
> = {
  agenda: { icono: "agenda", etiqueta: "nav.agenda" },
  miembros: { icono: "miembros", etiqueta: "nav.miembros", termino: "miembro" },
  recepcion: { icono: "recepcion", etiqueta: "nav.recepcion" },
  horarios: { icono: "reloj", etiqueta: "nav.horarios" },
  ventas: { icono: "ventas", etiqueta: "planes.nav.vender" },
  cobranza: { icono: "ventas", etiqueta: "accesos.cobrarTitulo" },
};
// En citas cada servicio se cobra: el acceso es «Cobrar» (lo pendiente de pago),
// no vender planes que el negocio quizá no tiene.
const accesos = computed(() =>
  [
    "agenda",
    "miembros",
    sesion.modalidad === "citas" ? "horarios" : "recepcion",
    sesion.modalidad === "citas" ? "cobranza" : "ventas",
  ]
    .filter((ruta) => puedeEntrar(ruta, sesion))
    .map((ruta) => {
      const d = DEFINICION[ruta];
      return {
        clave: ruta,
        ruta,
        icono: d.icono,
        etiqueta: d.etiqueta,
        // Suelto, fuera del menú: "Alumnos" o "Clientes", no "Directorio".
        texto:
          d.termino !== undefined
            ? plural(sesion.terminologia[d.termino])
            : undefined,
      };
    }),
);
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
          :to="{ name: item.ruta }"
          :icono="item.icono ?? 'punto'"
          :titulo="item.texto ?? $t(item.etiqueta)"
          :texto="
            $t(
              `accesos.${item.clave}${item.clave === 'agenda' ? (sesion.modalidad === 'citas' ? 'Citas' : 'Clases') : ''}`,
            )
          "
          :tono="TONOS[item.clave] ?? 'azul'"
          :ilustracion="ILUSTRACIONES[item.clave] ?? 'agenda'"
          :imagen="IMAGENES[item.clave]"
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
