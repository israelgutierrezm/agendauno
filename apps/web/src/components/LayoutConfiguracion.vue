<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import {
  CATEGORIAS_CONFIGURACION,
  destinoDe,
  coincideBusqueda,
  normalizar,
  ubicacion,
  vistasVisibles,
  type CategoriaConfiguracion,
  type OpcionConfiguracion,
} from "@/lib/menu";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Configuración del negocio, tercer nivel: a la izquierda las categorías (una
 * abierta a la vez, la de la pantalla actual al llegar) con sus opciones y «Buscar
 * ajuste»; arriba la ruta de ubicación. Solo categorías y opciones permitidas.
 *
 * Con poco ancho (menos de 1280 px, con el menú principal al lado no cabe un
 * formulario cómodo), la navegación se abre con «Secciones de configuración» en un
 * panel dentro de la página, que no se encima con el menú principal.
 */
const { t } = useI18n();
const route = useRoute();
const sesion = useSesionTenantStore();

type CategoriaVisible = CategoriaConfiguracion & {
  opciones: OpcionConfiguracion[];
};

const categorias = computed<CategoriaVisible[]>(() =>
  CATEGORIAS_CONFIGURACION.map((c) => ({
    ...c,
    opciones: vistasVisibles(
      {
        clave: c.clave,
        etiqueta: c.etiqueta,
        icono: c.icono,
        vistas: c.opciones,
      },
      sesion,
    ) as OpcionConfiguracion[],
  })).filter((c) => c.opciones.length > 0),
);

const actual = computed(() => ubicacion(route));
const abierta = ref<string | null>(actual.value?.categoria?.clave ?? null);
watch(
  () => actual.value?.categoria?.clave,
  (clave) => {
    if (clave) {
      abierta.value = clave;
    }
  },
);
function alternar(clave: string): void {
  abierta.value = abierta.value === clave ? null : clave;
}

// «Buscar ajuste»: nombre de la opción, su categoría o sus sinónimos.
const busqueda = ref("");
const resultados = computed(() => {
  const q = normalizar(busqueda.value.trim());
  if (q === "") {
    return [];
  }
  return categorias.value.flatMap((c) =>
    c.opciones
      .filter((op) =>
        [t(op.etiqueta), t(c.etiqueta), ...op.sinonimos].some((texto) =>
          coincideBusqueda(texto, q),
        ),
      )
      .map((op) => ({ categoria: c, opcion: op })),
  );
});

// Panel con poco ancho: «Secciones de configuración».
const panelAbierto = ref(false);
const botonSecciones = ref<HTMLButtonElement | null>(null);
function cerrarPanel(): void {
  if (panelAbierto.value) {
    panelAbierto.value = false;
    void nextTick(() => botonSecciones.value?.focus());
  }
}
function alNavegar(): void {
  busqueda.value = "";
  cerrarPanel();
}

const esActiva = (op: OpcionConfiguracion): boolean =>
  actual.value?.vista.clave === op.clave;
</script>

<template>
  <div class="lc">
    <!-- Navegación secundaria (pantalla ancha) -->
    <aside class="lc-lateral" :aria-label="t('configNegocio.secciones')">
      <div class="lc-nav">
        <label class="tu-campo-icono lc-buscar">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="busqueda"
            type="search"
            class="tu-input"
            :placeholder="t('configNegocio.buscar')"
            :aria-label="t('configNegocio.buscar')"
          />
        </label>
        <template v-if="busqueda.trim() !== ''">
          <p v-if="resultados.length === 0" class="lc-sin">
            {{ t("configNegocio.sinResultados") }}
          </p>
          <RouterLink
            v-for="r in resultados"
            :key="r.opcion.clave"
            :to="destinoDe(r.opcion)"
            class="lc-resultado"
            @click="alNavegar"
          >
            <span class="block font-medium">{{ t(r.opcion.etiqueta) }}</span>
            <span class="block text-xs lc-suave">{{
              t(r.categoria.etiqueta)
            }}</span>
          </RouterLink>
        </template>
        <ul v-else class="lc-categorias">
          <li v-for="c in categorias" :key="c.clave">
            <button
              type="button"
              class="lc-categoria"
              :aria-expanded="abierta === c.clave"
              @click="alternar(c.clave)"
            >
              <IconoNav :nombre="c.icono" :tam="18" class="shrink-0" />
              <span class="flex-1 text-left">{{ t(c.etiqueta) }}</span>
              <IconoNav
                nombre="chevron"
                :tam="14"
                class="shrink-0 transition-transform"
                :class="{ 'rotate-90': abierta === c.clave }"
              />
            </button>
            <ul v-if="abierta === c.clave" class="lc-opciones">
              <li v-for="op in c.opciones" :key="op.clave">
                <RouterLink
                  :to="destinoDe(op)"
                  class="lc-opcion"
                  active-class=""
                  exact-active-class=""
                  :class="{ 'lc-opcion-activa': esActiva(op) }"
                  :aria-current="esActiva(op) ? 'page' : undefined"
                  @click="alNavegar"
                  >{{ t(op.etiqueta) }}</RouterLink
                >
              </li>
            </ul>
          </li>
        </ul>
      </div>
    </aside>

    <div class="lc-contenido">
      <!-- Ruta de ubicación: solo los tramos con destino son enlaces. -->
      <nav
        class="lc-ubicacion"
        :aria-label="t('configNegocio.ubicacion')"
        data-prueba="ubicacion-config"
      >
        <RouterLink :to="{ name: 'ajustes' }" class="lc-miga">{{
          t("nav.areas.configuracion")
        }}</RouterLink>
        <template v-if="actual?.categoria">
          <span aria-hidden="true">/</span>
          <span>{{ t(actual.categoria.etiqueta) }}</span>
          <span aria-hidden="true">/</span>
          <span aria-current="page" class="lc-miga-actual">{{
            t(actual.vista.etiqueta)
          }}</span>
        </template>
      </nav>

      <!-- Con poco ancho: las secciones en un panel dentro de la página -->
      <button
        ref="botonSecciones"
        type="button"
        class="tu-btn tu-btn-fantasma lc-boton"
        :aria-expanded="panelAbierto"
        aria-controls="lc-panel"
        @click="panelAbierto = !panelAbierto"
      >
        <IconoNav nombre="lista" :tam="18" />
        {{ t("configNegocio.secciones") }}
      </button>
      <div
        v-if="panelAbierto"
        id="lc-panel"
        class="lc-panel tu-card"
        @keydown.esc="cerrarPanel"
      >
        <label class="tu-campo-icono lc-buscar">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="busqueda"
            type="search"
            class="tu-input"
            :placeholder="t('configNegocio.buscar')"
            :aria-label="t('configNegocio.buscar')"
          />
        </label>
        <template v-if="busqueda.trim() !== ''">
          <p v-if="resultados.length === 0" class="lc-sin">
            {{ t("configNegocio.sinResultados") }}
          </p>
          <RouterLink
            v-for="r in resultados"
            :key="r.opcion.clave"
            :to="destinoDe(r.opcion)"
            class="lc-resultado"
            @click="alNavegar"
          >
            <span class="block font-medium">{{ t(r.opcion.etiqueta) }}</span>
            <span class="block text-xs lc-suave">{{
              t(r.categoria.etiqueta)
            }}</span>
          </RouterLink>
        </template>
        <div v-else class="lc-panel-rejilla">
          <div v-for="c in categorias" :key="c.clave">
            <p class="lc-panel-titulo">
              <IconoNav :nombre="c.icono" :tam="16" />
              {{ t(c.etiqueta) }}
            </p>
            <RouterLink
              v-for="op in c.opciones"
              :key="op.clave"
              :to="destinoDe(op)"
              class="lc-opcion"
              active-class=""
              exact-active-class=""
              :class="{ 'lc-opcion-activa': esActiva(op) }"
              :aria-current="esActiva(op) ? 'page' : undefined"
              @click="alNavegar"
              >{{ t(op.etiqueta) }}</RouterLink
            >
          </div>
        </div>
      </div>

      <slot />
    </div>
  </div>
