<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import CampoContrasena from "@/components/CampoContrasena.vue";
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
interface Sucursal {
  nombre: string;
  zona_horaria: string | null;
  region: string | null;
}
interface Escaparate {
  estudio: {
    slug: string;
    nombre: string;
    logo_url: string | null;
    perfil: string;
    perfil_config: { terminologia?: Record<string, string> };
    ciudad: string | null;
    pais: string | null;
    whatsapp: string | null;
    tiene_citas: boolean;
  };
  sucursales: Sucursal[];
  instructores: string[];
  productos: Producto[];
  proximas_sesiones: Sesion[];
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
      image: estudio.logo_url ?? undefined,
      type: "profile",
      jsonLd: {
        "@context": "https://schema.org",
        "@type": "LocalBusiness",
        name: estudio.nombre,
        url: `https://agendauno.mx/estudio/${estudio.slug}`,
        image: estudio.logo_url ?? undefined,
        address: lugar || undefined,
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
    void router.push({ name: sesion.rutaInicio });
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
      <section
        class="px-4 py-14 text-center"
        :style="{ background: 'var(--superficie)' }"
      >
        <div class="mx-auto max-w-3xl">
          <img
            v-if="escaparate.estudio.logo_url"
            :src="escaparate.estudio.logo_url"
            :alt="escaparate.estudio.nombre"
            class="mx-auto h-20 w-20 rounded-2xl object-cover"
            :style="{ boxShadow: 'var(--sombra)' }"
          />
          <span
            v-else
            class="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl text-2xl font-bold text-white"
            :style="{ background: 'var(--primario)' }"
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

      <!-- Precios -->
      <section class="py-12" :style="{ background: 'var(--superficie)' }">
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
            v-for="(nombre, i) in escaparate.instructores"
            :key="i"
            class="flex items-center gap-3"
          >
            <span
              class="flex h-11 w-11 items-center justify-center rounded-full font-bold text-white"
              :style="{ background: 'var(--primario)' }"
              aria-hidden="true"
              >{{ iniciales(nombre) }}</span
            >
            <span class="font-semibold">{{ nombre }}</span>
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
            >
              <p class="font-semibold">{{ su.nombre }}</p>
              <p
                v-if="su.region"
                class="text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ su.region }}
              </p>
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
