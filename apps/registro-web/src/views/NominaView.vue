<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface UsuarioRow {
  id: string;
  nombre: string;
}
interface FilaNomina {
  usuario: string | null;
  tipo: string;
  unidades: number;
  monto_total_minor: number;
  moneda: string;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const staff = ref<UsuarioRow[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

const esquema = ref({
  usuarioId: "",
  tipo: "por_clase",
  monto: "",
  moneda: "MXN",
});
const guardando = ref(false);
const mensaje = ref<string | null>(null);

function isoHoy(): string {
  const d = new Date();
  const p = (n: number): string => (n < 10 ? `0${n}` : `${n}`);
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
function isoMesInicio(): string {
  const d = new Date();
  return `${d.getFullYear()}-${d.getMonth() + 1 < 10 ? "0" : ""}${d.getMonth() + 1}-01`;
}

const periodo = ref({ desde: isoMesInicio(), hasta: isoHoy() });
const filas = ref<FilaNomina[]>([]);
const calculando = ref(false);
const calculado = ref(false);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
const totalPeriodo = computed(() =>
  filas.value.reduce((s, f) => s + f.monto_total_minor, 0),
);
const monedaPeriodo = computed(() => filas.value[0]?.moneda ?? "MXN");

async function cargarStaff(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: { id: string; nombre: string }[] }>(
      `${base.value}/usuarios`,
    );
    staff.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function guardarEsquema(): Promise<void> {
  guardando.value = true;
  error.value = null;
  mensaje.value = null;
  try {
    await api.put(
      `${base.value}/staff/${esquema.value.usuarioId}/esquema-pago`,
      {
        tipo: esquema.value.tipo,
        monto_minor: Math.round(Number(esquema.value.monto) * 100),
        moneda: esquema.value.moneda,
      },
    );
    mensaje.value = "ok";
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

async function calcular(): Promise<void> {
  calculando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: FilaNomina[] }>(
      `${base.value}/nomina`,
      { params: periodo.value },
    );
    filas.value = data.data;
    calculado.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    calculando.value = false;
  }
}

onMounted(cargarStaff);
</script>

<template>
  <section class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('nomina.titulo')" />

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <!-- Esquema de pago -->
    <div class="mt-6 tu-card p-5">
      <h2 class="font-bold">{{ $t("nomina.esquemas") }}</h2>
      <form
        class="mt-3 grid sm:grid-cols-2 gap-3"
        @submit.prevent="guardarEsquema"
      >
        <div>
          <label class="tu-label" for="es">{{ $t("nomina.staff") }}</label>
          <select id="es" v-model="esquema.usuarioId" class="tu-input" required>
            <option value="" disabled>{{ $t("nomina.staff") }}</option>
            <option v-for="u in staff" :key="u.id" :value="u.id">
              {{ u.nombre }}
            </option>
          </select>
        </div>
        <div>
          <label class="tu-label" for="et">{{ $t("nomina.tipo") }}</label>
          <select id="et" v-model="esquema.tipo" class="tu-input">
            <option value="por_clase">{{ $t("nomina.tipoPorClase") }}</option>
            <option value="por_asistente">
              {{ $t("nomina.tipoPorAsistente") }}
            </option>
            <option value="por_hora">{{ $t("nomina.tipoPorHora") }}</option>
          </select>
        </div>
        <div>
          <label class="tu-label" for="em">{{ $t("nomina.monto") }}</label>
          <input
            id="em"
            v-model="esquema.monto"
            class="tu-input"
            type="number"
            min="0"
            step="0.01"
            required
          />
        </div>
        <div class="flex items-end">
          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            :disabled="
              guardando || esquema.usuarioId === '' || esquema.monto === ''
            "
          >
            {{ $t("nomina.guardarEsquema") }}
          </button>
        </div>
      </form>
      <p v-if="mensaje" class="mt-2 text-sm" :style="{ color: 'var(--exito)' }">
        {{ $t("nomina.guardado") }}
      </p>
    </div>

    <!-- Cálculo del periodo -->
    <div class="mt-6 tu-card p-5">
      <h2 class="font-bold">{{ $t("nomina.periodo") }}</h2>
      <div class="mt-3 flex flex-wrap items-end gap-3">
        <div>
          <label class="tu-label" for="nd">{{ $t("nomina.desde") }}</label>
          <input
            id="nd"
            v-model="periodo.desde"
            class="tu-input w-auto"
            type="date"
          />
        </div>
        <div>
          <label class="tu-label" for="nh">{{ $t("nomina.hasta") }}</label>
          <input
            id="nh"
            v-model="periodo.hasta"
            class="tu-input w-auto"
            type="date"
          />
        </div>
        <button
          class="tu-btn tu-btn-primario"
          :disabled="calculando"
          @click="calcular"
        >
          {{ $t("nomina.calcular") }}
        </button>
      </div>

      <div v-if="calculado" class="mt-4">
        <p
          v-if="filas.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("nomina.vacio") }}
        </p>
        <table v-else class="w-full text-sm">
          <thead>
            <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
              <th class="py-2 font-medium">{{ $t("nomina.colStaff") }}</th>
              <th class="py-2 font-medium">{{ $t("nomina.colTipo") }}</th>
              <th class="py-2 font-medium text-right">
                {{ $t("nomina.colUnidades") }}
              </th>
              <th class="py-2 font-medium text-right">
                {{ $t("nomina.colTotal") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(f, i) in filas"
              :key="i"
              class="border-t"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <td class="py-2">{{ f.usuario ?? "—" }}</td>
              <td class="py-2">
                {{
                  $t(
                    `nomina.tipo${f.tipo === "por_clase" ? "PorClase" : f.tipo === "por_asistente" ? "PorAsistente" : "PorHora"}`,
                  )
                }}
              </td>
              <td class="py-2 text-right">{{ f.unidades }}</td>
              <td class="py-2 text-right font-semibold">
                {{ dinero(f.monto_total_minor, f.moneda) }}
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t" :style="{ borderColor: 'var(--borde)' }">
              <td class="py-2 font-bold" colspan="3">
                {{ $t("nomina.totalPeriodo") }}
              </td>
              <td class="py-2 text-right font-extrabold">
                {{ dinero(totalPeriodo, monedaPeriodo) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </section>
</template>
