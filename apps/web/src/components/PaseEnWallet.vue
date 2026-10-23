<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";

/**
 * El pase de entrada en la cartera del teléfono (ADR 0195): Apple Wallet y Google
 * Wallet, si la plataforma los tiene y el negocio lo permite. Renovarlo deja sin efecto
 * el que ya estaba en la cartera (si perdió su teléfono).
 */
const props = defineProps<{ base: string }>();

const { t } = useI18n();
const disponibles = ref<{ apple: boolean; google: boolean } | null>(null);
const trabajando = ref(false);
const aviso = ref<string | null>(null);

onMounted(async () => {
  try {
    const { data } = await api.get<{
      data: { apple: boolean; google: boolean };
    }>(`${props.base}/mi/pase/wallet`);
    disponibles.value = data.data;
  } catch {
    disponibles.value = null;
  }
});

async function apple(): Promise<void> {
  trabajando.value = true;
  aviso.value = null;
  try {
    const { data } = await api.get<Blob>(`${props.base}/mi/pase/wallet/apple`, {
      responseType: "blob",
    });
    const url = URL.createObjectURL(
      new Blob([data], { type: "application/vnd.apple.pkpass" }),
    );
    // En el iPhone, Safari lo abre en Wallet.
    window.location.href = url;
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (e) {
    aviso.value = mensajeDeError(e);
  } finally {
    trabajando.value = false;
  }
}

async function google(): Promise<void> {
  trabajando.value = true;
  aviso.value = null;
  try {
    const { data } = await api.get<{ data: { url: string } }>(
      `${props.base}/mi/pase/wallet/google`,
    );
    window.location.href = data.data.url;
  } catch (e) {
    aviso.value = mensajeDeError(e);
  } finally {
    trabajando.value = false;
  }
}

async function renovar(): Promise<void> {
  if (
    !(await confirmar(t("paseWallet.renovarConfirmar"), {
      aceptar: t("paseWallet.renovar"),
    }))
  ) {
    return;
  }
  trabajando.value = true;
  try {
    await api.post(`${props.base}/mi/pase/wallet/renovar`);
    aviso.value = t("paseWallet.renovado");
  } catch (e) {
    aviso.value = mensajeDeError(e);
  } finally {
    trabajando.value = false;
  }
}
</script>

<template>
  <div
    v-if="disponibles && (disponibles.apple || disponibles.google)"
    class="mt-4 space-y-2"
    data-prueba="pase-wallet"
  >
    <div class="flex flex-wrap gap-2">
      <button
        v-if="disponibles.apple"
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="trabajando"
        data-prueba="wallet-apple"
        @click="apple"
      >
        {{ t("paseWallet.apple") }}
      </button>
      <button
        v-if="disponibles.google"
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="trabajando"
        data-prueba="wallet-google"
        @click="google"
      >
        {{ t("paseWallet.google") }}
      </button>
    </div>
    <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ t("paseWallet.ayuda") }}
      <button
        type="button"
        class="tu-enlace"
        :disabled="trabajando"
        data-prueba="wallet-renovar"
        @click="renovar"
      >
        {{ t("paseWallet.renovar") }}
      </button>
    </p>
    <p v-if="aviso" class="text-sm" role="status">{{ aviso }}</p>
  </div>
</template>
