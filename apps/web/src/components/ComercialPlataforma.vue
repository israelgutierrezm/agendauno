<script setup lang="ts">
import axios from "axios";
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * Datos comerciales de la plataforma (superadmin, ADR 0107): a dónde se manda a
 * quien pide cotización (correo y WhatsApp de ventas), el token del Banco de México
 * para el tipo de cambio (se escribe, nunca se muestra) y los paquetes de timbres que
 * se venden. Nada de esto vive fijo en el código.
 *
 * El formulario no se puede guardar hasta que cargó bien: guardarlo vacío borraría
 * las ventas y los paquetes que ya había.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

const { t } = useI18n();
const toast = useToastStore();

interface Comercial {
  ventas_correo: string | null;
  ventas_whatsapp: string | null;
  banxico_configurado: boolean;
  timbres_paquetes: number[];
}

const CAMPOS = [
  "ventas_correo",
  "ventas_whatsapp",
  "banxico_token",
  "timbres_paquetes",
] as const;
type Campo = (typeof CAMPOS)[number];

const correo = ref("");
const whatsapp = ref("");
const tokenBanxico = ref("");
const banxico = ref(false);
const paquetes = ref("");
const cargando = ref(true);
const cargado = ref(false);
const errorCarga = ref<string | null>(null);
const guardando = ref(false);
// Lo que la API rechazó de cada campo, debajo de ese campo.
const errores = ref<Partial<Record<Campo, string>>>({});

