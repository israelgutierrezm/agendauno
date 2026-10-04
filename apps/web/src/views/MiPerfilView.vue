<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import CampoContrasena from "@/components/CampoContrasena.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelApariencia from "@/components/PanelApariencia.vue";
import { api, mensajeDeError } from "@/lib/api";
import { clientIdGoogle, renderizarBotonGoogle } from "@/lib/google";
import {
  useSesionTenantStore,
  type UsuarioTenant,
} from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * "Mi perfil": lo que cada persona ajusta de sí misma: su foto, su nombre (con
 * apellidos por separado), su correo de acceso (se confirma por enlace), su
 * contraseña y su apariencia.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const usuario = computed(() => sesion.usuario);

// ---- Google: se conecta aquí para entrar con él (ADR 0093: no registra cuentas) ----
const hayGoogle = clientIdGoogle() !== undefined;
const botonGoogle = ref<HTMLElement | null>(null);
const conectandoGoogle = ref(false);
async function mostrarBotonGoogle(): Promise<void> {
  await nextTick();
  if (!hayGoogle || botonGoogle.value === null) {
    return;
  }
  botonGoogle.value.replaceChildren();
  await renderizarBotonGoogle(
    botonGoogle.value,
    (credential) => void conectarGoogle(credential),
  );
}
async function conectarGoogle(credential: string): Promise<void> {
  conectandoGoogle.value = true;
  try {
    await sesion.conectarGoogle(credential);
    toast.exito(t("miPerfil.google.conectado"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    conectandoGoogle.value = false;
  }
}
async function desconectarGoogle(): Promise<void> {
  conectandoGoogle.value = true;
  try {
    await sesion.desconectarGoogle();
    toast.exito(t("miPerfil.google.desconectado"));
    await mostrarBotonGoogle();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    conectandoGoogle.value = false;
  }
}
onMounted(() => {
  if (sesion.usuario?.google_conectado !== true) {
    void mostrarBotonGoogle();
  }
});

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

// ---- Correo de acceso ----
const editandoCorreo = ref(false);
const correo = ref({ email: "", password: "" });
const enviandoCorreo = ref(false);
async function pedirCambioCorreo(): Promise<void> {
  enviandoCorreo.value = true;
  try {
    const { data } = await api.post<Respuesta>(`${base.value}/yo/correo`, {
      email: correo.value.email.trim(),
      password: correo.value.password || null,
    });
    sesion.actualizarUsuario(data.data.usuario);
    toast.exito(
      t("miPerfil.correoEnviado", { email: correo.value.email.trim() }),
    );
    correo.value = { email: "", password: "" };
    editandoCorreo.value = false;
  } catch (err) {
    toast.error(mensajeDeError(err, t("miPerfil.error")));
  } finally {
    enviandoCorreo.value = false;
  }
}
async function cancelarCambioCorreo(): Promise<void> {
  enviandoCorreo.value = true;
  try {
    const { data } = await api.delete<Respuesta>(`${base.value}/yo/correo`);
    sesion.actualizarUsuario(data.data.usuario);
    toast.exito(t("miPerfil.cambioCancelado"));
  } catch (err) {
    toast.error(mensajeDeError(err, t("miPerfil.error")));
  } finally {
    enviandoCorreo.value = false;
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

// ---- Calendario (iCal) ----
const calendario = ref<{ url: string; webcal: string } | null>(null);
const cargandoCalendario = ref(false);
async function obtenerCalendario(nuevo = false): Promise<void> {
  cargandoCalendario.value = true;
  try {
    const { data } = nuevo
      ? await api.post<{ data: { url: string; webcal: string } }>(
          `${base.value}/yo/calendario/regenerar`,
        )
      : await api.get<{ data: { url: string; webcal: string } }>(
          `${base.value}/yo/calendario`,
        );
    calendario.value = data.data;
  } catch (err) {
    toast.error(mensajeDeError(err, t("miPerfil.error")));
  } finally {
    cargandoCalendario.value = false;
  }
}
async function copiarCalendario(): Promise<void> {
  if (calendario.value === null) {
    return;
  }
  try {
    await navigator.clipboard.writeText(calendario.value.url);
    toast.exito(t("miPerfil.calendarioCopiado"));
  } catch {
    // Sin permiso del portapapeles: el enlace sigue visible para copiarlo a mano.
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
          <button
            type="submit"
            class="tu-btn tu-btn-primario"
            :disabled="guardandoDatos || datos.nombre.trim() === ''"
          >
            {{ $t("miPerfil.guardar") }}
          </button>
        </div>
      </form>

      <!-- Correo de acceso -->
      <div class="mp-fila">
        <div>
          <h2 class="mp-titulo">{{ $t("miPerfil.correo") }}</h2>
          <p class="mp-ayuda">{{ $t("miPerfil.correoAyuda") }}</p>
        </div>
        <div class="space-y-4">
          <p class="text-sm">{{ usuario.email }}</p>
          <template v-if="usuario.email_pendiente">
            <p class="text-sm" role="status" style="color: var(--aviso)">
              {{
                $t("miPerfil.correoPendiente", {
                  email: usuario.email_pendiente,
                })
              }}
            </p>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="enviandoCorreo"
              @click="cancelarCambioCorreo"
            >
              {{ $t("miPerfil.cancelarCambio") }}
            </button>
          </template>
          <form
            v-else-if="editandoCorreo"
            class="space-y-4"
            @submit.prevent="pedirCambioCorreo"
          >
            <div>
              <label class="tu-label" for="mp-correo">{{
                $t("miPerfil.correoNuevo")
              }}</label>
              <input
                id="mp-correo"
                v-model="correo.email"
                type="email"
                class="tu-input"
                required
                maxlength="255"
                autocomplete="email"
              />
            </div>
            <div v-if="usuario.tiene_contrasena !== false">
              <label class="tu-label" for="mp-correo-clave">{{
                $t("miPerfil.tuContrasena")
              }}</label>
              <CampoContrasena
                id="mp-correo-clave"
                v-model="correo.password"
                autocomplete="current-password"
                required
              />
            </div>
            <div class="flex flex-wrap gap-2">
              <button
                type="submit"
                class="tu-btn tu-btn-primario"
                :disabled="enviandoCorreo || correo.email.trim() === ''"
              >
                {{ $t("miPerfil.enviarEnlace") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma"
                @click="editandoCorreo = false"
              >
                {{ $t("miPerfil.cancelar") }}
              </button>
            </div>
          </form>
          <button
            v-else
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            @click="editandoCorreo = true"
          >
            {{ $t("miPerfil.cambiarCorreo") }}
          </button>
        </div>
      </div>

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

      <!-- Entrar con Google: solo si el sitio lo tiene configurado (o ya lo conectó,
           para poder quitarlo). Sin configurar no se ofrece. -->
      <div
        v-if="hayGoogle || usuario.google_conectado"
        class="mp-fila"
        data-prueba="google"
      >
        <div>
          <h2 class="mp-titulo">{{ $t("miPerfil.google.titulo") }}</h2>
          <p class="mp-ayuda">{{ $t("miPerfil.google.ayuda") }}</p>
        </div>
        <div class="space-y-3">
          <template v-if="usuario.google_conectado">
            <p class="mp-estado">
              <span class="mp-punto" aria-hidden="true" />
              {{ $t("miPerfil.google.conectadoEstado") }}
            </p>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              data-prueba="desconectar-google"
              :disabled="conectandoGoogle"
              @click="desconectarGoogle"
            >
              {{ $t("miPerfil.google.desconectar") }}
            </button>
          </template>
          <div v-else ref="botonGoogle" data-prueba="boton-google" />
        </div>
      </div>

      <!-- Calendario -->
      <div class="mp-fila">
        <div>
          <h2 class="mp-titulo">{{ $t("miPerfil.calendario") }}</h2>
          <p class="mp-ayuda">{{ $t("miPerfil.calendarioAyuda") }}</p>
        </div>
        <div class="space-y-3">
          <button
            v-if="calendario === null"
            type="button"
            class="tu-btn tu-btn-fantasma text-sm"
            :disabled="cargandoCalendario"
            @click="obtenerCalendario()"
          >
            {{ $t("miPerfil.calendarioObtener") }}
          </button>
          <template v-else>
            <input
              class="tu-input text-xs"
              :value="calendario.url"
              readonly
              aria-label="URL"
              @focus="($event.target as HTMLInputElement).select()"
            />
            <div class="flex flex-wrap gap-2">
              <a
                class="tu-btn tu-btn-primario text-sm"
                :href="calendario.webcal"
              >
                {{ $t("miPerfil.calendarioAbrir") }}
              </a>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma text-sm"
                @click="copiarCalendario"
              >
                {{ $t("miPerfil.calendarioCopiar") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma text-sm"
                :disabled="cargandoCalendario"
                @click="obtenerCalendario(true)"
              >
                {{ $t("miPerfil.calendarioNuevo") }}
              </button>
            </div>
            <p class="tu-hint">{{ $t("miPerfil.calendarioGoogle") }}</p>
            <p class="tu-hint">{{ $t("miPerfil.calendarioNuevoAyuda") }}</p>
          </template>
        </div>
      </div>

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
.mp-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}
.mp-punto {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
  background: var(--exito);
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
