<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import DestinoMedicion from "@/components/DestinoMedicion.vue";
import EnviosMedicion from "@/components/EnviosMedicion.vue";
import { api, mensajeDeError } from "@/lib/api";
import type { DestinoMedicion as Destino, EnvioMedicion } from "@/lib/medicion";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * GA4 y Meta del negocio (ADR 0143), en Configuración → Integraciones → Medición: sus
 * propios destinos para medir cómo llegan sus alumnos o clientes, los eventos
 * adicionales que decide mandar y sus últimos envíos.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/medicion/destinos`);

const destinos = ref<Destino[]>([]);
const envios = ref<EnvioMedicion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: { destinos: Destino[]; envios: EnvioMedicion[] };
    }>(base.value);
    destinos.value = data.data.destinos;
    envios.value = data.data.envios;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);

function reemplazar(nuevo: Destino): void {
  destinos.value = destinos.value.map((d) =>
    d.proveedor === nuevo.proveedor ? nuevo : d,
  );
}
async function guardar(d: Destino, datos: Record<string, unknown>) {
  const { data } = await api.put<{ data: Destino }>(
    `${base.value}/${d.proveedor}`,
    datos,
  );
  return data.data;
}
async function probar(d: Destino) {
  const { data } = await api.post<{
    data: { ok: boolean; mensajes: string[] };
  }>(`${base.value}/${d.proveedor}/probar`);
  return data.data;
}
async function desconectar(d: Destino) {
  const { data } = await api.delete<{ data: Destino }>(
    `${base.value}/${d.proveedor}`,
  );
  return data.data;
}
</script>

<template>
  <section class="space-y-5" data-prueba="medicion-negocio">
    <!-- Sin título propio: la pestaña «Medición» ya lo nombra. -->
    <div>
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ t("medicion.negocio.descripcion") }}
      </p>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ t("medicion.negocio.embudo") }}
      </p>
    </div>
    <p v-if="cargando" :style="{ color: 'var(--texto-suave)' }">
      {{ t("comun.cargando") }}
    </p>
    <p v-else-if="error" :style="{ color: 'var(--error)' }">{{ error }}</p>
    <template v-else>
      <div class="grid gap-4 lg:grid-cols-2">
        <DestinoMedicion
          v-for="d in destinos"
          :key="d.proveedor"
          :destino="d"
          negocio
          extras
          :al-guardar="(datos) => guardar(d, datos)"
          :al-probar="() => probar(d)"
          :al-desconectar="() => desconectar(d)"
          @cambio="reemplazar"
        />
      </div>
      <EnviosMedicion :envios="envios" />
    </template>
  </section>
</template>