</template>

<style scoped>
.lc {
  display: flex;
  align-items: flex-start;
  gap: 1.5rem;
  padding: 1.25rem 1rem 0;
}
.lc-lateral {
  display: none;
}
.lc-contenido {
  flex: 1;
  min-width: 0;
}
.lc-ubicacion {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  padding: 0 1rem;
  font-size: 0.82rem;
  color: var(--texto-suave);
}
.lc-miga {
  color: var(--texto-suave);
  text-decoration: none;
}
.lc-miga:hover {
  color: var(--texto);
  text-decoration: underline;
}
.lc-miga-actual {
  color: var(--texto);
  font-weight: 500;
}
.lc-boton {
  margin: 0.75rem 1rem 0;
}
.lc-panel {
  margin: 0.75rem 1rem 0;
  padding: 1rem;
}
.lc-panel-rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
  gap: 1rem;
}
.lc-panel-titulo {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.25rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--texto-suave);
}
/* Con ancho de sobra: la navegación como columna fija a la izquierda. */
@media (min-width: 1280px) {
  .lc {
    padding: 1.25rem 1.5rem 0;
  }
  .lc-lateral {
    display: block;
    position: sticky;
    top: 5rem;
    width: 15.5rem;
    flex-shrink: 0;
  }
  .lc-boton,
  .lc-panel {
    display: none;
  }
}
.lc-nav {
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
  padding: 0.75rem;
}
.lc-buscar {
  display: flex;
  width: 100%;
  margin-bottom: 0.5rem;
}
.lc-buscar input {
  width: 100%;
}
.lc-categorias > li + li {
  border-top: 1px solid var(--borde);
}
.lc-categoria {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.7rem 0.4rem;
  border: 0;
  background: transparent;
  color: var(--texto);
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
}
.lc-categoria[aria-expanded="true"] {
  font-weight: 700;
}
.lc-opciones {
  padding: 0 0 0.5rem;
}
.lc-opcion {
  display: block;
  padding: 0.45rem 0.75rem 0.45rem 2.1rem;
  border-left: 3px solid transparent;
  border-radius: 0 0.5rem 0.5rem 0;
  color: var(--texto-suave);
  font-size: 0.88rem;
  text-decoration: none;
}
.lc-panel .lc-opcion {
  padding-left: 0.75rem;
}
.lc-opcion:hover {
  color: var(--texto);
  background: var(--superficie-2);
}
.lc-opcion-activa {
  border-left-color: var(--primario);
  background: var(--primario-suave);
  color: var(--primario);
  font-weight: 600;
}
.lc-resultado {
  display: block;
  padding: 0.5rem 0.6rem;
  border-radius: 0.5rem;
  color: var(--texto);
  text-decoration: none;
}
.lc-resultado:hover {
  background: var(--superficie-2);
}
.lc-sin,
.lc-suave {
  color: var(--texto-suave);
}
.lc-sin {
  padding: 0.5rem 0.6rem;
  font-size: 0.85rem;
}
</style>
