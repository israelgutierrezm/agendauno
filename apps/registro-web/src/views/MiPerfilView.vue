<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import CampoContrasena from "@/components/CampoContrasena.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelApariencia from "@/components/PanelApariencia.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  useSesionTenantStore,
  type UsuarioTenant,
} from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * "Mi perfil": lo que cada persona ajusta de sí misma: su foto, su nombre (con
 * apellidos por separado), su contraseña y su apariencia.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const usuario = computed(() => sesion.usuario);

type Respuesta = { data: { usuario: UsuarioTenant } };

// ---- Foto ----
const selectorFoto = ref<HTMLInputElement | null>(null);
const subiendoFoto = ref(false);
async function alElegirFoto(e: Event): Promise<void> {
  const archivo = (e.target as HTMLInputElement).files?.[0];
  if (!archivo) {
    return;
  }
  subiendoFoto.value = true;
  try {
    const datos = new FormData();
    datos.append("foto", archivo);
    const { data } = await api.post<Respuesta>(`${base.value}/yo/foto`, datos);
    sesion.actualizarUsuario(data.data.usuario);
  } catch (err) {
    toast.error(mensajeDeError(err, t("miPerfil.error")));
  } finally {
    subiendoFoto.value = false;
    if (selectorFoto.value) {
      selectorFoto.value.value = "";
    }
  }
}
async function quitarFoto(): Promise<void> {
  subiendoFoto.value = true;
  try {
    const { data } = await api.delete<Respuesta>(`${base.value}/yo/foto`);
    sesion.actualizarUsuario(data.data.usuario);
  } catch (err) {
    toast.error(mensajeDeError(err, t("miPerfil.error")));
  } finally {
    subiendoFoto.value = false;
  }
}

// ---- Datos ----
const datos = ref({ nombre: "", primer_apellido: "", segundo_apellido: "" });
watch(
  usuario,
  (u) => {
    if (u === null) {
      return;
    }
    // Sin las partes guardadas aún, se propone el nombre completo como nombre(s).
    datos.value = {
      nombre: u.nombre_pila ?? u.nombre,
      primer_apellido: u.primer_apellido ?? "",
      segundo_apellido: u.segundo_apellido ?? "",
    };
  },
  { immediate: true },
);
const guardandoDatos = ref(false);
async function guardarDatos(): Promise<void> {
  guardandoDatos.value = true;
  try {
    const { data } = await api.put<Respuesta>(`${base.value}/yo/perfil`, {
      nombre: datos.value.nombre,
      primer_apellido: datos.value.primer_apellido || null,
      segundo_apellido: datos.value.segundo_apellido || null,
    });
    sesion.actualizarUsuario(data.data.usuario);
    toast.exito(t("miPerfil.guardado"));
  } catch (err) {
    toast.error(mensajeDeError(err, t("miPerfil.error")));
  } finally {
    guardandoDatos.value = false;
  }
}

// ---- Contraseña ----
const clave = ref({ actual: "", nueva: "", confirmacion: "" });
const cambiandoClave = ref(false);
async function cambiarClave(): Promise<void> {
  cambiandoClave.value = true;
  try {
    const { data } = await api.put<Respuesta>(`${base.value}/yo/contrasena`, {
      actual: clave.value.actual || null,
      password: clave.value.nueva,
      password_confirmation: clave.value.confirmacion,
    });
    sesion.actualizarUsuario(data.data.usuario);
    clave.value = { actual: "", nueva: "", confirmacion: "" };
    toast.exito(t("miPerfil.cambiada"));
  } catch (err) {
    toast.error(mensajeDeError(err, t("miPerfil.error")));
  } finally {
    cambiandoClave.value = false;
  }
}

