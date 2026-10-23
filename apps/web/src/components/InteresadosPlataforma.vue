<script setup lang="ts">
import axios from "axios";
import { onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { mensajeDeError } from "@/lib/api";
import { PRODUCTOS, PRODUCTOS_LISTA, type Producto } from "@/lib/producto";

/**
 * Superadmin: quienes dejaron sus datos en la landing de un producto que aún no recibe
 * registros (ADR 0108: TurnoUno antes de su lanzamiento), para avisarles al abrir.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

interface Interesado {
  id: string;
  producto: Producto;
  nombre: string;
  correo: string;
  telefono: string | null;
  negocio: string | null;
  giro: string | null;
  ciudad: string | null;
  registrado_en: string;
}

const { t } = useI18n();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});

const producto = ref<Producto>("turnouno");
const lista = ref<Interesado[]>([]);
const total = ref(0);
// Por páginas (antes solo se veían los primeros 50) y con búsqueda.
const buscar = ref("");
const pagina = ref(1);
const meta = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
} | null>(null);
const cargando = ref(false);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await cliente.get<{
      data: Interesado[];
      meta: {
        total: number;
        page: number;
        per_page: number;
        ultima_pagina: number;
      };
    }>("/api/v1/plataforma/interesados", {
      params: {
        producto: producto.value,
        page: pagina.value,
        ...(buscar.value.trim() !== "" ? { q: buscar.value.trim() } : {}),
      },
      headers: { Authorization: `Bearer ${props.token}` },
    });
    lista.value = data.data;
    total.value = data.meta.total;
    meta.value = data.meta;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(iso),
  );
}

watch(producto, () => {
  pagina.value = 1;
  void cargar();
});
let espera: ReturnType<typeof setTimeout> | undefined;
watch(buscar, () => {
  clearTimeout(espera);
  espera = setTimeout(() => {
    pagina.value = 1;
    void cargar();
  }, 300);
});
function irPagina(n: number): void {
  pagina.value = n;
  void cargar();
}
onMounted(() => void cargar());
</script>

<template>
  <section class="tu-card p-5" data-prueba="interesados-plataforma">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <div>
        <h2 class="font-medium text-lg">
          {{ t("interesados.plataforma.titulo") }}
          <span
            v-if="!cargando"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            >· {{ total }}</span
          >
        </h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ t("interesados.plataforma.ayuda") }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <input
          v-model="buscar"
          type="search"
          class="tu-input w-64"
          :placeholder="t('operacion.listadosTres.buscarInteresados')"
          :aria-label="t('operacion.listadosTres.buscarInteresados')"
          data-prueba="buscar-interesados"
        />
        <label class="block">
          <span class="sr-only">{{
            t("interesados.plataforma.producto")
          }}</span>
          <select v-model="producto" class="tu-input">
            <option v-for="p in PRODUCTOS_LISTA" :key="p" :value="p">
              {{ PRODUCTOS[p].nombre }}
            </option>
          </select>
        </label>
      </div>
    </div>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-peligro tu-btn-chico"
          @click="cargar"
        >
          {{ t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <p
      v-else-if="cargando"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t("comun.cargando") }}
    </p>
    <p
      v-else-if="lista.length === 0"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{
        buscar.trim() !== ""
          ? t("operacion.listadosDos.sinResultados")
          : t("interesados.plataforma.vacio")
      }}
    </p>
    <ul v-else class="mt-4 divide-y divide-[var(--borde)]">
      <li v-for="i in lista" :key="i.id" class="py-3 text-sm">
        <p class="font-medium">
          {{ i.nombre }}
          <a class="tu-enlace font-normal ml-1" :href="`mailto:${i.correo}`">{{
            i.correo
          }}</a>
        </p>
        <p class="mt-0.5 text-xs" :style="{ color: 'var(--texto-suave)' }">
          <template v-if="i.negocio">{{ i.negocio }} · </template>
          <template v-if="i.giro"
            >{{ t(`registro.perfiles.${i.giro}`) }} ·
          </template>
          <template v-if="i.ciudad">{{ i.ciudad }} · </template>
          <template v-if="i.telefono">{{ i.telefono }} · </template>
          {{
            t("interesados.plataforma.registrado", {
              fecha: fecha(i.registrado_en),
            })
          }}
        </p>
      </li>
    </ul>
    <PaginacionListado
      v-if="meta && meta.ultima_pagina > 1"
      :page="meta.page"
      :ultima-pagina="meta.ultima_pagina"
      :total="meta.total"
      :per-page="meta.per_page"
      @ir="irPagina"
    />
  </section>
</template>
