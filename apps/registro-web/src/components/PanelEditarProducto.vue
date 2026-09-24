<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

export interface ProductoEditable {
  id: string;
  nombre: string;
  tipo: string;
  precio_minor: number;
  moneda: string;
  ilimitado: boolean;
  creditos_incluidos: number | null;
  vigencia_dias: number | null;
  politica_reset: string;
  unidades_por_ciclo: number | null;
  politica_rollover: string;
  rollover_max: number | null;
  sucursal_id: string | null;
  archivado: boolean;
}

// `producto` null = alta; objeto = edición.
const props = defineProps<{ producto: ProductoEditable | null }>();
const emit = defineEmits<{ (e: "cerrar"): void; (e: "guardado"): void }>();

const sesion = useSesionTenantStore();
const base = `/api/v1/app/${sesion.slug}`;
const esEdicion = computed(() => props.producto !== null);

interface Sucursal {
  id: string;
  nombre: string;
}
const sucursales = ref<Sucursal[]>([]);

const p = props.producto;
const form = reactive({
  nombre: p?.nombre ?? "",
  tipo: p?.tipo ?? "paquete",
  precio: p ? String(p.precio_minor / 100) : "899",
  moneda: p?.moneda ?? "MXN",
  ilimitado: p?.ilimitado ?? false,
  creditos:
    p?.creditos_incluidos != null ? String(p.creditos_incluidos / 1000) : "8",
  vigencia_dias: p?.vigencia_dias != null ? String(p.vigencia_dias) : "",
  politica_reset: p?.politica_reset ?? "ninguno",
  unidades_por_ciclo:
    p?.unidades_por_ciclo != null ? String(p.unidades_por_ciclo / 1000) : "4",
  politica_rollover: p?.politica_rollover ?? "ninguno",
  rollover_max: p?.rollover_max != null ? String(p.rollover_max / 1000) : "",
  sucursal_id: p?.sucursal_id ?? "",
  archivado: p?.archivado ?? false,
});
const guardando = ref(false);
const error = ref<string | null>(null);

const recurrente = computed(() => form.politica_reset !== "ninguno");

// Los <input type="number"> pueden entregar number, string o '' (vacío): normalizamos.
function aNumero(valor: unknown): number | null {
  const s = String(valor ?? "").trim();
  return s !== "" && !Number.isNaN(Number(s)) ? Number(s) : null;
}
function unidades(valor: unknown): number | null {
  const n = aNumero(valor);
  return n !== null ? Math.round(n * 1000) : null;
}

async function cargarSucursales(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Sucursal[] }>(`${base}/sucursales`);
    sucursales.value = data.data;
  } catch {
    // Sin sucursales no bloquea el editor (la restricción por sede es opcional).
  }
}

