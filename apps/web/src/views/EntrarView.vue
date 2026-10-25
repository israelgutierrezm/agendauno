<script setup lang="ts">
import AvisoTono from "@/components/AvisoTono.vue";
import ComposicionAcceso from "@/components/ComposicionAcceso.vue";
import PanelRoles from "@/components/PanelRoles.vue";
import axios from "axios";
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import {
  RouterLink,
  useRoute,
  useRouter,
  type RouteLocationRaw,
} from "vue-router";

import CampoContrasena from "@/components/CampoContrasena.vue";
import IconoNav from "@/components/IconoNav.vue";
import LogoProducto from "@/components/LogoProducto.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  googleEnDominioRaiz,
  googleEnEsteSitio,
  renderizarBotonGoogle,
} from "@/lib/google";
import {
  leerNegociosRecientes,
  olvidarNegocio,
  recordarNegocio,
  type NegocioReciente,
} from "@/lib/negociosRecientes";
import {
  enSubdominioDeEstudio,
  origenDominioRaiz,
  slugDeContexto,
  urlEntrarEnDominioRaiz,
} from "@/lib/tenant";
import {
  PRODUCTOS,
  productoActual,
  productoDelHost,
  type Producto,
} from "@/lib/producto";
import { volverSeguro } from "@/lib/destinoSeguro";
import { useRegistroDelProducto } from "@/marketing/registroProducto";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Marca {
  nombre: string;
  logo_url: string | null;
  // De qué producto es (ADR 0108).
  producto?: Producto;
}

interface NegocioDirectorio {
  slug: string;
  nombre: string;
  perfil: string;
  logo_url: string | null;
  ciudad: string | null;
  pais: string | null;
  producto?: Producto;
}

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();
// Si el producto aún no recibe registros (ADR 0108), quien administra un negocio deja
// sus datos en la lista de interesados y no se ofrece buscar negocios.
const { abierto: registroAbierto } = useRegistroDelProducto();
// El collage habla del producto del dominio (ADR 0131): clases en AgendaUno, citas en
// TurnoUno.
const esTurnoUno = productoActual() === "turnouno";
// Un enlace directo o subdominio fija el tenant. En el dominio raíz primero se
// elige un negocio: el usuario ya no tiene que recordar ni escribir su slug.
const slug = ref(slugDeContexto() ?? String(route.query.estudio ?? ""));
const estudioFijo = enSubdominioDeEstudio();
const seleccionando = computed(() => slug.value.trim() === "");
const email = ref("");
const password = ref("");
const marca = ref<Marca | null>(null);
const marcaCargando = ref(false);
const logoFallido = ref(false);
watch([slug, () => marca.value?.logo_url], () => {
  logoFallido.value = false;
});

const recientes = ref<NegocioReciente[]>(leerNegociosRecientes());
// Cada producto es un sitio aparte (ADR 0136): un negocio del otro no se abre aquí;
// se dice dónde entrar.
const otroProducto = ref<{
  nombre: string;
  producto: Producto;
  slug: string;
} | null>(null);
const enlaceOtroProducto = computed(() => {
  const otro = otroProducto.value;
  if (otro === null) {
    return "";
  }
  const ruta = `/entrar?estudio=${encodeURIComponent(otro.slug)}`;
  // En desarrollo (sin los dominios de los productos), la misma página con su producto.
  return productoDelHost(window.location.hostname) === null
    ? `${ruta}&producto=${otro.producto}`
    : `https://${PRODUCTOS[otro.producto].dominio}${ruta}`;
});
const busqueda = ref("");
const resultados = ref<NegocioDirectorio[]>([]);
const buscando = ref(false);
const busquedaRealizada = ref(false);
const errorBusqueda = ref<string | null>(null);

