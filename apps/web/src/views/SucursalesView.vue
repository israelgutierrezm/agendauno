<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Sucursal {
  id: string;
  nombre: string;
  zona_horaria: string | null;
  region: string | null;
  moneda: string | null;
  impuesto_tasa_bps: number;
  latitud?: number | null;
  longitud?: number | null;
}
interface Organizacion {
  id: string;
  nombre: string;
  sucursales: Sucursal[];
}

// Zonas horarias frecuentes en México (más una opción para el resto del mundo).
const ZONAS = [
  "America/Mexico_City",
  "America/Cancun",
  "America/Merida",
  "America/Monterrey",
  "America/Hermosillo",
  "America/Tijuana",
  "America/Bogota",
  "UTC",
];

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("sucursales.gestionar"));

const organizaciones = ref<Organizacion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

const totalSucursales = computed(() =>
  organizaciones.value.reduce((n, o) => n + o.sucursales.length, 0),
);

// Drawer de alta/edición.
const abierto = ref(false);
const editandoId = ref<string | null>(null);
const orgId = ref("");
const guardando = ref(false);
const form = ref({
  nombre: "",
  region: "",
  zona_horaria: "America/Mexico_City",
  moneda: "MXN",
  iva: "16",
  ubicacion: "",
});

const esNueva = computed(() => editandoId.value === null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Organizacion[] }>(
      `${base.value}/organizaciones`,
    );
    organizaciones.value = data.data;
    if (orgId.value === "" && data.data.length > 0) {
      orgId.value = data.data[0].id;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function abrirNueva(): void {
  editandoId.value = null;
  orgId.value = organizaciones.value[0]?.id ?? "";
  form.value = {
    nombre: "",
    region: "",
    zona_horaria: "America/Mexico_City",
    moneda: "MXN",
    iva: "16",
    ubicacion: "",
  };
  abierto.value = true;
}

function abrirEdicion(s: Sucursal): void {
  editandoId.value = s.id;
  form.value = {
    nombre: s.nombre,
    region: s.region ?? "",
    zona_horaria: s.zona_horaria ?? "America/Mexico_City",
    moneda: s.moneda ?? "MXN",
    iva: String(s.impuesto_tasa_bps / 100),
    ubicacion:
      s.latitud != null && s.longitud != null
        ? `${s.latitud}, ${s.longitud}`
        : "",
  };
  abierto.value = true;
}

// Ubicación: "19.4194, -99.1617" (como la copia Google Maps). Vacía = sin ubicación.
const coordenadas = computed<
  { latitud: number; longitud: number } | null | "invalida"
>(() => {
  const texto = form.value.ubicacion.trim();
  if (texto === "") {
    return null;
  }
  const m = /^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/.exec(texto);
  if (m === null) {
    return "invalida";
  }
  const [latitud, longitud] = [Number(m[1]), Number(m[2])];
  return Math.abs(latitud) <= 90 && Math.abs(longitud) <= 180
    ? { latitud, longitud }
    : "invalida";
});
const enlaceMapa = computed(() => {
  const c = coordenadas.value;
  return c && c !== "invalida"
    ? `https://www.google.com/maps?q=${c.latitud},${c.longitud}`
    : null;
});
const ubicando = ref(false);
const errorUbicacion = ref<string | null>(null);
function usarMiUbicacion(): void {
  if (!("geolocation" in navigator)) {
    errorUbicacion.value = t("operacion.sedes.sinPermiso");
    return;
  }
  ubicando.value = true;
  errorUbicacion.value = null;
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      form.value.ubicacion = `${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)}`;
      ubicando.value = false;
    },
    () => {
      errorUbicacion.value = t("operacion.sedes.sinPermiso");
      ubicando.value = false;
    },
    { timeout: 10000 },
  );
}

function cerrar(): void {
  abierto.value = false;
}

