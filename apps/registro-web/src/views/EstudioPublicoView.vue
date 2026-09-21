<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import CampoContrasena from '@/components/CampoContrasena.vue'
import { api, mensajeDeError } from '@/lib/api'
import { useSesionTenantStore } from '@/stores/sesionTenant'

interface Sesion {
  clase: string | null
  inicia_en: string | null
  zona_horaria: string | null
  sucursal: string | null
  instructor: string | null
  capacidad: number | null
  lugares_libres: number | null
}
interface Producto {
  nombre: string
  tipo: string
  precio_minor: number
  moneda: string
  ilimitado: boolean
  creditos_incluidos: number | null
}
interface Sucursal {
  nombre: string
  zona_horaria: string | null
  region: string | null
}
interface Escaparate {
  estudio: {
    slug: string
    nombre: string
    logo_url: string | null
    perfil: string
    perfil_config: { terminologia?: Record<string, string> }
    ciudad: string | null
    pais: string | null
    whatsapp: string | null
  }
  sucursales: Sucursal[]
  instructores: string[]
  productos: Producto[]
  proximas_sesiones: Sesion[]
}

const route = useRoute()
const router = useRouter()
const sesion = useSesionTenantStore()

const slug = computed(() => String(route.params.slug))
const escaparate = ref<Escaparate | null>(null)
const cargando = ref(true)
const noDisponible = ref(false)

const registrando = ref(false)
const form = ref({ nombre: '', apellido: '', email: '', password: '', passwordConfirmation: '' })
const errorRegistro = ref<string | null>(null)

const ubicacion = computed(() => {
  const e = escaparate.value?.estudio
  return e ? [e.ciudad, e.pais].filter(Boolean).join(', ') : ''
})

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency: moneda }).format(minor / 100)
}
function fechaHora(iso: string | null, zona: string | null): string {
  if (iso === null) {
    return '—'
  }
  return new Intl.DateTimeFormat('es-MX', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: zona ?? undefined,
  }).format(new Date(iso))
}
function iniciales(nombre: string): string {
  return nombre.split(' ').slice(0, 2).map((p) => p.charAt(0)).join('').toUpperCase()
}

async function cargar(): Promise<void> {
  cargando.value = true
  noDisponible.value = false
  try {
    const { data } = await api.get<{ data: Escaparate }>(`/api/v1/app/${slug.value}/escaparate`)
    escaparate.value = data.data
  } catch {
    // 404 (estudio no publicado/privado/inexistente) u otro error: pantalla amable.
    noDisponible.value = true
  } finally {
    cargando.value = false
  }
}

async function registrar(): Promise<void> {
  errorRegistro.value = null
  try {
    await sesion.registrarAlumno(slug.value, {
      nombre: form.value.nombre,
      primer_apellido: form.value.apellido,
      email: form.value.email,
      password: form.value.password,
      passwordConfirmation: form.value.passwordConfirmation,
    })
    // Auto-login: al portal del alumno (su cuenta) para reservar/comprar.
    void router.push({ name: sesion.rutaInicio })
  } catch (e) {
    errorRegistro.value = mensajeDeError(e)
  }
}

onMounted(cargar)
</script>

