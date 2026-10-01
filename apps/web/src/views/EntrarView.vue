<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import {
  RouterLink,
  useRoute,
  useRouter,
  type RouteLocationRaw,
} from "vue-router";

import CampoContrasena from "@/components/CampoContrasena.vue";
import LogoAgendaUno from "@/components/LogoAgendaUno.vue";
import { api, mensajeDeError } from "@/lib/api";
import { clientIdGoogle, renderizarBotonGoogle } from "@/lib/google";
import {
  leerNegociosRecientes,
  olvidarNegocio,
  recordarNegocio,
  type NegocioReciente,
} from "@/lib/negociosRecientes";
import { enSubdominioDeEstudio, slugDeContexto } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Marca {
  nombre: string;
  logo_url: string | null;
}

interface NegocioDirectorio {
  slug: string;
  nombre: string;
  perfil: string;
  logo_url: string | null;
  ciudad: string | null;
  pais: string | null;
}

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();

// Un enlace directo o subdominio fija el tenant. En el dominio raíz primero se
// elige un negocio: el usuario ya no tiene que recordar ni escribir su slug.
const slug = ref(slugDeContexto() ?? String(route.query.estudio ?? ""));
const estudioFijo = enSubdominioDeEstudio();
const seleccionando = computed(() => slug.value.trim() === "");
const email = ref("");
const password = ref("");
const avisoGoogle = ref(false);
const marca = ref<Marca | null>(null);
const marcaCargando = ref(false);
const logoFallido = ref(false);
watch([slug, () => marca.value?.logo_url], () => {
  logoFallido.value = false;
});

const recientes = ref<NegocioReciente[]>(leerNegociosRecientes());
const busqueda = ref("");
const resultados = ref<NegocioDirectorio[]>([]);
const buscando = ref(false);
const busquedaRealizada = ref(false);
const errorBusqueda = ref<string | null>(null);

const hayGoogle = clientIdGoogle() !== undefined;
const contenedorGoogle = ref<HTMLElement | null>(null);
const googleRenderizadoPara = ref("");

// Con más de un rol, primero «¿Cómo quieres entrar?».
// Tras entrar: de vuelta a donde venía (p. ej. agendar una cita) o a su inicio. Solo
// rutas internas: un enlace no puede mandar a otro sitio.
function destino(): RouteLocationRaw {
  const volver = String(route.query.volver ?? "");
  if (/^\/(?![/\\])/.test(volver)) {
    return volver;
  }
  return { name: sesion.destinoAlEntrar };
}

function ubicacion(negocio: {
  ciudad: string | null;
  pais: string | null;
}): string {
  return [negocio.ciudad, negocio.pais].filter(Boolean).join(", ");
}

function iniciales(nombre: string): string {
  return nombre
    .split(" ")
    .slice(0, 2)
    .map((parte) => parte.charAt(0))
    .join("")
    .toUpperCase();
}

async function prepararGoogle(): Promise<void> {
  if (!hayGoogle || slug.value.trim() === "") return;
  await nextTick();
  if (
    contenedorGoogle.value === null ||
    googleRenderizadoPara.value === slug.value
  ) {
    return;
  }
  contenedorGoogle.value.replaceChildren();
  await renderizarBotonGoogle(
    contenedorGoogle.value,
    (credential) => void entrarConGoogle(credential),
  );
  googleRenderizadoPara.value = slug.value;
}

async function cargarMarca(
  valor: string,
  volverAlSelectorSiFalla = false,
): Promise<void> {
  const slugBuscado = valor.trim();
  if (slugBuscado === "") {
    marca.value = null;
    return;
  }
  marcaCargando.value = true;
  try {
    const { data } = await api.get<{ data: Marca }>(
      `/api/v1/app/${slugBuscado}/marca`,
    );
    marca.value = data.data;
    recientes.value = recordarNegocio({
      slug: slugBuscado,
      nombre: data.data.nombre,
      logo_url: data.data.logo_url,
      ciudad: null,
      pais: null,
    });
    await prepararGoogle();
  } catch {
    marca.value = null;
    if (volverAlSelectorSiFalla && !estudioFijo) {
      slug.value = "";
      errorBusqueda.value = t("entrar.negocioNoDisponible");
      await router.replace({ name: "entrar" });
    }
  } finally {
    marcaCargando.value = false;
  }
}

