<script setup lang="ts">
import { computed, onMounted, ref, type WritableComputedRef } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import BarraListado from "@/components/BarraListado.vue";
import BotonImportar from "@/components/BotonImportar.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { puedeEntrar } from "@/lib/acceso";
import { api, mensajeDeError } from "@/lib/api";
import { useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { plural } from "@/lib/terminologia";
import { useVistaListado } from "@/lib/vistaListado";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

// Solo lo necesario para ubicarlo: primer nombre + apellido paterno y foto
// (el correo y el teléfono son privados).
interface Instructor {
  id: string;
  nombre: string;
  nombre_corto: string;
  foto_url: string | null;
  // Dónde atiende (ulids); vacío = no tiene sede propia (atiende en todas).
  sucursales?: string[];
  // Para las tarjetas (`resumen=1`): su agenda de los próximos 7 días y sus sedes.
  resumen?: {
    proxima: {
      inicia_en: string;
      zona_horaria: string | null;
      clase: string | null;
      tipo: "clase" | "cita";
    } | null;
    semana: { clases: number; citas: number };
    sedes: string[];
    resenas: { promedio: number; total: number } | null;
  };
}
interface Sucursal {
  id: string;
  nombre: string;
}
interface Activacion {
  email: string;
  token: string;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeInvitar = computed(() => sesion.puede("usuarios.invitar"));
// El perfil (con su expediente) es para quien administra al equipo.
const puedeVerPerfil = computed(() => sesion.puede("usuarios.gestionar"));
const vista = useVistaListado("instructores", "cuadricula");
const busqueda = ref("");

const instructores = ref<Instructor[]>([]);

// Filtros como en Miembros: sede, agenda de la semana y reseñas.
const filtros = ref({ sucursal: "", agenda: "", resenas: "" });
const filtrosDef = computed(() => [
  ...(hayMultiSucursal.value
    ? [
        {
          clave: "sucursal",
          etiqueta: t("equipoVisual.filtros.sede"),
          opciones: sucursales.value.map((s) => ({
            valor: s.id,
            texto: s.nombre,
          })),
        },
      ]
    : []),
  {
    clave: "agenda",
    etiqueta: t("equipoVisual.filtros.agenda"),
    opciones: [
      { valor: "con", texto: t("equipoVisual.filtros.conAgenda") },
      { valor: "sin", texto: t("equipoVisual.filtros.sinAgenda") },
    ],
  },
  // Las reseñas solo las ve quien ve a los clientes.
  ...(sesion.puede("miembros.ver")
    ? [
        {
          clave: "resenas",
          etiqueta: t("equipoVisual.filtros.resenas"),
          opciones: [
            { valor: "4", texto: t("equipoVisual.filtros.cuatroOMas") },
            { valor: "con", texto: t("equipoVisual.filtros.conResenas") },
            { valor: "sin", texto: t("equipoVisual.filtros.sinResenas") },
          ],
        },
      ]
    : []),
]);
function cambioFiltro(clave: string, valor: string): void {
  filtros.value = { ...filtros.value, [clave]: valor };
}
// Un filtro de la lista en la dirección; lo que no sea una de sus opciones se ignora.
function filtroEnUrl(
  clave: "sucursal" | "agenda" | "resenas",
  permitidos?: readonly string[],
): WritableComputedRef<string> {
  return computed({
    get: () => filtros.value[clave],
    set: (v: string) => {
      if (permitidos === undefined || permitidos.includes(v)) {
        filtros.value = { ...filtros.value, [clave]: v };
      }
    },
  });
}
// La búsqueda y los filtros van en la dirección: un enlace o volver atrás los conserva.
useFiltrosEnUrl({
  q: busqueda,
  sede: filtroEnUrl("sucursal"),
  agenda: filtroEnUrl("agenda", ["", "con", "sin"]),
  resenas: filtroEnUrl("resenas", ["", "4", "con", "sin"]),
});
function limpiarFiltros(): void {
  filtros.value = { sucursal: "", agenda: "", resenas: "" };
}
const filtrando = computed(
  () =>
    busqueda.value.trim() !== "" ||
    Object.values(filtros.value).some((v) => v !== ""),
);
function pasaFiltros(i: Instructor): boolean {
  const f = filtros.value;
  const semana = i.resumen?.semana;
  const agendado = (semana?.clases ?? 0) + (semana?.citas ?? 0) > 0;
  const resenas = i.resumen?.resenas ?? null;
  // Sin sede propia atiende en todas: también aparece en cada una.
  const sedes = i.sucursales ?? [];
  return (
    (f.sucursal === "" || sedes.length === 0 || sedes.includes(f.sucursal)) &&
    (f.agenda === "" || (f.agenda === "con") === agendado) &&
    (f.resenas === "" ||
      (f.resenas === "sin"
        ? resenas === null
        : resenas !== null &&
          (f.resenas === "con" || resenas.promedio >= Number(f.resenas))))
  );
}
const visibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return instructores.value.filter(
    (i) =>
      (q === "" ||
        i.nombre.toLowerCase().includes(q) ||
        i.nombre_corto.toLowerCase().includes(q)) &&
      pasaFiltros(i),
  );
});
const sucursales = ref<Sucursal[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
// El de la carga, aparte del de invitar: sin lista no es «aún no hay instructores».
const errorCarga = ref<string | null>(null);

// Con varias sedes, se puede acotar al instructor a una desde el alta (R19).
const hayMultiSucursal = computed(() => sucursales.value.length > 1);

const form = ref({ nombre: "", email: "", sucursalId: "" });
const invitando = ref(false);
const activacion = ref<Activacion | null>(null);
const abierto = ref(false);

function abrir(): void {
  form.value = { nombre: "", email: "", sucursalId: "" };
  activacion.value = null;
  error.value = null;
  abierto.value = true;
}
function cerrar(): void {
  abierto.value = false;
}

function cuando(iso: string, zona: string | null): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona ?? undefined,
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}

