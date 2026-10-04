<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import CampoContrasena from "@/components/CampoContrasena.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Privacidad del alumno (derechos ARCO frente al negocio): recibir o no promociones
 * (oposición), aceptar los avisos por WhatsApp (solo si el negocio los usa, ADR
 * 0069), descargar sus datos (acceso) y pedir la baja de sus datos (cancelación). La
 * rectificación está en "Mi perfil". Descargar y pedir la baja se confirman con su
 * contraseña en una ventana: así se sabe que es la persona de la sesión.
 */
interface Privacidad {
  recibe_promociones: boolean;
  whatsapp_disponible?: boolean;
  acepta_whatsapp?: boolean;
  baja: {
    estado: "pendiente" | "atendida" | "rechazada";
    solicitada_en: string | null;
    respuesta: string | null;
  } | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const datos = ref<Privacidad | null>(null);
const pidiendoBaja = ref(false);
const motivo = ref("");
const ocupado = ref(false);

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Privacidad }>(
      `${base.value}/mi/privacidad`,
    );
    datos.value = data.data;
  } catch {
    datos.value = null;
  }
}

async function cambiar(
  cambio: { recibe_promociones: boolean } | { acepta_whatsapp: boolean },
): Promise<void> {
  ocupado.value = true;
  try {
    const { data } = await api.put<{ data: Privacidad }>(
      `${base.value}/mi/privacidad`,
      cambio,
    );
    datos.value = data.data;
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

// Descargar los datos y pedir la baja: primero su contraseña (en una ventana).
type Accion = "descargar" | "baja";
const confirmando = ref<Accion | null>(null);
const contrasena = ref("");
const errorContrasena = ref<string | null>(null);

function pedirContrasena(accion: Accion): void {
  contrasena.value = "";
  errorContrasena.value = null;
  confirmando.value = accion;
}
function cerrarConfirmacion(): void {
  if (ocupado.value) return;
  confirmando.value = null;
  contrasena.value = "";
}

function guardarArchivo(contenido: unknown): void {
  const archivo = new Blob([JSON.stringify(contenido, null, 2)], {
    type: "application/json",
  });
  const url = URL.createObjectURL(archivo);
  const enlace = document.createElement("a");
  enlace.href = url;
  enlace.download = `mis-datos-${sesion.slug}.json`;
  enlace.click();
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

async function confirmar(): Promise<void> {
  if (confirmando.value === null || contrasena.value === "" || ocupado.value) {
    return;
  }
  ocupado.value = true;
  errorContrasena.value = null;
  try {
    if (confirmando.value === "descargar") {
      const { data } = await api.post<unknown>(`${base.value}/mi/datos`, {
        password: contrasena.value,
      });
      guardarArchivo(data);
    } else {
      const { data } = await api.post<{ data: Privacidad }>(
        `${base.value}/mi/privacidad/baja`,
        { motivo: motivo.value.trim() || null, password: contrasena.value },
      );
      datos.value = data.data;
      pidiendoBaja.value = false;
      toast.exito(t("miPrivacidad.bajaEnviada"));
    }
    confirmando.value = null;
    contrasena.value = "";
  } catch (e) {
    // Contraseña incorrecta, sin contraseña o demasiados intentos: se dice aquí.
    errorContrasena.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <div v-if="datos" class="tu-card p-6">
    <h2 class="font-medium text-lg">{{ $t("miPrivacidad.titulo") }}</h2>

    <label class="mt-3 flex items-center justify-between gap-3 text-sm">
      <span>
        {{ $t("miPrivacidad.promociones") }}
        <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }">{{
          $t("miPrivacidad.promocionesAyuda")
        }}</span>
      </span>
      <input
        type="checkbox"
        class="h-5 w-5"
        :checked="datos.recibe_promociones"
        :disabled="ocupado"
        @change="
          cambiar({
            recibe_promociones: ($event.target as HTMLInputElement).checked,
          })
        "
      />
    </label>

    <label
      v-if="datos.whatsapp_disponible"
      class="mt-3 flex items-center justify-between gap-3 text-sm"
    >
      <span>
        {{ $t("miPrivacidad.whatsapp") }}
        <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }">{{
          $t("miPrivacidad.whatsappAyuda")
        }}</span>
      </span>
      <input
        type="checkbox"
        class="h-5 w-5"
        data-prueba="acepta-whatsapp"
        :checked="datos.acepta_whatsapp"
        :disabled="ocupado"
        @change="
          cambiar({
            acepta_whatsapp: ($event.target as HTMLInputElement).checked,
          })
        "
      />
    </label>

    <div class="mt-4 flex flex-wrap gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        :disabled="ocupado"
        @click="pedirContrasena('descargar')"
      >
        {{ $t("miPrivacidad.descargar") }}
      </button>
      <button
        v-if="!datos.baja || datos.baja.estado === 'rechazada'"
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        style="color: var(--error)"
        @click="pidiendoBaja = !pidiendoBaja"
      >
        {{ $t("miPrivacidad.baja") }}
      </button>
    </div>

    <p
      v-if="datos.baja?.estado === 'pendiente'"
      class="mt-3 text-sm"
      role="status"
      style="color: var(--aviso)"
    >
      {{ $t("miPrivacidad.bajaPendiente") }}
    </p>
    <p
      v-else-if="datos.baja?.estado === 'rechazada'"
      class="mt-3 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{
        $t("miPrivacidad.bajaRechazada", { respuesta: datos.baja.respuesta })
      }}
    </p>

    <form
      v-if="pidiendoBaja"
      class="mt-3 space-y-3 rounded-xl border p-4"
      :style="{ borderColor: 'var(--borde)' }"
      @submit.prevent="pedirContrasena('baja')"
    >
      <p class="text-sm">{{ $t("miPrivacidad.bajaExplica") }}</p>
      <div>
        <label class="tu-label" for="mp-baja-motivo">{{
          $t("miPrivacidad.motivo")
        }}</label>
        <input
          id="mp-baja-motivo"
          v-model="motivo"
          class="tu-input"
          maxlength="500"
        />
      </div>
      <button
        type="submit"
        class="tu-btn tu-btn-primario text-sm"
        :disabled="ocupado"
      >
        {{ $t("miPrivacidad.confirmarBaja") }}
      </button>
    </form>
    <!-- Confirma con su contraseña que es la persona de la sesión. -->
    <ModalDialogo
      :abierto="confirmando !== null"
      :titulo="$t('miPrivacidad.confirmarTitulo')"
      @cerrar="cerrarConfirmacion"
    >
      <form
        id="mp-confirmar"
        class="space-y-3"
        data-prueba="confirmar-contrasena"
        @submit.prevent="confirmar"
      >
        <p class="text-sm">
          {{
            confirmando === "baja"
              ? $t("miPrivacidad.confirmarBajaTexto")
              : $t("miPrivacidad.confirmarDescargaTexto")
          }}
        </p>
        <div>
          <label class="tu-label" for="mp-contrasena">{{
            $t("miPrivacidad.contrasena")
          }}</label>
          <CampoContrasena
            id="mp-contrasena"
            v-model="contrasena"
            autocomplete="current-password"
          />
          <p
            v-if="errorContrasena"
            class="mt-1 text-sm"
            data-prueba="error-contrasena"
            style="color: var(--error)"
          >
            {{ errorContrasena }}
          </p>
        </div>
      </form>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          :disabled="ocupado"
          @click="cerrarConfirmacion"
        >
          {{ $t("miPrivacidad.cancelar") }}
        </button>
        <button
          type="submit"
          form="mp-confirmar"
          class="tu-btn tu-btn-primario"
          :disabled="ocupado || contrasena === ''"
        >
          {{
            confirmando === "baja"
              ? $t("miPrivacidad.confirmarBaja")
              : $t("miPrivacidad.descargar")
          }}
        </button>
      </template>
    </ModalDialogo>
  </div>
</template>
