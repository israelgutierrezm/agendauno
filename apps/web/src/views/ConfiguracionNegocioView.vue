<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import {
  CATEGORIAS_CONFIGURACION,
  destinoDe,
  coincideBusqueda,
  normalizar,
  vistasVisibles,
  type OpcionConfiguracion,
} from "@/lib/menu";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Portada de Configuración del negocio: cada categoría con una frase de qué permite
 * configurar y sus opciones (solo las permitidas; una categoría sin opciones no se
 * muestra). «Buscar ajuste» encuentra por nombre, categoría o sinónimos (p. ej.
 * «logo», «conectar pagos», «roles»).
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();

const TONOS: Record<string, string> = {
  negocio: "azul",
  servicios: "verde",
  agenda: "morado",
  pagos: "naranja",
  accesos: "cielo",
  documentos: "rosa",
};

const categorias = computed(() =>
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
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="t('nav.areas.configuracion')"
      :subtitulo="t('configNegocio.subtitulo')"
    />

    <label class="tu-campo-icono cn-buscar mt-6">
      <IconoNav nombre="buscar" :tam="18" />
      <input
        v-model="busqueda"
        type="search"
        class="tu-input"
        :placeholder="t('configNegocio.buscar')"
        :aria-label="t('configNegocio.buscar')"
        data-prueba="buscar-ajuste"
      />
    </label>

    <!-- Resultados de la búsqueda, con su ubicación -->
    <div v-if="busqueda.trim() !== ''" class="mt-4">
      <EstadoVacio
        v-if="resultados.length === 0"
        class="tu-card"
        icono="buscar"
        :titulo="t('configNegocio.sinResultados')"
      />
      <ul v-else class="tu-card cn-resultados">
        <li v-for="r in resultados" :key="r.opcion.clave">
          <RouterLink :to="destinoDe(r.opcion)" class="cn-resultado">
            <span
              class="tu-icono-tono"
              :class="`tu-tono-${TONOS[r.categoria.clave]}`"
            >
              <IconoNav :nombre="r.categoria.icono" :tam="18" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ t(r.opcion.etiqueta) }}</span>
              <span class="block text-xs cn-suave">{{
                t(r.categoria.etiqueta)
              }}</span>
            </span>
            <IconoNav nombre="chevron" :tam="16" class="cn-suave" />
          </RouterLink>
        </li>
      </ul>
    </div>

    <!-- Las categorías -->
    <ul v-else class="cn-rejilla mt-6" data-prueba="categorias-config">
      <li
        v-for="c in categorias"
        :key="c.clave"
        class="tu-card cn-categoria"
        :class="`tu-tono-${TONOS[c.clave]}`"
      >
        <div class="flex items-start gap-3">
          <span class="tu-icono-tono cn-icono">
            <IconoNav :nombre="c.icono" :tam="22" />
          </span>
          <div class="min-w-0">
            <h2 class="text-base font-semibold">{{ t(c.etiqueta) }}</h2>
            <p class="mt-0.5 text-sm cn-suave">{{ t(c.descripcion) }}</p>
          </div>
        </div>
        <ul class="cn-opciones">
          <li v-for="op in c.opciones" :key="op.clave">
            <RouterLink :to="destinoDe(op)" class="cn-opcion">
              <span>{{ t(op.etiqueta) }}</span>
              <IconoNav nombre="chevron" :tam="14" />
            </RouterLink>
          </li>
        </ul>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.cn-buscar {
  display: flex;
  max-width: 28rem;
}
.cn-buscar input {
  width: 100%;
}
.cn-suave {
  color: var(--texto-suave);
}
.cn-rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 19rem), 1fr));
  gap: 1rem;
}
.cn-categoria {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  padding: 1.15rem 1.25rem;
}
.cn-icono {
  width: 2.75rem;
  height: 2.75rem;
}
.cn-opciones {
  border-top: 1px solid var(--borde);
  padding-top: 0.4rem;
}
.cn-opcion {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.45rem 0.25rem;
  border-radius: 0.5rem;
  color: var(--texto);
  font-size: 0.9rem;
  text-decoration: none;
}
.cn-opcion:hover {
  color: var(--tono);
  background: color-mix(in srgb, var(--tono) 8%, var(--superficie));
}
.cn-opcion svg {
  color: var(--texto-suave);
}
.cn-resultados > li + li {
  border-top: 1px solid var(--borde);
}
.cn-resultado {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  color: var(--texto);
  text-decoration: none;
}
.cn-resultado:hover {
  background: var(--superficie-2);
}
</style>
