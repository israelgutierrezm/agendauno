<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import CampoContrasena from "@/components/CampoContrasena.vue";
import IconoRed from "@/components/IconoRed.vue";
import { api, mensajeDeError } from "@/lib/api";
import { trackEvent } from "@/lib/analytics";
import { recordarNegocio } from "@/lib/negociosRecientes";
import { updateSeo } from "@/lib/seo";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Sesion {
  clase: string | null;
  inicia_en: string | null;
  zona_horaria: string | null;
  sucursal: string | null;
  instructor: string | null;
  capacidad: number | null;
  lugares_libres: number | null;
}
interface Producto {
  nombre: string;
  tipo: string;
  precio_minor: number;
  moneda: string;
  ilimitado: boolean;
  creditos_incluidos: number | null;
}
type NombreRed = "instagram" | "facebook" | "tiktok" | "youtube" | "sitio_web";
interface Red {
  red: NombreRed;
  url: string;
}
interface DiaAbierto {
  dia: number;
  abre: string;
  cierra: string;
}
interface Sucursal {
  id?: string;
  nombre: string;
  zona_horaria: string | null;
  region: string | null;
  direccion?: string | null;
  mapa_url?: string | null;
  telefono?: string | null;
  whatsapp_url?: string | null;
  redes?: Red[];
  // Null: no hay horario capturado ni de sus profesionales.
  horario?: DiaAbierto[] | null;
}
interface Servicio {
  id: string;
  nombre: string;
  descripcion: string | null;
  categoria: string | null;
  grupal: boolean;
  duracion_minutos: number | null;
  precio_minor: number | null;
  moneda: string;
  agendable: boolean;
  niveles: string[];
}
interface ClaseHorario {
  dia: number;
  hora: string;
  duracion_minutos: number | null;
  clase: string;
  categoria: string | null;
  instructor: string | null;
  sucursal: string | null;
}
interface Resena {
  calificacion: number;
  comentario: string | null;
  nombre: string | null;
  actividad: string | null;
  fecha: string | null;
}
interface Escaparate {
  estudio: {
    slug: string;
    nombre: string;
    logo_url: string | null;
    portada_url?: string | null;
    descripcion?: string | null;
    redes?: Red[];
    whatsapp_url?: string | null;
    perfil: string;
    perfil_config: { terminologia?: Record<string, string> };
    ciudad: string | null;
    pais: string | null;
    whatsapp: string | null;
    tiene_citas: boolean;
  };
  sucursales: Sucursal[];
  instructores: { nombre: string; foto_url: string | null }[];
  servicios?: Servicio[];
  horario_clases?: ClaseHorario[];
  productos: Producto[];
  proximas_sesiones: Sesion[];
  // Solo las que el negocio deja visibles (promedio de todas; comentarios recientes).
  resenas?: { promedio: number | null; total: number; recientes: Resena[] };
}

const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();

const slug = computed(() => String(route.params.slug));
const escaparate = ref<Escaparate | null>(null);
const cargando = ref(true);
const noDisponible = ref(false);

const registrando = ref(false);
const form = ref({
  nombre: "",
  apellido: "",
  email: "",
  password: "",
  passwordConfirmation: "",
});
const errorRegistro = ref<string | null>(null);
// Su correo ya era de alguien en el negocio: se confirma por correo antes de entrar.
const confirmacionEnviada = ref<string | null>(null);

const ubicacion = computed(() => {
  const e = escaparate.value?.estudio;
  return e ? [e.ciudad, e.pais].filter(Boolean).join(", ") : "";
});
const usaCitas = computed(() => escaparate.value?.estudio.tiene_citas === true);

// Descripción larga: se recorta y se abre con «Leer más».
const descripcionAbierta = ref(false);
const descripcionLarga = computed(
  () => (escaparate.value?.estudio.descripcion ?? "").length > 280,
);