async function guardar(): Promise<void> {
  if (
    !puedeGestionar.value ||
    form.value.nombre.trim() === "" ||
    coordenadas.value === "invalida"
  ) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    const cuerpo = {
      nombre: form.value.nombre.trim(),
      region: form.value.region.trim() !== "" ? form.value.region.trim() : null,
      zona_horaria: form.value.zona_horaria,
      moneda:
        form.value.moneda.trim() !== ""
          ? form.value.moneda.trim().toUpperCase()
          : null,
      impuesto_tasa_bps: Math.round((Number(form.value.iva) || 0) * 100),
      latitud: coordenadas.value === null ? null : coordenadas.value.latitud,
      longitud: coordenadas.value === null ? null : coordenadas.value.longitud,
    };
    if (esNueva.value) {
      await api.post(
        `${base.value}/organizaciones/${orgId.value}/sucursales`,
        cuerpo,
      );
    } else {
      await api.put(`${base.value}/sucursales/${editandoId.value}`, cuerpo);
    }
    abierto.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

function dinero(moneda: string | null): string {
  return moneda ?? "MXN";
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion
        :titulo="$t('sedes.titulo')"
        :total="totalSucursales"
      />
      <button
        v-if="puedeGestionar"
        class="tu-btn tu-btn-primario"
        type="button"
        @click="abrirNueva"
      >
        {{ $t("sedes.agregar") }}
      </button>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <div v-for="org in organizaciones" :key="org.id" class="mt-6">
        <h2
          v-if="organizaciones.length > 1"
          class="font-semibold text-sm mb-2"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ org.nombre }}
        </h2>

        <p
          v-if="org.sucursales.length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("sedes.vacio") }}
        </p>

        <ul v-else class="space-y-2">
          <li
            v-for="s in org.sucursales"
            :key="s.id"
            class="tu-card p-4 flex items-center justify-between gap-3"
          >
            <div class="min-w-0">
              <div class="font-semibold truncate">{{ s.nombre }}</div>
              <div
                class="text-sm truncate"
                :style="{ color: 'var(--texto-suave)' }"
              >
                <span v-if="s.region">{{ s.region }} · </span>
                {{ s.zona_horaria }} · {{ dinero(s.moneda) }} ·
                {{ $t("sedes.iva", { n: s.impuesto_tasa_bps / 100 }) }}
              </div>
            </div>
            <button
              v-if="puedeGestionar"
              class="tu-btn tu-btn-fantasma text-sm shrink-0"
              type="button"
              @click="abrirEdicion(s)"
            >
              {{ $t("sedes.editar") }}
            </button>
          </li>
        </ul>
      </div>
    </template>

    <!-- Alta / edición en drawer lateral -->
    <PanelLateral
      :abierto="abierto"
      :titulo="esNueva ? $t('sedes.nueva') : $t('sedes.editarTitulo')"
      @cerrar="cerrar"
    >
      <form class="space-y-4" @submit.prevent="guardar">
        <div v-if="esNueva && organizaciones.length > 1">
          <label class="tu-label" for="s-org">{{
            $t("sedes.organizacion")
          }}</label>
          <select id="s-org" v-model="orgId" class="tu-input">
            <option v-for="o in organizaciones" :key="o.id" :value="o.id">
              {{ o.nombre }}
            </option>
          </select>
        </div>
        <div>
          <label class="tu-label" for="s-nombre">{{
            $t("sedes.nombre")
          }}</label>
          <input
            id="s-nombre"
            v-model="form.nombre"
            class="tu-input"
            required
            :placeholder="$t('sedes.nombrePh')"
          />
        </div>
        <div>
          <label class="tu-label" for="s-region">{{
            $t("sedes.region")
          }}</label>
          <input id="s-region" v-model="form.region" class="tu-input" />
        </div>
        <div>
          <label class="tu-label" for="s-zona">{{ $t("sedes.zona") }}</label>
          <select id="s-zona" v-model="form.zona_horaria" class="tu-input">
            <option v-for="z in ZONAS" :key="z" :value="z">{{ z }}</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="tu-label" for="s-moneda">{{
              $t("sedes.moneda")
            }}</label>
            <input
              id="s-moneda"
              v-model="form.moneda"
              class="tu-input uppercase"
              maxlength="3"
            />
          </div>
          <div>
            <label class="tu-label" for="s-iva">{{
              $t("sedes.ivaLabel")
            }}</label>
            <input
              id="s-iva"
              v-model="form.iva"
              type="number"
              min="0"
              max="100"
              step="0.5"
              class="tu-input"
            />
          </div>
        </div>
        <div>
          <label class="tu-label" for="s-ubicacion">{{
            $t("operacion.sedes.ubicacion")
          }}</label>
          <input
            id="s-ubicacion"
            v-model="form.ubicacion"
            class="tu-input"
            :placeholder="$t('operacion.sedes.ubicacionPh')"
          />
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("operacion.sedes.ubicacionAyuda") }}
          </p>
          <p
            v-if="coordenadas === 'invalida'"
            class="mt-1 text-xs"
            style="color: var(--error)"
          >
            {{ $t("operacion.sedes.invalida") }}
          </p>
          <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
            <button
              type="button"
              class="tu-enlace"
              :disabled="ubicando"
              @click="usarMiUbicacion"
            >
              {{
                ubicando
                  ? $t("operacion.sedes.ubicando")
                  : $t("operacion.sedes.usarMiUbicacion")
              }}
            </button>
            <a
              v-if="enlaceMapa"
              :href="enlaceMapa"
              target="_blank"
              rel="noopener"
              class="tu-enlace"
              >{{ $t("operacion.sedes.verMapa") }}</a
            >
          </div>
          <p
            v-if="errorUbicacion"
            class="mt-1 text-xs"
            style="color: var(--error)"
          >
            {{ errorUbicacion }}
          </p>
        </div>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button class="tu-btn tu-btn-fantasma" type="button" @click="cerrar">
            {{ $t("comun.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="
              guardando ||
              form.nombre.trim() === '' ||
              coordenadas === 'invalida'
            "
            @click="guardar"
          >
            {{ guardando ? $t("comun.guardar") + "…" : $t("comun.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>