async function buscarNegocios(inicial = false): Promise<void> {
  buscando.value = true;
  busquedaRealizada.value = !inicial;
  errorBusqueda.value = null;
  try {
    const params = busqueda.value.trim()
      ? { q: busqueda.value.trim() }
      : undefined;
    const { data } = await api.get<{ data: NegocioDirectorio[] }>(
      "/api/v1/directorio",
      { params },
    );
    resultados.value = data.data.slice(0, 8);
  } catch (error) {
    errorBusqueda.value = mensajeDeError(error);
  } finally {
    buscando.value = false;
  }
}

async function seleccionarNegocio(negocio: NegocioDirectorio): Promise<void> {
  recientes.value = recordarNegocio({
    slug: negocio.slug,
    nombre: negocio.nombre,
    logo_url: negocio.logo_url,
    ciudad: negocio.ciudad,
    pais: negocio.pais,
  });
  slug.value = negocio.slug;
  marca.value = { nombre: negocio.nombre, logo_url: negocio.logo_url };
  sesion.error = null;
  await router.replace({ name: "entrar", query: { estudio: negocio.slug } });
  await prepararGoogle();
}

async function seleccionarReciente(negocio: NegocioReciente): Promise<void> {
  slug.value = negocio.slug;
  marca.value = { nombre: negocio.nombre, logo_url: negocio.logo_url };
  sesion.error = null;
  await router.replace({ name: "entrar", query: { estudio: negocio.slug } });
  await cargarMarca(negocio.slug, true);
}

function olvidar(slugNegocio: string): void {
  recientes.value = olvidarNegocio(slugNegocio);
}

async function elegirOtro(): Promise<void> {
  if (estudioFijo) return;
  slug.value = "";
  marca.value = null;
  sesion.error = null;
  googleRenderizadoPara.value = "";
  await router.replace({ name: "entrar" });
  if (!busquedaRealizada.value && recientes.value.length === 0) {
    await buscarNegocios(true);
  }
}

async function enviar(): Promise<void> {
  try {
    await sesion.iniciarSesion(slug.value.trim(), email.value, password.value);
    if (marca.value !== null) {
      recientes.value = recordarNegocio({
        slug: slug.value,
        nombre: marca.value.nombre,
        logo_url: marca.value.logo_url,
        ciudad: null,
        pais: null,
      });
    }
    void router.push(destino());
  } catch {
    // El error queda en sesion.error.
  }
}

async function entrarConGoogle(credential: string): Promise<void> {
  try {
    await sesion.iniciarSesionConGoogle(slug.value.trim(), credential);
    void router.push(destino());
  } catch {
    // El error queda en sesion.error.
  }
}

onMounted(async () => {
  if (slug.value.trim() !== "") {
    await cargarMarca(slug.value, true);
    return;
  }
  // Como ReservaClase: sin historial se muestran opciones públicas; con
  // historial, el acceso rápido ocupa el primer plano y la búsqueda queda lista.
  if (recientes.value.length === 0) {
    await buscarNegocios(true);
  }
});
</script>