// "3 clases · 5 citas" (solo lo que tiene).
function carga(i: Instructor): string {
  const s = i.resumen?.semana;
  if (!s || s.clases + s.citas === 0) {
    return t("tarjetas.nadaAgendado");
  }
  return [
    s.clases > 0 ? t("tarjetas.clases", { n: s.clases }, s.clases) : "",
    s.citas > 0 ? t("tarjetas.citas", { n: s.citas }, s.citas) : "",
  ]
    .filter(Boolean)
    .join(" · ");
}

async function cargar(): Promise<void> {
  cargando.value = true;
  errorCarga.value = null;
  try {
    const [i, s] = await Promise.all([
      api.get<{ data: Instructor[] }>(`${base.value}/instructores`, {
        params: { resumen: 1 },
      }),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
    ]);
    instructores.value = i.data.data;
    sucursales.value = s.data.data;
  } catch (e) {
    errorCarga.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function invitar(): Promise<void> {
  invitando.value = true;
  error.value = null;
  activacion.value = null;
  try {
    const email = form.value.email;
    const { data } = await api.post<{
      data: { activacion: Activacion | null };
    }>(`${base.value}/usuarios/invitar`, {
      nombre: form.value.nombre,
      email: form.value.email,
      rol: "instructor",
      // Sede opcional: si se elige, el instructor queda acotado a ella.
      sucursal_id: form.value.sucursalId !== "" ? form.value.sucursalId : null,
    });
    activacion.value = data.data.activacion;
    form.value = { nombre: "", email: "", sucursalId: "" };
    // En producción el enlace llega por correo (no hay token que mostrar): se avisa
    // y se cierra el panel.
    if (activacion.value === null) {
      useToastStore().exito(t("instructores.invitar.enviada", { email }));
      cerrar();
    }
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    invitando.value = false;
  }
}

// Indicadores del equipo: cuántos son, cuántos tienen agenda esta semana, cuánto
// trabajo hay y cómo los califican.
const indicadores = computed<Indicador[]>(() => {
  const lista = instructores.value;
  const conAgenda = lista.filter(
    (i) => (i.resumen?.semana.clases ?? 0) + (i.resumen?.semana.citas ?? 0) > 0,
  ).length;
  const sesiones = lista.reduce(
    (s, i) =>
      s + (i.resumen?.semana.clases ?? 0) + (i.resumen?.semana.citas ?? 0),
    0,
  );
  const calificados = lista.filter((i) => i.resumen?.resenas);
  const promedio =
    calificados.length > 0
      ? calificados.reduce((s, i) => s + i.resumen!.resenas!.promedio, 0) /
        calificados.length
      : null;
  return [
    {
      clave: "equipo",
      etiqueta: plural(sesion.terminologia.instructor),
      valor: String(lista.length),
      icono: "instructores",
    },
    {
      clave: "conAgenda",
      etiqueta: t("equipoVisual.kpi.conAgenda"),
      valor: t("equipoVisual.deTotal", { n: conAgenda, total: lista.length }),
      icono: "agenda",
    },
    {
      clave: "semana",
      etiqueta: sesion.esCitas
        ? t("equipoVisual.kpi.citasSemana")
        : t("equipoVisual.kpi.clasesSemana"),
      valor: String(sesiones),
      icono: "reloj",
    },
    {
      clave: "resenas",
      etiqueta: t("equipoVisual.kpi.calificacion"),
      valor: promedio !== null ? promedio.toFixed(1) : "—",
      icono: "estrella",
    },
  ];
});

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="plural(sesion.terminologia.instructor)"
      :subtitulo="
        sesion.esCitas
          ? $t('equipoVisual.subtitulo')
          : $t('adminUsabilidad.equipoClases')
      "
    >
      <template
        v-if="puedeInvitar || puedeEntrar('usuarios', sesion)"
        #acciones
      >
        <!-- Quién entra al panel y con qué permisos vive en Configuración: es otra
             sección, así que va como enlace. -->
        <RouterLink
          v-if="puedeEntrar('usuarios', sesion)"
          :to="{ name: 'usuarios' }"
          class="tu-enlace text-sm"
          >{{ $t("instructores.administrarAcceso") }}</RouterLink
        >
        <template v-if="puedeInvitar">
          <BotonImportar
            ruta="importar-instructores"
            :texto="$t('nav.importar')"
          />
          <button
            class="tu-btn tu-btn-primario tu-btn-crear"
            type="button"
            @click="abrir"
          >
            {{ $t("instructores.invitar.enviar") }}
          </button>
        </template>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <AvisoTono
      v-if="!cargando && errorCarga"
      tono="peligro"
      class="mt-6"
      role="alert"
      data-prueba="error-carga-instructores"
    >
      {{ errorCarga }}
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

    <template v-else-if="!cargando">
      <!-- Lista -->
      <EstadoVacio
        v-if="instructores.length === 0"
        class="mt-8"
        icono="instructores"
        :titulo="$t('instructores.vacio')"
      />
      <template v-else>
        <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />
        <BarraListado
          v-model:busqueda="busqueda"
          v-model:vista="vista"
          class="mt-5"
          :filtros="filtrosDef"
          :valores="filtros"
          :placeholder="$t('tabla.buscar')"
          @cambio-filtro="cambioFiltro"
          @limpiar="limpiarFiltros"
        />

        <EstadoVacio
          v-if="visibles.length === 0"
          class="mt-4 tu-card"
          icono="buscar"
          :titulo="$t('equipoVisual.filtros.nadie')"
          data-prueba="sin-coincidencias"
        >
          <button
            v-if="filtrando"
            type="button"
            class="tu-btn tu-btn-discreto mt-3"
            @click="
              busqueda = '';
              limpiarFiltros();
            "
          >
            {{ $t("tabla.limpiar") }}
          </button>
        </EstadoVacio>

        <!-- Cuadrícula: quién es y cómo viene su semana (sin datos de contacto) -->
        <ul
          v-else-if="vista === 'cuadricula'"
          class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
          <li v-for="i in visibles" :key="i.id">
            <component
              :is="puedeVerPerfil ? RouterLink : 'div'"
              :to="
                puedeVerPerfil
                  ? { name: 'ficha-instructor', params: { id: i.id } }
                  : undefined
              "
              class="in-tarjeta tu-card"
              :class="{ 'in-enlace': puedeVerPerfil }"
            >
              <div class="flex items-center gap-3">
                <AvatarIniciales
                  :nombre="i.nombre"
                  :foto="i.foto_url"
                  tam="lg"
                />
                <div class="min-w-0">
                  <span class="block truncate font-medium">{{
                    i.nombre_corto
                  }}</span>
                  <span
                    v-if="i.resumen?.sedes.length"
                    class="block truncate text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ i.resumen.sedes.join(" · ") }}</span
                  >
                </div>
              </div>
              <dl v-if="i.resumen" class="in-datos">
                <div>
                  <dt>{{ $t("tarjetas.estaSemana") }}</dt>
                  <dd>{{ carga(i) }}</dd>
                </div>
                <div v-if="i.resumen.proxima">
                  <dt>{{ $t("tarjetas.proxima") }}</dt>
                  <dd :title="i.resumen.proxima.clase ?? undefined">
                    {{
                      cuando(
                        i.resumen.proxima.inicia_en,
                        i.resumen.proxima.zona_horaria,
                      )
                    }}<template v-if="i.resumen.proxima.clase">
                      · {{ i.resumen.proxima.clase }}</template
                    >
                  </dd>
                </div>
                <div v-if="i.resumen.resenas">
                  <dt>{{ $t("tarjetas.resenas") }}</dt>
                  <dd>
                    {{
                      $t("tarjetas.promedio", {
                        promedio: i.resumen.resenas.promedio.toFixed(1),
                        n: i.resumen.resenas.total,
                      })
                    }}
                  </dd>
                </div>
              </dl>
            </component>
          </li>
        </ul>

        <!-- Lista: una fila por persona (en el teléfono, una ficha con su perfil a
             la vista). -->
        <div
          v-else
          class="relative mt-4 tu-card overflow-x-auto tu-tabla-adaptable"
        >
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>{{ $t("equipoVisual.col.persona") }}</th>
                <th>{{ $t("tarjetas.estaSemana") }}</th>
                <th class="hidden md:table-cell">
                  {{ $t("equipoVisual.col.sedes") }}
                </th>
                <th class="hidden lg:table-cell">
                  {{ $t("tarjetas.proxima") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ $t("tarjetas.resenas") }}
                </th>
                <th>
                  <span class="sr-only">{{
                    $t("equipoVisual.col.acciones")
                  }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in visibles" :key="i.id" data-prueba="profesional">
                <td :data-etiqueta="$t('equipoVisual.col.persona')">
                  <div class="flex items-center gap-3">
                    <AvatarIniciales
                      :nombre="i.nombre"
                      :foto="i.foto_url"
                      tam="md"
                    />
                    <RouterLink
                      v-if="puedeVerPerfil"
                      :to="{ name: 'ficha-instructor', params: { id: i.id } }"
                      class="in-nombre"
                      >{{ i.nombre_corto }}</RouterLink
                    >
                    <span v-else class="font-medium">{{ i.nombre_corto }}</span>
                  </div>
                </td>
                <td :data-etiqueta="$t('tarjetas.estaSemana')">
                  {{ carga(i) }}
                </td>
                <td
                  class="hidden md:table-cell in-suave"
                  :data-etiqueta="$t('equipoVisual.col.sedes')"
                >
                  {{ i.resumen?.sedes.join(" · ") || "—" }}
                </td>
                <td
                  class="hidden lg:table-cell in-suave"
                  :data-etiqueta="$t('tarjetas.proxima')"
                >
                  <template v-if="i.resumen?.proxima">
                    {{
                      cuando(
                        i.resumen.proxima.inicia_en,
                        i.resumen.proxima.zona_horaria,
                      )
                    }}<template v-if="i.resumen.proxima.clase">
                      · {{ i.resumen.proxima.clase }}</template
                    >
                  </template>
                  <template v-else>—</template>
                </td>
                <td
                  class="hidden sm:table-cell tabular-nums"
                  :data-etiqueta="$t('tarjetas.resenas')"
                >
                  {{
                    i.resumen?.resenas
                      ? $t("tarjetas.promedio", {
                          promedio: i.resumen.resenas.promedio.toFixed(1),
                          n: i.resumen.resenas.total,
                        })
                      : "—"
                  }}
                </td>
                <td class="tu-celda-acciones">
                  <RouterLink
                    v-if="puedeVerPerfil"
                    :to="{ name: 'ficha-instructor', params: { id: i.id } }"
                    class="tu-enlace text-sm"
                    >{{ $t("profesional.verPerfil") }}</RouterLink
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- Invitar instructor: pocos campos, en un diálogo chico. -->
    <ModalDialogo
      :abierto="abierto"
      :titulo="$t('instructores.invitar.titulo')"
      tam="md"
      :ocupado="invitando"
      @cerrar="cerrar"
    >
      <form
        id="form-invitar-instructor"
        class="space-y-4"
        @submit.prevent="invitar"
      >
        <p
          v-if="error"
          class="text-sm"
          role="alert"
          :style="{ color: 'var(--error)' }"
        >
          {{ error }}
        </p>
        <div>
          <label class="tu-label" for="in">{{
            $t("instructores.invitar.nombre")
          }}</label>
          <input id="in" v-model="form.nombre" class="tu-input" required />
        </div>
        <div>
          <label class="tu-label" for="ie">{{
            $t("instructores.invitar.email")
          }}</label>
          <input
            id="ie"
            v-model="form.email"
            class="tu-input"
            type="email"
            required
          />
        </div>
        <!-- Con varias sucursales, la suya es obligatoria (ADR 0098). -->
        <div v-if="hayMultiSucursal">
          <label class="tu-label" for="is">{{
            $t("instructores.invitar.sede")
          }}</label>
          <select
            id="is"
            v-model="form.sucursalId"
            class="tu-input"
            required
            data-prueba="sucursal-invitar"
          >
            <option value="" disabled>
              {{ $t("instructores.invitar.eligeSede") }}
            </option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
          <p class="tu-hint mt-1">{{ $t("instructores.invitar.sedeAyuda") }}</p>
        </div>
      </form>
      <div v-if="activacion" class="in-activacion mt-4 text-sm">
        {{ $t("instructores.invitar.creada", { email: activacion.email }) }}
        <code class="block mt-1 break-all">{{ activacion.token }}</code>
      </div>

      <template #pie>
        <!-- Con la invitación hecha (y su token a la vista), ya no hay qué
             cancelar. -->
        <button
          class="tu-btn tu-btn-secundario"
          type="button"
          :disabled="invitando"
          @click="cerrar"
        >
          {{ activacion ? $t("comun.cerrar") : $t("comun.cancelar") }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="submit"
          form="form-invitar-instructor"
          :disabled="
            invitando ||
            form.nombre === '' ||
            form.email === '' ||
            (hayMultiSucursal && form.sucursalId === '')
          "
          :aria-busy="invitando || undefined"
        >
          {{
            invitando
              ? $t("instructores.invitar.enviando")
              : $t("instructores.invitar.enviar")
          }}
        </button>
      </template>
    </ModalDialogo>
  </section>
</template>

<style scoped>
.in-tarjeta {
  display: block;
  height: 100%;
  padding: 1rem;
  text-decoration: none;
  color: inherit;
}
.in-datos {
  margin-top: 0.85rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--borde);
  display: grid;
  /* Sin minmax(0, …) la columna crece al ancho del texto y se sale de la tarjeta. */
  grid-template-columns: minmax(0, 1fr);
  gap: 0.3rem;
  font-size: 0.75rem;
}
.in-datos > div {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
}
.in-datos dt {
  color: var(--texto-suave);
  flex-shrink: 0;
}
.in-datos dd {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: right;
}
.in-suave {
  color: var(--texto-suave);
}
/* El nombre abre su perfil: se lee como nombre, no como enlace azul. */
.in-nombre {
  color: inherit;
  font-weight: 500;
  text-decoration: none;
}
.in-nombre:hover {
  text-decoration: underline;
}
.in-activacion {
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
}
.in-enlace {
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}
.in-enlace:hover {
  border-color: color-mix(in srgb, var(--primario) 40%, var(--borde));
}
</style>
