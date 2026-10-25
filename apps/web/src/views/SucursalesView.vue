<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import CampoCelular from "@/components/CampoCelular.vue";
import BarraListado from "@/components/BarraListado.vue";
import CargadorImagen from "@/components/CargadorImagen.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import SelectorBuscable from "@/components/SelectorBuscable.vue";
import { api, mensajeDeError } from "@/lib/api";
import { etiquetaZona, opcionesZona, ZONA_POR_OMISION } from "@/lib/region";
import { useDescartarCambios } from "@/lib/editorFormulario";
import { useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";
import { normalizar } from "@/lib/menu";

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

const { t } = useI18n();
const toast = useToastStore();
const sesion = useSesionTenantStore();
// Sin zona propia, la del negocio (CDMX si no eligió otra). La moneda es la del
// negocio: una sola para todas sus sucursales (ADR 0099).
const zonaNegocio = computed(
  () => sesion.estudio?.zona_horaria ?? ZONA_POR_OMISION,
);
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("sucursales.gestionar"));

const organizaciones = ref<Organizacion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

const totalSucursales = computed(() =>
  organizaciones.value.reduce((n, o) => n + o.sucursales.length, 0),
);
const busqueda = ref("");
// La búsqueda va en la dirección: un enlace o volver atrás la conserva.
useFiltrosEnUrl({ q: busqueda });
const listado = computed(() => {
  const q = normalizar(busqueda.value.trim());
  return organizaciones.value
    .map((org) => ({
      ...org,
      sucursales: org.sucursales.filter(
        (s) =>
          !q ||
          normalizar(
            [s.nombre, s.region, s.direccion].filter(Boolean).join(" "),
          ).includes(q),
      ),
    }))
    .filter((org) => !q || org.sucursales.length > 0);
});

// Drawer de alta/edición.
const abierto = ref(false);
const editandoId = ref<string | null>(null);
const orgId = ref("");
const guardando = ref(false);
const form = ref({
  nombre: "",
  region: "",
  zona_horaria: ZONA_POR_OMISION,
  iva: "16",
  ubicacion: "",
  direccion: "",
  mapa_url: "",
  telefono: "",
  whatsapp: "",
  redes: redesVacias(),
  horario: horarioVacio(),
});
// Con cambios sin guardar, cerrar o salir pregunta antes (patrón de formularios).
const descartar = useDescartarCambios({
  abierto,
  valor: () => form.value,
  guardando,
});
// Todas las zonas, con las del país del negocio primero (ADR 0103).
const zonas = computed(() =>
  opcionesZona(sesion.pais, form.value.zona_horaria),
);
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
// Lo que no se pudo guardar, dentro del diálogo (no en la página de atrás): p. ej. una
// segunda sucursal sin el plan que la incluye. Cada error de campo, junto a su campo.
const errorGuardar = ref<string | null>(null);
const CAMPOS_FORM = new Set([
  "nombre",
  "region",
  "zona_horaria",
  "impuesto_tasa_bps",
  "direccion",
  "latitud",
  "longitud",
  "mapa_url",
  "telefono",
  "whatsapp",
]);
function campoEnFormulario(campo: string): boolean {
  return (
    CAMPOS_FORM.has(campo) ||
    campo.startsWith("redes.") ||
    /^horario\.\d+\.cierra$/.test(campo)
  );
}
async function enfocarPrimerError(): Promise<void> {
  await nextTick();
  document.querySelector<HTMLElement>('#s-form [aria-invalid="true"]')?.focus();
}
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
    zona_horaria: zonaNegocio.value,
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
  errorGuardar.value = null;
  descartar.fijar();
  abierto.value = true;
}

