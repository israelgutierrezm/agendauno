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
  duracion_minutos: number | null;
  // Preparación antes y limpieza después: ocupan la agenda, no se le dicen al cliente.
  preparacion_min?: number;
  limpieza_min?: number;
  // Espacios o equipos que puede usar (2.4); vacío = no requiere.
  recursos?: string[];
  actividad: string | null;
}
interface Recurso {
  id: string;
  nombre: string;
  sucursal: string | null;
  activo: boolean;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("catalogo.gestionar"));

const ofertas = ref<Oferta[]>([]);
const recursos = ref<Recurso[]>([]);
function nombresDe(ids: string[] | undefined): string {
  return (ids ?? [])
    .map((id) => recursos.value.find((r) => r.id === id)?.nombre)
    .filter(Boolean)
    .join(", ");
}
const cargando = ref(true);
const error = ref<string | null>(null);

// Oferta en edición (una a la vez) + su formulario.
const editandoId = ref<string | null>(null);
const form = ref<{
  politica: Politica;
  precio: string;
  duracion: string;
  lugares: string;
  preparacion: string;
  limpieza: string;
  recursos: string[];
}>({
  politica: "entitlement",
  precio: "",
  duracion: "",
  lugares: "0",
  preparacion: "0",
  limpieza: "0",
  recursos: [],
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
// El pago-al-agendar (cita) necesita duración para calcular los huecos.
const duracionInvalida = computed(
  () =>
    form.value.politica === "pago" && (Number(form.value.duracion) || 0) <= 0,
);
const formInvalido = computed(
  () => precioInvalido.value || duracionInvalida.value,
);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Oferta[] }>(`${base.value}/ofertas`);
    ofertas.value = data.data;
    // Espacios del negocio (si no hay, la sección no aparece).
    const r = await api.get<{ data: Recurso[] }>(`${base.value}/recursos`);
    recursos.value = r.data.data.filter((x) => x.activo);
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
    duracion: o.duracion_minutos !== null ? String(o.duracion_minutos) : "",
    lugares: String(o.lugares),
    preparacion: String(o.preparacion_min ?? 0),
    limpieza: String(o.limpieza_min ?? 0),
    recursos: [...(o.recursos ?? [])],
  };
}
function cerrar(): void {
  editandoId.value = null;
}

async function guardar(o: Oferta): Promise<void> {
  if (!puedeGestionar.value || formInvalido.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    const precioMinor = Math.round((Number(form.value.precio) || 0) * 100);
    const duracion = Number(form.value.duracion) || 0;
    await api.put(`${base.value}/ofertas/${o.id}`, {
      lugares: Number(form.value.lugares) || 0,
      // Con pago, mandamos el precio; sin precio lo limpiamos (null).
      precio_clase_minor: precioMinor > 0 ? precioMinor : null,
      politica_reserva: form.value.politica,
      // La duración solo aplica a citas; sin valor la limpiamos (null).
      duracion_minutos: duracion > 0 ? duracion : null,
      preparacion_min: Math.max(0, Number(form.value.preparacion) || 0),
      limpieza_min: Math.max(0, Number(form.value.limpieza) || 0),
      ...(recursos.value.length > 0 ? { recursos: form.value.recursos } : {}),
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
  <section class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      :titulo="$t('catalogo.titulo')"
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
              <!-- Una sola línea de datos: actividad · modalidad · cómo se reserva -->
              <p
                class="mt-0.5 text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                <template v-if="o.actividad">{{ o.actividad }} · </template
                >{{ $t(`catalogo.modalidad.${o.modalidad}`) }} ·
                <template v-if="o.politica_reserva === 'pago'"
                  >{{ $t("catalogo.badgePago") }}
                  {{ dinero(o.precio_clase_minor)
                  }}<template v-if="o.duracion_minutos">
                    · {{ o.duracion_minutos }} min</template
                  ></template
                >
                <template v-else>{{
                  $t("catalogo.badgeEntitlement")
                }}</template>
                <!-- Va después del v-if/v-else de cómo se reserva (no entre ellos). -->
                <template
                  v-if="(o.preparacion_min ?? 0) + (o.limpieza_min ?? 0) > 0"
                >
                  ·
                  {{
                    $t("margenesServicio.resumen", {
                      n: (o.preparacion_min ?? 0) + (o.limpieza_min ?? 0),
                    })
                  }}</template
                ><template v-if="(o.recursos ?? []).length > 0">
                  ·
                  {{
                    $t("recursosServicio.resumen", {
                      lista: nombresDe(o.recursos),
                    })
                  }}</template
                >
                <span
                  v-if="guardadoId === o.id"
                  class="ml-2"
                  :style="{ color: 'var(--exito)' }"
                  >{{ $t("catalogo.guardado") }}</span
                >
              </p>
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
              <div v-if="form.politica === 'pago'">
                <label class="tu-label" :for="`duracion-${o.id}`">{{
                  $t("catalogo.duracion")
                }}</label>
                <input
                  :id="`duracion-${o.id}`"
                  v-model="form.duracion"
                  type="number"
                  min="5"
                  step="5"
                  class="tu-input"
                  :style="
                    duracionInvalida ? { borderColor: 'var(--error)' } : {}
                  "
                />
                <span
                  v-if="duracionInvalida"
                  class="tu-hint"
                  style="color: var(--error)"
                  >{{ $t("catalogo.duracionReq") }}</span
                >
                <span v-else class="tu-hint">{{
                  $t("catalogo.duracionAyuda")
                }}</span>
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
              <!-- Preparación y limpieza (2.3): ocupan la agenda, no la cita. -->
              <div>
                <label class="tu-label" :for="`preparacion-${o.id}`">{{
                  $t("margenesServicio.preparacion")
                }}</label>
                <input
                  :id="`preparacion-${o.id}`"
                  v-model="form.preparacion"
                  type="number"
                  min="0"
                  max="240"
                  step="5"
                  class="tu-input"
                />
              </div>
              <div>
                <label class="tu-label" :for="`limpieza-${o.id}`">{{
                  $t("margenesServicio.limpieza")
                }}</label>
                <input
                  :id="`limpieza-${o.id}`"
                  v-model="form.limpieza"
                  type="number"
                  min="0"
                  max="240"
                  step="5"
                  class="tu-input"
                />
                <span class="tu-hint">{{ $t("margenesServicio.ayuda") }}</span>
              </div>
              <!-- Espacios que usa (2.4): solo si el negocio tiene espacios. -->
              <fieldset v-if="recursos.length > 0" class="sm:col-span-2">
                <legend class="tu-label">
                  {{ $t("recursosServicio.titulo") }}
                </legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                  <label
                    v-for="r in recursos"
                    :key="r.id"
                    class="flex items-center gap-2"
                  >
                    <input
                      v-model="form.recursos"
                      type="checkbox"
                      :value="r.id"
                    />
                    {{ r.nombre
                    }}<span
                      v-if="r.sucursal"
                      :style="{ color: 'var(--texto-suave)' }"
                      >· {{ r.sucursal }}</span
                    >
                  </label>
                </div>
                <span class="tu-hint">{{ $t("recursosServicio.ayuda") }}</span>
              </fieldset>
            </div>

            <div class="mt-4 flex items-center gap-3">
              <button
                class="tu-btn tu-btn-primario"
                type="button"
                :disabled="guardando || formInvalido"
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