// El botón de Google solo funciona en el dominio raíz (Google no admite orígenes
// comodín): en el subdominio de un negocio, un enlace lleva a «Entrar» del dominio
// raíz para ese negocio.
const hayGoogle = googleEnEsteSitio();
const googleEnRaiz = googleEnDominioRaiz();
const hostRaiz = origenDominioRaiz().replace(/^[a-z]+:\/\//, "");
const urlGoogleRaiz = computed(() =>
  urlEntrarEnDominioRaiz(slug.value.trim(), volverA()),
);
const contenedorGoogle = ref<HTMLElement | null>(null);
const googleRenderizadoPara = ref("");

// Tras entrar: de vuelta a donde venía (p. ej. agendar una cita o el plan que eligió
// en su página) o a su inicio. Solo rutas internas de este negocio: un enlace no puede
// mandar a otro sitio ni a otro negocio (ADR 0142).
function volverA(): string | null {
  return volverSeguro(route.query.volver, slug.value.trim() || null, (r) =>
    router.resolve(r),
  );
}
function destino(): RouteLocationRaw {
  return volverA() ?? { name: sesion.destinoAlEntrar };
}

// Sesión guardada que no se pudo confirmar al abrir (sin red, en mantenimiento): se
// reintenta aquí sin escribir la contraseña y se vuelve a lo que se había abierto.
const reintentando = ref(false);
async function reintentarSesion(): Promise<void> {
  reintentando.value = true;
  try {
    if (await sesion.reintentarSesion()) {
      const volver = volverA() ?? sesion.volverTrasConfirmar;
      sesion.volverTrasConfirmar = null;
      await router.replace(volver ?? { name: sesion.rutaInicio });
    }
  } finally {
    reintentando.value = false;
  }
}

// Con más de un rol en el negocio, el panel lateral pregunta con cuál entra (como en
// Acadion); con uno, entra directo.
const eligiendoRol = ref(false);
function continuar(): void {
  if (sesion.requiereElegirRol) {
    eligiendoRol.value = true;
    return;
  }
  void router.push(destino());
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
    const suProducto = data.data.producto ?? productoActual();
    if (suProducto !== productoActual()) {
      // De otro producto: aquí no se abre ni se recuerda (ADR 0136).
      marca.value = null;
      otroProducto.value = {
        nombre: data.data.nombre,
        producto: suProducto,
        slug: slugBuscado,
      };
      if (!estudioFijo) {
        slug.value = "";
        await router.replace({ name: "entrar" });
      }
      return;
    }
    otroProducto.value = null;
    marca.value = data.data;
    recientes.value = recordarNegocio({
      slug: slugBuscado,
      nombre: data.data.nombre,
      logo_url: data.data.logo_url,
      ciudad: null,
      pais: null,
      producto: suProducto,
    });
    await prepararGoogle();
  } catch (e) {
    marca.value = null;
    // Solo si el servidor dice que no existe (404) ya no está disponible. Sin
    // conexión o con el servidor caído se queda elegido y se avisa eso.
    const noExiste = axios.isAxiosError(e) && e.response?.status === 404;
    if (!noExiste) {
      sesion.error = t("entrar.sinConexion");
    } else if (volverAlSelectorSiFalla && !estudioFijo) {
      slug.value = "";
      errorBusqueda.value = t("entrar.negocioNoDisponible");
      await router.replace({ name: "entrar" });
    }
  } finally {
    marcaCargando.value = false;
  }
}

// Solo cuenta la respuesta de la última búsqueda: la carga inicial no pisa la que la
// persona hizo mientras tanto.
let ultimaBusqueda = 0;
async function buscarNegocios(inicial = false): Promise<void> {
  const esta = ++ultimaBusqueda;
  buscando.value = true;
  busquedaRealizada.value = !inicial;
  errorBusqueda.value = null;
  try {
    // Solo los negocios de este producto (ADR 0108, 0136).
    const params = {
      producto: productoActual(),
      ...(busqueda.value.trim() ? { q: busqueda.value.trim() } : {}),
    };
    const { data } = await api.get<{ data: NegocioDirectorio[] }>(
      "/api/v1/directorio",
      { params },
    );
    if (esta === ultimaBusqueda) resultados.value = data.data.slice(0, 8);
  } catch (error) {
    if (esta === ultimaBusqueda) errorBusqueda.value = mensajeDeError(error);
  } finally {
    if (esta === ultimaBusqueda) buscando.value = false;
  }
}

async function seleccionarNegocio(negocio: NegocioDirectorio): Promise<void> {
  otroProducto.value = null;
  recientes.value = recordarNegocio({
    slug: negocio.slug,
    nombre: negocio.nombre,
    logo_url: negocio.logo_url,
    ciudad: negocio.ciudad,
    pais: negocio.pais,
    producto: negocio.producto,
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
        producto: marca.value.producto,
      });
    }
    continuar();
  } catch {
    // El error queda en sesion.error.
  }
}

async function entrarConGoogle(credential: string): Promise<void> {
  try {
    await sesion.iniciarSesionConGoogle(slug.value.trim(), credential);
    continuar();
  } catch {
    // El error queda en sesion.error.
  }
}

