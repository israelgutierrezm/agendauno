<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import CargadorImagen from "@/components/CargadorImagen.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
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
  // Lo que ve quien la elige en línea (página pública y agendar).
  descripcion?: string | null;
  // Paquete: servicios que incluye, en orden (ADR 0063).
  incluye?: string[];
  // Foto que ve quien lo elige (ADR 0066).
  foto_url?: string | null;
}
interface Recurso {
  id: string;
  nombre: string;
  sucursal: string | null;
  activo: boolean;
}

/**
 * Catálogo con el patrón de los listados: indicadores, la tabla de servicios (o
 * clases) y, en diálogos, configurar uno o dar de alta varios en una línea
 * (nombre, duración y precio o lugares; ADR 0088).
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("catalogo.gestionar"));
const esCitas = computed(() => sesion.esCitas === true);

// Cómo se reserva: con citas, «se paga» o «con bono o membresía», y lo que cambia
// en la página para agendar al pasar de uno a otro (ADR 0091).
const OPCIONES_POLITICA = ["pago", "entitlement"] as const;
const textosPolitica = computed(() =>
  esCitas.value ? "catalogo.politicaCitas" : "catalogo.politica",
);
function efectoPolitica(o: Oferta): string {
  if (!esCitas.value || form.value.politica === o.politica_reserva) {
    return "";
  }
  return form.value.politica === "entitlement"
    ? t("catalogo.politicaCitas.dejaDeAparecer", { servicio: o.nombre })
    : t("catalogo.politicaCitas.apareceConPrecio", { servicio: o.nombre });
}

const ofertas = ref<Oferta[]>([]);
const recursos = ref<Recurso[]>([]);
function nombresDe(ids: string[] | undefined): string {
  return (ids ?? [])
    .map((id) => recursos.value.find((r) => r.id === id)?.nombre)
    .filter(Boolean)
    .join(", ");
}
function serviciosDe(ids: string[] | undefined): string {
  return (ids ?? [])
    .map((id) => ofertas.value.find((x) => x.id === id)?.nombre)
    .filter(Boolean)
    .join(", ");
}
// Lo que puede incluir un paquete: los demás servicios que no son paquete.
function incluibles(o: Oferta): Oferta[] {
  return ofertas.value.filter(
    (x) => x.id !== o.id && (x.incluye ?? []).length === 0,
  );
}
// Paquetes que ya lo incluyen (entonces no puede incluir otros).
function paquetesCon(o: Oferta): string {
  return ofertas.value
    .filter((x) => (x.incluye ?? []).includes(o.id))
    .map((x) => x.nombre)
    .join(", ");
}
const cargando = ref(true);
const error = ref<string | null>(null);

// Oferta en edición (una a la vez) + su formulario.
const editandoId = ref<string | null>(null);
const form = ref<{
  descripcion: string;
  politica: Politica;
  precio: string;
  duracion: string;
  lugares: string;
  preparacion: string;
  limpieza: string;
  recursos: string[];
  incluye: string[];
}>({
  descripcion: "",
  politica: "entitlement",
  precio: "",
  duracion: "",
  lugares: "0",
  preparacion: "0",
  limpieza: "0",
  recursos: [],
  incluye: [],
});
const guardando = ref(false);
const guardadoId = ref<string | null>(null);
// La foto se sube aparte, al momento.
function fotoCambiada(o: Oferta, url: string | null): void {
  o.foto_url = url;
}

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
    descripcion: o.descripcion ?? "",
    politica: o.politica_reserva,
    precio:
      o.precio_clase_minor !== null ? String(o.precio_clase_minor / 100) : "",
    duracion: o.duracion_minutos !== null ? String(o.duracion_minutos) : "",
    lugares: String(o.lugares),
    preparacion: String(o.preparacion_min ?? 0),
    limpieza: String(o.limpieza_min ?? 0),
    recursos: [...(o.recursos ?? [])],
    incluye: [...(o.incluye ?? [])],
  };
}
function cerrar(): void {
  editandoId.value = null;
}
// El que se configura (en un diálogo); como lista, para usar el mismo editor.
const editando = computed(
  () => ofertas.value.find((o) => o.id === editandoId.value) ?? null,
);
const editandoLista = computed(() => (editando.value ? [editando.value] : []));

// Una línea bajo el nombre: márgenes, espacios y lo que incluye un paquete.
function extras(o: Oferta): string {
  const partes: string[] = [];
  const margen = (o.preparacion_min ?? 0) + (o.limpieza_min ?? 0);
  if (margen > 0) {
    partes.push(t("margenesServicio.resumen", { n: margen }));
  }
  if ((o.recursos ?? []).length > 0) {
    partes.push(
      t("recursosServicio.resumen", { lista: nombresDe(o.recursos) }),
    );
  }
  if ((o.incluye ?? []).length > 0) {
    partes.push(
      t("perfilPublico.catalogo.incluyeResumen", {
        lista: serviciosDe(o.incluye),
      }),
    );
  }
  return partes.join(" · ");
}

const indicadores = computed<Indicador[]>(() => {
  const total = ofertas.value.length;
  const deTotal = (n: number): string =>
    t("listadosVisual.catalogo.deTotal", { n, total });
  const conPrecio = ofertas.value.filter(
    (o) => (o.precio_clase_minor ?? 0) > 0,
  );
  const conCupo = ofertas.value.filter((o) => (o.capacidad ?? 0) > 0);
  return [
    {
      clave: "servicios",
      etiqueta: esCitas.value
        ? t("listadosVisual.catalogo.kpi.servicios")
        : t("listadosVisual.catalogo.kpi.clases"),
      valor: String(total),
      icono: "etiqueta",
    },
    esCitas.value
      ? {
          clave: "precio",
          etiqueta: t("listadosVisual.catalogo.kpi.precioPromedio"),
          valor:
            conPrecio.length > 0
              ? dinero(
                  Math.round(
                    conPrecio.reduce(
                      (s, o) => s + (o.precio_clase_minor ?? 0),
                      0,
                    ) / conPrecio.length,
                  ),
                )
              : "—",
          icono: "dinero",
        }
      : {
          clave: "cupo",
          etiqueta: t("listadosVisual.catalogo.kpi.cupoPromedio"),
          valor:
            conCupo.length > 0
              ? String(
                  Math.round(
                    conCupo.reduce((s, o) => s + (o.capacidad ?? 0), 0) /
                      conCupo.length,
                  ),
                )
              : "—",
          icono: "personas",
        },
    {
      clave: "foto",
      etiqueta: t("listadosVisual.catalogo.kpi.conFoto"),
      valor: deTotal(ofertas.value.filter((o) => o.foto_url).length),
      icono: "contenido",
    },
    {
      clave: "descripcion",
      etiqueta: t("listadosVisual.catalogo.kpi.conDescripcion"),
      valor: deTotal(ofertas.value.filter((o) => o.descripcion).length),
      icono: "documentos",
    },
  ];
});

// ---- Alta en una línea (varios a la vez) ----
const DURACIONES = [15, 20, 30, 45, 60, 75, 90, 120, 150, 180];
interface FilaAlta {
  nombre: string;
  duracion: number;
  valor: string;
}
const filas = ref<FilaAlta[]>([]);
const dandoAlta = ref(false);
const dandoAltaGuardando = ref(false);
function agregarFila(): void {
  filas.value.push({
    nombre: "",
    duracion: esCitas.value ? 30 : 60,
    valor: esCitas.value ? "" : "10",
  });
}
function abrirAlta(): void {
  filas.value = [];
  agregarFila();
  dandoAlta.value = true;
}
const filasValidas = computed(() =>
  filas.value.filter(
    (f) =>
      f.nombre.trim() !== "" &&
      (esCitas.value
        ? Number(f.valor.replace(/[^\d.]/g, "")) > 0
        : Number(f.valor) > 0),
  ),
);
async function guardarAlta(): Promise<void> {
  dandoAltaGuardando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/ofertas/rapidas`, {
      items: filasValidas.value.map((f) =>
        esCitas.value
          ? {
              nombre: f.nombre.trim(),
              duracion_minutos: f.duracion,
              precio_minor: Math.round(
                Number(f.valor.replace(/[^\d.]/g, "")) * 100,
              ),
            }
          : {
              nombre: f.nombre.trim(),
              duracion_minutos: f.duracion,
              capacidad: Number(f.valor),
            },
      ),
    });
    dandoAlta.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    dandoAltaGuardando.value = false;
  }
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
      descripcion: form.value.descripcion.trim(),
      // Con pago, mandamos el precio; sin precio lo limpiamos (null).
      precio_clase_minor: precioMinor > 0 ? precioMinor : null,
      politica_reserva: form.value.politica,
      // La duración solo aplica a citas; sin valor la limpiamos (null).
      duracion_minutos: duracion > 0 ? duracion : null,
      preparacion_min: Math.max(0, Number(form.value.preparacion) || 0),
      limpieza_min: Math.max(0, Number(form.value.limpieza) || 0),
      ...(recursos.value.length > 0 ? { recursos: form.value.recursos } : {}),
      // En el orden en que se marcaron.
      incluye: form.value.incluye,
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
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      :titulo="$t('catalogo.titulo')"
      :total="ofertas.length"
      :subtitulo="$t('listadosVisual.catalogo.subtitulo')"
    >
      <template #acciones>
        <button
          v-if="puedeGestionar"
          type="button"
          class="tu-btn tu-btn-primario tu-btn-crear"
          data-prueba="nuevo-servicio"
          @click="abrirAlta"
        >
          {{
            esCitas
              ? $t("listadosVisual.catalogo.nuevoServicio")
              : $t("listadosVisual.catalogo.nuevaClase")
          }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <EstadoVacio
        v-if="ofertas.length === 0"
        class="mt-8"
        icono="etiqueta"
        :titulo="$t('catalogo.vacio')"
      />

      <template v-else>
        <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

        <div class="tu-card mt-5 overflow-x-auto">
          <table class="ct-tabla">
            <thead>
              <tr>
                <th>{{ $t("listadosVisual.catalogo.servicio") }}</th>
                <th class="hidden sm:table-cell">
                  {{ $t("listadosVisual.catalogo.duracion") }}
                </th>
                <th class="text-right">
                  {{
                    esCitas
                      ? $t("listadosVisual.catalogo.precio")
                      : $t("listadosVisual.catalogo.cupo")
                  }}
                </th>
                <th class="hidden md:table-cell">
                  {{ $t("listadosVisual.catalogo.reserva") }}
                </th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in ofertas" :key="o.id" data-prueba="servicio">
                <td>
                  <div class="flex items-center gap-3">
                    <img
                      v-if="o.foto_url"
                      :src="o.foto_url"
                      alt=""
                      class="ct-foto"
                    />
                    <span v-else class="ct-foto ct-sin-foto" aria-hidden="true">
                      <IconoNav nombre="etiqueta" :tam="16" />
                    </span>
                    <div class="min-w-0">
                      <p class="font-medium">
                        {{ o.nombre }}
                        <span
                          v-if="guardadoId === o.id"
                          class="ml-2 text-sm"
                          :style="{ color: 'var(--exito)' }"
                          >{{ $t("catalogo.guardado") }}</span
                        >
                      </p>
                      <p class="ct-sub">
                        <template v-if="o.descripcion">{{
                          o.descripcion
                        }}</template>
                        <template v-else>{{
                          $t("listadosVisual.catalogo.sinDescripcion")
                        }}</template>
                      </p>
                      <p v-if="extras(o)" class="ct-sub">{{ extras(o) }}</p>
                    </div>
                  </div>
                </td>
                <td class="hidden sm:table-cell whitespace-nowrap">
                  {{
                    o.duracion_minutos
                      ? $t("listadosVisual.catalogo.minutos", {
                          n: o.duracion_minutos,
                        })
                      : "—"
                  }}
                </td>
                <td class="text-right whitespace-nowrap tabular-nums">
                  <template v-if="o.politica_reserva === 'pago'">{{
                    dinero(o.precio_clase_minor)
                  }}</template>
                  <template v-else-if="o.capacidad">{{
                    $t("listadosVisual.catalogo.lugares", { n: o.capacidad })
                  }}</template>
                  <template v-else>—</template>
                </td>
                <td class="hidden md:table-cell whitespace-nowrap">
                  <span
                    class="tu-pildora"
                    :style="{
                      '--tono':
                        o.politica_reserva === 'pago'
                          ? 'var(--primario)'
                          : 'var(--texto-suave)',
                    }"
                    >{{
                      o.politica_reserva === "pago"
                        ? $t("catalogo.badgePago")
                        : $t("catalogo.badgeEntitlement")
                    }}</span
                  >
                </td>
                <td class="text-right">
                  <button
                    v-if="puedeGestionar"
                    class="tu-enlace text-sm"
                    type="button"
                    data-prueba="configurar"
                    @click="configurar(o)"
                  >
                    {{ $t("catalogo.configurar") }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- Configurar un servicio -->
    <ModalDialogo
      :abierto="editando !== null"
      :titulo="editando?.nombre ?? ''"
      icono="etiqueta"
      @cerrar="cerrar"
    >
      <template v-for="o in editandoLista" :key="o.id">
        <div class="mb-4" data-prueba="foto-servicio">
          <span class="tu-label">{{ $t("perfilPublico.catalogo.foto") }}</span>
          <CargadorImagen
            :url="o.foto_url ?? null"
            :ruta="`ofertas/${o.id}/foto`"
            campo="foto"
            clave="foto_url"
            proporcion="4 / 3"
            :arrastra="$t('perfilPublico.catalogo.fotoArrastra')"
            :ayuda="$t('perfilPublico.catalogo.fotoAyuda')"
            :quitar-texto="$t('perfilPublico.catalogo.fotoQuitar')"
            :puede-gestionar="puedeGestionar"
            @update:url="fotoCambiada(o, $event)"
          />
        </div>
        <label class="tu-label" :for="`c-desc-${o.id}`">{{
          $t("perfilPublico.catalogo.descripcion")
        }}</label>
        <textarea
          :id="`c-desc-${o.id}`"
          v-model="form.descripcion"
          class="tu-input mb-4"
          rows="3"
          maxlength="600"
          :placeholder="$t('perfilPublico.catalogo.descripcionPh')"
        />
        <label class="tu-label">{{ $t("catalogo.politica.etiqueta") }}</label>
        <div class="mt-1 space-y-2" data-prueba="politica-reserva">
          <label
            v-for="opcion in OPCIONES_POLITICA"
            :key="opcion"
            class="ct-opcion"
            :class="{ 'ct-opcion-activa': form.politica === opcion }"
          >
            <input
              v-model="form.politica"
              type="radio"
              :value="opcion"
              class="mt-1"
              :data-prueba="`politica-${opcion}`"
            />
            <span>
              <span class="font-medium">{{
                $t(`${textosPolitica}.${opcion}`)
              }}</span>
              <span
                class="block text-sm"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t(`${textosPolitica}.${opcion}Ayuda`) }}</span
              >
            </span>
          </label>
        </div>
        <!-- Lo que cambia en su página al guardar (ADR 0091). -->
        <p
          v-if="efectoPolitica(o)"
          class="mt-2 text-sm"
          :style="{ color: 'var(--aviso)' }"
          data-prueba="efecto-politica"
        >
          {{ efectoPolitica(o) }}
        </p>

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
                :style="precioInvalido ? { borderColor: 'var(--error)' } : {}"
              />
              <span class="text-sm" :style="{ color: 'var(--texto-suave)' }">{{
                $t("catalogo.moneda")
              }}</span>
            </div>
            <span
              v-if="precioInvalido"
              class="tu-hint"
              style="color: var(--error)"
              >{{ $t("catalogo.precioReq") }}</span
            >
          </div>
          <div v-if="form.politica === 'pago' || esCitas">
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
              :style="duracionInvalida ? { borderColor: 'var(--error)' } : {}"
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
                <input v-model="form.recursos" type="checkbox" :value="r.id" />
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
          <!-- Paquete (ADR 0063): servicios que incluye, sin anidar. -->
          <fieldset
            v-if="paquetesCon(o) || incluibles(o).length > 0"
            class="sm:col-span-2"
            data-prueba="incluye"
          >
            <legend class="tu-label">
              {{ $t("perfilPublico.catalogo.incluye") }}
            </legend>
            <p v-if="paquetesCon(o)" class="tu-hint">
              {{
                $t("perfilPublico.catalogo.incluidoEn", {
                  lista: paquetesCon(o),
                })
              }}
            </p>
            <template v-else>
              <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <label
                  v-for="x in incluibles(o)"
                  :key="x.id"
                  class="flex items-center gap-2"
                >
                  <input v-model="form.incluye" type="checkbox" :value="x.id" />
                  {{ x.nombre }}
                </label>
              </div>
              <span class="tu-hint">{{
                $t("perfilPublico.catalogo.incluyeAyuda")
              }}</span>
            </template>
          </fieldset>
        </div>
      </template>
      <template #pie>
        <button
          class="tu-btn tu-btn-fantasma"
          type="button"
          :disabled="guardando"
          @click="cerrar"
        >
          {{ $t("catalogo.cerrar") }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="button"
          :disabled="guardando || formInvalido"
          @click="editando && guardar(editando)"
        >
          {{ guardando ? $t("catalogo.guardando") : $t("catalogo.guardar") }}
        </button>
      </template>
    </ModalDialogo>

    <!-- Alta en una línea: nombre, duración y precio (o lugares) -->
    <ModalDialogo
      :abierto="dandoAlta"
      :titulo="
        esCitas
          ? $t('listadosVisual.catalogo.nuevoServicio')
          : $t('listadosVisual.catalogo.nuevaClase')
      "
      icono="etiqueta"
      @cerrar="dandoAlta = false"
    >
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("listadosVisual.catalogo.altaAyuda") }}
      </p>
      <div class="ct-filas mt-4" data-prueba="filas-alta">
        <div class="ct-fila ct-fila-cabeza" aria-hidden="true">
          <span>{{ $t("listadosVisual.catalogo.nombre") }}</span>
          <span>{{ $t("listadosVisual.catalogo.duracion") }}</span>
          <span>{{
            esCitas
              ? $t("listadosVisual.catalogo.precio")
              : $t("listadosVisual.catalogo.cupo")
          }}</span>
          <span></span>
        </div>
        <div v-for="(f, i) in filas" :key="i" class="ct-fila">
          <input
            v-model="f.nombre"
            class="tu-input"
            :aria-label="$t('listadosVisual.catalogo.nombre')"
            maxlength="120"
          />
          <label class="tu-select-icono w-full">
            <IconoNav nombre="reloj" :tam="16" />
            <select
              v-model.number="f.duracion"
              class="tu-input w-full"
              :aria-label="$t('listadosVisual.catalogo.duracion')"
            >
              <option v-for="d in DURACIONES" :key="d" :value="d">
                {{ $t("listadosVisual.catalogo.minutos", { n: d }) }}
              </option>
            </select>
          </label>
          <div v-if="esCitas" class="ct-precio">
            <span aria-hidden="true">$</span>
            <input
              v-model="f.valor"
              class="tu-input"
              inputmode="decimal"
              :aria-label="$t('listadosVisual.catalogo.precio')"
            />
          </div>
          <input
            v-else
            v-model="f.valor"
            class="tu-input"
            type="number"
            min="1"
            :aria-label="$t('listadosVisual.catalogo.cupo')"
          />
          <button
            type="button"
            class="tu-icono-btn"
            :aria-label="$t('listadosVisual.catalogo.quitar')"
            :disabled="filas.length === 1"
            @click="filas.splice(i, 1)"
          >
            <IconoNav nombre="cerrar" :tam="16" />
          </button>
        </div>
      </div>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma mt-3"
        @click="agregarFila"
      >
        <IconoNav nombre="mas" :tam="16" />
        {{ $t("listadosVisual.catalogo.otraFila") }}
      </button>
      <template #pie>
        <button
          class="tu-btn tu-btn-fantasma"
          type="button"
          :disabled="dandoAltaGuardando"
          @click="dandoAlta = false"
        >
          {{ $t("catalogo.cerrar") }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="button"
          data-prueba="guardar-alta"
          :disabled="dandoAltaGuardando || filasValidas.length === 0"
          @click="guardarAlta"
        >
          {{ $t("catalogo.guardar") }}
        </button>
      </template>
    </ModalDialogo>
  </section>
</template>

<style scoped>
.ct-tabla {
  width: 100%;
  font-size: 0.9rem;
  border-collapse: collapse;
}
.ct-tabla th {
  padding: 0.7rem 1rem;
  border-bottom: 1px solid var(--borde);
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 500;
  text-align: left;
}
.ct-tabla th.text-right {
  text-align: right;
}
.ct-tabla td {
  padding: 0.8rem 1rem;
  border-top: 1px solid var(--borde);
  vertical-align: middle;
}
.ct-tabla tbody tr:first-child td {
  border-top: 0;
}
.ct-foto {
  width: 2.75rem;
  height: 2.75rem;
  flex-shrink: 0;
  border-radius: 0.5rem;
  object-fit: cover;
}
.ct-sin-foto {
  display: inline-grid;
  place-items: center;
  border: 1px solid var(--borde);
  color: var(--texto-suave);
}
.ct-sub {
  max-width: 36rem;
  overflow: hidden;
  color: var(--texto-suave);
  font-size: 0.8rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ct-opcion {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
  cursor: pointer;
}
.ct-opcion-activa {
  border-color: var(--primario);
}
.ct-filas {
  display: grid;
  gap: 0.6rem;
}
.ct-fila {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 8.5rem 8rem auto;
  gap: 0.6rem;
  align-items: center;
}
.ct-fila-cabeza {
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.ct-precio {
  position: relative;
}
.ct-precio > span {
  position: absolute;
  top: 50%;
  left: 0.75rem;
  transform: translateY(-50%);
  color: var(--texto-suave);
}
.ct-precio > input {
  padding-left: 1.6rem;
}
</style>
