<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import CargadorImagen from "@/components/CargadorImagen.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { claveSegunModalidad } from "@/lib/menu";
import {
  esFija,
  moverSeccion,
  type BannerSitio,
  type PlantillaSitio,
  type SeccionSitio,
  type TipoSeccion,
} from "@/lib/sitioWeb";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * El constructor del sitio del negocio (ADR 0114): elegir plantilla, ordenar, mostrar u
 * ocultar secciones, sus títulos y textos, la foto de «Nosotros», los banners, la vista
 * previa y publicar. Lo que se guarda es un borrador: el público ve lo publicado.
 */
interface Borrador {
  plantilla: PlantillaSitio;
  secciones: (SeccionSitio & { visible: boolean })[];
  banners: BannerSitio[];
}
interface DatosSitio {
  borrador: Borrador;
  cambios_sin_publicar: boolean;
  publicado_en: string | null;
  pagina_publica: boolean;
  tiene_portada: boolean;
  catalogo: {
    plantillas: { clave: PlantillaSitio; orden: TipoSeccion[] }[];
    con_texto: TipoSeccion[];
    con_foto: TipoSeccion[];
    max_titulo: number;
    max_texto: number;
    banners_maximos: number;
  };
}

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/sitio`);

const datos = ref<DatosSitio | null>(null);
const borrador = ref<Borrador | null>(null);
const guardadoComo = ref("");
const cargando = ref(true);
const ocupado = ref(false);
const error = ref<string | null>(null);
const errores = ref<Record<string, string[]>>({});
const abierta = ref<TipoSeccion | null>(null);

// Vista previa: en un marco con el ancho de un teléfono o de una computadora.
const anchoPrevia = ref<"telefono" | "computadora">("telefono");
const vuelta = ref(0);
const urlPrevia = computed(() => `/estudio/${sesion.slug}/vista-previa`);

const sinGuardar = computed(
  () =>
    borrador.value !== null &&
    JSON.stringify(borrador.value) !== guardadoComo.value,
);
const hayCambios = computed(
  () => sinGuardar.value || (datos.value?.cambios_sin_publicar ?? false),
);
// Qué pasa con el sitio: sin guardar, sin publicar, publicado o la página de siempre.
const etiquetaEstado = computed(() =>
  sinGuardar.value
    ? t("sitioWeb.estado.sinGuardar")
    : hayCambios.value
      ? t("sitioWeb.estado.cambios")
      : datos.value?.publicado_en
        ? t("sitioWeb.estado.alDia")
        : t("sitioWeb.estado.deSiempre"),
);
const portadaSubida = computed(() => datos.value?.tiene_portada ?? false);

function tm(clave: string): string {
  return t(claveSegunModalidad(clave, sesion.esCitas, te));
}
function nombreSeccion(tipo: TipoSeccion): string {
  return tm(`sitioWeb.secciones.nombres.${tipo}`);
}
function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso));
}

function tomar(d: DatosSitio): void {
  datos.value = d;
  // Una copia para editar: lo guardado queda como referencia de «sin guardar».
  guardadoComo.value = JSON.stringify(d.borrador);
  borrador.value = JSON.parse(guardadoComo.value) as Borrador;
  errores.value = {};
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: DatosSitio }>(base.value);
    tomar(data.data);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

/** Guarda el borrador; `true` si quedó guardado. */
async function guardar(avisar = true): Promise<boolean> {
  if (!borrador.value || ocupado.value) {
    return false;
  }
  ocupado.value = true;
  errores.value = {};
  try {
    const { data } = await api.put<{ data: DatosSitio }>(
      base.value,
      borrador.value,
    );
    tomar(data.data);
    vuelta.value++;
    if (avisar) {
      toast.exito(t("sitioWeb.avisos.guardado"));
    }
    return true;
  } catch (e) {
    errores.value = erroresDe(e);
    toast.error(mensajeDeError(e));
    return false;
  } finally {
    ocupado.value = false;
  }
}

async function publicar(): Promise<void> {
  if (
    !(await confirmar(t("sitioWeb.confirmar.publicar"), {
      aceptar: t("sitioWeb.acciones.publicar"),
    }))
  ) {
    return;
  }
  // Lo que se ve en pantalla es lo que se publica.
  if (sinGuardar.value && !(await guardar(false))) {
    return;
  }
  ocupado.value = true;
  try {
    const { data } = await api.post<{ data: DatosSitio }>(
      `${base.value}/publicar`,
    );
    tomar(data.data);
    vuelta.value++;
    toast.exito(t("sitioWeb.avisos.publicado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

async function descartar(): Promise<void> {
  if (
    !(await confirmar(t("sitioWeb.confirmar.descartar"), {
      aceptar: t("sitioWeb.acciones.descartar"),
      peligro: true,
    }))
  ) {
    return;
  }
  ocupado.value = true;
  try {
    const { data } = await api.post<{ data: DatosSitio }>(
      `${base.value}/descartar`,
    );
    tomar(data.data);
    vuelta.value++;
    toast.exito(t("sitioWeb.avisos.descartado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

async function usarPlantilla(clave: PlantillaSitio): Promise<void> {
  const actual = borrador.value;
  const plantilla = datos.value?.catalogo.plantillas.find(
    (p) => p.clave === clave,
  );
  if (!actual || !plantilla || actual.plantilla === clave) {
    return;
  }
  if (
    !(await confirmar(
      t("sitioWeb.confirmar.plantilla", {
        plantilla: t(`sitioWeb.plantillas.${clave}`),
      }),
      { aceptar: t("sitioWeb.confirmar.usar") },
    ))
  ) {
    return;
  }
  // Su orden; cada sección conserva su título, texto, foto y visibilidad.
  const porTipo = new Map(actual.secciones.map((s) => [s.tipo, s]));
  const orden: TipoSeccion[] = ["inicio", ...plantilla.orden, "contacto"];
  actual.plantilla = clave;
  actual.secciones = orden
    .map((tipo) => porTipo.get(tipo))
    .filter((s): s is Borrador["secciones"][number] => s !== undefined);
}

function mover(indice: number, paso: -1 | 1): void {
  if (borrador.value) {
    borrador.value.secciones = moverSeccion(
      borrador.value.secciones,
      indice,
      paso,
    );
  }
}
function puedeMover(indice: number, paso: -1 | 1): boolean {
  const lista = borrador.value?.secciones ?? [];
  const destino = lista[indice + paso];
  return (
    destino !== undefined &&
    !esFija(lista[indice].tipo) &&
    !esFija(destino.tipo)
  );
}

function agregarBanner(): void {
  borrador.value?.banners.push({
    titulo: t("sitioWeb.banners.nuevo", {
      n: (borrador.value?.banners.length ?? 0) + 1,
    }),
    texto: null,
    enlace_texto: null,
    enlace_url: null,
    desde: null,
    hasta: null,
    foto_url: null,
  });
}
function quitarBanner(indice: number): void {
  borrador.value?.banners.splice(indice, 1);
}

// Un campo de texto vacío se guarda como null (el de siempre).
function texto(valor: string): string | null {
  return valor.trim() === "" ? null : valor;
}

/** Los errores de validación por campo (`meta.errors` del API). */
function erroresDe(e: unknown): Record<string, string[]> {
  const respuesta = (
    e as { response?: { data?: { meta?: { errors?: unknown } } } }
  ).response;
  const lista = respuesta?.data?.meta?.errors;
  return lista && typeof lista === "object"
    ? (lista as Record<string, string[]>)
    : {};
}
function errorDe(clave: string): string | null {
  return errores.value[clave]?.[0] ?? null;
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('sitioWeb.titulo')"
      :descripcion="$t('sitioWeb.descripcion')"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-else-if="error" class="mt-6 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-else-if="borrador && datos">
      <!-- Estado y publicar -->
      <div class="tu-card mt-6 p-5" data-prueba="estado-sitio">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="min-w-0">
            <p class="flex items-center gap-2 font-medium">
              <span
                class="sw-punto"
                :class="hayCambios ? 'sw-punto-aviso' : 'sw-punto-exito'"
                aria-hidden="true"
              ></span>
              {{ etiquetaEstado }}
            </p>
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{
                datos.publicado_en
                  ? $t("sitioWeb.estado.publicadoEl", {
                      fecha: fecha(datos.publicado_en),
                    })
                  : $t("sitioWeb.estado.nuncaPublicado")
              }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button
              v-if="datos.cambios_sin_publicar && !sinGuardar"
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="ocupado"
              @click="descartar"
            >
              {{ $t("sitioWeb.acciones.descartar") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="ocupado || !sinGuardar"
              data-prueba="guardar-sitio"
              @click="guardar()"
            >
              {{ $t("sitioWeb.acciones.guardar") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-primario"
              :disabled="ocupado || !hayCambios"
              data-prueba="publicar-sitio"
              @click="publicar"
            >
              {{ $t("sitioWeb.acciones.publicar") }}
            </button>
          </div>
        </div>
        <p
          v-if="!datos.pagina_publica"
          class="mt-4 text-sm"
          data-prueba="pagina-oculta"
        >
          {{ $t("sitioWeb.estado.paginaOculta") }}
          <RouterLink
            :to="{ name: 'configuracion', hash: '#pagina-publica' }"
            class="tu-enlace"
            >{{ $t("sitioWeb.estado.abrirPagina") }}</RouterLink
          >
        </p>
      </div>

      <div class="sw-columnas mt-6">
        <div class="min-w-0 space-y-6">
          <!-- Plantilla -->
          <div class="tu-card p-5">
            <h2 class="text-lg font-medium">
              {{ $t("sitioWeb.plantillas.titulo") }}
            </h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("sitioWeb.plantillas.ayuda") }}
            </p>
            <div class="mt-4 grid gap-3 sm:grid-cols-3" role="radiogroup">
              <button
                v-for="p in datos.catalogo.plantillas"
                :key="p.clave"
                type="button"
                role="radio"
                :aria-checked="borrador.plantilla === p.clave"
                class="sw-plantilla"
                :class="{
                  'sw-plantilla-activa': borrador.plantilla === p.clave,
                }"
                :data-prueba="`plantilla-${p.clave}`"
                @click="usarPlantilla(p.clave)"
              >
                <span class="font-medium">{{
                  $t(`sitioWeb.plantillas.${p.clave}`)
                }}</span>
                <span
                  class="mt-1 block text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ $t(`sitioWeb.plantillas.${p.clave}Desc`) }}</span
                >
                <span
                  v-if="borrador.plantilla === p.clave"
                  class="mt-2 flex items-center gap-1.5 text-xs"
                >
                  <span
                    class="sw-punto sw-punto-exito"
                    aria-hidden="true"
                  ></span>
                  {{ $t("sitioWeb.plantillas.elegida") }}
                </span>
              </button>
            </div>
            <p
              v-if="borrador.plantilla === 'portada' && !portadaSubida"
              class="mt-3 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("sitioWeb.plantillas.portadaSinFoto") }}
            </p>
          </div>

          <!-- Secciones -->
          <div class="tu-card p-5">
            <h2 class="text-lg font-medium">
              {{ $t("sitioWeb.secciones.titulo") }}
            </h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("sitioWeb.secciones.ayuda") }}
            </p>
            <ol class="mt-4 space-y-2" data-prueba="secciones">
              <li
                v-for="(s, i) in borrador.secciones"
                :key="s.tipo"
                class="sw-seccion"
                :data-prueba="`seccion-${s.tipo}`"
              >
                <div class="flex items-center gap-2">
                  <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ nombreSeccion(s.tipo) }}</p>
                    <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                      {{ tm(`sitioWeb.secciones.contenido.${s.tipo}`) }}
                    </p>
                  </div>
                  <label
                    v-if="!esFija(s.tipo)"
                    class="flex shrink-0 items-center gap-1.5 text-sm"
                  >
                    <input
                      v-model="s.visible"
                      type="checkbox"
                      :data-prueba="`visible-${s.tipo}`"
                    />
                    {{
                      s.visible
                        ? $t("sitioWeb.secciones.visible")
                        : $t("sitioWeb.secciones.oculta")
                    }}
                  </label>
                  <button
                    type="button"
                    class="tu-icono-btn shrink-0"
                    :disabled="!puedeMover(i, -1)"
                    :aria-label="
                      $t('sitioWeb.secciones.subir', {
                        seccion: nombreSeccion(s.tipo),
                      })
                    "
                    :data-prueba="`subir-${s.tipo}`"
                    @click="mover(i, -1)"
                  >
                    <IconoNav nombre="arriba" :tam="16" />
                  </button>
                  <button
                    type="button"
                    class="tu-icono-btn shrink-0"
                    :disabled="!puedeMover(i, 1)"
                    :aria-label="
                      $t('sitioWeb.secciones.bajar', {
                        seccion: nombreSeccion(s.tipo),
                      })
                    "
                    :data-prueba="`bajar-${s.tipo}`"
                    @click="mover(i, 1)"
                  >
                    <IconoNav nombre="abajo" :tam="16" />
                  </button>
                  <button
                    type="button"
                    class="tu-btn tu-btn-fantasma shrink-0 text-sm"
                    :aria-expanded="abierta === s.tipo"
                    :data-prueba="`editar-${s.tipo}`"
                    @click="abierta = abierta === s.tipo ? null : s.tipo"
                  >
                    {{
                      abierta === s.tipo
                        ? $t("sitioWeb.secciones.cerrar")
                        : $t("sitioWeb.secciones.editar")
                    }}
                  </button>
                </div>

                <div v-if="abierta === s.tipo" class="mt-4 space-y-4">
                  <div>
                    <label class="tu-label" :for="`sw-titulo-${s.tipo}`">{{
                      s.tipo === "inicio"
                        ? $t("sitioWeb.secciones.campos.titular")
                        : $t("sitioWeb.secciones.campos.titulo")
                    }}</label>
                    <input
                      :id="`sw-titulo-${s.tipo}`"
                      class="tu-input"
                      :value="s.titulo ?? ''"
                      :maxlength="datos.catalogo.max_titulo"
                      :placeholder="
                        s.tipo === 'inicio'
                          ? (sesion.estudio?.nombre ?? '')
                          : nombreSeccion(s.tipo)
                      "
                      @input="
                        s.titulo = texto(
                          ($event.target as HTMLInputElement).value,
                        )
                      "
                    />
                    <p class="sw-ayuda">
                      {{
                        s.tipo === "inicio"
                          ? $t("sitioWeb.secciones.campos.titularAyuda")
                          : $t("sitioWeb.secciones.campos.tituloAyuda")
                      }}
                    </p>
                  </div>
                  <div v-if="datos.catalogo.con_texto.includes(s.tipo)">
                    <label class="tu-label" :for="`sw-texto-${s.tipo}`">{{
                      s.tipo === "inicio"
                        ? $t("sitioWeb.secciones.campos.textoInicio")
                        : $t("sitioWeb.secciones.campos.texto")
                    }}</label>
                    <textarea
                      :id="`sw-texto-${s.tipo}`"
                      class="tu-input"
                      rows="5"
                      :value="s.texto ?? ''"
                      :maxlength="datos.catalogo.max_texto"
                      @input="
                        s.texto = texto(
                          ($event.target as HTMLTextAreaElement).value,
                        )
                      "
                    ></textarea>
                    <p v-if="s.tipo === 'inicio'" class="sw-ayuda">
                      {{ $t("sitioWeb.secciones.campos.textoInicioAyuda") }}
                    </p>
                  </div>
                  <div v-if="datos.catalogo.con_foto.includes(s.tipo)">
                    <span class="tu-label">{{
                      $t("sitioWeb.secciones.campos.foto")
                    }}</span>
                    <CargadorImagen
                      :url="s.foto_url"
                      ruta="sitio/imagenes"
                      campo="imagen"
                      clave="url"
                      quitar-sin-borrar
                      proporcion="4 / 3"
                      :arrastra="$t('sitioWeb.secciones.campos.fotoArrastra')"
                      @update:url="s.foto_url = $event"
                    />
                  </div>
                  <p
                    v-if="
                      errorDe(`secciones.${i}.titulo`) ||
                      errorDe(`secciones.${i}.texto`)
                    "
                    class="text-sm"
                    style="color: var(--error)"
                  >
                    {{
                      errorDe(`secciones.${i}.titulo`) ??
                      errorDe(`secciones.${i}.texto`)
                    }}
                  </p>
                </div>
              </li>
            </ol>
          </div>

          <!-- Banners -->
          <div class="tu-card p-5" data-prueba="banners">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="text-lg font-medium">
                  {{ $t("sitioWeb.banners.titulo") }}
                </h2>
                <p
                  class="mt-1 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("sitioWeb.banners.ayuda") }}
                </p>
              </div>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma text-sm"
                :disabled="
                  borrador.banners.length >= datos.catalogo.banners_maximos
                "
                data-prueba="agregar-banner"
                @click="agregarBanner"
              >
                {{ $t("sitioWeb.banners.agregar") }}
              </button>
            </div>
            <p
              v-if="borrador.banners.length === 0"
              class="mt-4 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("sitioWeb.banners.vacio") }}
            </p>
            <p
              v-else-if="
                borrador.banners.length >= datos.catalogo.banners_maximos
              "
              class="mt-2 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                $t("sitioWeb.banners.tope", {
                  n: datos.catalogo.banners_maximos,
                })
              }}
            </p>
            <ul class="mt-4 space-y-4">
              <li
                v-for="(b, i) in borrador.banners"
                :key="b.id ?? `nuevo-${i}`"
                class="sw-seccion space-y-4"
                :data-prueba="`banner-${i}`"
              >
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0 flex-1">
                    <label class="tu-label" :for="`sw-banner-titulo-${i}`">{{
                      $t("sitioWeb.banners.campos.titulo")
                    }}</label>
                    <input
                      :id="`sw-banner-titulo-${i}`"
                      v-model="b.titulo"
                      class="tu-input"
                      :maxlength="datos.catalogo.max_titulo"
                    />
                  </div>
                  <button
                    type="button"
                    class="tu-icono-btn mt-6 shrink-0"
                    :aria-label="$t('sitioWeb.banners.quitar')"
                    @click="quitarBanner(i)"
                  >
                    <IconoNav nombre="cerrar" :tam="16" />
                  </button>
                </div>
                <div>
                  <label class="tu-label" :for="`sw-banner-texto-${i}`">{{
                    $t("sitioWeb.banners.campos.texto")
                  }}</label>
                  <input
                    :id="`sw-banner-texto-${i}`"
                    class="tu-input"
                    :value="b.texto ?? ''"
                    maxlength="240"
                    @input="
                      b.texto = texto(($event.target as HTMLInputElement).value)
                    "
                  />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label
                      class="tu-label"
                      :for="`sw-banner-enlace-texto-${i}`"
                      >{{ $t("sitioWeb.banners.campos.enlaceTexto") }}</label
                    >
                    <input
                      :id="`sw-banner-enlace-texto-${i}`"
                      class="tu-input"
                      :value="b.enlace_texto ?? ''"
                      maxlength="40"
                      @input="
                        b.enlace_texto = texto(
                          ($event.target as HTMLInputElement).value,
                        )
                      "
                    />
                  </div>
                  <div>
                    <label class="tu-label" :for="`sw-banner-enlace-${i}`">{{
                      $t("sitioWeb.banners.campos.enlaceUrl")
                    }}</label>
                    <input
                      :id="`sw-banner-enlace-${i}`"
                      class="tu-input"
                      :value="b.enlace_url ?? ''"
                      maxlength="300"
                      inputmode="url"
                      @input="
                        b.enlace_url = texto(
                          ($event.target as HTMLInputElement).value,
                        )
                      "
                    />
                  </div>
                </div>
                <p class="sw-ayuda -mt-2">
                  {{ $t("sitioWeb.banners.campos.enlaceAyuda") }}
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label class="tu-label" :for="`sw-banner-desde-${i}`">{{
                      $t("sitioWeb.banners.campos.desde")
                    }}</label>
                    <input
                      :id="`sw-banner-desde-${i}`"
                      type="date"
                      class="tu-input"
                      :value="b.desde ?? ''"
                      @input="
                        b.desde = texto(
                          ($event.target as HTMLInputElement).value,
                        )
                      "
                    />
                  </div>
                  <div>
                    <label class="tu-label" :for="`sw-banner-hasta-${i}`">{{
                      $t("sitioWeb.banners.campos.hasta")
                    }}</label>
                    <input
                      :id="`sw-banner-hasta-${i}`"
                      type="date"
                      class="tu-input"
                      :value="b.hasta ?? ''"
                      @input="
                        b.hasta = texto(
                          ($event.target as HTMLInputElement).value,
                        )
                      "
                    />
                  </div>
                </div>
                <p class="sw-ayuda -mt-2">
                  {{ $t("sitioWeb.banners.campos.vigenciaAyuda") }}
                </p>
                <div>
                  <span class="tu-label">{{
                    $t("sitioWeb.banners.campos.foto")
                  }}</span>
                  <CargadorImagen
                    :url="b.foto_url"
                    ruta="sitio/imagenes"
                    campo="imagen"
                    clave="url"
                    quitar-sin-borrar
                    proporcion="16 / 7"
                    :arrastra="$t('sitioWeb.secciones.campos.fotoArrastra')"
                    @update:url="b.foto_url = $event"
                  />
                </div>
                <p
                  v-for="campo in [
                    'titulo',
                    'texto',
                    'enlace_texto',
                    'enlace_url',
                    'desde',
                    'hasta',
                    'foto_url',
                  ]"
                  v-show="errorDe(`banners.${i}.${campo}`)"
                  :key="campo"
                  class="text-sm"
                  style="color: var(--error)"
                >
                  {{ errorDe(`banners.${i}.${campo}`) }}
                </p>
              </li>
            </ul>
          </div>
        </div>

        <!-- Vista previa (en pantallas angostas, en otra pestaña) -->
        <aside class="sw-previa" data-prueba="vista-previa">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-medium">
              {{ $t("sitioWeb.acciones.vistaPrevia") }}
            </h2>
            <div class="flex flex-wrap items-center gap-2">
              <div class="tu-segmentado" role="group">
                <button
                  type="button"
                  :aria-pressed="anchoPrevia === 'telefono'"
                  @click="anchoPrevia = 'telefono'"
                >
                  {{ $t("sitioWeb.acciones.telefono") }}
                </button>
                <button
                  type="button"
                  :aria-pressed="anchoPrevia === 'computadora'"
                  @click="anchoPrevia = 'computadora'"
                >
                  {{ $t("sitioWeb.acciones.computadora") }}
                </button>
              </div>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma text-sm"
                @click="vuelta++"
              >
                {{ $t("sitioWeb.acciones.actualizar") }}
              </button>
              <a
                :href="urlPrevia"
                target="_blank"
                rel="noopener"
                class="tu-enlace text-sm"
                >{{ $t("sitioWeb.acciones.abrirPestana") }}</a
              >
            </div>
          </div>
          <p
            v-if="sinGuardar"
            class="mt-2 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("sitioWeb.estado.sinGuardar") }}
          </p>
          <div class="sw-marco mt-3" :class="`sw-marco-${anchoPrevia}`">
            <iframe
              :key="vuelta"
              :src="urlPrevia"
              :title="$t('sitioWeb.acciones.vistaPrevia')"
              loading="lazy"
            ></iframe>
          </div>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.sw-columnas {
  display: grid;
  gap: 1.5rem;
}
@media (min-width: 1280px) {
  .sw-columnas {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
  }
  .sw-previa {
    position: sticky;
    top: 5rem;
  }
}
.sw-ayuda {
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.sw-punto {
  display: inline-block;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
}
.sw-punto-exito {
  background: var(--exito);
}
.sw-punto-aviso {
  background: var(--aviso);
}
.sw-plantilla {
  display: block;
  width: 100%;
  padding: 0.875rem;
  text-align: left;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie);
}
.sw-plantilla-activa {
  border-color: var(--primario);
  box-shadow: 0 0 0 1px var(--primario);
}
.sw-seccion {
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
}
.sw-marco {
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  overflow: hidden;
  background: var(--fondo);
}
.sw-marco iframe {
  display: block;
  width: 100%;
  height: 70vh;
  border: 0;
}
.sw-marco-telefono {
  max-width: 390px;
  margin-left: auto;
  margin-right: auto;
}
/* En pantallas angostas el marco no cabe: se abre en otra pestaña. */
@media (max-width: 767px) {
  .sw-marco {
    display: none;
  }
}
</style>