function abrirEdicion(s: Sucursal): void {
  editandoId.value = s.id;
  form.value = {
    nombre: s.nombre,
    region: s.region ?? "",
    zona_horaria: s.zona_horaria ?? zonaNegocio.value,
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
  errorGuardar.value = null;
  descartar.fijar();
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
  void descartar.cerrar();
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
  errorGuardar.value = null;
  errores.value = {};
  try {
    const cuerpo = {
      nombre: form.value.nombre.trim(),
      region: form.value.region.trim() !== "" ? form.value.region.trim() : null,
      zona_horaria: form.value.zona_horaria,
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
      // Sin organización todavía (se omitió el paso «Tu negocio» de la configuración
      // inicial): se crea con el nombre del negocio, como lo hace ese paso.
      if (orgId.value === "") {
        const org = await api.post<{ data: { id: string } }>(
          `${base.value}/organizaciones`,
          { nombre: sesion.estudio?.nombre ?? cuerpo.nombre },
        );
        orgId.value = org.data.data.id;
      }
      await api.post(
        `${base.value}/organizaciones/${orgId.value}/sucursales`,
        cuerpo,
      );
    } else {
      await api.put(`${base.value}/sucursales/${editandoId.value}`, cuerpo);
    }
    descartar.fijar();
    abierto.value = false;
    toast.exito(t("operacion.sedes.guardada", { nombre: cuerpo.nombre }));
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
    const campos = Object.keys(errores.value);
    errorGuardar.value =
      campos.length > 0 && campos.every(campoEnFormulario)
        ? t("comun.revisaCampos")
        : mensajeDeError(e);
    void enfocarPrimerError();
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('sedes.titulo')"
      :total="totalSucursales"
      :subtitulo="$t('adminUsabilidad.sucursales.ayuda')"
    >
      <template #acciones>
        <button
          v-if="puedeGestionar"
          class="tu-btn tu-btn-primario tu-btn-crear"
          type="button"
          @click="abrirNueva"
        >
          {{ $t("sedes.agregar") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>

    <template v-if="!cargando">
      <BarraListado
        v-if="totalSucursales"
        v-model:busqueda="busqueda"
        class="mt-5"
        :placeholder="$t('adminUsabilidad.sucursales.buscar')"
      />
      <EstadoVacio
        v-if="totalSucursales && !listado.length"
        class="mt-6"
        icono="buscar"
        :titulo="$t('adminUsabilidad.sucursales.sinResultados')"
      />
      <!-- Sin organización aún: el mismo vacío; «Agregar sucursal» la crea. -->
      <EstadoVacio
        v-if="!error && organizaciones.length === 0"
        class="mt-6"
        icono="ubicacion"
        data-prueba="sin-sucursales"
        :titulo="$t('sedes.vacio')"
      />
      <div v-for="org in listado" :key="org.id" class="mt-6">
        <h2
          v-if="organizaciones.length > 1"
          class="font-semibold text-sm mb-2"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ org.nombre }}
        </h2>

        <EstadoVacio
          v-if="org.sucursales.length === 0"
          class="py-6"
          icono="ubicacion"
          compacto
          :titulo="$t('sedes.vacio')"
        />

        <div v-else class="su-rejilla">
          <article
            v-for="s in org.sucursales"
            :key="s.id"
            class="tu-card su-tarjeta"
            data-prueba="sucursal"
          >
            <div class="su-cabecera">
              <img
                v-if="s.foto_url"
                :src="s.foto_url"
                alt=""
                class="su-foto"
                loading="lazy"
              />
              <span v-else class="su-foto su-sin-foto" aria-hidden="true"
                ><IconoNav nombre="ubicacion" :tam="27"
              /></span>
              <div class="min-w-0">
                <h2 class="font-semibold break-words">{{ s.nombre }}</h2>
                <p v-if="s.region" class="su-suave mt-1 text-sm">
                  {{ s.region }}
                </p>
              </div>
            </div>
            <p class="su-direccion">
              <IconoNav nombre="ubicacion" :tam="16" class="shrink-0" /><span>{{
                s.direccion || $t("adminUsabilidad.sucursales.sinDireccion")
              }}</span>
            </p>
            <dl class="su-datos">
              <div>
                <dt>{{ $t("adminUsabilidad.sucursales.zona") }}</dt>
                <dd>{{ etiquetaZona(s.zona_horaria ?? zonaNegocio) }}</dd>
              </div>
              <div>
                <dt>{{ $t("adminUsabilidad.sucursales.impuesto") }}</dt>
                <dd>{{ s.impuesto_tasa_bps / 100 }}%</dd>
              </div>
              <div v-if="s.telefono">
                <dt>{{ $t("perfilPublico.sucursal.telefono") }}</dt>
                <dd>{{ s.telefono }}</dd>
              </div>
            </dl>
            <div class="su-acciones">
              <!-- Ver el mapa abre información (otra página): es un enlace. -->
              <a
                v-if="s.mapa_url"
                :href="s.mapa_url"
                target="_blank"
                rel="noopener noreferrer"
                class="tu-enlace text-sm"
                >{{ $t("operacion.sedes.verMapa") }}</a
              >
              <button
                v-if="puedeGestionar"
                class="tu-btn tu-btn-secundario"
                type="button"
                @click="abrirEdicion(s)"
              >
                <IconoNav nombre="editar" :tam="16" />{{ $t("sedes.editar") }}
              </button>
            </div>
          </article>
        </div>
      </div>
    </template>

    <!-- Formulario amplio por secciones: Datos, Ubicación, Contacto y Horarios
         (más de doce campos: el diálogo ancho). -->
    <ModalDialogo
      :abierto="abierto"
      :titulo="esNueva ? $t('sedes.nueva') : $t('sedes.editarTitulo')"
      icono="ubicacion"
      tam="xl"
      :descripcion="$t('adminUsabilidad.sucursales.formulario')"
      :ocupado="guardando"
      @cerrar="cerrar"
    >
      <form id="s-form" class="grid gap-6" @submit.prevent="guardar">
        <AvisoTono
          v-if="errorGuardar"
          tono="peligro"
          role="alert"
          data-prueba="error-sucursal"
        >
          {{ errorGuardar }}
        </AvisoTono>

        <!-- Datos: cómo se llama, su zona, su impuesto y su foto -->
        <section
          class="su-seccion"
          aria-labelledby="s-sec-datos"
          data-prueba="seccion-datos"
        >
          <header>
            <h3 id="s-sec-datos">
              {{ $t("perfilPublico.sucursal.secciones.datos") }}
            </h3>
            <p>{{ $t("perfilPublico.sucursal.secciones.datosAyuda") }}</p>
          </header>
          <div class="grid gap-4 sm:grid-cols-2">
            <div
              v-if="esNueva && organizaciones.length > 1"
              class="sm:col-span-2"
            >
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
              <label class="tu-label" for="s-nombre"
                >{{ $t("sedes.nombre")
                }}<span class="tu-obligatorio" aria-hidden="true"
                  >*</span
                ></label
              >
              <input
                id="s-nombre"
                v-model="form.nombre"
                class="tu-input"
                required
                maxlength="255"
                :placeholder="$t('sedes.nombrePh')"
                :aria-invalid="errores.nombre ? 'true' : undefined"
                :aria-describedby="
                  errores.nombre ? 's-nombre-error' : undefined
                "
              />
              <span
                v-if="errores.nombre"
                id="s-nombre-error"
                class="tu-error-campo"
                >{{ errores.nombre }}</span
              >
            </div>
            <div>
              <label class="tu-label" for="s-region">{{
                $t("sedes.region")
              }}</label>
              <input
                id="s-region"
                v-model="form.region"
                class="tu-input"
                maxlength="255"
                :placeholder="$t('sedes.regionPh')"
                :aria-invalid="errores.region ? 'true' : undefined"
                :aria-describedby="
                  errores.region ? 's-region-error' : undefined
                "
              />
              <span
                v-if="errores.region"
                id="s-region-error"
                class="tu-error-campo"
                >{{ errores.region }}</span
              >
            </div>
            <div>
              <label class="tu-label" for="s-zona">{{
                $t("sedes.zona")
              }}</label>
              <SelectorBuscable
                id="s-zona"
                v-model="form.zona_horaria"
                :opciones="zonas"
                :aria-invalid="errores.zona_horaria ? 'true' : undefined"
                :aria-describedby="
                  errores.zona_horaria ? 's-zona-error' : undefined
                "
              />
              <span
                v-if="errores.zona_horaria"
                id="s-zona-error"
                class="tu-error-campo"
                >{{ errores.zona_horaria }}</span
              >
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
                step="0.01"
                class="tu-input"
                :aria-invalid="errores.impuesto_tasa_bps ? 'true' : undefined"
                :aria-describedby="
                  errores.impuesto_tasa_bps ? 's-iva-error' : undefined
                "
              />
              <span
                v-if="errores.impuesto_tasa_bps"
                id="s-iva-error"
                class="tu-error-campo"
                >{{ errores.impuesto_tasa_bps }}</span
              >
            </div>
            <div class="sm:col-span-2" data-prueba="foto-sede">
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
              <p v-else class="su-ayuda">
                {{ $t("perfilPublico.sucursal.fotoAlGuardar") }}
              </p>
            </div>
          </div>
        </section>

        <!-- Ubicación: dirección, punto en el mapa y enlace de Google Maps -->
        <section
          class="su-seccion"
          aria-labelledby="s-sec-ubicacion"
          data-prueba="seccion-ubicacion"
        >
          <header>
            <h3 id="s-sec-ubicacion">
              {{ $t("perfilPublico.sucursal.secciones.ubicacion") }}
            </h3>
            <p>{{ $t("perfilPublico.sucursal.secciones.ubicacionAyuda") }}</p>
          </header>
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="tu-label" for="s-direccion">{{
                $t("perfilPublico.sucursal.direccion")
              }}</label>
              <input
                id="s-direccion"
                v-model="form.direccion"
                class="tu-input"
                maxlength="255"
                :placeholder="$t('perfilPublico.sucursal.direccionPh')"
                :aria-invalid="errores.direccion ? 'true' : undefined"
                :aria-describedby="
                  errores.direccion ? 's-direccion-error' : undefined
                "
              />
              <span
                v-if="errores.direccion"
                id="s-direccion-error"
                class="tu-error-campo"
                >{{ errores.direccion }}</span
              >
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
                :aria-invalid="
                  coordenadas === 'invalida' ||
                  errores.latitud ||
                  errores.longitud
                    ? 'true'
                    : undefined
                "
              />
              <p class="mt-1 su-ayuda">
                {{ $t("operacion.sedes.ubicacionAyuda") }}
              </p>
              <p v-if="coordenadas === 'invalida'" class="tu-error-campo">
                {{ $t("operacion.sedes.invalida") }}
              </p>
              <p
                v-else-if="errores.latitud || errores.longitud"
                class="tu-error-campo"
              >
                {{ errores.latitud || errores.longitud }}
              </p>
              <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                <!-- Llena el campo de arriba: es una acción, no un enlace. -->
                <button
                  type="button"
                  class="tu-btn tu-btn-secundario tu-btn-chico"
                  :disabled="ubicando"
                  :aria-busy="ubicando || undefined"
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
                  >{{ $t("operacion.sedes.verMapa") }}
                  <IconoNav
                    nombre="flecha"
                    :tam="14"
                    class="inline align-[-0.15em]"
                /></a>
              </div>
              <p v-if="errorUbicacion" class="tu-error-campo">
                {{ errorUbicacion }}
              </p>
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
                :aria-invalid="errores.mapa_url ? 'true' : undefined"
              />
              <p v-if="errores.mapa_url" class="tu-error-campo">
                {{ errores.mapa_url }}
              </p>
              <p v-else class="mt-1 su-ayuda">
                {{ $t("perfilPublico.sucursal.mapaAyuda") }}
              </p>
            </div>
          </div>
        </section>

        <!-- Contacto: teléfono, WhatsApp (con su lada, ADR 0103) y redes -->
        <section
          class="su-seccion"
          aria-labelledby="s-sec-contacto"
          data-prueba="seccion-contacto"
        >
          <header>
            <h3 id="s-sec-contacto">
              {{ $t("perfilPublico.sucursal.secciones.contacto") }}
            </h3>
            <p>{{ $t("perfilPublico.sucursal.secciones.contactoAyuda") }}</p>
          </header>
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="tu-label" for="s-telefono">{{
                $t("perfilPublico.sucursal.telefono")
              }}</label>
              <CampoCelular
                id="s-telefono"
                v-model="form.telefono"
                maxlength="24"
                :aria-invalid="errores.telefono ? 'true' : undefined"
              />
              <p v-if="errores.telefono" class="tu-error-campo">
                {{ errores.telefono }}
              </p>
            </div>
            <div>
              <label class="tu-label" for="s-whatsapp">{{
                $t("perfilPublico.sucursal.whatsapp")
              }}</label>
              <CampoCelular
                id="s-whatsapp"
                v-model="form.whatsapp"
                maxlength="24"
                :aria-invalid="errores.whatsapp ? 'true' : undefined"
              />
              <p v-if="errores.whatsapp" class="tu-error-campo">
                {{ errores.whatsapp }}
              </p>
            </div>
          </div>
          <fieldset>
            <legend class="tu-label">
              {{ $t("perfilPublico.sucursal.redes") }}
            </legend>
            <p class="-mt-1 mb-2 su-ayuda">
              {{ $t("perfilPublico.sucursal.redesAyuda") }}
            </p>
            <div class="grid gap-2 grid-cols-2">
              <label v-for="r in REDES" :key="r" class="block">
                <span class="su-ayuda">
                  {{ $t(`perfilPublico.redes.${r}`) }}
                </span>
                <input
                  v-model="form.redes[r]"
                  class="tu-input"
                  :placeholder="$t(`perfilPublico.redesPh.${r}`)"
                  :aria-invalid="errores[`redes.${r}`] ? 'true' : undefined"
                />
                <span v-if="errores[`redes.${r}`]" class="tu-error-campo">
                  {{ errores[`redes.${r}`] }}
                </span>
              </label>
            </div>
          </fieldset>
        </section>

        <!-- Horarios: los días y horas en que atiende -->
        <section
          class="su-seccion"
          aria-labelledby="s-sec-horarios"
          data-prueba="seccion-horarios"
        >
          <header>
            <h3 id="s-sec-horarios">
              {{ $t("perfilPublico.sucursal.secciones.horarios") }}
            </h3>
            <p>{{ $t("perfilPublico.sucursal.horarioAyuda") }}</p>
          </header>
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
                  :aria-invalid="errorDelDia(d) ? 'true' : undefined"
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
        </section>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-secundario"
            type="button"
            :disabled="guardando"
            @click="cerrar"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            form="s-form"
            :disabled="
              guardando ||
              form.nombre.trim() === '' ||
              coordenadas === 'invalida'
            "
            :aria-busy="guardando || undefined"
          >
            {{ guardando ? $t("comun.guardando") : $t("comun.guardar") }}
          </button>
        </div>
      </template>
    </ModalDialogo>
  </section>
</template>

<style scoped>
/* Secciones del formulario: título y una línea de qué va en cada una. */
.su-seccion {
  display: grid;
  gap: 1rem;
}
.su-seccion + .su-seccion {
  border-top: 1px solid var(--borde);
  padding-top: 1.25rem;
}
.su-seccion > header h3 {
  font-size: 0.95rem;
  font-weight: 600;
}
.su-seccion > header p,
.su-ayuda {
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.su-seccion > header p {
  margin-top: 0.15rem;
}
.su-foto {
  width: 4.5rem;
  height: 4.5rem;
  flex-shrink: 0;
  border-radius: 0.8rem;
  object-fit: cover;
}
.su-sin-foto {
  display: inline-grid;
  place-items: center;
  border: 1px solid var(--borde);
  color: var(--texto-suave);
  background: var(--primario-suave);
}
.su-rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr));
  gap: 1rem;
}
.su-tarjeta {
  display: flex;
  flex-direction: column;
  padding: 1.25rem;
}
.su-cabecera {
  display: flex;
  align-items: center;
  gap: 1rem;
}
.su-direccion {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin-top: 1.25rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
  overflow-wrap: anywhere;
}
.su-datos {
  display: grid;
  gap: 0.75rem;
  margin-block: 1.25rem;
  font-size: 0.875rem;
}
.su-datos > div {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
}
.su-datos dt,
.su-suave {
  color: var(--texto-suave);
}
.su-datos dd {
  min-width: 0;
  text-align: right;
  overflow-wrap: anywhere;
}
.su-acciones {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: auto;
  border-top: 1px solid var(--borde);
  padding-top: 1rem;
}
</style>
