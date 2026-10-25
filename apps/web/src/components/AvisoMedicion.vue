<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import { elegir, medicion, pideConsentimiento } from "@/lib/etiquetas";

/**
 * La barra de cookies de las páginas públicas (ADR 0143 y 0206), como la de Fitune: al
 * pie, a todo lo ancho, mientras no se haya elegido (en las del producto, al entrar; en
 * la de un negocio, solo si mide). Dice qué se usa y para qué, con un solo botón,
 * «Aceptar»; en «Cookies y configuración» se eligen o se rechazan la analítica y la
 * publicidad. Sin aceptar, solo funcionan las necesarias. El mismo enlace del pie de
 * página la vuelve a abrir (LFPDPPP: avisar al entrar y cómo deshabilitarlas).
 */
const { t } = useI18n();

const visible = computed(
  () =>
    pideConsentimiento() &&
    (medicion.preferencias === null || medicion.eligiendo),
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
    class="am-barra"
    aria-labelledby="am-titulo"
    data-prueba="aviso-medicion"
  >
    <div class="am-contenido">
      <div class="am-cuerpo">
        <h2 id="am-titulo" class="am-titulo">
          {{ t("medicion.aviso.titulo") }}
        </h2>
        <p class="am-texto">
          {{ texto }}
          <button
            type="button"
            class="am-enlace"
            data-prueba="am-elegir"
            :aria-expanded="detalle"
            @click="detalle = !detalle"
          >
            {{ t("medicion.aviso.elegir") }}</button
          >.
        </p>

        <div v-if="detalle" class="am-opciones">
          <label class="am-opcion">
            <input
              v-model="analitica"
              type="checkbox"
              data-prueba="am-analitica"
            />
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
          <p class="am-enlaces">
            <RouterLink
              :to="{ name: 'politica-cookies' }"
              class="am-enlace"
              data-prueba="am-politica"
              >{{ t("medicion.aviso.politicaCookies") }}</RouterLink
            >
            <RouterLink :to="enlaceAviso" class="am-enlace">{{
              t("medicion.aviso.avisoPrivacidad")
            }}</RouterLink>
          </p>
        </div>
      </div>

      <div class="am-acciones">
        <button
          v-if="detalle"
          type="button"
          class="tu-btn tu-btn-primario am-boton"
          data-prueba="am-guardar"
          @click="elegir({ analitica, publicidad })"
        >
          {{ t("medicion.aviso.guardar") }}
        </button>
        <button
          v-else
          type="button"
          class="tu-btn tu-btn-primario am-boton"
          data-prueba="am-aceptar"
          @click="elegir({ analitica: true, publicidad: true })"
        >
          {{ t("medicion.aviso.aceptar") }}
        </button>
        <button
          v-if="medicion.eligiendo"
          type="button"
          class="am-enlace am-cerrar"
          data-prueba="am-cerrar"
          @click="medicion.eligiendo = false"
        >
          {{ t("medicion.aviso.cerrar") }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* Oscura en los dos temas (como la de Fitune) y algo translúcida: se adivina la
   página detrás. */
.am-barra {
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: 60;
  /* Abierta en una pantalla baja, se desplaza dentro en lugar de salirse. */
  max-height: 85svh;
  overflow-y: auto;
  padding: 1.25rem 1.5rem calc(1.25rem + env(safe-area-inset-bottom));
  background: rgb(24 24 27 / 0.9);
  -webkit-backdrop-filter: blur(10px);
  backdrop-filter: blur(10px);
  color: #fff;
  box-shadow: 0 -8px 28px rgb(0 0 0 / 0.18);
}
.am-contenido {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem 2.5rem;
  max-width: 80rem;
  margin: 0 auto;
}
.am-cuerpo {
  flex: 1 1 32rem;
  min-width: 0;
}
.am-titulo {
  font-size: 0.875rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}
.am-texto {
  margin-top: 0.35rem;
  font-size: 0.875rem;
  line-height: 1.5;
}
.am-enlace {
  color: #fff;
  font-weight: 700;
  text-decoration: underline;
  text-underline-offset: 2px;
  cursor: pointer;
}
.am-enlace:focus-visible {
  outline: 2px solid #fff;
  outline-offset: 2px;
  border-radius: 2px;
}
.am-opciones {
  display: grid;
  gap: 0.75rem 2rem;
  margin-top: 1rem;
}
@media (min-width: 640px) {
  .am-opciones {
    grid-template-columns: 1fr 1fr;
  }
}
.am-opcion {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
  font-size: 0.85rem;
}
.am-opcion input {
  margin-top: 0.2rem;
  accent-color: var(--acento, #2563eb);
}
.am-opcion span {
  display: grid;
}
.am-opcion small,
.am-nota {
  color: rgb(255 255 255 / 0.72);
}
.am-nota,
.am-enlaces {
  grid-column: 1 / -1;
  font-size: 0.8rem;
}
.am-enlaces {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1.25rem;
}
.am-acciones {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  margin-left: auto;
}
.am-boton {
  min-width: 11rem;
  padding-block: 0.75rem;
  font-weight: 700;
}
.am-cerrar {
  font-size: 0.8rem;
  font-weight: 500;
}
@media (max-width: 639px) {
  .am-barra {
    padding-inline: 1rem;
  }
  .am-acciones {
    width: 100%;
  }
  .am-boton {
    width: 100%;
  }
}
</style>
