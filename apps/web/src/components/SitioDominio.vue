<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import DominioPropioSitio from "@/components/DominioPropioSitio.vue";
import SeoPaginaSitio from "@/components/SeoPaginaSitio.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import type { SeoSitio } from "@/lib/sitioWeb";
import { urlPublicaEstudio } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * «Dominio y SEO» del sitio (ADR 0167): su dirección pública, cómo se publica la
 * página (en el directorio, solo con el enlace o cerrada), lo que ven buscadores y
 * redes de Inicio y el dominio propio (Plus, ADR 0189).
 *
 * Abrir o cerrar la página se aplica al momento (no es parte del borrador); el SEO sí
 * es del borrador y se publica con el sitio.
 */
type Visibilidad = "publica" | "enlace" | "cerrada";

const seo = defineModel<SeoSitio>("seo", { required: true });
const props = withDefaults(
  defineProps<{
    catalogo: { max_seo_titulo: number; max_seo_descripcion: number };
    errores: Record<string, string[]>;
    publicacion: { publicado: boolean; privado: boolean };
    paginas: number;
    conPlus: boolean;
    // Abrir o cerrar la página va en vivo: lo hace quien publica.
    puedePublicar?: boolean;
  }>(),
  { puedePublicar: true },
);
const emit = defineEmits<{
  publicacion: [valor: { publicado: boolean; privado: boolean }];
  irAPaginas: [];
}>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const direccion = computed(() => urlPublicaEstudio(sesion.slug ?? ""));
const copiado = ref(false);

function deLaPublicacion(p: {
  publicado: boolean;
  privado: boolean;
}): Visibilidad {
  return !p.publicado ? "cerrada" : p.privado ? "enlace" : "publica";
}
const visibilidad = ref<Visibilidad>(deLaPublicacion(props.publicacion));
watch(
  () => props.publicacion,
  (p) => (visibilidad.value = deLaPublicacion(p)),
);
const cambioVisibilidad = computed(
  () => visibilidad.value !== deLaPublicacion(props.publicacion),
);
const guardando = ref(false);
const errorVisibilidad = ref<string | null>(null);

async function guardarVisibilidad(): Promise<void> {
  if (guardando.value || !cambioVisibilidad.value) {
    return;
  }
  // Cerrarla deja a los clientes sin página ni reservas en línea: se confirma.
  if (
    visibilidad.value === "cerrada" &&
    !(await confirmar(t("confirmaciones.ocultarPagina"), {
      aceptar: t("confirmaciones.ocultar"),
      peligro: true,
    }))
  ) {
    return;
  }
  guardando.value = true;
  errorVisibilidad.value = null;
  try {
    const { data } = await api.put<{
      data: { publicado: boolean; privado: boolean; en_directorio: boolean };
    }>(`/api/v1/app/${sesion.slug}/publicacion`, {
      publicado: visibilidad.value !== "cerrada",
      privado: visibilidad.value === "enlace",
    });
    if (sesion.estudio) {
      sesion.estudio.publicado = data.data.publicado;
      sesion.estudio.en_directorio = data.data.en_directorio;
    }
    emit("publicacion", {
      publicado: data.data.publicado,
      privado: data.data.privado,
    });
  } catch (e) {
    errorVisibilidad.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

async function copiar(): Promise<void> {
  try {
    await navigator.clipboard.writeText(`https://${direccion.value}`);
    copiado.value = true;
    window.setTimeout(() => (copiado.value = false), 1800);
  } catch {
    // Sin portapapeles: se puede copiar el texto a mano.
  }
}
</script>

<template>
  <div class="space-y-6" data-prueba="sitio-dominio">
    <!-- Su dirección y cómo se publica -->
    <div class="tu-card p-5">
      <h2 class="text-lg font-medium">
        {{ $t("sitioWeb.dominio.direccion") }}
      </h2>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <a
          :href="`https://${direccion}`"
          target="_blank"
          rel="noopener"
          class="tu-enlace break-all"
          data-prueba="direccion-publica"
          >{{ direccion }}</a
        >
        <button type="button" class="tu-btn tu-btn-fantasma" @click="copiar">
          {{ copiado ? $t("enlace.copiado") : $t("enlace.copiar") }}
        </button>
      </div>

      <fieldset class="mt-5" :disabled="!puedePublicar">
        <legend class="tu-label">
          {{ $t("sitioWeb.dominio.comoSePublica") }}
        </legend>
        <div class="mt-2 space-y-2">
          <label
            v-for="v in ['publica', 'enlace', 'cerrada'] as const"
            :key="v"
            class="flex items-start gap-3 text-sm"
          >
            <input
              v-model="visibilidad"
              type="radio"
              name="visibilidad-sitio"
              :value="v"
              class="mt-1"
              :data-prueba="`visibilidad-${v}`"
            />
            <span>
              <span class="font-medium">{{
                $t(`configuracionInicial.publicacion.vis.${v}`)
              }}</span>
              <span class="block" :style="{ color: 'var(--texto-suave)' }">{{
                $t(`configuracionInicial.publicacion.vis.${v}Ayuda`)
              }}</span>
            </span>
          </label>
        </div>
      </fieldset>
      <p class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("sitioWeb.dominio.alMomento") }}
      </p>
      <p
        v-if="errorVisibilidad"
        class="tu-error-campo mt-2"
        role="alert"
        data-prueba="error-visibilidad"
      >
        {{ errorVisibilidad }}
      </p>
      <div v-if="puedePublicar" class="mt-3 flex justify-end">
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          :disabled="!cambioVisibilidad || guardando"
          data-prueba="guardar-visibilidad"
          @click="guardarVisibilidad"
        >
          {{ $t("sitioWeb.dominio.guardarVisibilidad") }}
        </button>
      </div>
    </div>

    <!-- Lo que ven buscadores y redes de Inicio (borrador: se publica con el sitio) -->
    <div class="tu-card p-5" data-prueba="seo-sitio">
      <h2 class="text-lg font-medium">{{ $t("sitioWeb.dominio.seo") }}</h2>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("sitioWeb.dominio.seoAyuda") }}
      </p>
      <div class="mt-4">
        <SeoPaginaSitio
          v-model="seo"
          :catalogo="catalogo"
          prefijo="seo"
          :errores="errores"
          :placeholder-titulo="sesion.estudio?.nombre ?? ''"
        />
      </div>
      <p v-if="paginas > 0" class="mt-3 text-sm">
        {{ $t("sitioWeb.dominio.seoPaginas", { n: paginas }) }}
        <button type="button" class="tu-enlace" @click="emit('irAPaginas')">
          {{ $t("sitioWeb.dominio.irPaginas") }}
        </button>
      </p>
    </div>

    <!-- Dominio propio (Plus, ADR 0189) -->
    <DominioPropioSitio :puede-publicar="puedePublicar" />
  </div>
</template>
