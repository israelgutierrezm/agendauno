<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import DestinoMedicion from "@/components/DestinoMedicion.vue";
import EnviosMedicion from "@/components/EnviosMedicion.vue";
import { mensajeDeError } from "@/lib/api";
import type { DestinoMedicion as Destino, EnvioMedicion } from "@/lib/medicion";
import { PRODUCTOS, PRODUCTOS_LISTA } from "@/lib/producto";

/**
 * Medición del SaaS en el superadmin (ADR 0143): la propiedad de GA4 y el píxel de Meta
 * de AgendaUno y de TurnoUno, cada uno por separado, y los últimos envíos del embudo
 * (negocio creado, prueba iniciada, activación operativa, primera suscripción pagada).
 */
const props = defineProps<{ apiUrl: string; token: string }>();
const { t } = useI18n();

const destinos = ref<Destino[]>([]);
const envios = ref<EnvioMedicion[]>([]);
const cargando = ref(true);
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

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await cliente().get<{
      data: { destinos: Destino[]; envios: EnvioMedicion[] };
    }>("/api/v1/plataforma/medicion");
    destinos.value = data.data.destinos;
    envios.value = data.data.envios;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);

const porProducto = computed(() =>
  PRODUCTOS_LISTA.map((producto) => ({
    producto,
    nombre: PRODUCTOS[producto].nombre,
    destinos: destinos.value.filter((d) => d.producto === producto),
  })),
);

function ruta(d: Destino): string {
  return `/api/v1/plataforma/medicion/${d.producto}/${d.proveedor}`;
}
function reemplazar(nuevo: Destino): void {
  destinos.value = destinos.value.map((d) =>
    d.producto === nuevo.producto && d.proveedor === nuevo.proveedor
      ? nuevo
      : d,
  );
}
async function guardar(d: Destino, datos: Record<string, unknown>) {
  const { data } = await cliente().put<{ data: Destino }>(ruta(d), datos);
  return data.data;
}
async function probar(d: Destino) {
  const { data } = await cliente().post<{
    data: { ok: boolean; mensajes: string[] };
  }>(`${ruta(d)}/probar`);
  return data.data;
}
async function desconectar(d: Destino) {
  const { data } = await cliente().delete<{ data: Destino }>(ruta(d));
  return data.data;
}
</script>

<template>
  <section class="space-y-6" data-prueba="medicion-plataforma">
    <div>
      <h2 class="text-lg font-semibold">
        {{ t("medicion.plataforma.titulo") }}
      </h2>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ t("medicion.plataforma.descripcion") }}
      </p>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ t("medicion.plataforma.embudo") }}
      </p>
    </div>

    <p v-if="cargando" :style="{ color: 'var(--texto-suave)' }">…</p>
    <p v-else-if="error" :style="{ color: 'var(--error)' }">{{ error }}</p>
    <template v-else>
      <div
        v-for="grupo in porProducto"
        :key="grupo.producto"
        :data-producto="grupo.producto"
      >
        <h3 class="tu-label">{{ grupo.nombre }}</h3>
        <div class="mt-2 grid gap-4 lg:grid-cols-2">
          <DestinoMedicion
            v-for="d in grupo.destinos"
            :key="d.proveedor"
            :destino="d"
            :al-guardar="(datos) => guardar(d, datos)"
            :al-probar="() => probar(d)"
            :al-desconectar="() => desconectar(d)"
            @cambio="reemplazar"
          />
        </div>
      </div>
      <EnviosMedicion :envios="envios" con-negocio />
    </template>
  </section>
</template>