// Servicios o clases, por categoría y con buscador.
const busqueda = ref("");
const categoria = ref<string | null>(null);
const servicios = computed(() => escaparate.value?.servicios ?? []);
const categorias = computed(() => [
  ...new Set(
    servicios.value
      .map((x) => x.categoria)
      .filter((c): c is string => c !== null && c !== ""),
  ),
]);
const serviciosVisibles = computed(() => {
  const q = busqueda.value.trim().toLocaleLowerCase("es-MX");
  return servicios.value.filter(
    (x) =>
      (categoria.value === null || x.categoria === categoria.value) &&
      (q === "" ||
        `${x.nombre} ${x.descripcion ?? ""}`
          .toLocaleLowerCase("es-MX")
          .includes(q)),
  );
});
const gruposServicios = computed(() => {
  const grupos = new Map<string, Servicio[]>();
  for (const x of serviciosVisibles.value) {
    const clave = x.categoria ?? "";
    grupos.set(clave, [...(grupos.get(clave) ?? []), x]);
  }
  return [...grupos.entries()].map(([nombre, lista]) => ({ nombre, lista }));
});

// Horario semanal de clases, por día (lunes a domingo).
const horarioPorDia = computed(() => {
  const filas = escaparate.value?.horario_clases ?? [];
  return [1, 2, 3, 4, 5, 6, 7]
    .map((dia) => ({ dia, clases: filas.filter((f) => f.dia === dia) }))
    .filter((d) => d.clases.length > 0);
});
const variasSedes = computed(
  () => (escaparate.value?.sucursales.length ?? 0) > 1,
);
function horaCorta(hora: string): string {
  return hora.slice(0, 5);
}
function horarioDeSede(su: Sucursal): { dia: number; texto: string | null }[] {
  return [1, 2, 3, 4, 5, 6, 7].map((dia) => {
    const d = (su.horario ?? []).find((h) => h.dia === dia);
    return { dia, texto: d ? `${d.abre} – ${d.cierra}` : null };
  });
}
const etiquetaProfesional = computed(
  () =>
    escaparate.value?.estudio.perfil_config.terminologia?.instructor ??
    "Profesional",
);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function fechaHora(iso: string | null, zona: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: zona ?? undefined,
  }).format(new Date(iso));
}
function iniciales(nombre: string): string {
  return nombre
    .split(" ")
    .slice(0, 2)
    .map((p) => p.charAt(0))
    .join("")
    .toUpperCase();
}

function abrirRegistro(origen: string): void {
  registrando.value = true;
  trackEvent("student_registration_opened", {
    source: origen,
    business_profile: escaparate.value?.estudio.perfil ?? "unknown",
  });
}

async function cargar(): Promise<void> {
  cargando.value = true;
  noDisponible.value = false;
  try {
    const { data } = await api.get<{ data: Escaparate }>(
      `/api/v1/app/${slug.value}/escaparate`,
    );
    escaparate.value = data.data;
    const estudio = data.data.estudio;
    recordarNegocio({
      slug: estudio.slug,
      nombre: estudio.nombre,
      logo_url: estudio.logo_url,
      ciudad: estudio.ciudad,
      pais: estudio.pais,
    });
    const lugar = [estudio.ciudad, estudio.pais].filter(Boolean).join(", ");
    const esCitas = estudio.tiene_citas;
    updateSeo({
      title: `${estudio.nombre} | ${esCitas ? "Servicios y citas" : "Horarios y clases"} en AgendaUno`,
      description: esCitas
        ? `Consulta servicios, profesionales y horarios disponibles de ${estudio.nombre}${lugar ? ` en ${lugar}` : ""}. Reserva tu cita en línea.`
        : `Consulta próximas clases, instructores y precios de ${estudio.nombre}${lugar ? ` en ${lugar}` : ""}.`,
      path: `/estudio/${estudio.slug}`,
      image: estudio.portada_url ?? estudio.logo_url ?? undefined,
      type: "profile",
      jsonLd: {
        "@context": "https://schema.org",
        "@type": "LocalBusiness",
        name: estudio.nombre,
        url: `https://agendauno.mx/estudio/${estudio.slug}`,
        image: estudio.logo_url ?? undefined,
        address: lugar || undefined,
        description: estudio.descripcion ?? undefined,
        sameAs: (estudio.redes ?? []).map((r) => r.url),
      },
    });
    trackEvent("public_studio_viewed", { business_profile: estudio.perfil });
  } catch {
    // 404 (estudio no publicado/privado/inexistente) u otro error: pantalla amable.
    noDisponible.value = true;
  } finally {
    cargando.value = false;
  }
}