<template>
  <div class="tu-login-shell">
    <section class="tu-login-visual" aria-labelledby="login-beneficio">
      <div class="tu-login-mensaje">
        <p class="tu-login-etiqueta">{{ $t("entrar.etiqueta") }}</p>
        <h2 id="login-beneficio">{{ $t("entrar.panelTitulo") }}</h2>
        <p>{{ $t("entrar.panelDesc") }}</p>
      </div>

      <div class="tu-login-collage" aria-hidden="true">
        <img
          :src="'/assets/landing/disciplinas/pilates-v1.jpg'"
          alt=""
          width="1122"
          height="1402"
        />
        <img
          :src="'/assets/landing/disciplinas/pole-v1.jpg'"
          alt=""
          width="1122"
          height="1402"
        />
        <div class="tu-login-actividad tu-login-actividad--cita">
          <span class="tu-login-icono">✓</span>
          <span>
            <strong>{{ $t("entrar.panelCita") }}</strong>
            <small>{{ $t("entrar.panelCitaDetalle") }}</small>
          </span>
        </div>
        <div class="tu-login-actividad tu-login-actividad--clase">
          <span class="tu-login-icono tu-login-icono--clase">7</span>
          <span>
            <strong>{{ $t("entrar.panelClase") }}</strong>
            <small>{{ $t("entrar.panelClaseDetalle") }}</small>
          </span>
        </div>
      </div>

      <RouterLink class="tu-login-explorar" :to="{ name: 'directorio' }">
        {{ $t("entrar.buscarReserva") }} <span aria-hidden="true">→</span>
      </RouterLink>
    </section>

    <section class="tu-login-formulario">
      <div class="tu-login-formulario-inner">
        <div class="tu-login-identidad">
          <img
            v-if="!seleccionando && marca?.logo_url && !logoFallido"
            :key="`${slug}:${marca.logo_url}`"
            :src="marca.logo_url"
            :alt="marca.nombre"
            class="tu-login-logo-negocio"
            @error="logoFallido = true"
          />
          <LogoAgendaUno v-else variante="isotipo" :ancho="64" />
        </div>

        <template v-if="seleccionando">
          <h1 class="tu-login-titulo">{{ $t("entrar.selectorTitulo") }}</h1>
          <p class="tu-login-subtitulo">
            {{
              recientes.length
                ? $t("entrar.selectorSubtitulo")
                : "Escribe el nombre de tu estudio, academia o negocio para acceder."
            }}
          </p>

          <section v-if="recientes.length > 0" class="tu-selector-seccion">
            <div class="tu-selector-cabecera">
              <h2>{{ $t("entrar.recientesTitulo") }}</h2>
              <span>{{ $t("entrar.recientesPrivacidad") }}</span>
            </div>
            <ul class="tu-negocios-recientes">
              <li v-for="negocio in recientes" :key="negocio.slug">
                <button
                  type="button"
                  class="tu-negocio-principal"
                  @click="seleccionarReciente(negocio)"
                >
                  <img
                    v-if="negocio.logo_url"
                    :src="negocio.logo_url"
                    :alt="negocio.nombre"
                  />
                  <span v-else class="tu-negocio-iniciales" aria-hidden="true">
                    {{ iniciales(negocio.nombre) }}
                  </span>
                  <span class="tu-negocio-texto">
                    <strong>{{ negocio.nombre }}</strong>
                    <small>{{
                      ubicacion(negocio) || $t("entrar.accesoGuardado")
                    }}</small>
                  </span>
                  <span aria-hidden="true">→</span>
                </button>
                <button
                  type="button"
                  class="tu-negocio-olvidar"
                  :aria-label="
                    $t('entrar.olvidarAria', { nombre: negocio.nombre })
                  "
                  @click="olvidar(negocio.slug)"
                >
                  {{ $t("entrar.olvidar") }}
                </button>
              </li>
            </ul>
          </section>

          <section class="tu-selector-seccion">
            <h2>
              {{
                recientes.length
                  ? $t("entrar.buscarTitulo")
                  : "Busca tu negocio"
              }}
            </h2>
            <form
              class="tu-selector-busqueda"
              @submit.prevent="buscarNegocios()"
            >
              <label class="sr-only" for="buscar-negocio">{{
                $t("entrar.buscarPlaceholder")
              }}</label>
              <input
                id="buscar-negocio"
                v-model="busqueda"
                class="tu-input"
                type="search"
                :placeholder="$t('entrar.buscarPlaceholder')"
                autocomplete="organization"
              />
              <button
                class="tu-btn tu-btn-primario"
                type="submit"
                :disabled="buscando"
              >
                {{ buscando ? $t("entrar.buscando") : $t("entrar.buscar") }}
              </button>
            </form>

            <p v-if="errorBusqueda" class="tu-selector-error">
              {{ errorBusqueda }}
            </p>
            <p
              v-else-if="
                busquedaRealizada && !buscando && resultados.length === 0
              "
              class="tu-selector-vacio"
            >
              {{ $t("entrar.sinResultados") }}
            </p>
            <ul v-else-if="resultados.length > 0" class="tu-resultados-negocio">
              <li v-for="negocio in resultados" :key="negocio.slug">
                <button type="button" @click="seleccionarNegocio(negocio)">
                  <img
                    v-if="negocio.logo_url"
                    :src="negocio.logo_url"
                    :alt="negocio.nombre"
                  />
                  <span v-else class="tu-negocio-iniciales" aria-hidden="true">
                    {{ iniciales(negocio.nombre) }}
                  </span>
                  <span class="tu-negocio-texto">
                    <strong>{{ negocio.nombre }}</strong>
                    <small>{{
                      ubicacion(negocio) || $t("directorio.ubicacionPendiente")
                    }}</small>
                  </span>
                  <span aria-hidden="true">→</span>
                </button>
              </li>
            </ul>
          </section>
        </template>

        <template v-else>
          <button
            v-if="!estudioFijo"
            type="button"
            class="tu-login-cambiar"
            @click="elegirOtro"
          >
            <span aria-hidden="true">←</span> {{ $t("entrar.elegirOtro") }}
          </button>
          <h1 class="tu-login-titulo">
            {{
              marca?.nombre
                ? $t("entrar.tituloEstudio", { nombre: marca.nombre })
                : $t("entrar.titulo")
            }}
          </h1>
          <p class="tu-login-subtitulo">
            {{
              marcaCargando
                ? $t("comun.cargando")
                : $t("entrar.subtituloEstudio")
            }}
          </p>
          <!-- Con sesión en otro negocio: entrar aquí la cambia en este navegador. -->
          <p
            v-if="sesion.autenticado && sesion.slug !== slug.trim()"
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("entrar.otraSesion", { negocio: sesion.estudio?.nombre ?? "" })
            }}
            <RouterLink :to="{ name: sesion.rutaInicio }" class="tu-enlace">{{
              $t("entrar.volverAlPanel")
            }}</RouterLink>
          </p>

          <form class="tu-login-form mt-8 space-y-5" @submit.prevent="enviar">
            <div>
              <label class="tu-label" for="email">{{
                $t("entrar.email")
              }}</label>
              <input
                id="email"
                v-model="email"
                class="tu-input"
                type="email"
                autocomplete="email"
                required
              />
            </div>
            <div>
              <label class="tu-label" for="password">{{
                $t("entrar.password")
              }}</label>
              <CampoContrasena
                id="password"
                v-model="password"
                autocomplete="current-password"
                :required="true"
              />
              <RouterLink
                class="tu-enlace mt-2 inline-block text-sm"
                :to="{
                  name: 'recuperar-contrasena',
                  params: { slug: slug.trim() },
                  query: email ? { email } : {},
                }"
              >
                {{ $t("recuperarContrasena.enlace") }}
              </RouterLink>
            </div>

            <p v-if="sesion.error" class="text-sm" style="color: var(--error)">
              {{ sesion.error }}
            </p>

            <button
              class="tu-btn tu-btn-primario w-full justify-center py-3"
              type="submit"
              :disabled="sesion.cargando || marcaCargando"
            >
              {{
                sesion.cargando ? $t("entrar.entrando") : $t("entrar.entrar")
              }}
            </button>

            <div class="tu-login-separador">
              <span />
              <small>o</small>
              <span />
            </div>

            <div
              v-if="hayGoogle"
              ref="contenedorGoogle"
              class="flex justify-center"
            ></div>
            <template v-else>
              <button
                class="tu-btn tu-btn-fantasma w-full justify-center"
                type="button"
                @click="avisoGoogle = true"
              >
                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 48 48"
                  aria-hidden="true"
                >
                  <path
                    fill="#EA4335"
                    d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
                  />
                  <path
                    fill="#4285F4"
                    d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"
                  />
                  <path
                    fill="#FBBC05"
                    d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"
                  />
                  <path
                    fill="#34A853"
                    d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"
                  />
                </svg>
                {{ $t("entrar.google") }}
              </button>
              <p v-if="avisoGoogle" class="tu-login-aviso">
                {{ $t("entrar.googlePronto") }}
              </p>
            </template>
          </form>
        </template>

        <p class="tu-login-registro">
          {{ $t("entrar.sinCuenta") }}
          <RouterLink class="tu-enlace" :to="{ name: 'registro' }">{{
            $t("entrar.registrar")
          }}</RouterLink>
        </p>
      </div>
    </section>
  </div>
