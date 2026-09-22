<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface DatosFiscales {
  razon_social: string;
  rfc: string;
  regimen_fiscal: string;
  codigo_postal: string;
  sellos_cargados: boolean;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const form = ref({
  razon_social: "",
  rfc: "",
  regimen_fiscal: "",
  codigo_postal: "",
});
const sellosCargados = ref(false);
const guardado = ref(false);
const cargando = ref(true);
const guardando = ref(false);
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);

const cerInput = useTemplateRef<HTMLInputElement>("cerInput");
const keyInput = useTemplateRef<HTMLInputElement>("keyInput");
const selloPassword = ref("");
const subiendoSello = ref(false);
const mensajeSello = ref<string | null>(null);
const errorSello = ref<string | null>(null);

function aplicar(d: DatosFiscales): void {
  form.value = {
    razon_social: d.razon_social,
    rfc: d.rfc,
    regimen_fiscal: d.regimen_fiscal,
    codigo_postal: d.codigo_postal,
  };
  sellosCargados.value = d.sellos_cargados;
  guardado.value = true;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: DatosFiscales | null }>(
      `${base.value}/datos-fiscales`,
    );
    if (data.data !== null) {
      aplicar(data.data);
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function guardar(): Promise<void> {
  guardando.value = true;
  error.value = null;
  mensaje.value = null;
  try {
    const { data } = await api.put<{ data: DatosFiscales }>(
      `${base.value}/datos-fiscales`,
      form.value,
    );
    aplicar(data.data);
    mensaje.value = "ok";
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

async function subirSello(): Promise<void> {
  const cer = cerInput.value?.files?.[0];
  const key = keyInput.value?.files?.[0];
  if (!cer || !key || selloPassword.value === "") {
    return;
  }
  subiendoSello.value = true;
  errorSello.value = null;
  mensajeSello.value = null;
  try {
    const fd = new FormData();
    fd.append("certificado", cer);
    fd.append("llave", key);
    fd.append("password", selloPassword.value);
    const { data } = await api.post<{ data: DatosFiscales }>(
      `${base.value}/datos-fiscales/sello`,
      fd,
    );
    sellosCargados.value = data.data.sellos_cargados;
    selloPassword.value = "";
    if (cerInput.value) {
      cerInput.value.value = "";
    }
    if (keyInput.value) {
      keyInput.value.value = "";
    }
    mensajeSello.value = "ok";
  } catch (e) {
    errorSello.value = mensajeDeError(e);
  } finally {
    subiendoSello.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      icono="datosFiscales"
      :titulo="$t('datosFiscales.titulo')"
      :subtitulo="$t('datosFiscales.subtitulo')"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <!-- Datos fiscales -->
      <div class="mt-6 tu-card p-6">
        <form class="space-y-4" @submit.prevent="guardar">
          <div>
            <label class="tu-label" for="rs">{{
              $t("datosFiscales.razonSocial")
            }}</label>
            <input
              id="rs"
              v-model="form.razon_social"
              class="tu-input"
              required
            />
          </div>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="tu-label" for="rfc">{{
                $t("datosFiscales.rfc")
              }}</label>
              <input
                id="rfc"
                v-model="form.rfc"
                class="tu-input uppercase"
                required
              />
            </div>
            <div>
              <label class="tu-label" for="cp">{{
                $t("datosFiscales.codigoPostal")
              }}</label>
              <input
                id="cp"
                v-model="form.codigo_postal"
                class="tu-input"
                inputmode="numeric"
                required
              />
            </div>
          </div>
          <div>
            <label class="tu-label" for="reg">{{
              $t("datosFiscales.regimenFiscal")
            }}</label>
            <input
              id="reg"
              v-model="form.regimen_fiscal"
              class="tu-input"
              inputmode="numeric"
              required
            />
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("datosFiscales.ayudaRegimen") }}
            </p>
          </div>

          <p v-if="mensaje" class="text-sm" :style="{ color: 'var(--exito)' }">
            {{ $t("datosFiscales.guardado") }}
          </p>
          <p v-if="error" class="text-sm" style="color: var(--error)">
            {{ error }}
          </p>

          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            :disabled="guardando"
          >
            {{
              guardando
                ? $t("datosFiscales.guardando")
                : $t("datosFiscales.guardar")
            }}
          </button>
        </form>
      </div>

      <!-- Sello digital (CSD) -->
      <div class="mt-6 tu-card p-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="font-bold">{{ $t("datosFiscales.sellos.titulo") }}</h2>
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("datosFiscales.sellos.subtitulo") }}
            </p>
          </div>
          <span
            class="tu-badge"
            :class="sellosCargados ? 'tu-badge-exito' : ''"
          >
            {{
              sellosCargados
                ? $t("datosFiscales.sellos.cargados")
                : $t("datosFiscales.sellos.pendientes")
            }}
          </span>
        </div>

        <p
          v-if="!guardado"
          class="mt-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("datosFiscales.sellos.requiereDatos") }}
        </p>

        <form v-else class="mt-4 space-y-4" @submit.prevent="subirSello">
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="tu-label" for="cer">{{
                $t("datosFiscales.sellos.certificado")
              }}</label>
              <input
                id="cer"
                ref="cerInput"
                class="tu-input"
                type="file"
                accept=".cer"
                required
              />
            </div>
            <div>
              <label class="tu-label" for="key">{{
                $t("datosFiscales.sellos.llave")
              }}</label>
              <input
                id="key"
                ref="keyInput"
                class="tu-input"
                type="file"
                accept=".key"
                required
              />
            </div>
          </div>
          <div>
            <label class="tu-label" for="sp">{{
              $t("datosFiscales.sellos.password")
            }}</label>
            <input
              id="sp"
              v-model="selloPassword"
              class="tu-input"
              type="password"
              required
            />
          </div>

          <p
            v-if="mensajeSello"
            class="text-sm"
            :style="{ color: 'var(--exito)' }"
          >
            {{ $t("datosFiscales.sellos.cargado") }}
          </p>
          <p v-if="errorSello" class="text-sm" style="color: var(--error)">
            {{ errorSello }}
          </p>

          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            :disabled="subiendoSello"
          >
            {{
              subiendoSello
                ? $t("datosFiscales.sellos.cargando")
                : $t("datosFiscales.sellos.cargar")
            }}
          </button>
        </form>
      </div>
    </template>
  </section>
</template>
