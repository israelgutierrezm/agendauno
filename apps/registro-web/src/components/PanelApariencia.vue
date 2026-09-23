<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import PanelLateral from "@/components/PanelLateral.vue";
import { mensajeDeError } from "@/lib/api";
import { useAparienciaStore } from "@/stores/apariencia";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useTemaStore } from "@/stores/tema";
import { useToastStore } from "@/stores/toast";

/**
 * Panel lateral de apariencia (al estilo de Acadion): elegir un tema, ajustar el
 * tamaño de letra y —si el tema lo permite— algunos colores propios. El tema y los
 * colores se guardan en la cuenta; el tamaño de letra, en este navegador.
 */
const props = defineProps<{ abierto: boolean }>();
const emit = defineEmits<{ cerrar: [] }>();

const { t } = useI18n();
const apariencia = useAparienciaStore();
const sesion = useSesionTenantStore();
const tema = useTemaStore();
const toast = useToastStore();

const base = computed(() => `/api/v1/app/${sesion.slug}`);
const guardando = ref(false);

// El catálogo se pide al abrir el panel (no hace falta en cada pantalla).
watch(
  () => props.abierto,
  async (abierto) => {
    if (abierto && apariencia.disponibles.length === 0) {
      try {
        await apariencia.cargarCatalogo(base.value);
      } catch (e) {
        toast.error(mensajeDeError(e, t("apariencia.error")));
      }
    }
  },
);

async function guardar(accion: () => Promise<void>): Promise<void> {
  guardando.value = true;
  try {
    await accion();
  } catch (e) {
    toast.error(mensajeDeError(e, t("apariencia.error")));
  } finally {
    guardando.value = false;
  }
}

function elegir(clave: string): void {
  if (clave !== apariencia.actual?.clave) {
    void guardar(() => apariencia.elegir(base.value, clave));
  }
}
function personalizar(token: string, evento: Event): void {
  const valor = (evento.target as HTMLInputElement).value;
  void guardar(() => apariencia.personalizar(base.value, token, valor));
}
function restablecer(): void {
  void guardar(() => apariencia.restablecer(base.value));
}
</script>

