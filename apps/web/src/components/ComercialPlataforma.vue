<script setup lang="ts">
import axios from "axios";
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

/**
 * Datos comerciales de la plataforma (superadmin, ADR 0107): a dónde se manda a
 * quien pide cotización (correo y WhatsApp de ventas), el token del Banco de México
 * para el tipo de cambio (se escribe, nunca se muestra) y los paquetes de timbres que
 * se venden. Nada de esto vive fijo en el código.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

const { t } = useI18n();

interface Comercial {
  ventas_correo: string | null;
  ventas_whatsapp: string | null;
  banxico_configurado: boolean;
  timbres_paquetes: number[];
}

const correo = ref("");
const whatsapp = ref("");
const tokenBanxico = ref("");
const banxico = ref(false);
const paquetes = ref("");
const guardando = ref(false);
const aviso = ref<string | null>(null);
const error = ref<string | null>(null);

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
  try {
    const { data } = await cliente().get<{ data: Comercial }>(
      "/api/v1/plataforma/configuracion",
    );
    aplicar(data.data);
  } catch {
    error.value = t("plataforma.tokenInvalido");
  }
}

async function guardar(): Promise<void> {
  guardando.value = true;
  aviso.value = null;
  error.value = null;
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
    aviso.value = t("suscripcion.plataforma.comercial.guardado");
  } catch (e) {
    const datos = axios.isAxiosError(e) ? e.response?.data : null;
    const errores = datos?.meta?.errors as Record<string, string[]> | undefined;
    error.value =
      (errores ? Object.values(errores)[0]?.[0] : null) ??
      datos?.message ??
      t("plataforma.tokenInvalido");
  } finally {
    guardando.value = false;
  }
}

async function quitarToken(): Promise<void> {
  guardando.value = true;
  try {
    const { data } = await cliente().put<{ data: Comercial }>(
      "/api/v1/plataforma/configuracion",
      { banxico_token: null },
    );
    aplicar(data.data);
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
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="block">
        <span class="tu-label">{{
          $t("suscripcion.plataforma.comercial.ventasCorreo")
        }}</span>
        <input
          v-model="correo"
          class="tu-input"
          type="email"
          autocomplete="off"
        />
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
        />
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
          :placeholder="
            banxico
              ? $t('suscripcion.plataforma.comercial.banxicoGuardado')
              : ''
          "
        />
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
            :disabled="guardando"
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
        />
        <span
          class="mt-1 block text-xs"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("suscripcion.plataforma.comercial.paquetesAyuda") }}
        </span>
      </label>
    </div>
    <button class="tu-btn tu-btn-primario" type="submit" :disabled="guardando">
      {{ $t("suscripcion.plataforma.comercial.guardar") }}
    </button>
    <p v-if="aviso" class="text-sm" style="color: var(--exito)">{{ aviso }}</p>
    <p v-if="error" class="text-sm" style="color: var(--error)">{{ error }}</p>
  </form>
</template>