const aparienciaAbierta = ref(false);
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('miPerfil.titulo')" />

    <div v-if="usuario" class="mt-6 tu-card overflow-hidden">
      <!-- Foto -->
      <div class="mp-fila">
        <div>
          <h2 class="mp-titulo">{{ $t("miPerfil.foto") }}</h2>
          <p class="mp-ayuda">{{ $t("miPerfil.fotoAyuda") }}</p>
        </div>
        <div class="flex items-center gap-4">
          <AvatarIniciales
            :nombre="usuario.nombre"
            :foto="usuario.foto_url"
            tam="xl"
          />
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="subiendoFoto"
              @click="selectorFoto?.click()"
            >
              {{
                usuario.foto_url
                  ? $t("miPerfil.cambiarFoto")
                  : $t("miPerfil.subirFoto")
              }}
            </button>
            <button
              v-if="usuario.foto_url"
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="subiendoFoto"
              @click="quitarFoto"
            >
              {{ $t("miPerfil.quitarFoto") }}
            </button>
          </div>
          <input
            ref="selectorFoto"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="sr-only"
            tabindex="-1"
            @change="alElegirFoto"
          />
        </div>
      </div>

      <!-- Datos -->
      <form class="mp-fila" @submit.prevent="guardarDatos">
        <div>
          <h2 class="mp-titulo">{{ $t("miPerfil.datos") }}</h2>
          <p class="mp-ayuda">{{ $t("miPerfil.datosAyuda") }}</p>
        </div>
        <div class="space-y-4">
          <div>
            <label class="tu-label" for="mp-nombre">{{
              $t("miPerfil.nombre")
            }}</label>
            <input
              id="mp-nombre"
              v-model="datos.nombre"
              class="tu-input"
              required
              maxlength="80"
              autocomplete="given-name"
            />
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="tu-label" for="mp-ap1">{{
                $t("miPerfil.primerApellido")
              }}</label>
              <input
                id="mp-ap1"
                v-model="datos.primer_apellido"
                class="tu-input"
                maxlength="80"
                autocomplete="family-name"
              />
            </div>
            <div>
              <label class="tu-label" for="mp-ap2">{{
                $t("miPerfil.segundoApellido")
              }}</label>
              <input
                id="mp-ap2"
                v-model="datos.segundo_apellido"
                class="tu-input"
                maxlength="80"
              />
            </div>
          </div>
          <div>
            <p class="tu-label">{{ $t("miPerfil.correo") }}</p>
            <p class="text-sm">{{ usuario.email }}</p>
            <p class="tu-hint">{{ $t("miPerfil.correoAyuda") }}</p>
          </div>
          <button
            type="submit"
            class="tu-btn tu-btn-primario"
            :disabled="guardandoDatos || datos.nombre.trim() === ''"
          >
            {{ $t("miPerfil.guardar") }}
          </button>
        </div>
      </form>

      <!-- Contraseña -->
      <form class="mp-fila" @submit.prevent="cambiarClave">
        <div>
          <h2 class="mp-titulo">{{ $t("miPerfil.contrasena") }}</h2>
          <p class="mp-ayuda">{{ $t("miPerfil.contrasenaAyuda") }}</p>
        </div>
        <div class="space-y-4">
          <div v-if="usuario.tiene_contrasena !== false">
            <label class="tu-label" for="mp-actual">{{
              $t("miPerfil.actual")
            }}</label>
            <CampoContrasena
              id="mp-actual"
              v-model="clave.actual"
              autocomplete="current-password"
              required
            />
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="tu-label" for="mp-nueva">{{
                $t("miPerfil.nueva")
              }}</label>
              <CampoContrasena
                id="mp-nueva"
                v-model="clave.nueva"
                autocomplete="new-password"
                :minlength="8"
                required
              />
            </div>
            <div>
              <label class="tu-label" for="mp-conf">{{
                $t("miPerfil.confirmar")
              }}</label>
              <CampoContrasena
                id="mp-conf"
                v-model="clave.confirmacion"
                autocomplete="new-password"
                :minlength="8"
                required
              />
            </div>
          </div>
          <button
            type="submit"
            class="tu-btn tu-btn-primario"
            :disabled="
              cambiandoClave ||
              clave.nueva.length < 8 ||
              clave.nueva !== clave.confirmacion
            "
          >
            {{ $t("miPerfil.cambiar") }}
          </button>
        </div>
      </form>

      <!-- Apariencia -->
      <div class="mp-fila">
        <div>
          <h2 class="mp-titulo">{{ $t("miPerfil.apariencia") }}</h2>
          <p class="mp-ayuda">{{ $t("miPerfil.aparienciaAyuda") }}</p>
        </div>
        <div>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            @click="aparienciaAbierta = true"
          >
            {{ $t("miPerfil.abrirApariencia") }}
          </button>
        </div>
      </div>
    </div>

    <PanelApariencia
      :abierto="aparienciaAbierta"
      @cerrar="aparienciaAbierta = false"
    />
  </section>
</template>

<style scoped>
/* Como la demo: secciones separadas por una línea fina; a la izquierda qué es y a
   la derecha el formulario. */
.mp-fila {
  display: grid;
  gap: 1rem;
  padding: 1.5rem;
  border-top: 1px solid var(--borde);
}
.mp-fila:first-child {
  border-top: 0;
}
@media (min-width: 768px) {
  .mp-fila {
    grid-template-columns: 15rem minmax(0, 1fr);
    gap: 2rem;
  }
}
.mp-titulo {
  font-weight: 600;
}
.mp-ayuda {
  margin-top: 0.25rem;
  font-size: 0.85rem;
  color: var(--texto-suave);
}
</style>
