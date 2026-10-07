<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import CamposDatosPersonales from "@/components/CamposDatosPersonales.vue";
import CampoContrasena from "@/components/CampoContrasena.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import FondoDecorado from "@/components/FondoDecorado.vue";
import IconoNav from "@/components/IconoNav.vue";
import LogoCalendario from "@/components/LogoCalendario.vue";
import MiPrivacidad from "@/components/MiPrivacidad.vue";
import PanelApariencia from "@/components/PanelApariencia.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { APPS_CALENDARIO } from "@/lib/calendario";
import {
  googleEnDominioRaiz,
  googleEnEsteSitio,
  renderizarBotonGoogle,
} from "@/lib/google";
import { origenDominioRaiz, urlEntrarEnDominioRaiz } from "@/lib/tenant";
import { esMiembro, nombreDeRol } from "@/lib/roles";
import {
  useSesionTenantStore,
  type UsuarioTenant,
} from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * "Mi perfil": lo que cada persona ajusta de sí misma, en tres partes:
 * - Datos personales: su foto, su nombre (con apellidos por separado) y, si es
 *   cliente o alumno, su celular (para avisos y WhatsApp).
 * - Acceso: su correo (se confirma por enlace), su contraseña y Google.
 * - Preferencias y privacidad: su calendario, su apariencia y, si es cliente o
 *   alumno, su privacidad (promociones, WhatsApp, sus datos).
 */
const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const usuario = computed(() => sesion.usuario);
const mostrarPrivacidad = computed(() => esMiembro(usuario.value));
const rolVisible = computed(() =>
  nombreDeRol(
    usuario.value?.rol ?? "",
    usuario.value?.roles_disponibles,
    (llave) => (te(llave) ? t(llave) : null),
  ),
);
const tituloPreferencias = computed(() =>
  t(
    mostrarPrivacidad.value
      ? "miPerfil.seccionPreferencias"
      : "miPerfil.preferencias",
  ),
);

