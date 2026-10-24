<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import DestinoMedicion from "@/components/DestinoMedicion.vue";
import EnviosMedicion from "@/components/EnviosMedicion.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PestanasSeccion from "@/components/PestanasSeccion.vue";
import { mensajeDeError } from "@/lib/api";
import { usePestanaEnUrl } from "@/lib/filtrosEnUrl";
import type { DestinoMedicion as Destino, EnvioMedicion } from "@/lib/medicion";
import { PRODUCTOS, PRODUCTOS_LISTA, type Producto } from "@/lib/producto";

/**
 * Medición del SaaS en el superadmin (ADR 0143): la propiedad de GA4 y el píxel de Meta
 * de AgendaUno y de TurnoUno, cada uno por separado, y los últimos envíos del embudo
 * (negocio creado, prueba iniciada, activación operativa, primera suscripción pagada).
 *
 * Un producto a la vez (sus dos destinos lado a lado) y los envíos en su propia
 * pestaña, por páginas: antes todo iba seguido y eran casi tres pantallas.
 */
const props = defineProps<{ apiUrl: string; token: string }>();
const { t } = useI18n();

type Seccion = Producto | "envios";
const SECCIONES: readonly Seccion[] = [...PRODUCTOS_LISTA, "envios"];
// Los envíos que se ven a la vez (la API trae los últimos 50).
const POR_PAGINA = 15;

// La pestaña, en la dirección (`?medicion=turnouno`). Clave propia: `pestana` es la
// sección del superadmin y `producto`, el filtro de Negocios.
const seccion = usePestanaEnUrl<Seccion>(SECCIONES, { clave: "medicion" });
const opciones = computed<{ clave: Seccion; texto: string }[]>(() => [
  ...PRODUCTOS_LISTA.map((producto) => ({
    clave: producto,
    texto: PRODUCTOS[producto].nombre,
  })),
  { clave: "envios", texto: t("medicion.envios.titulo") },
]);

const destinos = ref<Destino[]>([]);
const envios = ref<EnvioMedicion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const paginaEnvios = ref(1);

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
    paginaEnvios.value = 1;
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
    destinos: destinos.value.filter((d) => d.producto === producto),
  })),
);

const ultimaPaginaEnvios = computed(() =>
  Math.max(1, Math.ceil(envios.value.length / POR_PAGINA)),
);
const enviosVisibles = computed(() =>
  envios.value.slice(
    (paginaEnvios.value - 1) * POR_PAGINA,
    paginaEnvios.value * POR_PAGINA,
  ),
);

function irPaginaEnvios(n: number): void {
  paginaEnvios.value = n;
}

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
  <section data-prueba="medicion-plataforma">
    <!-- Sin título propio: el encabezado de la sección ya dice «Medición». -->
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ t("medicion.plataforma.descripcion") }}
    </p>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ t("medicion.plataforma.embudo") }}
    </p>

    <PestanasSeccion
      v-model="seccion"
      :opciones="opciones"
      :etiqueta="t('medicion.plataforma.secciones')"
    />

    <p
      v-if="cargando"
      class="mt-5 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("comun.cargando") }}
    </p>
    <AvisoTono v-else-if="error" tono="peligro" class="mt-5" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="cargar"
        >
          {{ t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <template v-else>
      <!-- Con v-show: lo que se escribió en un destino sigue ahí al volver de otra
           pestaña. -->
      <div
        v-for="grupo in porProducto"
        v-show="seccion === grupo.producto"
        :key="grupo.producto"
        class="mt-5 grid gap-4 lg:grid-cols-2"
        :data-producto="grupo.producto"
      >
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
      <!-- Integrada: la pestaña ya se llama «Últimos envíos», sin repetirlo como
           título. La paginación, dentro de la tarjeta, debajo de la lista. -->
      <EnviosMedicion
        v-if="seccion === 'envios'"
        class="mt-5"
        :envios="enviosVisibles"
        con-negocio
        integrado
      >
        <template #pie>
          <PaginacionListado
            v-if="ultimaPaginaEnvios > 1"
            class="mt-2"
            :page="paginaEnvios"
            :ultima-pagina="ultimaPaginaEnvios"
            :total="envios.length"
            :per-page="POR_PAGINA"
            data-prueba="paginas-envios"
            @ir="irPaginaEnvios"
          />
        </template>
      </EnviosMedicion>
    </template>
  </section>
</template>