<template>
  <PanelLateral
    :abierto="abierto"
    :titulo="$t('apariencia.titulo')"
    @cerrar="emit('cerrar')"
  >
    <div class="space-y-7 p-5">
      <p class="-mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("apariencia.subtitulo") }}
      </p>

      <!-- Temas -->
      <section>
        <h3 class="pa-seccion">{{ $t("apariencia.tema") }}</h3>
        <div class="mt-3 space-y-2">
          <button
            v-for="opcion in apariencia.disponibles"
            :key="opcion.clave"
            type="button"
            class="pa-tema"
            :class="{ 'pa-activo': opcion.clave === apariencia.actual?.clave }"
            :disabled="guardando"
            :aria-pressed="opcion.clave === apariencia.actual?.clave"
            @click="elegir(opcion.clave)"
          >
            <!-- Miniatura: barra lateral, fondo, una tarjeta y el acento. -->
            <span
              class="pa-muestra"
              :style="{ background: opcion.muestra.fondo }"
              aria-hidden="true"
            >
              <span
                class="pa-muestra-barra"
                :style="{ background: opcion.muestra.barra }"
              ></span>
              <span class="pa-muestra-cuerpo">
                <span
                  class="pa-muestra-tarjeta"
                  :style="{ background: opcion.muestra.superficie }"
                ></span>
                <span
                  class="pa-muestra-acento"
                  :style="{ background: opcion.muestra.acento }"
                ></span>
              </span>
            </span>
            <span class="flex-1 min-w-0 text-left">
              <span class="block text-sm font-semibold">{{
                opcion.nombre
              }}</span>
              <span
                v-if="opcion.es_default || opcion.oscuro"
                class="text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  [
                    opcion.es_default ? $t("apariencia.predeterminado") : "",
                    opcion.oscuro ? $t("apariencia.oscuro") : "",
                  ]
                    .filter(Boolean)
                    .join(" · ")
                }}
              </span>
            </span>
            <svg
              v-if="opcion.clave === apariencia.actual?.clave"
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2.2"
              stroke-linecap="round"
              stroke-linejoin="round"
              :style="{ color: 'var(--acento)' }"
              aria-hidden="true"
            >
              <path d="M4.5 12.75l6 6 9-13.5" />
            </svg>
          </button>
        </div>
      </section>

      <!-- Tamaño de letra -->
      <section>
        <h3 class="pa-seccion">{{ $t("apariencia.letra") }}</h3>
        <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("apariencia.letraAyuda") }}
        </p>
        <div class="mt-3 flex items-center gap-2">
          <button
            type="button"
            class="pa-letra"
            :aria-label="$t('apariencia.achicar')"
            :disabled="tema.densidad === 'compacta'"
            @click="tema.ajustarDensidad(-1)"
          >
            <span class="text-xs font-semibold">A−</span>
          </button>
          <span class="w-24 text-center text-sm">{{
            $t(`apariencia.densidades.${tema.densidad}`)
          }}</span>
          <button
            type="button"
            class="pa-letra"
            :aria-label="$t('apariencia.agrandar')"
            :disabled="tema.densidad === 'comoda'"
            @click="tema.ajustarDensidad(1)"
          >
            <span class="text-base font-semibold">A+</span>
          </button>
        </div>
      </section>

      <!-- Ajustes propios -->
      <section v-if="apariencia.actual?.permite_personalizar">
        <h3 class="pa-seccion">{{ $t("apariencia.propios") }}</h3>
        <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("apariencia.propiosAyuda") }}
        </p>
        <div class="mt-3 space-y-3">
          <label
            v-for="token in apariencia.personalizables"
            :key="token"
            class="flex items-center justify-between gap-3 text-sm"
          >
            <span>{{ $t(`apariencia.tokens.${token}`) }}</span>
            <input
              type="color"
              class="pa-color"
              :value="apariencia.actual.tokens[token]"
              :disabled="guardando"
              @change="personalizar(token, $event)"
            />
          </label>
        </div>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma w-full mt-4 text-sm"
          :disabled="
            guardando ||
            Object.keys(apariencia.actual.personalizacion).length === 0
          "
          @click="restablecer"
        >
          {{ $t("apariencia.restablecer") }}
        </button>
      </section>
      <p
        v-else-if="apariencia.actual"
        class="rounded-lg p-3 text-xs"
        :style="{
          background: 'var(--superficie-2)',
          color: 'var(--texto-suave)',
        }"
      >
        {{ $t("apariencia.sinPropios") }}
      </p>
    </div>
  </PanelLateral>
</template>

<style scoped>
.pa-seccion {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--texto-suave);
}
.pa-tema {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.75rem;
  padding: 0.7rem;
  border-radius: 0.85rem;
  border: 1px solid var(--borde);
  background: var(--superficie);
  color: var(--texto);
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    transform 0.15s ease;
}
.pa-tema:hover {
  transform: scale(1.01);
}
.pa-activo {
  border-color: var(--acento);
  box-shadow: 0 0 0 1px var(--acento);
}
.pa-muestra {
  display: flex;
  width: 3.5rem;
  height: 2.5rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: 0.45rem;
  box-shadow: inset 0 0 0 1px rgb(0 0 0 / 12%);
}
.pa-muestra-barra {
  width: 32%;
  height: 100%;
  /* Una barra clara (blanca) necesita su orilla para distinguirse del fondo. */
  box-shadow: inset -1px 0 0 rgb(0 0 0 / 10%);
}
.pa-muestra-cuerpo {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 0.25rem;
}
.pa-muestra-tarjeta {
  height: 0.7rem;
  border-radius: 0.2rem;
  box-shadow: 0 0 0 1px rgb(0 0 0 / 8%);
}
.pa-muestra-acento {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 999px;
  align-self: flex-end;
}
.pa-letra {
  display: grid;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.6rem;
  border: 1px solid var(--borde);
  background: var(--superficie);
  color: var(--texto);
  cursor: pointer;
}
.pa-letra:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
.pa-color {
  width: 3.5rem;
  height: 2rem;
  border: none;
  background: transparent;
  cursor: pointer;
  padding: 0;
}
</style>