async function registrar(): Promise<void> {
  errorRegistro.value = null;
  try {
    const registro = await sesion.registrarAlumno(slug.value, {
      nombre: form.value.nombre,
      primer_apellido: form.value.apellido,
      email: form.value.email,
      password: form.value.password,
      passwordConfirmation: form.value.passwordConfirmation,
    });
    trackEvent("student_account_created", {
      business_profile: escaparate.value?.estudio.perfil ?? "unknown",
    });
    // Auto-login: al portal del alumno (su cuenta) para reservar/comprar.
    if (registro.confirmar !== null) {
      confirmacionEnviada.value = registro.confirmar;
      return;
    }
    void router.push({ name: sesion.destinoAlEntrar });
  } catch (e) {
    errorRegistro.value = mensajeDeError(e);
  }
}

onMounted(cargar);
</script>

<template>
  <div>
    <p
      v-if="cargando"
      class="mx-auto max-w-5xl px-4 py-16 text-center"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("escaparate.cargando") }}
    </p>

    <section
      v-else-if="noDisponible"
      class="mx-auto max-w-md px-4 py-20 text-center"
    >
      <p class="text-lg font-semibold">{{ $t("escaparate.noDisponible") }}</p>
      <RouterLink
        :to="{ name: 'directorio' }"
        class="tu-enlace mt-4 inline-block"
        >{{ $t("escaparate.volverDirectorio") }}</RouterLink
      >
    </section>

    <template v-else-if="escaparate">
      <!-- Hero -->
      <div
        v-if="escaparate.estudio.portada_url"
        class="ep-portada"
        :style="{ backgroundImage: `url(${escaparate.estudio.portada_url})` }"
        role="img"
        :aria-label="escaparate.estudio.nombre"
      ></div>
      <section
        class="px-4 py-14 text-center"
        :class="{ 'pt-0': escaparate.estudio.portada_url }"
        :style="{ background: 'var(--superficie)' }"
      >
        <div
          class="mx-auto max-w-3xl"
          :class="{ 'ep-sobre-portada': escaparate.estudio.portada_url }"
        >
          <img
            v-if="escaparate.estudio.logo_url"
            :src="escaparate.estudio.logo_url"
            :alt="escaparate.estudio.nombre"
            class="mx-auto h-20 w-20 rounded-2xl object-cover"
            :style="{ boxShadow: 'var(--sombra)' }"
          />
          <span
            v-else
            class="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl text-2xl font-bold"
            :style="{
              background: 'var(--primario)',
              color: 'var(--primario-contraste)',
            }"
            aria-hidden="true"
            >{{ iniciales(escaparate.estudio.nombre) }}</span
          >

          <h1 class="mt-5 text-4xl font-extrabold tracking-tight">
            {{ escaparate.estudio.nombre }}
          </h1>
          <span class="tu-badge mt-4">{{
            $t(`registro.perfiles.${escaparate.estudio.perfil}`)
          }}</span>
          <p
            v-if="ubicacion"
            class="mt-2 text-lg"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ ubicacion }}
          </p>
          <div
            v-if="escaparate.estudio.descripcion"
            class="mx-auto mt-5 max-w-2xl text-left sm:text-center"
          >
            <p
              class="whitespace-pre-line"
              :class="{
                'ep-recortada': descripcionLarga && !descripcionAbierta,
              }"
              data-prueba="descripcion"
            >
              {{ escaparate.estudio.descripcion }}
            </p>
            <button
              v-if="descripcionLarga"
              type="button"
              class="tu-enlace mt-1 text-sm"
              @click="descripcionAbierta = !descripcionAbierta"
            >
              {{
                descripcionAbierta
                  ? $t("perfilPublico.publico.leerMenos")
                  : $t("perfilPublico.publico.leerMas")
              }}
            </button>
          </div>
          <ul
            v-if="
              (escaparate.estudio.redes ?? []).length > 0 ||
              escaparate.estudio.whatsapp_url
            "
            class="mt-5 flex flex-wrap justify-center gap-2"
            data-prueba="redes"
          >
            <li v-for="r in escaparate.estudio.redes ?? []" :key="r.red">
              <a
                :href="r.url"
                target="_blank"
                rel="noopener"
                class="ep-red"
                :aria-label="$t(`perfilPublico.redes.${r.red}`)"
                :title="$t(`perfilPublico.redes.${r.red}`)"
              >
                <IconoRed :red="r.red" />
              </a>
            </li>
            <li v-if="escaparate.estudio.whatsapp_url">
              <a
                :href="escaparate.estudio.whatsapp_url"
                target="_blank"
                rel="noopener"
                class="ep-red"
                :aria-label="$t('perfilPublico.publico.whatsapp')"
                :title="$t('perfilPublico.publico.whatsapp')"
              >
                <IconoRed red="whatsapp" />
              </a>
            </li>
          </ul>

          <div class="mt-7 flex flex-wrap justify-center gap-3">
            <RouterLink
              v-if="escaparate.estudio.tiene_citas"
              :to="{ name: 'agendar-cita', params: { slug } }"
              class="tu-btn tu-btn-primario px-6"
              @click="
                trackEvent('book_appointment_clicked', { source: 'hero' })
              "
            >
              {{ $t("escaparate.agendarCita") }}
            </RouterLink>
            <button
              v-if="!usaCitas"
              type="button"
              class="tu-btn tu-btn-primario px-6"
              @click="abrirRegistro('hero')"
            >
              {{ $t("escaparate.reservar") }}
            </button>
            <RouterLink
              :to="{ name: 'entrar', query: { estudio: slug } }"
              class="tu-btn tu-btn-fantasma px-6"
              @click="
                trackEvent('student_login_clicked', { source: 'public_studio' })
              "
            >
              {{
                usaCitas
                  ? $t("escaparate.yaSoyCliente")
                  : $t("escaparate.yaSoyAlumno")
              }}
            </RouterLink>
          </div>
        </div>
      </section>

      <!-- Flujo de citas -->
      <section v-if="usaCitas" class="tu-agenda-citas">
        <div class="mx-auto max-w-5xl px-4 py-12 sm:py-16">
          <div class="tu-agenda-citas-cabecera">
            <div>
              <p class="tu-agenda-citas-etiqueta">Agenda en línea</p>
              <h2 class="text-3xl font-light tracking-tight">
                {{ $t("escaparate.agendaCitasTitulo") }}
              </h2>
              <p
                class="mt-3 max-w-2xl"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("escaparate.agendaCitasDesc") }}
              </p>
            </div>
            <RouterLink
              :to="{ name: 'agendar-cita', params: { slug } }"
              class="tu-btn tu-btn-primario shrink-0 px-6"
              @click="
                trackEvent('book_appointment_clicked', { source: 'steps' })
              "
            >
              {{ $t("escaparate.agendarCita") }}
            </RouterLink>
          </div>
          <ol class="tu-agenda-pasos mt-9">
            <li v-for="n in 3" :key="n">
              <span class="tu-agenda-paso-num">0{{ n }}</span>
              <strong>{{ $t(`escaparate.agendaPaso${n}`) }}</strong>
              <span
                v-if="n < 3"
                class="tu-agenda-paso-linea"
                aria-hidden="true"
              ></span>
            </li>
          </ol>
        </div>
      </section>

      <!-- Próximas clases -->
      <section v-else class="mx-auto max-w-5xl px-4 py-12">
        <h2 class="text-2xl font-light">
          {{ $t("escaparate.proximasClases") }}
        </h2>
        <p
          v-if="escaparate.proximas_sesiones.length === 0"
          class="mt-4"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("escaparate.sinClases") }}
        </p>
        <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <li
            v-for="(s, i) in escaparate.proximas_sesiones"
            :key="i"
            class="tu-card p-5"
          >
            <p class="font-light">{{ s.clase ?? "—" }}</p>
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ fechaHora(s.inicia_en, s.zona_horaria) }}
            </p>
            <p
              v-if="s.sucursal || s.instructor"
              class="mt-1 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ [s.sucursal, s.instructor].filter(Boolean).join(" · ") }}
            </p>
            <span
              class="tu-badge mt-3"
              :class="
                s.lugares_libres === 0 ? 'tu-badge-aviso' : 'tu-badge-exito'
              "
            >
              <template v-if="s.lugares_libres === null">{{
                $t("escaparate.cupoAbierto")
              }}</template>
              <template v-else-if="s.lugares_libres === 0">{{
                $t("escaparate.lleno")
              }}</template>
              <template v-else>{{
                $t("escaparate.lugaresLibres", { n: s.lugares_libres })
              }}</template>
            </span>
          </li>
        </ul>
      </section>

      <!-- Servicios o clases, por categoría -->
      <section
        v-if="servicios.length > 0"
        class="mx-auto max-w-5xl px-4 py-12"
        data-prueba="servicios"
      >
        <div class="flex flex-wrap items-end justify-between gap-3">
          <h2 class="text-2xl font-light">
            {{
              usaCitas
                ? $t("perfilPublico.publico.servicios")
                : $t("perfilPublico.publico.clases")
            }}
          </h2>
          <input
            v-if="servicios.length > 5"
            v-model="busqueda"
            type="search"
            class="tu-input w-full sm:w-64"
            :placeholder="$t('perfilPublico.publico.buscar')"
            :aria-label="$t('perfilPublico.publico.buscar')"
          />
        </div>
        <div
          v-if="categorias.length > 1"
          class="tu-segmentado mt-4 flex-wrap"
          role="group"
        >
          <button
            type="button"
            :aria-pressed="categoria === null"
            @click="categoria = null"
          >
            {{ $t("perfilPublico.publico.todos") }}
          </button>
          <button
            v-for="c in categorias"
            :key="c"
            type="button"
            :aria-pressed="categoria === c"
            @click="categoria = c"
          >
            {{ c }}
          </button>
        </div>
        <p
          v-if="serviciosVisibles.length === 0"
          class="mt-6"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("perfilPublico.publico.sinCoincidencias") }}
        </p>
        <div v-for="g in gruposServicios" :key="g.nombre" class="mt-6">
          <h3
            v-if="g.nombre && categorias.length > 1 && categoria === null"
            class="text-sm font-semibold"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ g.nombre }}
          </h3>
          <ul class="mt-2 divide-y divide-[var(--borde)]">
            <li
              v-for="x in g.lista"
              :key="x.id"
              class="flex flex-wrap items-start justify-between gap-3 py-4"
            >
              <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ x.nombre }}</p>
                <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                  {{
                    [
                      x.duracion_minutos
                        ? $t("perfilPublico.publico.duracion", {
                            n: x.duracion_minutos,
                          })
                        : null,
                      x.niveles.length > 0
                        ? $t("perfilPublico.publico.niveles", {
                            lista: x.niveles.join(", "),
                          })
                        : null,
                    ]
                      .filter(Boolean)
                      .join(" · ")
                  }}
                </p>
                <p v-if="x.descripcion" class="mt-1 text-sm">
                  {{ x.descripcion }}
                </p>
              </div>
              <div class="flex items-center gap-3">
                <span
                  v-if="x.precio_minor !== null"
                  class="font-semibold tabular-nums"
                  >{{ dinero(x.precio_minor, x.moneda) }}</span
                >
                <RouterLink
                  v-if="x.agendable"
                  :to="{ name: 'agendar-cita', params: { slug } }"
                  class="tu-btn tu-btn-fantasma"
                  @click="
                    trackEvent('book_appointment_clicked', {
                      source: 'service',
                    })
                  "
                >
                  {{ $t("perfilPublico.publico.agendar") }}
                </RouterLink>
              </div>
            </li>
          </ul>
        </div>
      </section>

      <!-- Horario semanal de clases -->
      <section
        v-if="horarioPorDia.length > 0"
        id="horario"
        class="py-12"
        :style="{ background: 'var(--superficie)' }"
        data-prueba="horario-clases"
      >
        <div class="mx-auto max-w-5xl px-4">
          <h2 class="text-2xl font-light">
            {{ $t("perfilPublico.publico.horarioClases") }}
          </h2>
          <div class="ep-semana mt-6">
            <div v-for="d in horarioPorDia" :key="d.dia">
              <h3 class="text-sm font-semibold">
                {{ $t(`perfilPublico.dias.${d.dia}`) }}
              </h3>
              <ul class="mt-2 space-y-2">
                <li v-for="(c, i) in d.clases" :key="i" class="ep-clase">
                  <p class="text-sm font-semibold tabular-nums">
                    {{ horaCorta(c.hora) }}
                  </p>
                  <p class="text-sm">{{ c.clase }}</p>
                  <p
                    v-if="c.instructor || (variasSedes && c.sucursal)"
                    class="text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{
                      [c.instructor, variasSedes ? c.sucursal : null]
                        .filter(Boolean)
                        .join(" · ")
                    }}
                  </p>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </section>

      <!-- Precios -->
      <section
        id="precios"
        class="py-12"
        :style="{ background: 'var(--superficie)' }"
      >
        <div class="mx-auto max-w-5xl px-4">
          <h2 class="text-2xl font-light">{{ $t("escaparate.precios") }}</h2>
          <p
            v-if="escaparate.productos.length === 0"
            class="mt-4"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("escaparate.sinPrecios") }}
          </p>
          <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li
              v-for="(p, i) in escaparate.productos"
              :key="i"
              class="tu-card flex flex-col p-5"
            >
              <span class="tu-badge self-start">{{
                $t(`escaparate.registro.tipos.${p.tipo}`)
              }}</span>
              <p class="mt-2 font-light">{{ p.nombre }}</p>
              <p class="mt-1 text-2xl font-extrabold">
                {{ dinero(p.precio_minor, p.moneda) }}
              </p>
              <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
                <template v-if="p.ilimitado">{{
                  $t("escaparate.ilimitado")
                }}</template>
                <template v-else-if="p.creditos_incluidos">{{
                  $t("escaparate.creditos", { n: p.creditos_incluidos / 1000 })
                }}</template>
              </p>
              <button
                type="button"
                class="tu-btn tu-btn-primario mt-4 w-full"
                @click="abrirRegistro('price_card')"
              >
                {{ $t("escaparate.crearCuenta") }}
              </button>
            </li>
          </ul>
        </div>
      </section>

      <!-- Profesionales o instructores -->
      <section
        v-if="escaparate.instructores.length > 0"
        class="mx-auto max-w-5xl px-4 py-12"
      >
        <h2 class="text-2xl font-light">
          {{
            usaCitas
              ? $t("escaparate.profesionales")
              : $t("escaparate.instructores")
          }}
        </h2>
        <p
          v-if="usaCitas"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{
            $t("escaparate.profesionalesDesc", {
              profesional: etiquetaProfesional,
            })
          }}
        </p>
        <ul class="mt-6 flex flex-wrap gap-4">
          <li
            v-for="(p, i) in escaparate.instructores"
            :key="i"
            class="flex items-center gap-3"
          >
            <img
              v-if="p.foto_url"
              :src="p.foto_url"
              alt=""
              class="h-11 w-11 rounded-full object-cover"
            />
            <span
              v-else
              class="flex h-11 w-11 items-center justify-center rounded-full font-bold"
              :style="{
                background: 'var(--primario)',
                color: 'var(--primario-contraste)',
              }"
              aria-hidden="true"
              >{{ iniciales(p.nombre) }}</span
            >
            <span class="font-semibold">{{ p.nombre }}</span>
          </li>
        </ul>
      </section>

      <!-- Reseñas visibles de sus clientes -->
      <section
        v-if="(escaparate.resenas?.total ?? 0) > 0"
        class="mx-auto max-w-5xl px-4 py-12"
      >
        <h2 class="text-2xl font-light">{{ $t("escaparate.resenas") }}</h2>
        <p class="mt-2 flex items-baseline gap-2">
          <span class="text-3xl font-light">{{
            $t("escaparate.promedio", {
              promedio: escaparate.resenas?.promedio?.toFixed(1),
            })
          }}</span>
          <span class="text-sm" :style="{ color: 'var(--texto-suave)' }">{{
            $t("escaparate.totalResenas", escaparate.resenas?.total ?? 0)
          }}</span>
        </p>
        <ul
          v-if="(escaparate.resenas?.recientes ?? []).length > 0"
          class="mt-6 grid gap-4 sm:grid-cols-2"
        >
          <li
            v-for="(r, i) in escaparate.resenas?.recientes"
            :key="i"
            class="rounded-lg border p-4"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <p
              class="text-sm tracking-widest"
              :style="{ color: 'var(--aviso)' }"
              :aria-label="$t('escaparate.estrellas', { n: r.calificacion })"
              role="img"
            >
              {{ "★".repeat(r.calificacion)
              }}<span :style="{ color: 'var(--borde)' }">{{
                "★".repeat(5 - r.calificacion)
              }}</span>
            </p>
            <p class="mt-2">{{ r.comentario }}</p>
            <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ [r.nombre, r.actividad].filter(Boolean).join(" · ") }}
            </p>
          </li>
        </ul>
      </section>

      <!-- Ubicación -->
      <section
        v-if="escaparate.sucursales.length > 0 || ubicacion"
        class="py-12"
        :style="{ background: 'var(--superficie)' }"
      >
        <div class="mx-auto max-w-5xl px-4">
          <h2 class="text-2xl font-light">{{ $t("escaparate.ubicacion") }}</h2>
          <p
            v-if="ubicacion"
            class="mt-2"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ ubicacion }}
          </p>
          <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <li
              v-for="(su, i) in escaparate.sucursales"
              :key="i"
              class="tu-card p-4"
              data-prueba="sede"
            >
              <p class="font-semibold">{{ su.nombre }}</p>
              <p
                v-if="su.direccion || su.region"
                class="text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ su.direccion ?? su.region }}
              </p>
              <div
                v-if="su.mapa_url || su.whatsapp_url || su.telefono"
                class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm"
              >
                <a
                  v-if="su.mapa_url"
                  :href="su.mapa_url"
                  target="_blank"
                  rel="noopener"
                  class="tu-enlace inline-flex items-center gap-1.5"
                >
                  <IconoRed red="mapa" :tamano="16" />
                  {{ $t("perfilPublico.publico.comoLlegar") }}
                </a>
                <a
                  v-if="su.whatsapp_url"
                  :href="su.whatsapp_url"
                  target="_blank"
                  rel="noopener"
                  class="tu-enlace inline-flex items-center gap-1.5"
                >
                  <IconoRed red="whatsapp" :tamano="16" />
                  {{ $t("perfilPublico.publico.whatsapp") }}
                </a>
                <a
                  v-if="su.telefono"
                  :href="`tel:${su.telefono.replace(/[^0-9+]/g, '')}`"
                  class="tu-enlace inline-flex items-center gap-1.5"
                >
                  <IconoRed red="telefono" :tamano="16" />
                  {{ su.telefono }}
                </a>
              </div>
              <ul
                v-if="(su.redes ?? []).length > 0"
                class="mt-3 flex flex-wrap gap-2"
              >
                <li v-for="r in su.redes" :key="r.red">
                  <a
                    :href="r.url"
                    target="_blank"
                    rel="noopener"
                    class="ep-red ep-red-chica"
                    :aria-label="`${$t(`perfilPublico.redes.${r.red}`)} · ${su.nombre}`"
                  >
                    <IconoRed :red="r.red" :tamano="16" />
                  </a>
                </li>
              </ul>
              <dl
                v-if="su.horario"
                class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-sm"
              >
                <template v-for="h in horarioDeSede(su)" :key="h.dia">
                  <dt :style="{ color: 'var(--texto-suave)' }">
                    {{ $t(`perfilPublico.dias.${h.dia}`) }}
                  </dt>
                  <dd class="tabular-nums">
                    {{ h.texto ?? $t("perfilPublico.publico.cerrado") }}
                  </dd>
                </template>
              </dl>
            </li>
          </ul>
        </div>
      </section>

      <!-- CTA final -->
      <section class="mx-auto max-w-3xl px-4 py-14 text-center">
        <h2 class="text-2xl font-light">{{ escaparate.estudio.nombre }}</h2>
        <RouterLink
          v-if="usaCitas"
          :to="{ name: 'agendar-cita', params: { slug } }"
          class="tu-btn tu-btn-primario mt-5 px-8"
          @click="trackEvent('book_appointment_clicked', { source: 'final' })"
        >
          {{ $t("escaparate.agendarCita") }}
        </RouterLink>
        <button
          v-else
          type="button"
          class="tu-btn tu-btn-primario mt-5 px-8"
          @click="abrirRegistro('final')"
        >
          {{ $t("escaparate.reservar") }}
        </button>
      </section>

      <!-- Modal de registro -->
      <div
        v-if="registrando"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
      >
        <div
          class="absolute inset-0 bg-black/50"
          @click="registrando = false"
        />
        <div
          class="relative w-full max-w-md tu-card p-6"
          :style="{ background: 'var(--superficie)' }"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-xl font-light">
                {{ $t("escaparate.registro.titulo") }}
              </h3>
              <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{
                  $t("escaparate.registro.subtitulo", {
                    estudio: escaparate.estudio.nombre,
                  })
                }}
              </p>
            </div>
            <button
              type="button"
              class="tu-icono-btn shrink-0"
              :aria-label="$t('comun.cerrar')"
              @click="registrando = false"
            >
              <span aria-hidden="true">✕</span>
            </button>
          </div>

          <form class="mt-5 space-y-3" @submit.prevent="registrar">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="tu-label" for="rn">{{
                  $t("escaparate.registro.nombre")
                }}</label>
                <input
                  id="rn"
                  v-model="form.nombre"
                  class="tu-input"
                  required
                />
              </div>
              <div>
                <label class="tu-label" for="ra">{{
                  $t("escaparate.registro.apellido")
                }}</label>
                <input id="ra" v-model="form.apellido" class="tu-input" />
              </div>
            </div>
            <div>
              <label class="tu-label" for="re">{{
                $t("escaparate.registro.email")
              }}</label>
              <input
                id="re"
                v-model="form.email"
                class="tu-input"
                type="email"
                required
              />
            </div>
            <div>
              <label class="tu-label" for="rp">{{
                $t("escaparate.registro.password")
              }}</label>
              <CampoContrasena
                id="rp"
                v-model="form.password"
                autocomplete="new-password"
                :required="true"
              />
            </div>
            <div>
              <label class="tu-label" for="rpc">{{
                $t("escaparate.registro.passwordConfirm")
              }}</label>
              <CampoContrasena
                id="rpc"
                v-model="form.passwordConfirmation"
                autocomplete="new-password"
                :required="true"
              />
            </div>

            <p v-if="confirmacionEnviada" class="text-sm" role="status">
              {{
                $t("confirmarRegistro.enviado", { email: confirmacionEnviada })
              }}
            </p>
            <p v-if="errorRegistro" class="text-sm" style="color: var(--error)">
              {{ errorRegistro }}
            </p>

            <button
              class="tu-btn tu-btn-primario w-full"
              type="submit"
              :disabled="sesion.cargando"
            >
              {{
                sesion.cargando
                  ? $t("escaparate.registro.creando")
                  : $t("escaparate.registro.crear")
              }}
            </button>
          </form>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ep-portada {
  height: clamp(9rem, 28vw, 18rem);
  background-size: cover;
  background-position: center;
}
.ep-sobre-portada {
  margin-top: -2.5rem;
}
.ep-recortada {
  display: -webkit-box;
  -webkit-line-clamp: 4;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.ep-red {
  display: inline-flex;
  width: 2.5rem;
  height: 2.5rem;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  border: 1px solid var(--borde);
  color: var(--texto);
  background: var(--superficie);
}
.ep-red:hover {
  border-color: var(--texto-suave);
}
.ep-red-chica {
  width: 2rem;
  height: 2rem;
}
.ep-semana {
  display: grid;
  gap: 1.25rem;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
}
.ep-clase {
  padding: 0.6rem 0.75rem;
  border-radius: 0.75rem;
  border-left: 3px solid var(--primario);
  background: color-mix(in srgb, var(--primario) 7%, var(--superficie));
}
.tu-agenda-citas {
  background:
    radial-gradient(
      circle at 88% 12%,
      rgb(53 194 249 / 17%),
      transparent 22rem
    ),
    var(--fondo);
}
.tu-agenda-citas-cabecera {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 2rem;
}
.tu-agenda-citas-etiqueta {
  margin-bottom: 0.65rem;
  color: var(--primario);
  font-size: 0.72rem;
  font-weight: 750;
  letter-spacing: 0.09em;
  text-transform: uppercase;
}
.tu-agenda-pasos {
  display: grid;
  gap: 0.85rem;
}
.tu-agenda-pasos li {
  position: relative;
  display: flex;
  min-height: 6.5rem;
  align-items: center;
  gap: 1rem;
  padding: 1.2rem;
  border: 1px solid var(--borde);
  border-radius: 1.2rem;
  background: color-mix(in srgb, var(--superficie) 90%, transparent);
}
.tu-agenda-paso-num {
  display: grid;
  width: 3rem;
  height: 3rem;
  flex: 0 0 auto;
  place-content: center;
  border-radius: 50%;
  background: color-mix(in srgb, var(--primario) 11%, var(--superficie));
  color: var(--primario);
  font-size: 0.76rem;
  font-weight: 800;
}
.tu-agenda-paso-linea {
  display: none;
}
@media (min-width: 720px) {
  .tu-agenda-pasos {
    grid-template-columns: repeat(3, 1fr);
  }
  .tu-agenda-pasos li {
    flex-direction: column;
    align-items: flex-start;
  }
  .tu-agenda-paso-linea {
    position: absolute;
    top: 2.7rem;
    left: calc(100% - 0.25rem);
    z-index: 2;
    display: block;
    width: 1.35rem;
    border-top: 1px dashed color-mix(in srgb, var(--primario) 45%, var(--borde));
  }
}
@media (max-width: 639px) {
  .tu-agenda-citas-cabecera {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
