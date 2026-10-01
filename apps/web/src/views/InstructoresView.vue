<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import BarraListado from "@/components/BarraListado.vue";
import BotonImportar from "@/components/BotonImportar.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { plural } from "@/lib/terminologia";
import { useVistaListado } from "@/lib/vistaListado";
import { useSesionTenantStore } from "@/stores/sesionTenant";

// Solo lo necesario para ubicarlo: primer nombre + apellido paterno y foto
// (el correo y el teléfono son privados).
interface Instructor {
  id: string;
  nombre: string;
  nombre_corto: string;
  foto_url: string | null;
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
const visibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return q === ""
    ? instructores.value
    : instructores.value.filter((i) => i.nombre.toLowerCase().includes(q));
});
const sucursales = ref<Sucursal[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

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
  error.value = null;
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
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function invitar(): Promise<void> {
  invitando.value = true;
  error.value = null;
  activacion.value = null;
  try {
    const { data } = await api.post<{ data: { activacion: Activacion } }>(
      `${base.value}/usuarios/invitar`,
      {
        nombre: form.value.nombre,
        email: form.value.email,
        rol: "instructor",
        // Sede opcional: si se elige, el instructor queda acotado a ella.
        sucursal_id:
          form.value.sucursalId !== "" ? form.value.sucursalId : null,
      },
    );
    activacion.value = data.data.activacion;
    form.value = { nombre: "", email: "", sucursalId: "" };
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    invitando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion
        :titulo="plural(sesion.terminologia.instructor)"
        :total="instructores.length"
      />
      <div v-if="puedeInvitar" class="flex items-center gap-2 flex-wrap">
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
      </div>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Lista -->
      <p
        v-if="instructores.length === 0"
        class="mt-8 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("instructores.vacio") }}
      </p>
      <template v-else>
        <BarraListado
          v-model:busqueda="busqueda"
          v-model:vista="vista"
          class="mt-6"
          :placeholder="$t('tabla.buscar')"
        />

        <!-- Cuadrícula: quién es y cómo viene su semana (sin datos de contacto) -->
        <ul
          v-if="vista === 'cuadricula'"
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

        <!-- Lista -->
        <ul v-else class="mt-4 tu-card overflow-hidden">
          <li
            v-for="i in visibles"
            :key="i.id"
            class="flex items-center gap-3 border-t px-4 py-3 first:border-t-0"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <AvatarIniciales :nombre="i.nombre" :foto="i.foto_url" tam="md" />
            <span class="flex-1 font-medium">{{ i.nombre_corto }}</span>
            <RouterLink
              v-if="puedeVerPerfil"
              :to="{ name: 'ficha-instructor', params: { id: i.id } }"
              class="tu-enlace text-sm"
              >{{ $t("profesional.verPerfil") }} →</RouterLink
            >
          </li>
        </ul>
      </template>
    </template>

    <!-- Invitar instructor (drawer lateral) -->
    <PanelLateral
      :abierto="abierto"
      :titulo="$t('instructores.invitar.titulo')"
      @cerrar="cerrar"
    >
      <form class="space-y-4" @submit.prevent="invitar">
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
        <div v-if="hayMultiSucursal">
          <label class="tu-label" for="is">{{
            $t("instructores.invitar.sede")
          }}</label>
          <select id="is" v-model="form.sucursalId" class="tu-input">
            <option value="">
              {{ $t("instructores.invitar.todasSedes") }}
            </option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>
      </form>
      <div
        v-if="activacion"
        class="mt-4 text-sm rounded-lg p-3"
        :style="{
          background: 'var(--primario-suave)',
          color: 'var(--primario-fuerte)',
        }"
      >
        {{ $t("instructores.invitar.creada", { email: activacion.email }) }}
        <code class="block mt-1 break-all">{{ activacion.token }}</code>
      </div>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button class="tu-btn tu-btn-fantasma" type="button" @click="cerrar">
            {{ $t("comun.cerrar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="invitando || form.nombre === '' || form.email === ''"
            @click="invitar"
          >
            {{
              invitando
                ? $t("instructores.invitar.enviando")
                : $t("instructores.invitar.enviar")
            }}
          </button>
        </div>
      </template>
    </PanelLateral>
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
.in-enlace {
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}
.in-enlace:hover {
  border-color: color-mix(in srgb, var(--primario) 40%, var(--borde));
}
</style>
