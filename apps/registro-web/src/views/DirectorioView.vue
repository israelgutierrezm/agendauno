<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import { api, mensajeDeError } from '@/lib/api'

interface EstudioDirectorio {
  slug: string
  nombre: string
  logo_url: string | null
  ciudad: string | null
  pais: string | null
}

const router = useRouter()
const estudios = ref<EstudioDirectorio[]>([])
const q = ref('')
const cargando = ref(true)
const error = ref<string | null>(null)

async function cargar(): Promise<void> {
  cargando.value = true
  error.value = null
  try {
    const { data } = await api.get<{ data: EstudioDirectorio[] }>('/api/v1/directorio', {
      params: q.value.trim() !== '' ? { q: q.value.trim() } : {},
    })
    estudios.value = data.data
  } catch (e) {
    error.value = mensajeDeError(e)
  } finally {
    cargando.value = false
  }
}

function verEstudio(slug: string): void {
  void router.push({ name: 'estudio-publico', params: { slug } })
}

function iniciales(nombre: string): string {
  return nombre
    .split(' ')
    .slice(0, 2)
    .map((p) => p.charAt(0))
    .join('')
    .toUpperCase()
}

onMounted(cargar)
</script>

<template>
  <section class="mx-auto max-w-5xl px-4 py-10">
    <h1 class="text-3xl font-extrabold">{{ $t('directorio.titulo') }}</h1>
    <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">{{ $t('directorio.subtitulo') }}</p>

    <form class="mt-6 flex gap-2 max-w-md" @submit.prevent="cargar">
      <input
        v-model="q"
        class="tu-input"
        type="search"
        :placeholder="$t('directorio.buscar')"
        :aria-label="$t('directorio.buscar')"
      />
      <button class="tu-btn tu-btn-primario" type="submit">🔎</button>
    </form>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">{{ $t('comun.cargando') }}</p>

    <div v-else-if="error" class="mt-8 tu-card p-5" :style="{ color: 'var(--error)' }">
      {{ error }}
      <button class="tu-enlace ml-2" @click="cargar">{{ $t('comun.reintentar') }}</button>
    </div>

    <p
      v-else-if="estudios.length === 0"
      class="mt-10 text-center"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ q.trim() !== '' ? $t('directorio.sinResultados', { q }) : $t('directorio.vacio') }}
    </p>

    <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <li v-for="e in estudios" :key="e.slug" class="tu-card p-5 flex flex-col">
        <div class="flex items-center gap-3">
          <img
            v-if="e.logo_url"
            :src="e.logo_url"
            :alt="e.nombre"
            class="h-11 w-11 rounded-lg object-cover"
          />
          <span
            v-else
            class="h-11 w-11 rounded-lg inline-flex items-center justify-center font-bold text-white"
            :style="{ background: 'var(--primario)' }"
            aria-hidden="true"
            >{{ iniciales(e.nombre) }}</span
          >
          <div class="min-w-0">
            <p class="font-bold truncate">{{ e.nombre }}</p>
            <p class="text-sm truncate" :style="{ color: 'var(--texto-suave)' }">
              {{ [e.ciudad, e.pais].filter(Boolean).join(', ') || '—' }}
            </p>
          </div>
        </div>
        <button class="tu-btn tu-btn-primario mt-4 w-full" type="button" @click="verEstudio(e.slug)">
          {{ $t('directorio.verEstudio') }}
        </button>
      </li>
    </ul>
  </section>
</template>