function cliente() {
  return axios.create({
    baseURL: props.apiUrl,
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${props.token}`,
    },
  });
}

function aplicar(datos: Comercial): void {
  correo.value = datos.ventas_correo ?? "";
  whatsapp.value = datos.ventas_whatsapp ?? "";
  banxico.value = datos.banxico_configurado;
  paquetes.value = datos.timbres_paquetes.join(", ");
}

async function cargar(): Promise<void> {
  cargando.value = true;
  errorCarga.value = null;
  try {
    const { data } = await cliente().get<{ data: Comercial }>(
      "/api/v1/plataforma/configuracion",
    );
    aplicar(data.data);
    cargado.value = true;
  } catch (e) {
    errorCarga.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

/** Los errores de validación que son de un campo de este formulario. */
function erroresDeCampo(e: unknown): Partial<Record<Campo, string>> {
  const datos = axios.isAxiosError(e)
    ? (e.response?.data as
        { meta?: { errors?: Record<string, string[]> } } | undefined)
    : undefined;
  const propios: Partial<Record<Campo, string>> = {};
  for (const [llave, mensajes] of Object.entries(datos?.meta?.errors ?? {})) {
    // `timbres_paquetes.0` es del campo de paquetes.
    const campo = llave.split(".")[0] as Campo;
    if (CAMPOS.includes(campo) && propios[campo] === undefined && mensajes[0]) {
      propios[campo] = mensajes[0];
    }
  }
  return propios;
}

async function guardar(): Promise<void> {
  if (!cargado.value) {
    return;
  }
  guardando.value = true;
  errores.value = {};
  const cuerpo: Record<string, unknown> = {
    ventas_correo: correo.value.trim() || null,
    ventas_whatsapp: whatsapp.value.trim() || null,
    timbres_paquetes: paquetes.value
      .split(/[,\s]+/)
      .filter((p) => p !== "")
      .map(Number),
  };
  // El token solo se manda si se escribe uno (vacío lo deja como está).
  if (tokenBanxico.value.trim() !== "") {
    cuerpo.banxico_token = tokenBanxico.value.trim();
  }
  try {
    const { data } = await cliente().put<{ data: Comercial }>(
      "/api/v1/plataforma/configuracion",
      cuerpo,
    );
    aplicar(data.data);
    tokenBanxico.value = "";
    toast.exito(t("suscripcion.plataforma.comercial.guardado"));
  } catch (e) {
    errores.value = erroresDeCampo(e);
    if (Object.keys(errores.value).length === 0) {
      toast.error(mensajeDeError(e));
    }
  } finally {
    guardando.value = false;
  }
}

async function quitarToken(): Promise<void> {
  if (
    !(await confirmar(t("suscripcion.plataforma.comercial.confirmarQuitar"), {
      aceptar: t("suscripcion.plataforma.comercial.quitar"),
      peligro: true,
    }))
  ) {
    return;
  }
  guardando.value = true;
  try {
    const { data } = await cliente().put<{ data: Comercial }>(
      "/api/v1/plataforma/configuracion",
      { banxico_token: null },
    );
    banxico.value = data.data.banxico_configurado;
    toast.exito(t("suscripcion.plataforma.comercial.banxicoQuitado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <form
    class="tu-card p-5 space-y-4"
    data-prueba="comercial-plataforma"
    @submit.prevent="guardar"
  >
    <div>
      <h2 class="font-medium text-lg">
        {{ $t("suscripcion.plataforma.comercial.titulo") }}
      </h2>
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("suscripcion.plataforma.comercial.ayuda") }}
      </p>
    </div>

    <p
      v-if="cargando"
      class="text-sm"
      role="status"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <div
      v-else-if="errorCarga"
      class="flex flex-wrap items-center gap-3 text-sm"
      role="alert"
      data-prueba="comercial-error"
    >
      <span style="color: var(--error)">{{ errorCarga }}</span>
      <button type="button" class="tu-btn tu-btn-fantasma" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </div>

    <fieldset
      v-if="cargado"
      class="grid gap-3 sm:grid-cols-2"
      :disabled="guardando"
    >
      <label class="block">
        <span class="tu-label">{{
          $t("suscripcion.plataforma.comercial.ventasCorreo")
        }}</span>
        <input
          v-model="correo"
          class="tu-input"
          type="email"
          autocomplete="off"
          :aria-invalid="errores.ventas_correo ? 'true' : undefined"
        />
        <span v-if="errores.ventas_correo" class="tu-hint tu-error-campo">{{
          errores.ventas_correo
        }}</span>
      </label>
      <label class="block">
        <span class="tu-label">{{
          $t("suscripcion.plataforma.comercial.ventasWhatsApp")
        }}</span>
        <input
          v-model="whatsapp"
          class="tu-input"
          inputmode="tel"
          placeholder="52 55 1234 5678"
          :aria-invalid="errores.ventas_whatsapp ? 'true' : undefined"
        />
        <span v-if="errores.ventas_whatsapp" class="tu-hint tu-error-campo">{{
          errores.ventas_whatsapp
        }}</span>
      </label>
      <label class="block">
        <span class="tu-label">{{
          $t("suscripcion.plataforma.comercial.banxico")
        }}</span>
        <input
          v-model="tokenBanxico"
          class="tu-input"
          type="password"
          autocomplete="off"
          :aria-invalid="errores.banxico_token ? 'true' : undefined"
          :placeholder="
            banxico
              ? $t('suscripcion.plataforma.comercial.banxicoGuardado')
              : ''
          "
        />
        <span v-if="errores.banxico_token" class="tu-hint tu-error-campo">{{
          errores.banxico_token
        }}</span>
        <span
          class="mt-1 block text-xs"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{
            banxico
              ? $t("suscripcion.plataforma.tipoCambio.banxico")
              : $t("suscripcion.plataforma.comercial.banxicoAyuda")
          }}
          <button
            v-if="banxico"
            type="button"
            class="tu-enlace ml-1"
            data-prueba="quitar-banxico"
            @click="quitarToken"
          >
            {{ $t("suscripcion.plataforma.comercial.quitar") }}
          </button>
        </span>
      </label>
      <label class="block">
        <span class="tu-label">{{
          $t("suscripcion.plataforma.comercial.paquetes")
        }}</span>
        <input
          v-model="paquetes"
          class="tu-input"
          inputmode="numeric"
          placeholder="50, 100, 200, 350, 500"
          :aria-invalid="errores.timbres_paquetes ? 'true' : undefined"
        />
        <span v-if="errores.timbres_paquetes" class="tu-hint tu-error-campo">{{
          errores.timbres_paquetes
        }}</span>
        <span
          class="mt-1 block text-xs"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("suscripcion.plataforma.comercial.paquetesAyuda") }}
        </span>
      </label>
    </fieldset>
    <button
      class="tu-btn tu-btn-primario"
      type="submit"
      :disabled="!cargado || guardando"
    >
      {{ $t("suscripcion.plataforma.comercial.guardar") }}
    </button>
  </form>
</template>

<style scoped>
.tu-error-campo {
  color: var(--error);
}
</style>