// ---- Google: se conecta aquí para entrar con él (ADR 0093: no registra cuentas) ----
// El botón de Google solo funciona en el dominio raíz (Google no admite orígenes
// comodín): en el subdominio del negocio se explica y se enlaza allá.
const hayGoogle = googleEnEsteSitio();
const googleEnRaiz = googleEnDominioRaiz();
const hostRaiz = origenDominioRaiz().replace(/^[a-z]+:\/\//, "");
const urlConectarGoogle = computed(() =>
  urlEntrarEnDominioRaiz(sesion.slug ?? "", "/mi-perfil"),
);
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

// ---- Foto: se suelta sobre la tarjeta (o su zona) o se elige con un clic ----
const zonaFoto = ref<InstanceType<typeof ZonaArchivo> | null>(null);
// La zona de carga solo se ve al pulsar «Cambiar foto» (o al arrastrar una imagen).
const zonaFotoAbierta = ref(false);
const subiendoFoto = ref(false);
// Contador: al pasar sobre los hijos de la tarjeta llegan «dragleave» intermedios.
const sobreTarjeta = ref(0);
const arrastrandoFoto = computed(() => sobreTarjeta.value > 0);
// Lo mismo que valida el servidor: JPG, PNG o WebP de hasta 4 MB. La zona revisa
// tipo y peso y lo dice debajo de ella.
const TIPOS_FOTO = "image/jpeg,image/png,image/webp";
const MAX_FOTO = 4 * 1024 * 1024;

function elegirFoto(): void {
  zonaFoto.value?.abrir();
}
function alSalirDeTarjeta(): void {
  sobreTarjeta.value = Math.max(0, sobreTarjeta.value - 1);
}
function alSoltarEnTarjeta(e: DragEvent): void {
  sobreTarjeta.value = 0;
  zonaFoto.value?.recibir(e.dataTransfer?.files?.[0]);
}
async function subirFoto(archivo: File): Promise<void> {
  sobreTarjeta.value = 0;
  if (subiendoFoto.value) {
    return;
  }
  subiendoFoto.value = true;
  try {
    const datos = new FormData();
    datos.append("foto", archivo);
    const { data } = await api.post<Respuesta>(`${base.value}/yo/foto`, datos);
    sesion.actualizarUsuario(data.data.usuario);
    zonaFotoAbierta.value = false;
  } catch (err) {
    toast.error(mensajeDeError(err, t("miPerfil.error")));
  } finally {
    subiendoFoto.value = false;
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
const datos = ref({
  nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  celular: "",
  fecha_nacimiento: "",
  genero: "",
});
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
      celular: u.celular ?? "",
      fecha_nacimiento: u.fecha_nacimiento ?? "",
      genero: u.genero ?? "",
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
      // El celular es de su ficha de cliente o alumno: sin ficha no se manda.
      ...(usuario.value?.tiene_ficha
        ? {
            celular: datos.value.celular.trim() || null,
            fecha_nacimiento: datos.value.fecha_nacimiento || null,
            genero: datos.value.genero || null,
          }
        : {}),
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
  <section class="tu-pagina-cuenta">
    <EncabezadoSeccion
      :titulo="$t('miPerfil.titulo')"
      :subtitulo="$t('miPerfil.subtitulo')"
    />

    <template v-if="usuario">
      <!-- Identidad y foto, sin separar la imagen de la persona a la que pertenece. -->
      <!-- La foto se suelta en cualquier parte de la tarjeta (o en su zona). -->
      <div
        class="tu-card mp-identidad"
        :class="{ 'mp-identidad-activa': arrastrandoFoto }"
        data-prueba="identidad-perfil"
        @dragenter.prevent="sobreTarjeta += 1"
        @dragover.prevent
        @dragleave.prevent="alSalirDeTarjeta"
        @drop.prevent="alSoltarEnTarjeta"
      >
        <FondoDecorado />
        <div class="mp-persona">
          <!-- La foto: un clic (o Enter) la cambia. -->
          <div
            class="mp-foto-zona"
            :class="{
              'mp-foto-zona-activa': arrastrandoFoto,
              'mp-foto-zona-ocupada': subiendoFoto,
            }"
            role="button"
            tabindex="0"
            :aria-label="$t('miPerfil.fotoArrastra')"
            :aria-busy="subiendoFoto"
            data-prueba="zona-foto"
            @click="elegirFoto"
            @keydown.enter.prevent="elegirFoto"
            @keydown.space.prevent="elegirFoto"
          >
            <AvatarIniciales
              :nombre="usuario.nombre"
              :foto="usuario.foto_url"
              tam="xl"
            />
            <span class="mp-foto-insignia" aria-hidden="true">
              <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path
                  d="M8 6 9.5 3.5h5L16 6h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"
                />
                <circle cx="12" cy="12.5" r="3.5" />
              </svg>
            </span>
          </div>
          <div class="mp-persona-datos">
            <p class="mp-nombre">{{ usuario.nombre }}</p>
            <p class="mp-email">{{ usuario.email }}</p>
            <div class="mp-contexto">
              <span v-if="rolVisible" class="mp-rol">{{ rolVisible }}</span>
              <span v-if="sesion.estudio?.nombre" class="mp-negocio">
                <IconoNav nombre="ubicacion" :tam="15" />
                {{ sesion.estudio.nombre }}
              </span>
            </div>
          </div>
        </div>
        <!-- Subir: la misma zona de arrastrar y soltar que en toda la app. -->
        <div class="mp-foto-acciones">
          <ZonaArchivo
            ref="zonaFoto"
            v-model:abierta="zonaFotoAbierta"
            compacta
            :boton="
              usuario.foto_url
                ? $t('miPerfil.cambiarFoto')
                : $t('miPerfil.subirFoto')
            "
            icono="imagen"
            :accept="TIPOS_FOTO"
            :max-bytes="MAX_FOTO"
            :error-tipo="$t('miPerfil.fotoTipo')"
            :error-peso="$t('miPerfil.fotoPeso')"
            :texto="$t('miPerfil.fotoZonaTexto')"
            :elige="$t('miPerfil.fotoZonaElige')"
            :suelta="$t('miPerfil.fotoSuelta')"
            :ayuda="$t('miPerfil.fotoFormatos')"
            :ocupado="subiendoFoto"
            :ocupado-texto="$t('miPerfil.fotoSubiendo')"
            :resaltada="arrastrandoFoto"
            @archivo="subirFoto"
          />
          <button
            v-if="usuario.foto_url && !zonaFotoAbierta && !arrastrandoFoto"
            type="button"
            class="tu-btn tu-btn-fantasma text-sm mp-quitar-foto"
            :disabled="subiendoFoto"
            @click="quitarFoto"
          >
            {{ $t("miPerfil.quitarFoto") }}
          </button>
        </div>
      </div>

      <nav class="mp-atajos" :aria-label="$t('miPerfil.navegacion')">
        <a href="#mp-datos" class="mp-atajo">
          <IconoNav nombre="miembros" :tam="20" />
          <span>{{ $t("miPerfil.seccionDatos") }}</span>
          <IconoNav nombre="abajo" :tam="15" class="mp-atajo-flecha" />
        </a>
        <a href="#mp-acceso" class="mp-atajo">
          <IconoNav nombre="usuarios" :tam="20" />
          <span>{{ $t("miPerfil.seccionAcceso") }}</span>
          <IconoNav nombre="abajo" :tam="15" class="mp-atajo-flecha" />
        </a>
        <a href="#mp-preferencias" class="mp-atajo">
          <IconoNav nombre="ajustes" :tam="20" />
          <span>{{ tituloPreferencias }}</span>
          <IconoNav nombre="abajo" :tam="15" class="mp-atajo-flecha" />
        </a>
      </nav>

      <!-- DATOS PERSONALES -->
      <section
        id="mp-datos"
        class="tu-card mp-bloque"
        aria-labelledby="mp-datos-titulo"
      >
        <header class="mp-cabecera">
          <span class="mp-icono" aria-hidden="true"
            ><IconoNav nombre="miembros" :tam="22"
          /></span>
          <div>
            <h2 id="mp-datos-titulo" class="mp-seccion">
              {{ $t("miPerfil.seccionDatos") }}
            </h2>
            <p class="mp-ayuda">{{ $t("miPerfil.datosDescripcion") }}</p>
          </div>
        </header>
        <!-- Datos -->
        <form class="mp-fila" @submit.prevent="guardarDatos">
          <div>
            <h3 class="mp-titulo">{{ $t("miPerfil.datos") }}</h3>
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
            <!-- Celular: el de su ficha de cliente o alumno -->
            <div v-if="usuario.tiene_ficha">
              <label class="tu-label" for="mp-celular">{{
                $t("miPerfil.celular")
              }}</label>
              <input
                id="mp-celular"
                v-model="datos.celular"
                class="tu-input"
                type="tel"
                inputmode="tel"
                maxlength="30"
                autocomplete="tel"
                data-prueba="celular"
              />
              <p class="tu-hint mt-1">{{ $t("miPerfil.celularAyuda") }}</p>
            </div>
            <!-- Fecha de nacimiento y género (opcionales), también de su ficha -->
            <CamposDatosPersonales
              v-if="usuario.tiene_ficha"
              id="mp"
              v-model:fecha="datos.fecha_nacimiento"
              v-model:genero="datos.genero"
              ayuda
            />
            <div class="mp-pie-formulario">
              <button
                type="submit"
                class="tu-btn tu-btn-primario"
                :disabled="guardandoDatos || datos.nombre.trim() === ''"
              >
                {{
                  guardandoDatos
                    ? $t("miPerfil.guardando")
                    : $t("miPerfil.guardar")
                }}
              </button>
            </div>
          </div>
        </form>
      </section>

      <!-- ACCESO -->
      <section
        id="mp-acceso"
        class="tu-card mp-bloque"
        aria-labelledby="mp-acceso-titulo"
      >
        <header class="mp-cabecera">
          <span class="mp-icono" aria-hidden="true"
            ><IconoNav nombre="usuarios" :tam="22"
          /></span>
          <div>
            <h2 id="mp-acceso-titulo" class="mp-seccion">
              {{ $t("miPerfil.seccionAcceso") }}
            </h2>
            <p class="mp-ayuda">{{ $t("miPerfil.accesoDescripcion") }}</p>
          </div>
        </header>
        <!-- Correo de acceso -->
        <div class="mp-fila">
          <div>
            <h3 class="mp-titulo">{{ $t("miPerfil.correo") }}</h3>
            <p class="mp-ayuda">{{ $t("miPerfil.correoAyuda") }}</p>
          </div>
          <div class="space-y-4">
            <p class="mp-correo-actual">
              <IconoNav nombre="mensaje" :tam="18" />{{ usuario.email }}
            </p>
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
            <h3 class="mp-titulo">{{ $t("miPerfil.contrasena") }}</h3>
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
            <div class="mp-pie-formulario">
              <button
                type="submit"
                class="tu-btn tu-btn-primario"
                :disabled="
                  cambiandoClave ||
                  clave.nueva.length < 8 ||
                  clave.nueva !== clave.confirmacion
                "
              >
                {{
                  cambiandoClave
                    ? $t("miPerfil.guardando")
                    : $t("miPerfil.cambiar")
                }}
              </button>
            </div>
          </div>
        </form>

        <!-- Entrar con Google: solo si el sitio lo tiene configurado (o ya lo conectó,
           para poder quitarlo). Sin configurar no se ofrece. En el subdominio del
           negocio se conecta desde el dominio raíz. -->
        <div
          v-if="hayGoogle || googleEnRaiz || usuario.google_conectado"
          class="mp-fila"
          data-prueba="google"
        >
          <div>
            <h3 class="mp-titulo">{{ $t("miPerfil.google.titulo") }}</h3>
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
            <p
              v-else-if="googleEnRaiz"
              class="mp-ayuda"
              data-prueba="google-en-raiz"
            >
              {{ $t("miPerfil.google.enRaiz", { host: hostRaiz }) }}
              <a class="tu-enlace" :href="urlConectarGoogle">{{
                $t("miPerfil.google.conectarEn", { host: hostRaiz })
              }}</a>
            </p>
            <div v-else ref="botonGoogle" data-prueba="boton-google" />
          </div>
        </div>
      </section>

      <!-- PREFERENCIAS Y PRIVACIDAD -->
      <section
        id="mp-preferencias"
        class="tu-card mp-bloque"
        aria-labelledby="mp-preferencias-titulo"
      >
        <header class="mp-cabecera">
          <span class="mp-icono" aria-hidden="true"
            ><IconoNav nombre="ajustes" :tam="22"
          /></span>
          <div>
            <h2 id="mp-preferencias-titulo" class="mp-seccion">
              {{ tituloPreferencias }}
            </h2>
            <p class="mp-ayuda">{{ $t("miPerfil.preferenciasDescripcion") }}</p>
          </div>
        </header>
        <!-- Calendario -->
        <div class="mp-fila">
          <div>
            <h3 class="mp-titulo">{{ $t("miPerfil.calendario") }}</h3>
            <p class="mp-ayuda">{{ $t("miPerfil.calendarioAyuda") }}</p>
          </div>
          <div class="mp-calendario space-y-3">
            <span class="mp-icono mp-icono-calendario" aria-hidden="true"
              ><IconoNav nombre="agenda" :tam="28"
            /></span>
            <!-- Con qué calendarios funciona: cada uno con su miniatura. -->
            <ul
              class="mp-apps-calendario"
              :aria-label="$t('miPerfil.calendarioDispositivos')"
              data-prueba="apps-calendario"
            >
              <li v-for="app in APPS_CALENDARIO" :key="app.marca">
                <LogoCalendario :marca="app.marca" :tam="22" />{{ app.nombre }}
              </li>
            </ul>
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
              <p class="tu-hint mp-hint-app">
                <LogoCalendario marca="google" :tam="16" />{{
                  $t("miPerfil.calendarioGoogle")
                }}
              </p>
              <p class="tu-hint">{{ $t("miPerfil.calendarioNuevoAyuda") }}</p>
            </template>
          </div>
        </div>

        <!-- Apariencia -->
        <div class="mp-fila">
          <div>
            <h3 class="mp-titulo">{{ $t("miPerfil.apariencia") }}</h3>
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

        <!-- Privacidad (clientes y alumnos). Se recarga si cambia su celular: de él
           depende recibir avisos por WhatsApp. -->
        <div v-if="mostrarPrivacidad" class="mp-fila mp-privacidad">
          <div>
            <h3 class="mp-titulo">{{ $t("miPrivacidad.titulo") }}</h3>
            <p class="mp-ayuda">{{ $t("miPrivacidad.ayuda") }}</p>
          </div>
          <MiPrivacidad
            :key="`${sesion.slug ?? ''}|${usuario.celular ?? ''}`"
            integrado
          />
        </div>
      </section>
    </template>

    <PanelApariencia
      :abierto="aparienciaAbierta"
      @cerrar="aparienciaAbierta = false"
    />
  </section>
</template>

<style scoped>
/* Identidad visible, navegación corta y formularios con la misma jerarquía. */
.mp-identidad {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1.5rem;
  margin-top: 1.5rem;
  padding: 1.5rem;
  overflow: hidden;
  border-left: 3px solid var(--acento);
  background: color-mix(in srgb, var(--acento) 3%, var(--superficie));
  transition: box-shadow 0.15s ease;
}
/* Arrastrando una imagen sobre la tarjeta: toda ella la recibe. */
.mp-identidad-activa {
  box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--acento) 45%, transparent);
}
/* El contenido va sobre el adorno de fondo. */
.mp-persona,
.mp-foto-acciones {
  position: relative;
  z-index: 1;
}
.mp-persona {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  min-width: 0;
  flex: 1 1 20rem;
}
.mp-persona-datos {
  min-width: 0;
}
.mp-nombre {
  font-size: clamp(1.15rem, 1rem + 0.5vw, 1.5rem);
  font-weight: 600;
  letter-spacing: -0.025em;
  overflow-wrap: anywhere;
}
.mp-email {
  margin-top: 0.3rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
  overflow-wrap: anywhere;
}
.mp-contexto {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.8rem;
  margin-top: 0.75rem;
  font-size: 0.75rem;
}
.mp-rol {
  border: 1px solid color-mix(in srgb, var(--acento) 22%, var(--borde));
  padding: 0.2rem 0.55rem;
  border-radius: 0.35rem;
  /* El acento hacia el color del texto: se lee en temas claros y oscuros (un acento
     verde o rosa solo, sobre su tinte, queda corto de contraste). */
  color: color-mix(in srgb, var(--acento), var(--texto) 35%);
  background: var(--primario-suave);
  font-weight: 500;
}
.mp-negocio {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  min-width: 0;
  color: var(--texto-suave);
  overflow-wrap: anywhere;
}
/* «Cambiar foto» y «Quitar foto» en una fila; desplegada, la zona ocupa el ancho. */
.mp-foto-acciones {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: flex-end;
  gap: 0.5rem;
  flex: 0 1 21rem;
  min-width: 0;
}
.mp-foto-acciones > :deep(:has(> .tu-zona-archivo)) {
  width: 100%;
}
/* Sobre el adorno, la zona va en la superficie para que se lea igual en cada tema. */
.mp-foto-acciones :deep(.tu-zona-archivo:not(.tu-zona-archivo-activa)) {
  background: var(--superficie);
}
.mp-atajos {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  margin: 1rem 0 1.5rem;
}
.mp-atajo {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.85rem 1rem;
  min-width: 0;
  min-height: 44px;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
  color: var(--texto);
  font-size: 0.85rem;
  font-weight: 500;
  text-decoration: none;
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease;
}
.mp-atajo > svg {
  color: var(--primario-fuerte);
  flex-shrink: 0;
}
.mp-atajo-flecha {
  margin-left: auto;
}
.mp-atajo:hover {
  border-color: var(--acento);
  background: var(--primario-suave);
}
.mp-atajo:focus-visible {
  outline: 2px solid var(--acento);
  outline-offset: 3px;
}
.mp-bloque {
  margin-top: 1.25rem;
  scroll-margin-top: 6rem;
}
.mp-cabecera {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 1.2rem 1.5rem;
  background: color-mix(in srgb, var(--fondo) 60%, var(--superficie));
  border-radius: var(--radio-tarjeta) var(--radio-tarjeta) 0 0;
}
.mp-icono {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 2.6rem;
  height: 2.6rem;
  border-radius: 0.65rem;
  color: var(--primario-fuerte);
  background: var(--primario-suave);
}
.mp-fila {
  display: grid;
  gap: 1rem;
  padding: 1.5rem;
  border-top: 1px solid var(--borde);
}
.mp-fila > * {
  min-width: 0;
}
.mp-fila:first-child {
  border-top: 0;
}
@media (min-width: 768px) {
  .mp-fila {
    grid-template-columns: minmax(10rem, 0.65fr) minmax(0, 2fr);
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
.mp-seccion {
  font-size: 1rem;
  font-weight: 600;
  letter-spacing: -0.015em;
  color: var(--texto);
}
.mp-titulo {
  font-weight: 500;
}
.mp-ayuda {
  margin-top: 0.25rem;
  font-size: 0.85rem;
  color: var(--texto-suave);
}
/* Zona de la foto: se arrastra una imagen encima o se hace clic para elegirla. */
.mp-foto-zona {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  padding: 0.45rem;
  /* El punteado solo al arrastrar: la zona de carga de al lado ya lo dice. */
  border: 1.5px dashed transparent;
  border-radius: 1rem;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease;
}
.mp-foto-zona:hover,
.mp-foto-zona:focus-visible {
  border-color: color-mix(in srgb, var(--acento) 45%, var(--borde));
}
.mp-foto-zona:focus-visible {
  outline: 2px solid var(--acento);
  outline-offset: 4px;
}
.mp-foto-zona-activa {
  border-color: var(--acento);
  background: color-mix(in srgb, var(--acento) 6%, var(--superficie));
}
.mp-foto-zona-ocupada {
  cursor: progress;
  opacity: 0.7;
}
.mp-foto-insignia {
  position: absolute;
  right: -0.35rem;
  bottom: -0.35rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.8rem;
  height: 1.8rem;
  border: 2px solid var(--superficie);
  border-radius: 0.5rem;
  background: var(--primario);
  color: var(--primario-contraste);
}
.mp-pie-formulario {
  display: flex;
  justify-content: flex-end;
  padding-top: 0.5rem;
}
.mp-correo-actual {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}
.mp-correo-actual svg {
  flex-shrink: 0;
  color: var(--texto-suave);
}
.mp-calendario {
  padding: 1.1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: color-mix(in srgb, var(--acento) 3%, var(--superficie));
}
/* Las apps de calendario: miniatura y nombre, en una fila que se acomoda. */
.mp-apps-calendario {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1.1rem;
}
.mp-apps-calendario li {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.875rem;
  font-weight: 500;
}
.mp-hint-app {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
}
.mp-hint-app svg {
  margin-top: 0.1rem;
}
.mp-icono-calendario {
  width: 3rem;
  height: 3rem;
}
@media (max-width: 639px) {
  .mp-identidad {
    padding: 1.1rem;
    gap: 1rem;
  }
  .mp-persona {
    gap: 0.9rem;
    flex-basis: 100%;
  }
  .mp-foto-acciones {
    flex-basis: 100%;
  }
  .mp-atajos {
    grid-template-columns: 1fr;
    gap: 0;
    border: 1px solid var(--borde);
    border-radius: var(--radio-tarjeta);
    overflow: hidden;
  }
  .mp-atajo {
    border: 0;
    border-radius: 0;
  }
  .mp-atajo + .mp-atajo {
    border-top: 1px solid var(--borde);
  }
  .mp-cabecera,
  .mp-fila {
    padding: 1.1rem;
  }
  .mp-cabecera {
    align-items: flex-start;
  }
  .mp-pie-formulario .tu-btn {
    width: 100%;
  }
}
@media (prefers-reduced-motion: reduce) {
  .mp-identidad,
  .mp-atajo,
  .mp-foto-zona {
    transition: none;
  }
}
</style>