async function guardar(): Promise<void> {
  guardando.value = true;
  error.value = null;
  const carga = {
    nombre: form.nombre,
    tipo: form.tipo,
    precio_minor: Math.round((aNumero(form.precio) ?? 0) * 100),
    moneda: form.moneda,
    ilimitado: form.ilimitado,
    creditos_incluidos: form.ilimitado ? null : unidades(form.creditos),
    vigencia_dias: aNumero(form.vigencia_dias),
    politica_reset: form.politica_reset,
    unidades_por_ciclo: recurrente.value
      ? unidades(form.unidades_por_ciclo)
      : null,
    politica_rollover: form.politica_rollover,
    rollover_max:
      form.politica_rollover === "limitado"
        ? unidades(form.rollover_max)
        : null,
    sucursal_id: form.sucursal_id || null,
  };
  try {
    if (esEdicion.value && props.producto) {
      await api.put(`${base}/productos/${props.producto.id}`, {
        ...carga,
        archivado: form.archivado,
      });
    } else {
      await api.post(`${base}/productos`, carga);
    }
    emit("guardado");
    emit("cerrar");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

onMounted(cargarSucursales);
</script>

<template>
  <div class="fixed inset-0 z-50 flex justify-end">
    <div class="absolute inset-0 bg-black/40" @click="emit('cerrar')" />
    <aside
      class="relative flex h-full w-full max-w-md flex-col overflow-y-auto"
      :style="{ background: 'var(--superficie)', boxShadow: 'var(--sombra)' }"
    >
      <header
        class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b px-5 py-4"
        :style="{
          background: 'var(--superficie)',
          borderColor: 'var(--borde)',
        }"
      >
        <p class="text-lg font-light truncate">
          {{
            esEdicion
              ? $t("productoEditor.tituloEditar")
              : $t("productoEditor.tituloNuevo")
          }}
        </p>
        <button
          type="button"
          class="tu-icono-btn shrink-0"
          :aria-label="$t('comun.cerrar')"
          @click="emit('cerrar')"
        >
          <span aria-hidden="true">✕</span>
        </button>
      </header>

      <form class="flex-1 space-y-4 px-5 py-4" @submit.prevent="guardar">
        <!-- Básicos -->
        <div>
          <label class="tu-label" for="pn">{{
            $t("productoEditor.nombre")
          }}</label>
          <input id="pn" v-model="form.nombre" class="tu-input" required />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="tu-label" for="pt">{{
              $t("productoEditor.tipo")
            }}</label>
            <select id="pt" v-model="form.tipo" class="tu-input">
              <option value="paquete">
                {{ $t("productoEditor.tipos.paquete") }}
              </option>
              <option value="membresia">
                {{ $t("productoEditor.tipos.membresia") }}
              </option>
              <option value="pase_dia">
                {{ $t("productoEditor.tipos.pase_dia") }}
              </option>
              <option value="sesion_individual">
                {{ $t("productoEditor.tipos.sesion_individual") }}
              </option>
              <option value="add_on">
                {{ $t("productoEditor.tipos.add_on") }}
              </option>
              <option value="taller">
                {{ $t("productoEditor.tipos.taller") }}
              </option>
            </select>
          </div>
          <div>
            <label class="tu-label" for="pp">{{
              $t("productoEditor.precio")
            }}</label>
            <input
              id="pp"
              v-model="form.precio"
              class="tu-input"
              type="number"
              min="0"
              step="0.01"
              required
            />
          </div>
        </div>

        <!-- Acceso -->
        <div
          class="border-t pt-4 space-y-3"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <p class="text-sm font-semibold">{{ $t("productoEditor.acceso") }}</p>
          <label class="flex items-center justify-between gap-3 text-sm">
            <span>
              {{ $t("productoEditor.ilimitado") }}
              <span
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t("productoEditor.ilimitadoAyuda") }}</span
              >
            </span>
            <input v-model="form.ilimitado" type="checkbox" class="h-5 w-5" />
          </label>
          <div v-if="!form.ilimitado">
            <label class="tu-label" for="pc">{{
              $t("productoEditor.creditos")
            }}</label>
            <input
              id="pc"
              v-model="form.creditos"
              class="tu-input"
              type="number"
              min="0"
              step="0.5"
            />
          </div>
        </div>

        <!-- Vigencia -->
        <div class="border-t pt-4" :style="{ borderColor: 'var(--borde)' }">
          <label class="tu-label" for="pv">{{
            $t("productoEditor.vigencia")
          }}</label>
          <input
            id="pv"
            v-model="form.vigencia_dias"
            class="tu-input"
            type="number"
            min="1"
            :placeholder="$t('productoEditor.vigenciaPh')"
          />
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("productoEditor.vigenciaAyuda") }}
          </p>
        </div>

        <!-- Recurrencia (ciclos + rollover) -->
        <div
          class="border-t pt-4 space-y-3"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <p class="text-sm font-semibold">
            {{ $t("productoEditor.recurrencia") }}
          </p>
          <div>
            <label class="tu-label" for="pr">{{
              $t("productoEditor.reset")
            }}</label>
            <select id="pr" v-model="form.politica_reset" class="tu-input">
              <option value="ninguno">
                {{ $t("productoEditor.resets.ninguno") }}
              </option>
              <option value="calendario">
                {{ $t("productoEditor.resets.calendario") }}
              </option>
              <option value="aniversario">
                {{ $t("productoEditor.resets.aniversario") }}
              </option>
            </select>
          </div>
          <template v-if="recurrente">
            <div>
              <label class="tu-label" for="puc">{{
                $t("productoEditor.unidadesCiclo")
              }}</label>
              <input
                id="puc"
                v-model="form.unidades_por_ciclo"
                class="tu-input"
                type="number"
                min="0"
                step="0.5"
              />
            </div>
            <div>
              <label class="tu-label" for="prol">{{
                $t("productoEditor.rollover")
              }}</label>
              <select
                id="prol"
                v-model="form.politica_rollover"
                class="tu-input"
              >
                <option value="ninguno">
                  {{ $t("productoEditor.rollovers.ninguno") }}
                </option>
                <option value="completo">
                  {{ $t("productoEditor.rollovers.completo") }}
                </option>
                <option value="limitado">
                  {{ $t("productoEditor.rollovers.limitado") }}
                </option>
              </select>
            </div>
            <div v-if="form.politica_rollover === 'limitado'">
              <label class="tu-label" for="prm">{{
                $t("productoEditor.rolloverMax")
              }}</label>
              <input
                id="prm"
                v-model="form.rollover_max"
                class="tu-input"
                type="number"
                min="0"
                step="0.5"
              />
            </div>
          </template>
        </div>

        <!-- Restricción por sede -->
        <div
          v-if="sucursales.length > 0"
          class="border-t pt-4"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <label class="tu-label" for="ps">{{
            $t("productoEditor.sede")
          }}</label>
          <select id="ps" v-model="form.sucursal_id" class="tu-input">
            <option value="">{{ $t("productoEditor.todasSedes") }}</option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>

        <!-- Estado (solo edición) -->
        <label
          v-if="esEdicion"
          class="flex items-center justify-between gap-3 border-t pt-4 text-sm"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <span>
            {{ $t("productoEditor.archivado") }}
            <span
              class="block text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("productoEditor.archivadoAyuda") }}</span
            >
          </span>
          <input v-model="form.archivado" type="checkbox" class="h-5 w-5" />
        </label>

        <p v-if="error" class="text-sm" style="color: var(--error)">
          {{ error }}
        </p>
        <button
          class="tu-btn tu-btn-primario w-full"
          type="submit"
          :disabled="guardando || form.nombre.trim() === ''"
        >
          {{ guardando ? $t("comun.guardar") + "…" : $t("comun.guardar") }}
        </button>
      </form>
    </aside>
  </div>
</template>