<template>
  <div>
    <p v-if="cargando" class="mx-auto max-w-5xl px-4 py-16 text-center" :style="{ color: 'var(--texto-suave)' }">
      {{ $t('escaparate.cargando') }}
    </p>

    <section v-else-if="noDisponible" class="mx-auto max-w-md px-4 py-20 text-center">
      <p class="text-lg font-semibold">{{ $t('escaparate.noDisponible') }}</p>
      <RouterLink :to="{ name: 'directorio' }" class="tu-enlace mt-4 inline-block">{{ $t('escaparate.volverDirectorio') }}</RouterLink>
    </section>

    <template v-else-if="escaparate">
      <!-- Hero -->
      <section class="px-4 py-14 text-center" :style="{ background: 'var(--superficie)' }">
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
          >{{ iniciales(escaparate.estudio.nombre) }}</span>

          <h1 class="mt-5 text-4xl font-extrabold tracking-tight">{{ escaparate.estudio.nombre }}</h1>
          <p v-if="ubicacion" class="mt-2 text-lg" :style="{ color: 'var(--texto-suave)' }">{{ ubicacion }}</p>

          <div class="mt-7 flex flex-wrap justify-center gap-3">
            <button type="button" class="tu-btn tu-btn-primario px-6" @click="registrando = true">
              {{ $t('escaparate.reservar') }}
            </button>
            <RouterLink :to="{ name: 'entrar', query: { estudio: slug } }" class="tu-btn tu-btn-fantasma px-6">
              {{ $t('escaparate.yaSoyAlumno') }}
            </RouterLink>
          </div>
        </div>
      </section>

      <!-- Próximas clases -->
      <section class="mx-auto max-w-5xl px-4 py-12">
        <h2 class="text-2xl font-bold">{{ $t('escaparate.proximasClases') }}</h2>
        <p v-if="escaparate.proximas_sesiones.length === 0" class="mt-4" :style="{ color: 'var(--texto-suave)' }">
          {{ $t('escaparate.sinClases') }}
        </p>
        <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <li v-for="(s, i) in escaparate.proximas_sesiones" :key="i" class="tu-card p-5">
            <p class="font-bold">{{ s.clase ?? '—' }}</p>
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">{{ fechaHora(s.inicia_en, s.zona_horaria) }}</p>
            <p v-if="s.sucursal || s.instructor" class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ [s.sucursal, s.instructor].filter(Boolean).join(' · ') }}
            </p>
            <span
              class="tu-badge mt-3"
              :class="s.lugares_libres === 0 ? 'tu-badge-aviso' : 'tu-badge-exito'"
            >
              <template v-if="s.lugares_libres === null">{{ $t('escaparate.cupoAbierto') }}</template>
              <template v-else-if="s.lugares_libres === 0">{{ $t('escaparate.lleno') }}</template>
              <template v-else>{{ $t('escaparate.lugaresLibres', { n: s.lugares_libres }) }}</template>
            </span>
          </li>
        </ul>
      </section>

      <!-- Precios -->
      <section class="py-12" :style="{ background: 'var(--superficie)' }">
        <div class="mx-auto max-w-5xl px-4">
          <h2 class="text-2xl font-bold">{{ $t('escaparate.precios') }}</h2>
          <p v-if="escaparate.productos.length === 0" class="mt-4" :style="{ color: 'var(--texto-suave)' }">
            {{ $t('escaparate.sinPrecios') }}
          </p>
          <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="(p, i) in escaparate.productos" :key="i" class="tu-card flex flex-col p-5">
              <span class="tu-badge self-start">{{ $t(`escaparate.registro.tipos.${p.tipo}`) }}</span>
              <p class="mt-2 font-bold">{{ p.nombre }}</p>
              <p class="mt-1 text-2xl font-extrabold">{{ dinero(p.precio_minor, p.moneda) }}</p>
              <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
                <template v-if="p.ilimitado">{{ $t('escaparate.ilimitado') }}</template>
                <template v-else-if="p.creditos_incluidos">{{ $t('escaparate.creditos', { n: p.creditos_incluidos / 1000 }) }}</template>
              </p>
              <button type="button" class="tu-btn tu-btn-primario mt-4 w-full" @click="registrando = true">
                {{ $t('escaparate.crearCuenta') }}
              </button>
            </li>
          </ul>
        </div>
      </section>

      <!-- Instructores -->
      <section v-if="escaparate.instructores.length > 0" class="mx-auto max-w-5xl px-4 py-12">
        <h2 class="text-2xl font-bold">{{ $t('escaparate.instructores') }}</h2>
        <ul class="mt-6 flex flex-wrap gap-4">
          <li v-for="(nombre, i) in escaparate.instructores" :key="i" class="flex items-center gap-3">
            <span class="flex h-11 w-11 items-center justify-center rounded-full font-bold text-white" :style="{ background: 'var(--primario)' }" aria-hidden="true">{{ iniciales(nombre) }}</span>
            <span class="font-semibold">{{ nombre }}</span>
          </li>
        </ul>
      </section>

      <!-- Ubicación -->
      <section v-if="escaparate.sucursales.length > 0 || ubicacion" class="py-12" :style="{ background: 'var(--superficie)' }">
        <div class="mx-auto max-w-5xl px-4">
          <h2 class="text-2xl font-bold">{{ $t('escaparate.ubicacion') }}</h2>
          <p v-if="ubicacion" class="mt-2" :style="{ color: 'var(--texto-suave)' }">{{ ubicacion }}</p>
          <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="(su, i) in escaparate.sucursales" :key="i" class="tu-card p-4">
              <p class="font-semibold">{{ su.nombre }}</p>
              <p v-if="su.region" class="text-sm" :style="{ color: 'var(--texto-suave)' }">{{ su.region }}</p>
            </li>
          </ul>
        </div>
      </section>

      <!-- CTA final -->
      <section class="mx-auto max-w-3xl px-4 py-14 text-center">
        <h2 class="text-2xl font-bold">{{ escaparate.estudio.nombre }}</h2>
        <button type="button" class="tu-btn tu-btn-primario mt-5 px-8" @click="registrando = true">
          {{ $t('escaparate.reservar') }}
        </button>
      </section>

      <!-- Modal de registro -->
      <div v-if="registrando" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" @click="registrando = false" />
        <div class="relative w-full max-w-md tu-card p-6" :style="{ background: 'var(--superficie)' }">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-xl font-bold">{{ $t('escaparate.registro.titulo') }}</h3>
              <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">{{ $t('escaparate.registro.subtitulo', { estudio: escaparate.estudio.nombre }) }}</p>
            </div>
            <button type="button" class="tu-icono-btn shrink-0" :aria-label="$t('comun.cerrar')" @click="registrando = false"><span aria-hidden="true">✕</span></button>
          </div>

          <form class="mt-5 space-y-3" @submit.prevent="registrar">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="tu-label" for="rn">{{ $t('escaparate.registro.nombre') }}</label>
                <input id="rn" v-model="form.nombre" class="tu-input" required />
              </div>
              <div>
                <label class="tu-label" for="ra">{{ $t('escaparate.registro.apellido') }}</label>
                <input id="ra" v-model="form.apellido" class="tu-input" />
              </div>
            </div>
            <div>
              <label class="tu-label" for="re">{{ $t('escaparate.registro.email') }}</label>
              <input id="re" v-model="form.email" class="tu-input" type="email" required />
            </div>
            <div>
              <label class="tu-label" for="rp">{{ $t('escaparate.registro.password') }}</label>
              <CampoContrasena id="rp" v-model="form.password" autocomplete="new-password" :required="true" />
            </div>
            <div>
              <label class="tu-label" for="rpc">{{ $t('escaparate.registro.passwordConfirm') }}</label>
              <CampoContrasena id="rpc" v-model="form.passwordConfirmation" autocomplete="new-password" :required="true" />
            </div>

            <p v-if="errorRegistro" class="text-sm" style="color: var(--error)">{{ errorRegistro }}</p>

            <button class="tu-btn tu-btn-primario w-full" type="submit" :disabled="sesion.cargando">
              {{ sesion.cargando ? $t('escaparate.registro.creando') : $t('escaparate.registro.crear') }}
            </button>
          </form>
        </div>
      </div>
    </template>
  </div>
</template>
