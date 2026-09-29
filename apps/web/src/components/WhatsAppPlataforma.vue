<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { useToastStore } from "@/stores/toast";

/**
 * WhatsApp de la plataforma (ADR 0069), para el superadmin: encenderlo o apagarlo
 * para todos los negocios (cada mensaje cuesta; apagado, ningún negocio ve la opción
 * y sus avisos siguen por correo y push), el número y el token de la cuenta de
 * WhatsApp Business (el token no se vuelve a mostrar), una prueba y las plantillas
 * que hay que registrar en Meta.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

interface Plantilla {
  evento: string;
  nombre: string;
  titulo: string;
  texto: string;
  idioma: string;
  categoria: string;
}
interface Config {
  encendido: boolean;
  activo: boolean;
  phone_number_id: string;
  token_configurado: boolean;
  plantillas: Plantilla[];
}

const { t } = useI18n();
const toast = useToastStore();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});
const auth = (): { headers: Record<string, string> } => ({
  headers: { Authorization: `Bearer ${props.token}` },
});

const config = ref<Config | null>(null);
const borrador = ref({ encendido: false, phone_number_id: "", token: "" });
const telefono = ref("");
const ocupado = ref<"guardar" | "prueba" | null>(null);
const error = ref<string | null>(null);

const estado = computed<{ texto: string; tono: string }>(() => {
  if (!config.value?.encendido) {
    return { texto: t("plataformaAdmin.whatsapp.apagado"), tono: "wa-gris" };
  }
  return config.value.activo
    ? { texto: t("plataformaAdmin.whatsapp.encendido"), tono: "wa-exito" }
    : { texto: t("plataformaAdmin.whatsapp.incompleto"), tono: "wa-aviso" };
});

function aplicar(c: Config): void {
  config.value = c;
  borrador.value = {
    encendido: c.encendido,
    phone_number_id: c.phone_number_id,
    token: "",
  };
}

async function cargar(): Promise<void> {
  try {
    const { data } = await cliente.get<{ data: Config }>(
      "/api/v1/plataforma/whatsapp",
      auth(),
    );
    aplicar(data.data);
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function guardar(): Promise<void> {
  ocupado.value = "guardar";
  try {
    const { data } = await cliente.put<{ data: Config }>(
      "/api/v1/plataforma/whatsapp",
      {
        encendido: borrador.value.encendido,
        phone_number_id: borrador.value.phone_number_id.trim(),
        token: borrador.value.token.trim() || null,
      },
      auth(),
    );
    aplicar(data.data);
    toast.exito(t("plataformaAdmin.whatsapp.guardado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = null;
  }
}

async function probar(): Promise<void> {
  ocupado.value = "prueba";
  try {
    await cliente.post(
      "/api/v1/plataforma/whatsapp/prueba",
      { telefono: telefono.value.trim() },
      auth(),
    );
    toast.exito(t("plataformaAdmin.whatsapp.pruebaEnviada"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <div class="tu-card p-5">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h2 class="font-light text-lg">
          {{ $t("plataformaAdmin.whatsapp.titulo") }}
        </h2>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("plataformaAdmin.whatsapp.subtitulo") }}
        </p>
      </div>
      <span
        v-if="config"
        class="wa-estado text-sm shrink-0"
        data-prueba="estado"
      >
        <span class="wa-punto" :class="estado.tono"></span>
        {{ estado.texto }}
      </span>
    </div>

    <p
      v-if="error"
      class="mt-3 text-sm"
      role="alert"
      style="color: var(--error)"
    >
      {{ error }}
    </p>

    <form v-if="config" class="mt-4 space-y-3" @submit.prevent="guardar">
      <label class="flex items-center gap-2 text-sm">
        <input
          v-model="borrador.encendido"
          type="checkbox"
          data-prueba="encendido"
        />
        {{ $t("plataformaAdmin.whatsapp.enviar") }}
      </label>
      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="tu-label" for="wa-numero">{{
            $t("plataformaAdmin.whatsapp.numero")
          }}</label>
          <input
            id="wa-numero"
            v-model="borrador.phone_number_id"
            class="tu-input"
            inputmode="numeric"
            autocomplete="off"
          />
        </div>
        <div>
          <label class="tu-label" for="wa-token">{{
            $t("plataformaAdmin.whatsapp.token")
          }}</label>
          <input
            id="wa-token"
            v-model="borrador.token"
            class="tu-input"
            type="password"
            autocomplete="off"
            :placeholder="
              config.token_configurado
                ? $t('plataformaAdmin.whatsapp.tokenGuardado')
                : ''
            "
          />
        </div>
      </div>
      <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("plataformaAdmin.whatsapp.tokenAyuda") }}
      </p>
      <button
        class="tu-btn tu-btn-primario"
        type="submit"
        :disabled="ocupado === 'guardar'"
      >
        {{ $t("plataformaAdmin.whatsapp.guardar") }}
      </button>
    </form>

    <template v-if="config?.activo">
      <form
        class="mt-5 flex flex-col sm:flex-row gap-3 sm:items-end"
        data-prueba="prueba"
        @submit.prevent="probar"
      >
        <div class="flex-1">
          <label class="tu-label" for="wa-prueba">{{
            $t("plataformaAdmin.whatsapp.pruebaTelefono")
          }}</label>
          <input
            id="wa-prueba"
            v-model="telefono"
            class="tu-input"
            type="tel"
            autocomplete="off"
            placeholder="55 1234 5678"
            required
          />
        </div>
        <button
          class="tu-btn tu-btn-fantasma"
          type="submit"
          :disabled="ocupado === 'prueba'"
        >
          {{ $t("plataformaAdmin.whatsapp.prueba") }}
        </button>
      </form>
      <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("plataformaAdmin.whatsapp.pruebaAyuda") }}
      </p>
    </template>

    <details v-if="config" class="mt-5" data-prueba="plantillas">
      <summary class="text-sm font-medium cursor-pointer">
        {{
          $t("plataformaAdmin.whatsapp.plantillas", {
            n: config.plantillas.length,
          })
        }}
      </summary>
      <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("plataformaAdmin.whatsapp.plantillasAyuda") }}
      </p>
      <ul class="mt-2">
        <li v-for="p in config.plantillas" :key="p.nombre" class="wa-fila">
          <p class="text-sm font-medium">{{ p.titulo }}</p>
          <p class="mt-0.5 font-mono text-xs select-all">{{ p.nombre }}</p>
          <p
            class="mt-1 text-xs select-all"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ p.texto }}
          </p>
          <p class="mt-0.5 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("plataformaAdmin.whatsapp.categoria", { idioma: p.idioma }) }}
          </p>
        </li>
      </ul>
    </details>
  </div>
</template>

<style scoped>
.wa-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}
.wa-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  flex-shrink: 0;
}
.wa-gris {
  background: var(--texto-suave);
}
.wa-exito {
  background: var(--exito);
}
.wa-aviso {
  background: var(--aviso);
}
.wa-fila {
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.wa-fila:first-child {
  border-top: 0;
}
</style>
