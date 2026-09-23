<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const { t } = useI18n();

interface TipoDoc {
  id: string;
  nombre: string;
  descripcion: string | null;
  obligatorio: boolean;
  aplica_a: string;
  activo: boolean;
}
interface Doc {
  id: string;
  nombre: string;
  estado: string;
  motivo: string | null;
  persona: string | null;
  tipo: string | null;
  subido_en: string | null;
}
interface Miembro {
  id: string;
  nombre: string;
  nombre_completo: string;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("documentos.gestionar"));
const puedeSubir = computed(() => sesion.puede("documentos.subir"));

const tipos = ref<TipoDoc[]>([]);
const docs = ref<Doc[]>([]);
const miembros = ref<Miembro[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const filtroEstado = ref("");

const nuevoTipo = ref({
  nombre: "",
  descripcion: "",
  obligatorio: true,
  aplica_a: "miembro",
});
const creandoTipo = ref(false);

const subida = ref<{ persona: string; tipo: string; archivo: File | null }>({
  persona: "",
  tipo: "",
  archivo: null,
});
const subiendo = ref(false);
const accionando = ref(false);

function nombreMiembro(m: Miembro): string {
  return m.nombre_completo || m.nombre;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [t, d, m] = await Promise.all([
      api.get<{ data: TipoDoc[] }>(`${base.value}/tipos-documento`),
      api.get<{ data: Doc[] }>(`${base.value}/documentos`, {
        params: filtroEstado.value !== "" ? { estado: filtroEstado.value } : {},
      }),
      api.get<{ data: Miembro[] }>(`${base.value}/miembros`, {
        params: { tipo: "miembro" },
      }),
    ]);
    tipos.value = t.data.data;
    docs.value = d.data.data;
    miembros.value = m.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function crearTipo(): Promise<void> {
  creandoTipo.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/tipos-documento`, {
      nombre: nuevoTipo.value.nombre,
      descripcion: nuevoTipo.value.descripcion || null,
      obligatorio: nuevoTipo.value.obligatorio,
      aplica_a: nuevoTipo.value.aplica_a,
    });
    nuevoTipo.value = {
      nombre: "",
      descripcion: "",
      obligatorio: true,
      aplica_a: "miembro",
    };
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    creandoTipo.value = false;
  }
}

function archivoElegido(e: Event): void {
  const input = e.target as HTMLInputElement;
  subida.value.archivo = input.files?.[0] ?? null;
}

async function subir(): Promise<void> {
  if (subida.value.archivo === null || subida.value.persona === "") {
    return;
  }
  subiendo.value = true;
  error.value = null;
  try {
    const fd = new FormData();
    fd.append("persona_id", subida.value.persona);
    if (subida.value.tipo !== "") {
      fd.append("tipo_documento_id", subida.value.tipo);
    }
    fd.append("archivo", subida.value.archivo);
    await api.post(`${base.value}/documentos`, fd);
    subida.value = { persona: "", tipo: "", archivo: null };
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendo.value = false;
  }
}

async function validar(
  doc: Doc,
  estado: "aprobado" | "rechazado",
): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    let motivo: string | null = null;
    if (estado === "rechazado") {
      motivo = window.prompt(t("documentos.docs.motivo")) ?? "";
    }
    await api.post(`${base.value}/documentos/${doc.id}/validar`, {
      estado,
      motivo,
    });
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function ver(doc: Doc): Promise<void> {
  try {
    const r = await api.get(`${base.value}/documentos/${doc.id}`, {
      responseType: "blob",
    });
    const url = URL.createObjectURL(r.data as Blob);
    window.open(url, "_blank");
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 py-10">
    <EncabezadoSeccion :titulo="$t('documentos.titulo')" />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div v-if="!cargando" class="mt-6 grid gap-6 md:grid-cols-2">
      <!-- Tipos requeridos -->
      <div class="tu-card p-6">
        <h2 class="font-bold text-lg">{{ $t("documentos.tipos.titulo") }}</h2>
        <ul v-if="tipos.length > 0" class="mt-3 space-y-2 text-sm">
          <li
            v-for="t2 in tipos"
            :key="t2.id"
            class="flex items-center justify-between gap-2"
          >
            <span class="truncate">{{ t2.nombre }}</span>
            <span class="flex items-center gap-1 shrink-0">
              <span v-if="t2.obligatorio" class="tu-badge tu-badge-aviso"
                >obligatorio</span
              >
              <span class="tu-badge">{{
                $t(`documentos.tipos.${t2.aplica_a}`)
              }}</span>
            </span>
          </li>
        </ul>
        <p v-else class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("documentos.tipos.vacio") }}
        </p>

        <form
          v-if="puedeGestionar"
          class="mt-5 border-t pt-4 space-y-3"
          :style="{ borderColor: 'var(--borde)' }"
          @submit.prevent="crearTipo"
        >
          <p class="font-semibold text-sm">
            {{ $t("documentos.tipos.nuevo") }}
          </p>
          <input
            v-model="nuevoTipo.nombre"
            class="tu-input"
            :placeholder="$t('documentos.tipos.nombrePh')"
            required
          />
          <input
            v-model="nuevoTipo.descripcion"
            class="tu-input"
            :placeholder="$t('documentos.tipos.descripcion')"
          />
          <div class="flex items-center gap-3">
            <select v-model="nuevoTipo.aplica_a" class="tu-input">
              <option value="miembro">
                {{ $t("documentos.tipos.miembro") }}
              </option>
              <option value="instructor">
                {{ $t("documentos.tipos.instructor") }}
              </option>
              <option value="todos">{{ $t("documentos.tipos.todos") }}</option>
            </select>
            <label class="flex items-center gap-1.5 text-sm whitespace-nowrap">
              <input v-model="nuevoTipo.obligatorio" type="checkbox" />
              {{ $t("documentos.tipos.obligatorio") }}
            </label>
          </div>
          <button
            class="tu-btn tu-btn-fantasma w-full"
            type="submit"
            :disabled="creandoTipo || nuevoTipo.nombre.trim() === ''"
          >
            {{ $t("documentos.tipos.crear") }}
          </button>
        </form>
      </div>

      <!-- Subir documento -->
      <div v-if="puedeSubir" class="tu-card p-6 h-max">
        <h2 class="font-bold text-lg">{{ $t("documentos.docs.subir") }}</h2>
        <form class="mt-3 space-y-3" @submit.prevent="subir">
          <div>
            <label class="tu-label" for="dp">{{
              $t("documentos.docs.persona")
            }}</label>
            <select id="dp" v-model="subida.persona" class="tu-input" required>
              <option value="" disabled>
                {{ $t("documentos.docs.elegir") }}
              </option>
              <option v-for="m in miembros" :key="m.id" :value="m.id">
                {{ nombreMiembro(m) }}
              </option>
            </select>
          </div>
          <div>
            <label class="tu-label" for="dt">{{
              $t("documentos.docs.tipo")
            }}</label>
            <select id="dt" v-model="subida.tipo" class="tu-input">
              <option value="">{{ $t("documentos.docs.sinTipo") }}</option>
              <option v-for="t2 in tipos" :key="t2.id" :value="t2.id">
                {{ t2.nombre }}
              </option>
            </select>
          </div>
          <div>
            <label class="tu-label" for="df">{{
              $t("documentos.docs.archivo")
            }}</label>
            <input
              id="df"
              class="tu-input"
              type="file"
              accept=".jpg,.jpeg,.png,.pdf"
              @change="archivoElegido"
            />
          </div>
          <button
            class="tu-btn tu-btn-primario w-full"
            type="submit"
            :disabled="
              subiendo || subida.archivo === null || subida.persona === ''
            "
          >
            {{
              subiendo
                ? $t("documentos.docs.subiendo")
                : $t("documentos.docs.subirBtn")
            }}
          </button>
        </form>
      </div>
    </div>

    <!-- Lista de documentos -->
    <div v-if="!cargando" class="mt-6 tu-card p-6">
      <div class="flex items-center justify-between gap-2 flex-wrap">
        <h2 class="font-bold text-lg">{{ $t("documentos.docs.titulo") }}</h2>
        <select
          v-model="filtroEstado"
          class="tu-input max-w-[180px]"
          @change="cargar"
        >
          <option value="">{{ $t("documentos.docs.todos") }}</option>
          <option value="pendiente">
            {{ $t("documentos.docs.pendiente") }}
          </option>
          <option value="aprobado">{{ $t("documentos.docs.aprobado") }}</option>
          <option value="rechazado">
            {{ $t("documentos.docs.rechazado") }}
          </option>
        </select>
      </div>

      <p
        v-if="docs.length === 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("documentos.docs.vacio") }}
      </p>
      <ul v-else class="mt-3 space-y-2">
        <li
          v-for="d in docs"
          :key="d.id"
          class="flex items-center justify-between gap-2 text-sm flex-wrap"
        >
          <span class="min-w-0">
            <span class="font-medium">{{ d.persona ?? "—" }}</span>
            <span :style="{ color: 'var(--texto-suave)' }">
              · {{ d.tipo ?? d.nombre }}</span
            >
            <span
              class="tu-badge ml-1"
              :class="{
                'tu-badge-exito': d.estado === 'aprobado',
                'tu-badge-aviso': d.estado === 'pendiente',
              }"
              >{{ $t(`documentos.docs.${d.estado}`) }}</span
            >
          </span>
          <span class="flex items-center gap-2 shrink-0">
            <button class="tu-enlace" @click="ver(d)">
              {{ $t("documentos.docs.ver") }}
            </button>
            <template v-if="puedeGestionar && d.estado === 'pendiente'">
              <button
                class="tu-enlace"
                :disabled="accionando"
                @click="validar(d, 'aprobado')"
              >
                {{ $t("documentos.docs.aprobar") }}
              </button>
              <button
                class="tu-enlace"
                style="color: var(--error)"
                :disabled="accionando"
                @click="validar(d, 'rechazado')"
              >
                {{ $t("documentos.docs.rechazar") }}
              </button>
            </template>
          </span>
        </li>
      </ul>
    </div>
  </section>
</template>
