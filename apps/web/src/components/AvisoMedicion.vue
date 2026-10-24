<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import { elegir, hayDestinos, medicion } from "@/lib/etiquetas";

/**
 * El aviso de cookies de las páginas públicas (ADR 0143 y 0206), mientras no se haya
 * elegido: en las del producto, al entrar (con su política de cookies); en la de un
 * negocio, solo si mide con sus destinos. Aceptar todas, solo las necesarias o
 * configurar analítica y publicidad por separado. Después queda el botón «Cookies»
 * para cambiarlo cuando se quiera.
 */
const { t } = useI18n();

const pide = computed(
  () =>
    !medicion.pausada &&
    medicion.ambito !== null &&
    (medicion.ambito.startsWith("plataforma:") ||
      hayDestinos(medicion.destinos)),
);
const visible = computed(
  () => pide.value && (medicion.preferencias === null || medicion.eligiendo),
);
const conBoton = computed(
  () => pide.value && medicion.preferencias !== null && !medicion.eligiendo,
);
const detalle = ref(false);
const analitica = ref(false);
const publicidad = ref(false);
watch(
  () => [medicion.eligiendo, medicion.preferencias] as const,
  () => {
    detalle.value = medicion.eligiendo;
    analitica.value = medicion.preferencias?.analitica ?? false;
    publicidad.value = medicion.preferencias?.publicidad ?? false;
  },
  { immediate: true },
);

const slug = computed(() =>
  medicion.ambito?.startsWith("negocio:")
    ? medicion.ambito.slice("negocio:".length)
    : null,
);
const texto = computed(() =>
  slug.value !== null
    ? t("medicion.aviso.textoNegocio", {
        negocio: t("medicion.aviso.esteNegocio"),
      })
    : t("medicion.aviso.textoPlataforma"),
);
const enlaceAviso = computed(() =>
  slug.value !== null
    ? { name: "aviso-negocio", params: { slug: slug.value } }
    : { name: "aviso-privacidad" },
);
</script>

<template>
  <section
    v-if="visible"
    class="am-aviso"
    role="dialog"
    aria-labelledby="am-titulo"
    data-prueba="aviso-medicion"
  >
    <h2 id="am-titulo" class="am-titulo">{{ t("medicion.aviso.titulo") }}</h2>
    <p class="am-texto">{{ texto }}</p>

    <div v-if="detalle" class="am-opciones">
      <label class="am-opcion">
        <input v-model="analitica" type="checkbox" data-prueba="am-analitica" />
        <span>
          <strong>{{ t("medicion.aviso.analitica") }}</strong>
          <small>{{ t("medicion.aviso.analiticaTexto") }}</small>
        </span>
      </label>
      <label class="am-opcion">
        <input
          v-model="publicidad"
          type="checkbox"
          data-prueba="am-publicidad"
        />
        <span>
          <strong>{{ t("medicion.aviso.publicidad") }}</strong>
          <small>{{ t("medicion.aviso.publicidadTexto") }}</small>
        </span>
      </label>
      <p class="am-nota">{{ t("medicion.aviso.necesarias") }}</p>
    </div>

    <div class="am-acciones">
      <template v-if="detalle">
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          data-prueba="am-guardar"
          @click="elegir({ analitica, publicidad })"
        >
          {{ t("medicion.aviso.guardar") }}
        </button>
      </template>
      <template v-else>
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          data-prueba="am-aceptar"
          @click="elegir({ analitica: true, publicidad: true })"
        >
          {{ t("medicion.aviso.aceptar") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          data-prueba="am-rechazar"
          @click="elegir({ analitica: false, publicidad: false })"
        >
          {{ t("medicion.aviso.rechazar") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          data-prueba="am-elegir"
          @click="detalle = true"
        >
          {{ t("medicion.aviso.elegir") }}
        </button>
      </template>
    </div>
    <div class="am-pie">
      <span class="am-enlaces">
        <RouterLink
          :to="{ name: 'politica-cookies' }"
          class="tu-enlace"
          data-prueba="am-politica"
          >{{ t("medicion.aviso.politicaCookies") }}</RouterLink
        >
        <RouterLink :to="enlaceAviso" class="tu-enlace">{{
          t("medicion.aviso.avisoPrivacidad")
        }}</RouterLink>
      </span>
      <button
        v-if="medicion.eligiendo"
        type="button"
        class="tu-enlace"
        @click="medicion.eligiendo = false"
      >
        {{ t("medicion.aviso.cerrar") }}
      </button>
    </div>
  </section>

  <button
    v-else-if="conBoton"
    type="button"
    class="am-privacidad"
    data-prueba="am-privacidad"
    @click="medicion.eligiendo = true"
  >
    {{ t("medicion.aviso.privacidad") }}
  </button>
</template>

<style scoped>
.am-aviso {
  position: fixed;
  inset-inline: 1rem;
  bottom: 1rem;
  z-index: 60;
  max-width: 26rem;
  padding: 1rem 1.1rem;
  border: 1px solid var(--borde);
  border-radius: 0.9rem;
  background: var(--superficie);
  color: var(--texto);
  box-shadow: 0 10px 30px rgb(0 0 0 / 0.12);
}
@media (min-width: 640px) {
  .am-aviso {
    inset-inline: auto 1.25rem;
    bottom: 1.25rem;
  }
}
.am-titulo {
  font-size: 0.95rem;
  font-weight: 600;
}
.am-texto,
.am-nota {
  margin-top: 0.35rem;
  font-size: 0.85rem;
  line-height: 1.45;
  color: var(--texto-suave);
}
.am-opciones {
  display: grid;
  gap: 0.6rem;
  margin-top: 0.75rem;
}
.am-opcion {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
  font-size: 0.85rem;
}
.am-opcion input {
  margin-top: 0.2rem;
}
.am-opcion span {
  display: grid;
}
.am-opcion small {
  color: var(--texto-suave);
}
.am-acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.85rem;
}
.am-pie {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: 0.6rem;
  font-size: 0.8rem;
}
.am-enlaces {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.9rem;
}
.am-privacidad {
  position: fixed;
  left: 1rem;
  bottom: 1rem;
  z-index: 40;
  padding: 0.3rem 0.7rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  background: var(--superficie);
  color: var(--texto-suave);
  font-size: 0.75rem;
}
</style>
