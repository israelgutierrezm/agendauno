<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import EncabezadoSeccion from '@/components/EncabezadoSeccion.vue'
import { api, mensajeDeError } from '@/lib/api'
import { useSesionTenantStore } from '@/stores/sesionTenant'

const { t } = useI18n()

interface Miembro {
  id: string
  nombre_completo: string
  email: string | null
  estado: 'por_vencer' | 'vencida'
  vence: string
  dias_restantes: number
  ultima_asistencia: string | null
  dias_sin_asistir: number | null
}
interface Resumen {
  por_vencer: number
  vencidas: number
  dias: number
}

const sesion = useSesionTenantStore()
const base = computed(() => `/api/v1/app/${sesion.slug}`)

const miembros = ref<Miembro[]>([])
const resumen = ref<Resumen | null>(null)
const dias = ref(14)
const cargando = ref(true)
const error = ref<string | null>(null)
const exportando = ref(false)

function fechaCorta(iso: string | null): string {
  if (iso === null) {
    return '—'
  }
  // `vence` viene como fecha (YYYY-MM-DD); forzamos hora local para no restar un día.
  return new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${iso}T00:00:00`))
}

// "en 5 días" / "hoy" / "hace 3 días" — el signo de dias_restantes lo dice todo.
function venceTexto(m: Miembro): string {
  if (m.dias_restantes < 0) {
    return t('retencion.vencioHace', { n: Math.abs(m.dias_restantes) })
  }
  return m.dias_restantes === 0 ? t('retencion.venceHoy') : t('retencion.venceEn', { n: m.dias_restantes })
}
function asistTexto(m: Miembro): string {
  if (m.ultima_asistencia === null || m.dias_sin_asistir === null) {
    return t('retencion.sinAsistencia')
  }
  return m.dias_sin_asistir === 0 ? t('retencion.hoy') : t('retencion.haceDias', { n: m.dias_sin_asistir })
}

async function cargar(): Promise<void> {
  cargando.value = true
  error.value = null
  try {
    const { data } = await api.get<{ data: { resumen: Resumen; miembros: Miembro[] } }>(`${base.value}/retencion/por-vencer`, {
      params: { dias: dias.value },
    })
    miembros.value = data.data.miembros
    resumen.value = data.data.resumen
  } catch (e) {
    error.value = mensajeDeError(e)
  } finally {
    cargando.value = false
  }
}

async function exportar(): Promise<void> {
  exportando.value = true
  error.value = null
  try {
    const { data } = await api.get<Blob>(`${base.value}/retencion/por-vencer`, {
      params: { dias: dias.value, formato: 'csv' },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(data)
    const enlace = document.createElement('a')
    enlace.href = url
    enlace.download = `retencion-${sesion.slug}.csv`
    document.body.appendChild(enlace)
    enlace.click()
    document.body.removeChild(enlace)
    URL.revokeObjectURL(url)
  } catch (e) {
    error.value = mensajeDeError(e)
  } finally {
    exportando.value = false
  }
}

watch(dias, cargar)
onMounted(cargar)
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion icono="reportes" :titulo="$t('retencion.titulo')" :subtitulo="$t('retencion.subtitulo')" />

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <div class="tu-card px-5 py-3">
        <div class="text-2xl font-extrabold" :style="{ color: 'var(--aviso)' }">{{ resumen?.por_vencer ?? 0 }}</div>
        <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{ $t('retencion.porVencer') }}</div>
      </div>
      <div class="tu-card px-5 py-3">
        <div class="text-2xl font-extrabold" :style="{ color: 'var(--error)' }">{{ resumen?.vencidas ?? 0 }}</div>
        <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{ $t('retencion.vencidas') }}</div>
      </div>
      <label class="ml-auto flex items-center gap-2 text-sm">
        <span :style="{ color: 'var(--texto-suave)' }">{{ $t('retencion.ventanaLabel') }}</span>
        <select v-model.number="dias" class="tu-input w-auto">
          <option :value="7">{{ $t('retencion.ventana', { n: 7 }) }}</option>
          <option :value="14">{{ $t('retencion.ventana', { n: 14 }) }}</option>
          <option :value="30">{{ $t('retencion.ventana', { n: 30 }) }}</option>
          <option :value="90">{{ $t('retencion.ventana', { n: 90 }) }}</option>
        </select>
      </label>
      <button class="tu-btn tu-btn-primario" type="button" :disabled="exportando || miembros.length === 0" @click="exportar">
        {{ exportando ? $t('retencion.exportando') : $t('retencion.exportar') }}
      </button>
    </div>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">{{ error }}</p>
    <p v-if="cargando" class="mt-6 text-sm" :style="{ color: 'var(--texto-suave)' }">{{ $t('comun.cargando') }}</p>

    <template v-else>
      <p v-if="miembros.length === 0" class="mt-6 tu-card p-6 text-sm" :style="{ color: 'var(--texto-suave)' }">{{ $t('retencion.vacio') }}</p>
      <div v-else class="mt-4 tu-card overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
              <th class="px-4 py-2 font-medium">{{ $t('retencion.colAlumno') }}</th>
              <th class="px-4 py-2 font-medium">{{ $t('retencion.colVence') }}</th>
              <th class="px-4 py-2 font-medium hidden sm:table-cell">{{ $t('retencion.colActividad') }}</th>
              <th class="px-4 py-2 font-medium text-right"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in miembros" :key="m.id" class="border-t" :style="{ borderColor: 'var(--borde)' }">
              <td class="px-4 py-2">
                <RouterLink :to="{ name: 'ficha-miembro', params: { id: m.id } }" class="font-semibold tu-enlace">{{ m.nombre_completo }}</RouterLink>
                <span v-if="m.email" class="block text-xs" :style="{ color: 'var(--texto-suave)' }">{{ m.email }}</span>
              </td>
              <td class="px-4 py-2">
                <span class="tu-badge" :style="m.estado === 'vencida' ? { background: 'var(--error-suave)', color: 'var(--error)' } : { background: 'var(--aviso-suave)', color: 'var(--aviso)' }">{{ $t(`retencion.estados.${m.estado}`) }}</span>
                <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }">{{ fechaCorta(m.vence) }} · {{ venceTexto(m) }}</span>
              </td>
              <td class="px-4 py-2 hidden sm:table-cell" :style="{ color: 'var(--texto-suave)' }">{{ asistTexto(m) }}</td>
              <td class="px-4 py-2 text-right whitespace-nowrap">
                <a v-if="m.email" :href="`mailto:${m.email}`" class="tu-enlace text-sm mr-3">{{ $t('retencion.contactar') }}</a>
                <RouterLink :to="{ name: 'ficha-miembro', params: { id: m.id } }" class="tu-enlace text-sm">{{ $t('retencion.verFicha') }}</RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>
