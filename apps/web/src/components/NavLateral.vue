<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import {
  claveSegunModalidad,
  CATEGORIAS_CONFIGURACION,
  coincideBusqueda,
  destinoDe,
  menuVisible,
  ubicacion,
  vistasDeArea,
  type Area,
  type Vista,
} from "@/lib/menu";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Barra lateral: grupos (títulos discretos, sin desplegar) con un enlace directo por
 * área, que abre su primera vista permitida. Solo un área queda activa (la de la
 * pantalla actual, aunque sea una ficha o una pestaña). Contraída, solo íconos.
 */
defineProps<{ compacto: boolean }>();
const emit = defineEmits<{ navegar: [] }>();

const { t, te } = useI18n();
const route = useRoute();
const sesion = useSesionTenantStore();

const grupos = computed(() => menuVisible(sesion));
const activa = computed(() => ubicacion(route)?.area.clave ?? null);
const busqueda = ref("");
watch(
  () => route.fullPath,
  () => {
    busqueda.value = "";
  },
);

function etiquetaVista(vista: Vista): string {
  return vista.termino
    ? plural(sesion.terminologia[vista.termino])
    : t(claveSegunModalidad(vista.etiqueta, sesion.esCitas, te));
}

// Buscar únicamente en el menú permitido de la sesión activa: nunca recuperamos
// opciones ocultas por rol, modalidad, sucursal o suscripción.
const resultados = computed(() => {
  const q = busqueda.value.trim();
  if (!q) return [];
  return grupos.value.flatMap((grupo) =>
    grupo.areas.flatMap((area) =>
      vistasDeArea(area, sesion).flatMap((vista) => {
        const categoria =
          area.clave === "configuracion"
            ? CATEGORIAS_CONFIGURACION.find((c) =>
                c.opciones.some((op) => op.clave === vista.clave),
              )
            : undefined;
        const opcion = categoria?.opciones.find(
          (op) => op.clave === vista.clave,
        );
        const titulo = etiquetaVista(vista);
        const contexto = [
          etiqueta(area),
          categoria
            ? t(claveSegunModalidad(categoria.etiqueta, sesion.esCitas, te))
            : "",
        ]
          .filter((x) => x && x !== titulo)
          .join(" · ");
        return [titulo, contexto, ...(opcion?.sinonimos ?? [])].some((texto) =>
          coincideBusqueda(texto, q),
        )
          ? [
              {
                clave: `${grupo.clave}-${area.clave}-${vista.clave}`,
                titulo,
                contexto,
                icono: area.icono,
                vista,
              },
            ]
          : [];
      }),
    ),
  );
});

function etiqueta(a: Area): string {
  if (a.termino !== undefined && a.mio) {
    return t("portal.instructor.nav.mis", {
      grupo: plural(sesion.terminologia[a.termino]).toLowerCase(),
    });
  }
  // El texto del tipo de negocio (p. ej. «Citas» en lugar de «Reservas»).
  return a.termino !== undefined
    ? plural(sesion.terminologia[a.termino])
    : t(claveSegunModalidad(a.etiqueta, sesion.esCitas, te));
}
</script>

<template>
  <nav class="nl" :aria-label="t('nav.menu')">
    <label v-if="!compacto" class="nl-buscar tu-campo-icono">
      <IconoNav nombre="buscar" :tam="16" />
      <input
        v-model="busqueda"
        type="search"
        class="tu-input"
        :placeholder="t('adminUsabilidad.buscarMenu')"
        :aria-label="t('adminUsabilidad.buscarMenu')"
      />
    </label>
    <div
      v-if="!compacto && busqueda.trim()"
      class="nl-resultados"
      :aria-label="t('adminUsabilidad.resultadosMenu')"
    >
      <p v-if="!resultados.length" class="nl-sin" role="status">
        {{ t("adminUsabilidad.sinOpciones") }}
      </p>
      <RouterLink
        v-for="r in resultados"
        :key="r.clave"
        :to="destinoDe(r.vista)"
        class="nl-resultado"
        @click="emit('navegar')"
      >
        <IconoNav :nombre="r.icono" :tam="18" class="shrink-0" />
        <span class="min-w-0"
          ><span class="block font-medium">{{ r.titulo }}</span
          ><span v-if="r.contexto" class="nl-contexto">{{
            r.contexto
          }}</span></span
        >
      </RouterLink>
    </div>
    <template v-else>
      <div v-for="(g, i) in grupos" :key="g.clave" class="nl-grupo">
        <p v-if="!compacto" class="nl-titulo">{{ t(g.etiqueta) }}</p>
        <hr v-else-if="i > 0" class="nl-separador" />
        <RouterLink
          v-for="a in g.areas"
          :key="a.clave"
          :to="destinoDe(a.destino)"
          class="tu-side-link"
          active-class=""
          exact-active-class=""
          :class="{
            'tu-side-activo': activa === a.clave,
            'lg:justify-center': compacto,
          }"
          :aria-current="activa === a.clave ? 'page' : undefined"
          :title="compacto ? etiqueta(a) : undefined"
          :aria-label="compacto ? etiqueta(a) : undefined"
          :data-prueba="`area-${a.clave}`"
          @click="emit('navegar')"
        >
          <IconoNav :nombre="a.icono" :tam="20" class="shrink-0" />
          <span v-if="!compacto" class="truncate">{{ etiqueta(a) }}</span>
        </RouterLink>
      </div>
    </template>
  </nav>
</template>

<style scoped>
.nl {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.nl-buscar {
  width: 100%;
}
.nl-buscar .tu-input {
  min-width: 0;
  font-size: 0.8rem;
  background: var(--barra-fondo, var(--superficie));
  color: var(--barra-texto);
  border-color: var(--barra-borde);
}
.nl-resultados {
  display: grid;
  gap: 0.25rem;
}
.nl-resultado {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.65rem 0.7rem;
  border-radius: 0.65rem;
  color: var(--barra-texto);
  font-size: 0.85rem;
  text-decoration: none;
  overflow-wrap: anywhere;
}
.nl-resultado:hover,
.nl-resultado:focus-visible {
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.nl-contexto {
  display: block;
  margin-top: 0.15rem;
  font-size: 0.72rem;
  opacity: 0.8;
}
.nl-sin {
  padding: 0.65rem;
  color: var(--barra-texto);
  font-size: 0.8rem;
}
.nl-grupo {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.nl-titulo {
  padding: 0 0.7rem 0.3rem;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--barra-texto);
  opacity: 0.7;
}
.nl-separador {
  margin: 0 0.5rem 0.4rem;
  border: 0;
  border-top: 1px solid var(--barra-borde);
}
</style>
