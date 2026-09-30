<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { useToastStore } from "@/stores/toast";

/**
 * WhatsApp de la plataforma, para el superadmin: la conexión con Meta (número y
 * token; el token no se vuelve a mostrar), una prueba y dos usos que se encienden por
 * separado porque cada mensaje cuesta:
 * - con los dueños: verifican su número al registrarse y aceptan avisos (ADR 0070);
 * - de los negocios a sus clientes (ADR 0069); apagado, ningún negocio lo ve.
 * Debajo de cada uso, las plantillas que hay que registrar en Meta.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

interface Plantilla {
  evento: string;
  nombre: string;
  titulo: string;
  texto: string;
  idioma: string;
  categoria: "UTILITY" | "AUTHENTICATION";
}
type Uso = "duenos" | "negocios";
interface Config {
  negocios: boolean;
  duenos: boolean;
  conectado: boolean;
  phone_number_id: string;
  token_configurado: boolean;
  plantillas: Record<Uso, Plantilla[]>;
  // Webhook de estados de entrega (ADR 0074): lo que se carga en Meta.
  webhook?: {
    url: string;
    token_verificacion: string;
    app_secret_configurado: boolean;
  };
}

const USOS: Uso[] = ["duenos", "negocios"];

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
const borrador = ref({
  duenos: false,
  negocios: false,
  phone_number_id: "",
  token: "",
  app_secret: "",
});
const telefono = ref("");
const ocupado = ref<"guardar" | "prueba" | null>(null);
const error = ref<string | null>(null);

const conexion = computed<{ texto: string; tono: string }>(() =>
  config.value?.conectado
    ? { texto: t("plataformaAdmin.whatsapp.conectado"), tono: "wa-exito" }
    : { texto: t("plataformaAdmin.whatsapp.sinConectar"), tono: "wa-gris" },
);

function aplicar(c: Config): void {
  config.value = c;
  borrador.value = {
    duenos: c.duenos,
    negocios: c.negocios,
    phone_number_id: c.phone_number_id,
    token: "",
    app_secret: "",
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
        duenos: borrador.value.duenos,
        negocios: borrador.value.negocios,
        phone_number_id: borrador.value.phone_number_id.trim(),
        token: borrador.value.token.trim() || null,
        app_secret: borrador.value.app_secret.trim() || null,
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
        <span class="wa-punto" :class="conexion.tono"></span>
        {{ conexion.texto }}
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

    <form v-if="config" class="mt-4 space-y-4" @submit.prevent="guardar">
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
      <p class="-mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("plataformaAdmin.whatsapp.tokenAyuda") }}
      </p>

      <!-- Webhook de estados: entregado, leído o fallido (ADR 0074). -->
      <section v-if="config.webhook" class="wa-uso" data-prueba="webhook">
        <h3 class="text-sm font-medium">
          {{ $t("plataformaAdmin.whatsapp.webhook") }}
        </h3>
        <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("plataformaAdmin.whatsapp.webhookAyuda") }}
        </p>
        <div class="mt-2 grid gap-3 sm:grid-cols-2">
          <div>
            <label class="tu-label" for="wa-webhook-url">{{
              $t("plataformaAdmin.whatsapp.webhookUrl")
            }}</label>
            <input
              id="wa-webhook-url"
              class="tu-input"
              :value="config.webhook.url"
              readonly
              @focus="($event.target as HTMLInputElement).select()"
            />
          </div>
          <div>
            <label class="tu-label" for="wa-webhook-token">{{
              $t("plataformaAdmin.whatsapp.webhookToken")
            }}</label>
            <input
              id="wa-webhook-token"
              class="tu-input font-mono"
              :value="config.webhook.token_verificacion"
              readonly
              @focus="($event.target as HTMLInputElement).select()"
            />
          </div>
          <div>
            <label class="tu-label" for="wa-app-secret">{{
              $t("plataformaAdmin.whatsapp.appSecret")
            }}</label>
            <input
              id="wa-app-secret"
              v-model="borrador.app_secret"
              class="tu-input"
              type="password"
              autocomplete="off"
              :placeholder="
                config.webhook.app_secret_configurado
                  ? $t('plataformaAdmin.whatsapp.tokenGuardado')
                  : ''
              "
            />
          </div>
        </div>
      </section>

      <!-- Cada uso se enciende aparte; debajo, lo que hay que registrar en Meta. -->
      <section
        v-for="uso in USOS"
        :key="uso"
        class="wa-uso"
        :data-prueba="`uso-${uso}`"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="text-sm font-medium">
              {{ $t(`plataformaAdmin.whatsapp.${uso}`) }}
            </h3>
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`plataformaAdmin.whatsapp.${uso}Ayuda`) }}
            </p>
          </div>
          <span class="wa-estado text-sm shrink-0">
            <span
              class="wa-punto"
              :class="config[uso] && config.conectado ? 'wa-exito' : 'wa-gris'"
            ></span>
            {{
              config[uso] && config.conectado
                ? $t("plataformaAdmin.whatsapp.encendido")
                : $t("plataformaAdmin.whatsapp.apagado")
            }}
          </span>
        </div>
        <label class="mt-2 flex items-center gap-2 text-sm">
          <input
            v-model="borrador[uso]"
            type="checkbox"
            :data-prueba="`activar-${uso}`"
          />
          {{ $t(`plataformaAdmin.whatsapp.${uso}Activar`) }}
        </label>
        <details class="mt-2">
          <summary
            class="text-xs cursor-pointer"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("plataformaAdmin.whatsapp.plantillas", {
                n: config.plantillas[uso].length,
              })
            }}
          </summary>
          <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("plataformaAdmin.whatsapp.plantillasAyuda") }}
          </p>
          <ul class="mt-1">
            <li
              v-for="p in config.plantillas[uso]"
              :key="p.nombre"
              class="wa-fila"
            >
              <p class="text-sm font-medium">{{ p.titulo }}</p>
              <p class="mt-0.5 font-mono text-xs select-all">{{ p.nombre }}</p>
              <p
                class="mt-1 text-xs select-all"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ p.texto }}
              </p>
              <p
                class="mt-0.5 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t(`plataformaAdmin.whatsapp.categorias.${p.categoria}`) }}
                · {{ p.idioma }}
              </p>
            </li>
          </ul>
        </details>
      </section>

      <button
        class="tu-btn tu-btn-primario"
        type="submit"
        :disabled="ocupado === 'guardar'"
      >
        {{ $t("plataformaAdmin.whatsapp.guardar") }}
      </button>
    </form>

    <template v-if="config?.conectado">
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
.wa-uso {
  padding-top: 1rem;
  border-top: 1px solid var(--borde);
}
.wa-fila {
  padding: 0.6rem 0;
  border-top: 1px solid var(--borde);
}
.wa-fila:first-child {
  border-top: 0;
}
</style>
