<script setup lang="ts">
import axios from "axios";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import SelectorBuscable from "@/components/SelectorBuscable.vue";
import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { opcionesPais } from "@/lib/region";
import { useToastStore } from "@/stores/toast";

/**
 * En la ficha de un negocio, para el superadmin: su país (ADR 0107). Define la moneda,
 * el IVA y la factura de su suscripción, así que pasada la prueba el dueño ya no lo
 * cambia: lo hace soporte, aquí.
 */
const props = defineProps<{
  apiUrl: string;
  token: string;
  slug: string;
  nombre: string;
  pais: string;
}>();

const emit = defineEmits<{ cambiado: [] }>();

const { t } = useI18n();
const toast = useToastStore();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});

const paises = opcionesPais();
const elegido = ref(props.pais);
const guardando = ref(false);
const nombreDe = (codigo: string): string =>
  paises.find((p) => p.valor === codigo)?.etiqueta ?? codigo;
const cambio = computed(() => elegido.value !== props.pais);

async function guardar(): Promise<void> {
  if (
    !(await confirmar(
      t("plataformaAdmin.pais.confirmar", {
        estudio: props.nombre,
        pais: nombreDe(elegido.value),
      }),
      { aceptar: t("plataformaAdmin.pais.cambiar"), peligro: true },
    ))
  ) {
    return;
  }
  guardando.value = true;
  try {
    await cliente.put(
      `/api/v1/plataforma/estudios/${props.slug}/pais`,
      { pais: elegido.value },
      { headers: { Authorization: `Bearer ${props.token}` } },
    );
    toast.exito(t("plataformaAdmin.pais.cambiado"));
    emit("cambiado");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <section data-prueba="pais-negocio-plataforma">
    <h3
      class="text-xs font-medium uppercase tracking-wide"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("plataformaAdmin.pais.etiqueta") }}
    </h3>
    <div class="mt-2 flex items-end gap-2 flex-wrap">
      <div class="flex-1 min-w-[12rem]">
        <SelectorBuscable v-model="elegido" :opciones="paises" />
      </div>
      <button
        class="tu-btn tu-btn-fantasma text-sm"
        type="button"
        data-prueba="cambiar-pais"
        :disabled="guardando || !cambio"
        @click="guardar"
      >
        {{ $t("plataformaAdmin.pais.cambiar") }}
      </button>
    </div>
    <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("plataformaAdmin.pais.ayuda") }}
    </p>
  </section>
</template>