</template>

<style scoped>
.tu-login-shell {
  display: grid;
  min-height: calc(100svh - 3.5rem);
  background: var(--fondo);
}
.tu-login-visual {
  position: relative;
  display: none;
  min-height: calc(100svh - 3.5rem);
  overflow: hidden;
  padding: clamp(2rem, 4vw, 4.5rem);
  background: linear-gradient(145deg, #e9edf5 0%, #edf7ff 54%, #e4fbff 100%);
  color: #031b4e;
}
.tu-login-visual::after {
  position: absolute;
  right: -8rem;
  bottom: -8rem;
  width: 25rem;
  height: 25rem;
  border: 1px solid rgb(255 255 255 / 60%);
  border-radius: 50%;
  box-shadow: 0 0 0 4rem rgb(255 255 255 / 13%);
  content: "";
}
.tu-login-mensaje {
  position: relative;
  z-index: 2;
  max-width: 34rem;
  margin-top: 0;
}
.tu-login-etiqueta {
  color: var(--enlace);
  font-size: 0.76rem;
  font-weight: 700;
  letter-spacing: 0.09em;
  text-transform: uppercase;
}
.tu-login-mensaje h2 {
  margin-top: 0.9rem;
  font-size: clamp(2.7rem, 4.4vw, 4.6rem);
  font-weight: 720;
  letter-spacing: -0.045em;
  line-height: 0.98;
}
.tu-login-mensaje > p:last-child {
  max-width: 29rem;
  margin-top: 1.2rem;
  color: #40506b;
  font-size: 1.05rem;
  line-height: 1.65;
}
.tu-login-collage {
  position: relative;
  z-index: 1;
  width: min(100%, 38rem);
  height: clamp(17rem, 32vh, 25rem);
  margin-top: clamp(2rem, 5vh, 4rem);
}
.tu-login-collage > img {
  position: absolute;
  width: 42%;
  height: 88%;
  border: 0.35rem solid rgb(255 255 255 / 66%);
  border-radius: 1.5rem;
  object-fit: cover;
  box-shadow: 0 1.5rem 4rem rgb(45 55 70 / 18%);
}
.tu-login-collage > img:first-child {
  left: 3%;
  bottom: 0;
  transform: rotate(-4deg);
}
.tu-login-collage > img:nth-child(2) {
  top: 0;
  right: 9%;
  transform: rotate(4deg);
}
.tu-login-actividad {
  position: absolute;
  z-index: 3;
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.78rem 0.9rem;
  border: 1px solid rgb(255 255 255 / 65%);
  border-radius: 1.1rem;
  background: rgb(255 255 255 / 88%);
  box-shadow: 0 0.9rem 2.3rem rgb(37 47 61 / 17%);
  backdrop-filter: blur(16px);
}
.tu-login-actividad--cita {
  top: 14%;
  left: 31%;
}
.tu-login-actividad--clase {
  right: 0;
  bottom: 7%;
}
.tu-login-actividad strong,
.tu-login-actividad small {
  display: block;
}
.tu-login-actividad strong {
  font-size: 0.78rem;
}
.tu-login-actividad small {
  margin-top: 0.12rem;
  color: #657180;
  font-size: 0.66rem;
}
.tu-login-icono {
  display: grid;
  width: 2rem;
  height: 2rem;
  flex: 0 0 auto;
  place-content: center;
  border-radius: 50%;
  background: #198a4b;
  color: white;
  font-size: 0.75rem;
  font-weight: 800;
}
.tu-login-icono--clase {
  background: #1674cd;
}
.tu-login-explorar {
  position: absolute;
  z-index: 2;
  bottom: 2.2rem;
  left: clamp(2rem, 4vw, 4.5rem);
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  color: #344252;
  font-size: 0.85rem;
  font-weight: 700;
  text-decoration: none;
}
.tu-login-formulario {
  display: grid;
  min-height: calc(100svh - 3.5rem);
  place-items: center;
  padding: 3rem 1.25rem;
}
.tu-login-formulario-inner {
  width: min(100%, 32rem);
}
.tu-login-identidad {
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1rem;
  gap: 0.5rem;
}
.tu-login-logo-negocio {
  width: 3.75rem;
  height: 3.75rem;
  border-radius: 1rem;
  object-fit: contain;
  box-shadow: var(--sombra);
}
.tu-negocio-iniciales {
  display: inline-grid;
  flex: 0 0 auto;
  place-content: center;
  background: linear-gradient(145deg, #0070ff, #00a6d5);
  color: #fff;
  font-weight: 800;
}
.tu-login-titulo {
  margin-top: 1.65rem;
  font-size: clamp(2rem, 4vw, 2.65rem);
  font-weight: 750;
  letter-spacing: -0.035em;
  line-height: 1.06;
}
.tu-login-subtitulo {
  margin-top: 0.7rem;
  color: var(--texto-suave);
  line-height: 1.6;
}
.tu-selector-seccion {
  margin-top: 2rem;
}
.tu-selector-seccion > h2,
.tu-selector-cabecera h2 {
  font-size: 0.9rem;
  font-weight: 750;
}
.tu-selector-cabecera {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
}
.tu-selector-cabecera > span {
  color: var(--texto-suave);
  font-size: 0.72rem;
}
.tu-negocios-recientes,
.tu-resultados-negocio {
  display: grid;
  gap: 0.65rem;
  margin-top: 0.75rem;
  padding: 0;
  list-style: none;
}
.tu-negocios-recientes > li {
  position: relative;
  overflow: hidden;
  border: 1px solid var(--borde);
  border-radius: 1rem;
  background: var(--superficie);
  transition:
    border-color 0.2s ease,
    transform 0.2s ease,
    box-shadow 0.2s ease;
}
.tu-negocios-recientes > li:hover {
  border-color: color-mix(in srgb, var(--primario) 48%, var(--borde));
  box-shadow: var(--sombra);
  transform: translateY(-1px);
}
.tu-negocio-principal,
.tu-resultados-negocio button {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.8rem;
  padding: 0.8rem 4.5rem 0.8rem 0.85rem;
  border: 0;
  background: transparent;
  color: var(--texto);
  cursor: pointer;
  text-align: left;
}
.tu-negocio-principal > img,
.tu-resultados-negocio img,
.tu-negocio-iniciales {
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.8rem;
  object-fit: cover;
}
.tu-negocio-texto {
  min-width: 0;
  flex: 1;
}
.tu-negocio-texto strong,
.tu-negocio-texto small {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tu-negocio-texto strong {
  font-size: 0.9rem;
}
.tu-negocio-texto small {
  margin-top: 0.12rem;
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.tu-negocio-olvidar {
  position: absolute;
  top: 50%;
  right: 2rem;
  border: 0;
  background: transparent;
  color: var(--texto-suave);
  cursor: pointer;
  font-size: 0.7rem;
  transform: translateY(-50%);
}
.tu-negocio-olvidar:hover {
  color: var(--error);
  text-decoration: underline;
}
.tu-selector-busqueda {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.6rem;
  margin-top: 0.75rem;
}
.tu-resultados-negocio {
  max-height: 20rem;
  overflow-y: auto;
  padding-right: 0.15rem;
}
.tu-resultados-negocio li {
  border-bottom: 1px solid var(--borde);
}
.tu-resultados-negocio button {
  padding-right: 0.5rem;
  padding-left: 0.2rem;
}
.tu-resultados-negocio button:hover strong {
  color: var(--primario-fuerte);
}
.tu-selector-error,
.tu-selector-vacio {
  margin-top: 0.85rem;
  font-size: 0.82rem;
}
.tu-selector-error {
  color: var(--error);
}
.tu-selector-vacio {
  color: var(--texto-suave);
}
.tu-login-cambiar {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 1.25rem;
  border: 0;
  background: transparent;
  color: var(--primario-fuerte);
  cursor: pointer;
  font-size: 0.8rem;
  font-weight: 700;
}
.tu-login-form {
  width: 100%;
}
.tu-login-separador {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 0.75rem;
  color: var(--texto-suave);
}
.tu-login-separador span {
  border-top: 1px solid var(--borde);
}
.tu-login-aviso,
.tu-login-registro {
  color: var(--texto-suave);
  font-size: 0.8rem;
  text-align: center;
}
.tu-login-registro {
  margin-top: 1.5rem;
  font-size: 0.88rem;
}
@media (min-width: 1024px) {
  .tu-login-shell {
    grid-template-columns: minmax(0, 1.08fr) minmax(27rem, 0.92fr);
  }
  .tu-login-visual {
    display: block;
  }
}
@media (max-width: 520px) {
  .tu-selector-busqueda {
    grid-template-columns: 1fr;
  }
  .tu-selector-cabecera {
    align-items: flex-start;
    flex-direction: column;
    gap: 0.2rem;
  }
}
</style>
