<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import CargadorPortada from "@/components/CargadorPortada.vue";
import { api, mensajeDeError } from "@/lib/api";
import { urlPublicaEstudio } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Perfil público del negocio (Configuración): portada, descripción, redes y sitio
 * web, y los enlaces para compartir (su página y su página de enlaces para la bio de
 * Instagram). Lo guarda `PUT /perfil-publico`; la portada se sube aparte.
 */
withDefaults(defineProps<{ puedeGestionar?: boolean }>(), {
  puedeGestionar: true,
});

const REDES = [
  "instagram",
  "facebook",
  "tiktok",
  "youtube",
  "sitio_web",
] as const;
type Red = (typeof REDES)[number];

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const descripcion = ref("");
const redes = ref<Record<Red, string>>({
  instagram: "",
  facebook: "",
  tiktok: "",
  youtube: "",
  sitio_web: "",
});
const portadaUrl = ref<string | null>(null);
const cargando = ref(true);
const guardando = ref(false);
const guardado = ref(false);
const error = ref<string | null>(null);
const errores = ref<Record<string, string>>({});
const copiado = ref<string | null>(null);

const enlaces = computed(() => {
  const dominio = `https://${urlPublicaEstudio(sesion.slug ?? "")}`;
  return { pagina: dominio, enlaces: `${dominio}/enlaces` };
});

function aplicar(datos: {
  descripcion: string | null;
  portada_url: string | null;
  redes: Partial<Record<Red, string>>;
}): void {
  descripcion.value = datos.descripcion ?? "";
  portadaUrl.value = datos.portada_url;
  for (const r of REDES) {
    redes.value[r] = datos.redes[r] ?? "";
  }
}

async function cargar(): Promise<void> {
  cargando.value = true;
  try {
    const { data } = await api.get(`${base.value}/perfil-publico`);
    aplicar(data.data);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function guardar(): Promise<void> {
  guardando.value = true;
  guardado.value = false;
  error.value = null;
  errores.value = {};
  try {
    const { data } = await api.put(`${base.value}/perfil-publico`, {
      descripcion: descripcion.value,
      redes: redes.value,
    });
    aplicar(data.data);
    guardado.value = true;
  } catch (e) {
    const detalle = (
      e as {
        response?: { data?: { meta?: { errors?: Record<string, string[]> } } };
      }
    ).response?.data?.meta?.errors;
    if (detalle) {
      errores.value = Object.fromEntries(
        Object.entries(detalle).map(([k, v]) => [k, v[0] ?? ""]),
      );
    }
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

async function copiar(clave: "pagina" | "enlaces"): Promise<void> {
  try {
    await navigator.clipboard.writeText(enlaces.value[clave]);
    copiado.value = clave;
  } catch {
    copiado.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <div class="tu-card p-6">
    <h2 class="font-light text-lg">{{ $t("perfilPublico.config.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("perfilPublico.config.ayuda") }}
    </p>

    <p
      v-if="cargando"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <form v-else class="mt-5 space-y-5" @submit.prevent="guardar">
      <div>
        <span class="tu-label">{{ $t("perfilPublico.config.portada") }}</span>
        <CargadorPortada
          :portada-url="portadaUrl"
          :puede-gestionar="puedeGestionar"
          @update:portada-url="portadaUrl = $event"
        />
      </div>

      <div>
        <label class="tu-label" for="pp-descripcion">{{
          $t("perfilPublico.config.descripcion")
        }}</label>
        <textarea
          id="pp-descripcion"
          v-model="descripcion"
          class="tu-input"
          rows="4"
          maxlength="1500"
          :disabled="!puedeGestionar"
          :placeholder="$t('perfilPublico.config.descripcionPh')"
        />
      </div>

      <fieldset>
        <legend class="tu-label">{{ $t("perfilPublico.config.redes") }}</legend>
        <p class="-mt-1 mb-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("perfilPublico.config.redesAyuda") }}
        </p>
        <div class="grid gap-3 sm:grid-cols-2">
          <label v-for="r in REDES" :key="r" class="block">
            <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`perfilPublico.redes.${r}`) }}
            </span>
            <input
              v-model="redes[r]"
              class="tu-input"
              :data-prueba="`red-${r}`"
              :disabled="!puedeGestionar"
              :placeholder="$t(`perfilPublico.redesPh.${r}`)"
            />
            <span
              v-if="errores[`redes.${r}`]"
              class="text-xs"
              style="color: var(--error)"
            >
              {{ errores[`redes.${r}`] }}
            </span>
          </label>
        </div>
      </fieldset>

      <p
        v-if="error && Object.keys(errores).length === 0"
        class="text-sm"
        style="color: var(--error)"
      >
        {{ error }}
      </p>
      <div class="flex flex-wrap items-center gap-3">
        <button
          v-if="puedeGestionar"
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="guardando"
        >
          {{
            guardando
              ? $t("perfilPublico.config.guardando")
              : $t("perfilPublico.config.guardar")
          }}
        </button>
        <span
          v-if="guardado"
          class="text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ $t("perfilPublico.config.guardado") }}
        </span>
      </div>

      <div
        class="border-t pt-4 space-y-3"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <div v-for="clave in ['pagina', 'enlaces'] as const" :key="clave">
          <span class="tu-label">{{
            clave === "pagina"
              ? $t("perfilPublico.config.paginaPublica")
              : $t("perfilPublico.config.paginaEnlaces")
          }}</span>
          <div class="flex gap-2">
            <input class="tu-input" :value="enlaces[clave]" readonly />
            <button
              type="button"
              class="tu-btn tu-btn-fantasma shrink-0"
              @click="copiar(clave)"
            >
              {{
                copiado === clave
                  ? $t("perfilPublico.config.copiado")
                  : $t("perfilPublico.config.copiar")
              }}
            </button>
          </div>
        </div>
      </div>
    </form>
  </div>
</template>
