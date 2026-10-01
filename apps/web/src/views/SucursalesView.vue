<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import CargadorImagen from "@/components/CargadorImagen.vue";
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
  direccion?: string | null;
  telefono?: string | null;
  whatsapp?: string | null;
  redes?: Partial<Record<Red, string>>;
  horario?: { dia: number; abre: string; cierra: string }[];
  // La foto que ve el cliente al elegirla y su enlace de Google Maps.
  foto_url?: string | null;
  mapa_url?: string | null;
}
// Redes que puede tener la sede (si tiene cuentas propias).
const REDES = [
  "instagram",
  "facebook",
  "tiktok",
  "youtube",
  "sitio_web",
] as const;
type Red = (typeof REDES)[number];
interface DiaHorario {
  dia: number;
  abierto: boolean;
  abre: string;
  cierra: string;
}
function horarioVacio(): DiaHorario[] {
  return [1, 2, 3, 4, 5, 6, 7].map((dia) => ({
    dia,
    abierto: false,
    abre: "09:00",
    cierra: "20:00",
  }));
}
function redesVacias(): Record<Red, string> {
  return {
    instagram: "",
    facebook: "",
    tiktok: "",
    youtube: "",
    sitio_web: "",
  };
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
  direccion: "",
  mapa_url: "",
  telefono: "",
  whatsapp: "",
  redes: redesVacias(),
  horario: horarioVacio(),
});
// Foto de la sede en edición (se sube aparte, al momento).
const fotoSede = ref<string | null>(null);
function fotoCambiada(url: string | null): void {
  fotoSede.value = url;
  for (const o of organizaciones.value) {
    const s = o.sucursales.find((x) => x.id === editandoId.value);
    if (s) s.foto_url = url;
  }
}
const errores = ref<Record<string, string>>({});
// El error de un día abierto: la API numera solo los días que se envían.
function errorDelDia(d: DiaHorario): string | undefined {
  const i = form.value.horario.filter((x) => x.abierto).indexOf(d);
  return errores.value[`horario.${i}.cierra`];
}

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
    direccion: "",
    mapa_url: "",
    telefono: "",
    whatsapp: "",
    redes: redesVacias(),
    horario: horarioVacio(),
  };
  fotoSede.value = null;
  errores.value = {};
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
    direccion: s.direccion ?? "",
    mapa_url: s.mapa_url ?? "",
    telefono: s.telefono ?? "",
    whatsapp: s.whatsapp ?? "",
    redes: { ...redesVacias(), ...(s.redes ?? {}) },
    horario: horarioVacio().map((d) => {
      const guardado = (s.horario ?? []).find((h) => h.dia === d.dia);
      return guardado
        ? {
            dia: d.dia,
            abierto: true,
            abre: guardado.abre,
            cierra: guardado.cierra,
          }
        : d;
    }),
  };
  fotoSede.value = s.foto_url ?? null;
  errores.value = {};
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
  errores.value = {};
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
      // Perfil público de la sede.
      direccion: form.value.direccion.trim(),
      mapa_url: form.value.mapa_url.trim(),
      telefono: form.value.telefono.trim(),
      whatsapp: form.value.whatsapp.trim(),
      redes: form.value.redes,
      horario: form.value.horario
        .filter((d) => d.abierto)
        .map((d) => ({ dia: d.dia, abre: d.abre, cierra: d.cierra })),
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
    const detalle = (
      e as {
        response?: { data?: { meta?: { errors?: Record<string, string[]> } } };
      }
    ).response?.data?.meta?.errors;
    errores.value = Object.fromEntries(
      Object.entries(detalle ?? {}).map(([k, v]) => [k, v[0] ?? ""]),
    );
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
        class="tu-btn tu-btn-primario tu-btn-crear"
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
            <img
              v-if="s.foto_url"
              :src="s.foto_url"
              alt=""
              class="h-12 w-16 shrink-0 rounded-lg object-cover"
            />
            <div class="min-w-0 flex-1">
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

        <!-- Perfil público de la sede -->
        <div
          class="border-t pt-4 space-y-4"
          :style="{ borderColor: 'var(--borde)' }"
          data-prueba="perfil-sede"
        >
          <div>
            <h3 class="text-sm font-semibold">
              {{ $t("perfilPublico.sucursal.titulo") }}
            </h3>
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("perfilPublico.sucursal.ayuda") }}
            </p>
          </div>
          <div data-prueba="foto-sede">
            <span class="tu-label">{{
              $t("perfilPublico.sucursal.foto")
            }}</span>
            <CargadorImagen
              v-if="!esNueva"
              :url="fotoSede"
              :ruta="`sucursales/${editandoId}/foto`"
              campo="foto"
              clave="foto_url"
              proporcion="16 / 9"
              :arrastra="$t('perfilPublico.sucursal.fotoArrastra')"
              :ayuda="$t('perfilPublico.sucursal.fotoAyuda')"
              :quitar-texto="$t('perfilPublico.sucursal.fotoQuitar')"
              :puede-gestionar="puedeGestionar"
              @update:url="fotoCambiada"
            />
            <p v-else class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("perfilPublico.sucursal.fotoAlGuardar") }}
            </p>
          </div>
          <div>
            <label class="tu-label" for="s-direccion">{{
              $t("perfilPublico.sucursal.direccion")
            }}</label>
            <input
              id="s-direccion"
              v-model="form.direccion"
              class="tu-input"
              maxlength="255"
              :placeholder="$t('perfilPublico.sucursal.direccionPh')"
            />
          </div>
          <div>
            <label class="tu-label" for="s-mapa">{{
              $t("perfilPublico.sucursal.mapa")
            }}</label>
            <input
              id="s-mapa"
              v-model="form.mapa_url"
              class="tu-input"
              inputmode="url"
              maxlength="500"
              placeholder="https://maps.app.goo.gl/…"
            />
            <p
              v-if="errores.mapa_url"
              class="mt-1 text-xs"
              style="color: var(--error)"
            >
              {{ errores.mapa_url }}
            </p>
            <p
              v-else
              class="mt-1 text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("perfilPublico.sucursal.mapaAyuda") }}
            </p>
          </div>
          <div class="grid gap-3 grid-cols-2">
            <div>
              <label class="tu-label" for="s-telefono">{{
                $t("perfilPublico.sucursal.telefono")
              }}</label>
              <input
                id="s-telefono"
                v-model="form.telefono"
                class="tu-input"
                inputmode="tel"
                maxlength="30"
              />
            </div>
            <div>
              <label class="tu-label" for="s-whatsapp">{{
                $t("perfilPublico.sucursal.whatsapp")
              }}</label>
              <input
                id="s-whatsapp"
                v-model="form.whatsapp"
                class="tu-input"
                inputmode="tel"
                maxlength="30"
                :placeholder="$t('perfilPublico.sucursal.whatsappPh')"
              />
            </div>
          </div>
          <p
            v-if="errores.telefono || errores.whatsapp"
            class="-mt-2 text-xs"
            style="color: var(--error)"
          >
            {{ errores.telefono || errores.whatsapp }}
          </p>

          <fieldset>
            <legend class="tu-label">
              {{ $t("perfilPublico.sucursal.redes") }}
            </legend>
            <p
              class="-mt-1 mb-2 text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("perfilPublico.sucursal.redesAyuda") }}
            </p>
            <div class="grid gap-2 grid-cols-2">
              <label v-for="r in REDES" :key="r" class="block">
                <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                  {{ $t(`perfilPublico.redes.${r}`) }}
                </span>
                <input
                  v-model="form.redes[r]"
                  class="tu-input"
                  :placeholder="$t(`perfilPublico.redesPh.${r}`)"
                />
                <span
                  v-if="errores[`redes.${r}`]"
                  class="text-xs"
                  style="color: var(--error)"
                >
                  {{ errores[`redes.${r}`] }}
                </span>
              </label>
            </div>
          </fieldset>

          <fieldset>
            <legend class="tu-label">
              {{ $t("perfilPublico.sucursal.horario") }}
            </legend>
            <p
              class="-mt-1 mb-2 text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("perfilPublico.sucursal.horarioAyuda") }}
            </p>
            <ul class="space-y-1.5" data-prueba="horario-sede">
              <li
                v-for="d in form.horario"
                :key="d.dia"
                class="flex flex-wrap items-center gap-2 text-sm"
              >
                <label class="flex w-28 items-center gap-2">
                  <input v-model="d.abierto" type="checkbox" />
                  {{ $t(`perfilPublico.dias.${d.dia}`) }}
                </label>
                <template v-if="d.abierto">
                  <input
                    v-model="d.abre"
                    type="time"
                    class="tu-input w-auto"
                    :aria-label="$t('perfilPublico.sucursal.abre')"
                  />
                  <span :style="{ color: 'var(--texto-suave)' }">–</span>
                  <input
                    v-model="d.cierra"
                    type="time"
                    class="tu-input w-auto"
                    :aria-label="$t('perfilPublico.sucursal.cierra')"
                  />
                  <span
                    v-if="errorDelDia(d)"
                    class="text-xs"
                    style="color: var(--error)"
                  >
                    {{ errorDelDia(d) }}
                  </span>
                </template>
                <span v-else :style="{ color: 'var(--texto-suave)' }">{{
                  $t("perfilPublico.publico.cerrado")
                }}</span>
              </li>
            </ul>
          </fieldset>
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
