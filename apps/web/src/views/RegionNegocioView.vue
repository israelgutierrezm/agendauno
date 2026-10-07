<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import SelectorBuscable from "@/components/SelectorBuscable.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { opcionesPais, opcionesZona } from "@/lib/region";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * País, moneda y zona horaria del negocio (ADR 0099 y 0103). El país da la lada de los
 * celulares capturados sin ella y, con pesos mexicanos, la facturación (solo en
 * México). Una sola moneda para todo el negocio (pesos mexicanos si no elige otra),
 * que se elige antes de empezar a cobrar; con ella funcionan, o no, las pasarelas en
 * línea. La zona horaria cuenta los días de reportes, cortes, vigencias y horarios.
 */
interface Region {
  pais: string;
  lada?: string;
  moneda: string;
  zona_horaria: string;
  monedas: { codigo: string; nombre: string }[];
  puede_cambiar_moneda: boolean;
  pasarelas: { disponibles: boolean; motivo: string | null };
  facturacion: { disponible: boolean; motivo: string | null };
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const region = ref<Region | null>(null);
const pais = ref("MX");
const moneda = ref("MXN");
const zona = ref("America/Mexico_City");
const cargando = ref(true);
const error = ref<string | null>(null);
const guardando = ref(false);

const cambios = computed(
  () =>
    region.value !== null &&
    (pais.value !== region.value.pais ||
      moneda.value !== region.value.moneda ||
      zona.value !== region.value.zona_horaria),
);

// Los países con buscador (los más usados primero) y las zonas, con las del país
// elegido primero.
const paises = opcionesPais();
const zonas = computed(() => opcionesZona(pais.value, zona.value));
// Lo que solo funciona en México y en pesos (ADR 0099), según lo elegido.
const enPesos = computed(() => moneda.value === "MXN");
const factura = computed(() => enPesos.value && pais.value === "MX");

function aplicar(r: Region): void {
  region.value = r;
  pais.value = r.pais ?? "MX";
  moneda.value = r.moneda;
  zona.value = r.zona_horaria;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Region }>(
      `${base.value}/negocio/region`,
    );
    aplicar(data.data);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function guardar(): Promise<void> {
  if (region.value === null) {
    return;
  }
  const cambiaMoneda = moneda.value !== region.value.moneda;
  if (
    cambiaMoneda &&
    !(await confirmar(t("region.moneda.confirmar", { moneda: moneda.value })))
  ) {
    return;
  }
  guardando.value = true;
  try {
    const { data } = await api.put<{ data: Region }>(
      `${base.value}/negocio/region`,
      {
        ...(pais.value !== region.value.pais ? { pais: pais.value } : {}),
        ...(cambiaMoneda ? { moneda: moneda.value } : {}),
        ...(zona.value !== region.value.zona_horaria
          ? { zona_horaria: zona.value }
          : {}),
      },
    );
    aplicar(data.data);
    // La sesión usa el país, la moneda y la zona en todo el panel.
    await sesion.cargarYo();
    toast.exito(t("region.guardado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('region.titulo')" />
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("region.subtitulo") }}
    </p>

    <p v-if="error" class="mt-6 text-sm" style="color: var(--error)">
      {{ error }}
      <button type="button" class="tu-enlace ml-2" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </p>
    <p
      v-else-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <form
      v-else-if="region"
      class="mt-6 grid gap-4 lg:grid-cols-2"
      @submit.prevent="guardar"
    >
      <!-- País -->
      <section class="tu-card p-5 sm:p-6" data-prueba="region-pais">
        <h2 class="font-medium">{{ $t("region.pais.titulo") }}</h2>
        <label class="tu-label mt-4" for="rg-pais">{{
          $t("region.pais.etiqueta")
        }}</label>
        <SelectorBuscable
          id="rg-pais"
          v-model="pais"
          :opciones="paises"
          data-prueba="pais-negocio"
        />
        <p class="tu-hint mt-1">{{ $t("region.pais.ayuda") }}</p>
        <p
          v-if="pais !== 'MX'"
          class="tu-hint mt-2"
          :style="{ color: 'var(--aviso)' }"
          data-prueba="fuera-de-mexico"
        >
          {{ $t("region.pais.fueraDeMexico") }}
        </p>
      </section>

      <!-- Moneda -->
      <section class="tu-card p-5 sm:p-6" data-prueba="region-moneda">
        <h2 class="font-medium">{{ $t("region.moneda.titulo") }}</h2>
        <label class="tu-label mt-4" for="rg-moneda">{{
          $t("region.moneda.etiqueta")
        }}</label>
        <select
          id="rg-moneda"
          v-model="moneda"
          class="tu-input"
          :disabled="!region.puede_cambiar_moneda"
          data-prueba="moneda-negocio"
        >
          <option v-for="m in region.monedas" :key="m.codigo" :value="m.codigo">
            {{ m.codigo }} · {{ m.nombre }}
          </option>
        </select>
        <p
          v-if="!region.puede_cambiar_moneda"
          class="tu-hint mt-1"
          :style="{ color: 'var(--aviso)' }"
          data-prueba="moneda-bloqueada"
        >
          {{ $t("region.moneda.bloqueada", { moneda: region.moneda }) }}
        </p>
        <p v-else class="tu-hint mt-1">{{ $t("region.moneda.ayuda") }}</p>

        <!-- Qué funciona con esta moneda -->
        <dl class="rg-avisos" data-prueba="region-avisos">
          <div>
            <dt>{{ $t("region.avisos.pasarelas") }}</dt>
            <dd>
              <span
                class="tu-pildora"
                :style="{
                  '--tono': enPesos ? 'var(--exito)' : 'var(--texto-suave)',
                }"
                >{{
                  enPesos
                    ? $t("region.avisos.disponible")
                    : $t("region.avisos.soloPesos")
                }}</span
              >
            </dd>
          </div>
          <div>
            <dt>{{ $t("region.avisos.facturacion") }}</dt>
            <dd>
              <span
                class="tu-pildora"
                :style="{
                  '--tono': factura ? 'var(--exito)' : 'var(--texto-suave)',
                }"
                >{{
                  factura
                    ? $t("region.avisos.disponible")
                    : $t("region.avisos.soloPesosMexico")
                }}</span
              >
            </dd>
          </div>
        </dl>
      </section>

      <!-- Zona horaria -->
      <section class="tu-card p-5 sm:p-6" data-prueba="region-zona">
        <h2 class="font-medium">{{ $t("region.zona.titulo") }}</h2>
        <label class="tu-label mt-4" for="rg-zona">{{
          $t("region.zona.etiqueta")
        }}</label>
        <SelectorBuscable
          id="rg-zona"
          v-model="zona"
          :opciones="zonas"
          data-prueba="zona-negocio"
        />
        <p class="tu-hint mt-1">{{ $t("region.zona.ayuda") }}</p>
      </section>

      <div class="flex justify-end lg:col-span-2">
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="guardando || !cambios"
          data-prueba="guardar-region"
        >
          {{ guardando ? $t("region.guardando") : $t("region.guardar") }}
        </button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.rg-avisos {
  display: grid;
  gap: 0.6rem;
  margin-top: 1.1rem;
  padding-top: 1rem;
  border-top: 1px solid var(--borde);
}
.rg-avisos > div {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.875rem;
}
</style>