// Abierto desde la app con un enlace de un solo uso (ADR 0211): entra con esa sesión
// y va a la pantalla que se pidió (solo una ruta de este negocio).
async function entrarConEnlace(codigo: string): Promise<boolean> {
  try {
    const pedido = await sesion.iniciarSesionConEnlace(
      slug.value.trim(),
      codigo,
    );
    const seguro = volverSeguro(pedido, slug.value.trim() || null, (r) =>
      router.resolve(r),
    );
    await router.replace(seguro ?? { name: sesion.rutaInicio });
    return true;
  } catch {
    // El aviso del enlace vencido o usado queda en `sesion.error`.
    return false;
  }
}

onMounted(async () => {
  const enlace = route.query.enlace;
  if (
    typeof enlace === "string" &&
    enlace !== "" &&
    slug.value.trim() !== "" &&
    (await entrarConEnlace(enlace))
  ) {
    return;
  }
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
    <section class="tu-login-visual" :aria-label="$t('entrar.etiqueta')">
      <ComposicionAcceso :producto="esTurnoUno ? 'turnouno' : 'agendauno'" />
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
          <LogoProducto v-else variante="isotipo" :ancho="48" />
        </div>

        <!-- La sesión guardada no se pudo confirmar (sin red, en mantenimiento): sigue
             guardada y se reintenta sin volver a escribir la contraseña. -->
        <div
          v-if="sesion.avisoSinConfirmar"
          class="tu-login-sin-confirmar"
          role="status"
          data-prueba="sesion-sin-confirmar"
        >
          <p>{{ sesion.avisoSinConfirmar }}</p>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="reintentando"
            @click="reintentarSesion"
          >
            {{ reintentando ? $t("comun.cargando") : $t("comun.reintentar") }}
          </button>
        </div>

        <template v-if="seleccionando">
          <h1 class="tu-login-titulo">{{ $t("entrar.selectorTitulo") }}</h1>
          <p class="tu-login-subtitulo">
            {{
              recientes.length
                ? $t("entrar.selectorSubtitulo")
                : $t(
                    esTurnoUno
                      ? "entrar.selectorVacioCitas"
                      : "entrar.selectorVacioClases",
                  )
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
                  <IconoNav class="shrink-0" nombre="flecha" :tam="16" />
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
                $t(
                  esTurnoUno
                    ? "entrar.buscarPlaceholderCitas"
                    : "entrar.buscarPlaceholder",
                )
              }}</label>
              <input
                id="buscar-negocio"
                v-model="busqueda"
                class="tu-input"
                type="search"
                :placeholder="
                  $t(
                    esTurnoUno
                      ? 'entrar.buscarPlaceholderCitas'
                      : 'entrar.buscarPlaceholder',
                  )
                "
                autocomplete="organization"
              />
              <!-- La carga inicial de la lista no es una búsqueda: el botón sigue
                   diciendo «Buscar» hasta que la persona busca. -->
              <button
                class="tu-btn tu-btn-primario"
                type="submit"
                :disabled="buscando && busquedaRealizada"
              >
                {{
                  buscando && busquedaRealizada
                    ? $t("entrar.buscando")
                    : $t("entrar.buscar")
                }}
              </button>
            </form>

            <!-- Un negocio del otro producto: dónde entrar (ADR 0136). -->
            <p
              v-if="otroProducto"
              class="tu-selector-error"
              role="alert"
              data-prueba="otro-producto"
            >
              {{
                $t("entrar.otroProducto", {
                  negocio: otroProducto.nombre,
                  producto: PRODUCTOS[otroProducto.producto].nombre,
                })
              }}
              <a class="tu-enlace" :href="enlaceOtroProducto">{{
                $t("entrar.irAlOtroProducto", {
                  producto: PRODUCTOS[otroProducto.producto].nombre,
                })
              }}</a>
            </p>
            <p v-if="errorBusqueda" class="tu-selector-error" role="alert">
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
                  <IconoNav class="shrink-0" nombre="flecha" :tam="16" />
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
            <IconoNav nombre="atras" :tam="16" /> {{ $t("entrar.elegirOtro") }}
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

          <form class="tu-login-form" @submit.prevent="enviar">
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

            <AvisoTono v-if="sesion.error" tono="peligro" role="alert">
              {{ sesion.error }}
            </AvisoTono>

            <button
              class="tu-btn tu-btn-primario w-full justify-center py-3"
              type="submit"
              :disabled="sesion.cargando || marcaCargando"
            >
              {{
                sesion.cargando ? $t("entrar.entrando") : $t("entrar.entrar")
              }}
            </button>

            <!-- Sin Google configurado, ni el botón ni el separador. -->
            <div v-if="hayGoogle || googleEnRaiz" class="tu-login-separador">
              <span />
              <small>o</small>
              <span />
            </div>

            <div
              v-if="hayGoogle"
              ref="contenedorGoogle"
              class="flex justify-center"
            ></div>
            <!-- En el subdominio de un negocio, un enlace al dominio raíz. -->
            <template v-else-if="googleEnRaiz">
              <a
                class="tu-btn tu-btn-secundario w-full justify-center"
                :href="urlGoogleRaiz"
                data-prueba="google-en-raiz"
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
              </a>
              <p class="tu-login-aviso">
                {{ $t("entrar.googleEnRaiz", { host: hostRaiz }) }}
              </p>
            </template>
          </form>
        </template>

        <!-- Registro cerrado (ADR 0093): las cuentas de un negocio las crea el negocio. -->
        <p
          v-if="marca"
          class="tu-login-registro"
          data-prueba="sin-cuenta-cliente"
        >
          {{ $t("entrar.sinCuentaCliente", { negocio: marca.nombre }) }}
        </p>
        <p class="tu-login-registro" data-prueba="registrar-negocio">
          {{ $t("entrar.sinCuenta") }}
          <RouterLink class="tu-enlace" :to="{ name: 'registro' }">{{
            registroAbierto
              ? $t("entrar.registrar")
              : $t("landing.prelanzamiento.cta")
          }}</RouterLink>
        </p>
      </div>
      <RouterLink
        v-if="registroAbierto"
        class="tu-login-explorar"
        :to="{ name: 'directorio' }"
      >
        {{ $t("entrar.buscarReserva") }}
        <IconoNav nombre="flecha" :tam="16" />
      </RouterLink>
    </section>
  </div>
  <PanelRoles
    :abierto="eligiendoRol && sesion.requiereElegirRol"
    al-entrar
    :destino="volverA()"
    @cerrar="eligiendoRol = false"
  />
</template>

<style scoped>
.tu-login-shell {
  display: grid;
  min-width: 0;
  width: 100%;
  max-width: 84rem;
  margin-inline: auto;
  background: var(--fondo);
}
.tu-login-visual {
  display: none;
  min-width: 0;
  align-content: center;
  justify-items: center;
  gap: 0.5rem;
  padding: 1rem;
}
.tu-login-visual :deep(.acceso-escena-mensaje h2) {
  font-size: 4.6cqw;
  font-weight: 400;
  line-height: 1.15;
  letter-spacing: -0.035em;
  color: #263949;
}
.tu-login-visual :deep(.acceso-escena-cita strong) {
  font-weight: 500;
  color: #263949;
}
.tu-login-explorar {
  display: inline-flex;
  justify-self: center;
  align-items: center;
  gap: 0.55rem;
  min-height: 44px;
  padding: 0.5rem 1rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  font-weight: 400;
  text-decoration: none;
}
.tu-login-explorar:hover {
  text-decoration: underline;
  text-underline-offset: 4px;
}
.tu-login-formulario {
  display: grid;
  place-items: center;
  align-content: center;
  gap: 0.5rem;
  padding: 1.25rem;
}
.tu-login-formulario-inner {
  width: min(100%, 30rem);
  padding: clamp(1.25rem, 2.4vw, 2.25rem);
  border: 1px solid var(--borde);
  border-radius: 18px;
  background: var(--superficie);
  box-shadow: 0 12px 36px -16px
    color-mix(in srgb, var(--texto) 12%, transparent);
}
.tu-login-identidad {
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1.25rem;
  gap: 0.5rem;
}
.tu-login-logo-negocio {
  width: 3rem;
  height: 3rem;
  border-radius: 0.75rem;
  object-fit: contain;
  box-shadow: var(--sombra);
}
.tu-negocio-iniciales {
  display: inline-grid;
  flex: 0 0 auto;
  place-content: center;
  background: var(--superficie-2);
  color: var(--texto-suave);
  font-weight: 500;
}
.tu-login-titulo {
  margin: 0;
  font-size: clamp(1.4rem, 1.9vw, 1.65rem);
  font-weight: 400;
  letter-spacing: -0.025em;
  line-height: 1.3;
  color: var(--texto);
  text-wrap: balance;
}
.tu-login-subtitulo {
  margin-top: 0.6rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
  line-height: 1.6;
}
.tu-selector-seccion {
  margin-top: 1.5rem;
}
.tu-selector-seccion > h2,
.tu-selector-cabecera h2 {
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--texto-suave);
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
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  border: 1px solid var(--borde);
  border-radius: 12px;
  background: var(--superficie);
  transition: background-color 0.2s ease;
}
.tu-negocios-recientes > li:hover {
  background: var(--superficie-2);
}
@media (prefers-reduced-motion: reduce) {
  .tu-negocios-recientes > li {
    transition: none;
  }
}
.tu-negocio-principal,
.tu-resultados-negocio button {
  display: flex;
  width: 100%;
  min-width: 0;
  align-items: center;
  gap: 0.8rem;
  padding: 0.7rem 0.75rem;
  border-radius: 11px;
  border: 0;
  background: transparent;
  color: var(--texto);
  cursor: pointer;
  text-align: left;
}
.tu-negocio-principal > img,
.tu-resultados-negocio img,
.tu-negocio-iniciales {
  width: 2.5rem;
  height: 2.5rem;
  flex-shrink: 0;
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
  font-size: 0.875rem;
  font-weight: 500;
}
.tu-negocio-texto small {
  margin-top: 0.12rem;
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.tu-negocio-olvidar {
  min-height: 44px;
  padding: 0.5rem 0.75rem;
  margin-right: 0.25rem;
  border-radius: 8px;
  border: 0;
  background: transparent;
  color: var(--texto-suave);
  cursor: pointer;
  font-size: 0.7rem;
}
.tu-login-shell button:focus-visible,
.tu-login-shell a:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: 3px;
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
  min-height: 44px;
  margin-bottom: 0.5rem;
  border: 0;
  background: transparent;
  color: var(--texto-suave);
  cursor: pointer;
  font-size: 0.8rem;
  font-weight: 400;
}
.tu-login-cambiar:hover {
  color: var(--texto);
  text-decoration: underline;
  text-underline-offset: 3px;
}
.tu-login-form {
  display: grid;
  gap: 1rem;
  margin-top: 1.5rem;
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
.tu-login-sin-confirmar {
  display: grid;
  gap: 0.75rem;
  justify-items: start;
  margin-top: 1.25rem;
  margin-bottom: 1.25rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-inline-start: 2px solid var(--aviso);
  border-radius: 0.75rem;
  background: var(--aviso-suave);
  font-size: 0.8rem;
  line-height: 1.6;
}
.tu-login-registro {
  margin-top: 1.25rem;
  font-size: 0.8rem;
  line-height: 1.65;
}
.tu-login-registro[data-prueba="registrar-negocio"] {
  border-top: 1px solid var(--borde);
  padding-top: 1rem;
}
.tu-login-shell .tu-enlace {
  font-weight: 400;
}
@media (min-width: 1024px) {
  /* La composición toma todo el alto disponible (sin el encabezado ni el pie) y el
     formulario, una columna fija a la derecha. */
  .tu-login-shell {
    grid-template-columns: minmax(0, 1fr) minmax(26rem, 30rem);
    max-width: 104rem;
    gap: clamp(1rem, 2.5vw, 3rem);
    padding-inline: clamp(1rem, 2.5vw, 2.5rem);
  }
  .tu-login-visual {
    display: grid;
    padding: 0.75rem 0;
  }
  .tu-login-visual :deep(.acceso-escena) {
    /* Sin recortar las fotos: conserva su proporción y sus textos superpuestos, y
       crece hasta el alto de la ventana (o el ancho de su columna). */
    width: min(100%, calc((100svh - 9rem) * 1448 / 1086));
    max-width: none;
  }
}
@media (min-width: 1024px) and (max-height: 800px) {
  .tu-login-formulario {
    padding-block: 0.75rem;
  }
  .tu-login-formulario-inner {
    padding-block: 1rem;
  }
  .tu-selector-seccion {
    margin-top: 1.25rem;
  }
  .tu-login-registro {
    margin-top: 1rem;
  }
}
@media (max-width: 520px) {
  .tu-login-formulario {
    padding: 1.25rem 0.75rem;
    align-items: start;
  }
  .tu-login-formulario-inner {
    padding: 1.25rem;
    border-radius: 20px;
  }
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
