<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

type Politica = "entitlement" | "pago";

interface Oferta {
  id: string;
  nombre: string;
  modalidad: string;
  capacidad: number | null;
  lugares: number;
  precio_clase_minor: number | null;
  politica_reserva: Politica;
  actividad: string | null;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("catalogo.gestionar"));

const ofertas = ref<Oferta[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

// Oferta en edición (una a la vez) + su formulario.
const editandoId = ref<string | null>(null);
const form = ref<{ politica: Politica; precio: string; lugares: string }>({
  politica: "entitlement",
  precio: "",
  lugares: "0",
});
const guardando = ref(false);
const guardadoId = ref<string | null>(null);

function dinero(minor: number | null): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
  }).format((minor ?? 0) / 100);
}

// Para el pago-al-agendar hace falta un precio mayor a 0.
const precioInvalido = computed(
  () => form.value.politica === "pago" && (Number(form.value.precio) || 0) <= 0,
);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Oferta[] }>(`${base.value}/ofertas`);
    ofertas.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function configurar(o: Oferta): void {
  editandoId.value = o.id;
  guardadoId.value = null;
  form.value = {
    politica: o.politica_reserva,
    precio:
      o.precio_clase_minor !== null ? String(o.precio_clase_minor / 100) : "",
    lugares: String(o.lugares),
  };
}
function cerrar(): void {
  editandoId.value = null;
}

async function guardar(o: Oferta): Promise<void> {
  if (!puedeGestionar.value || precioInvalido.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    const precioMinor = Math.round((Number(form.value.precio) || 0) * 100);
    await api.put(`${base.value}/ofertas/${o.id}`, {
      lugares: Number(form.value.lugares) || 0,
      // Con pago, mandamos el precio; sin precio lo limpiamos (null).
      precio_clase_minor: precioMinor > 0 ? precioMinor : null,
      politica_reserva: form.value.politica,
    });
    guardadoId.value = o.id;
    editandoId.value = null;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      icono="ventas"
      :titulo="$t('catalogo.titulo')"
      :subtitulo="$t('catalogo.subtitulo')"
      :total="ofertas.length"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <p
        v-if="ofertas.length === 0"
        class="mt-8 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("catalogo.vacio") }}
      </p>

      <ul v-else class="mt-6 space-y-3">
        <li v-for="o in ofertas" :key="o.id" class="tu-card p-5">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <h3 class="font-semibold">{{ o.nombre }}</h3>
              <p
                v-if="o.actividad"
                class="text-sm truncate"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ o.actividad }}
              </p>
              <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="tu-badge">{{
                  $t(`catalogo.modalidad.${o.modalidad}`)
                }}</span>
                <span
                  v-if="o.politica_reserva === 'pago'"
                  class="tu-badge tu-badge-aviso"
                >
                  {{ $t("catalogo.badgePago") }} ·
                  {{ dinero(o.precio_clase_minor) }}
                </span>
                <span v-else class="tu-badge">{{
                  $t("catalogo.badgeEntitlement")
                }}</span>
                <span
                  v-if="guardadoId === o.id"
                  class="text-sm"
                  :style="{ color: 'var(--exito)' }"
                  >{{ $t("catalogo.guardado") }}</span
                >
              </div>
            </div>
            <button
              v-if="puedeGestionar && editandoId !== o.id"
              class="tu-btn tu-btn-fantasma shrink-0"
              type="button"
              @click="configurar(o)"
            >
              {{ $t("catalogo.configurar") }}
            </button>
          </div>

          <!-- Editor inline -->
          <div
            v-if="editandoId === o.id"
            class="mt-4 border-t pt-4"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <label class="tu-label">{{
              $t("catalogo.politica.etiqueta")
            }}</label>
            <div class="mt-1 space-y-2">
              <label
                class="flex items-start gap-2 rounded-lg p-3 cursor-pointer border"
                :style="{
                  borderColor:
                    form.politica === 'entitlement'
                      ? 'var(--primario)'
                      : 'var(--borde)',
                  background:
                    form.politica === 'entitlement'
                      ? 'var(--primario-suave)'
                      : 'transparent',
                }"
              >
                <input
                  v-model="form.politica"
                  type="radio"
                  value="entitlement"
                  class="mt-1"
                />
                <span>
                  <span class="font-medium">{{
                    $t("catalogo.politica.entitlement")
                  }}</span>
                  <span
                    class="block text-sm"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ $t("catalogo.politica.entitlementAyuda") }}</span
                  >
                </span>
              </label>
              <label
                class="flex items-start gap-2 rounded-lg p-3 cursor-pointer border"
                :style="{
                  borderColor:
                    form.politica === 'pago'
                      ? 'var(--primario)'
                      : 'var(--borde)',
                  background:
                    form.politica === 'pago'
                      ? 'var(--primario-suave)'
                      : 'transparent',
                }"
              >
                <input
                  v-model="form.politica"
                  type="radio"
                  value="pago"
                  class="mt-1"
                />
                <span>
                  <span class="font-medium">{{
                    $t("catalogo.politica.pago")
                  }}</span>
                  <span
                    class="block text-sm"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ $t("catalogo.politica.pagoAyuda") }}</span
                  >
                </span>
              </label>
            </div>

            <div class="mt-3 grid sm:grid-cols-2 gap-3">
              <div>
                <label class="tu-label" :for="`precio-${o.id}`">{{
                  $t("catalogo.precio")
                }}</label>
                <div class="flex items-center gap-2">
                  <span :style="{ color: 'var(--texto-suave)' }">$</span>
                  <input
                    :id="`precio-${o.id}`"
                    v-model="form.precio"
                    type="number"
                    min="0"
                    step="1"
                    class="tu-input"
                    :style="
                      precioInvalido ? { borderColor: 'var(--error)' } : {}
                    "
                  />
                  <span
                    class="text-sm"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ $t("catalogo.moneda") }}</span
                  >
                </div>
                <span
                  v-if="precioInvalido"
                  class="tu-hint"
                  style="color: var(--error)"
                  >{{ $t("catalogo.precioReq") }}</span
                >
              </div>
              <div>
                <label class="tu-label" :for="`lugares-${o.id}`">{{
                  $t("catalogo.lugares")
                }}</label>
                <input
                  :id="`lugares-${o.id}`"
                  v-model="form.lugares"
                  type="number"
                  min="0"
                  step="1"
                  class="tu-input"
                />
                <span class="tu-hint">{{ $t("catalogo.lugaresAyuda") }}</span>
              </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
              <button
                class="tu-btn tu-btn-primario"
                type="button"
                :disabled="guardando || precioInvalido"
                @click="guardar(o)"
              >
                {{
                  guardando ? $t("catalogo.guardando") : $t("catalogo.guardar")
                }}
              </button>
              <button
                class="tu-btn tu-btn-fantasma"
                type="button"
                :disabled="guardando"
                @click="cerrar"
              >
                {{ $t("catalogo.cerrar") }}
              </button>
            </div>
          </div>
        </li>
      </ul>
    </template>
  </section>
</template>
