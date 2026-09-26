<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { trackEvent } from "@/lib/analytics";
import AvisoPrivacidadContenido from "@/components/AvisoPrivacidadContenido.vue";

const router = useRouter();

// Alta por pasos: filtra interesados reales y captura datos de contacto útiles.
const paso = ref(1);
const pasosRegistro = [
  { numero: 1, titulo: "registro.paso1" },
  { numero: 2, titulo: "registro.paso2" },
  { numero: 3, titulo: "registro.paso3" },
] as const;

// Paso 1: el lugar.
const nombre = ref("");
const slug = ref("");
const slugTocado = ref(false);
const perfilNegocio = ref("");

const PERFILES = [
  "pilates",
  "pole",
  "academia",
  "gimnasio",
  "yoga",
  "danza",
  "natacion",
  "barberia",
  "estetica",
  "salon",
  "spa",
  "salud",
  "general",
] as const;

// Paso 2: quién eres.
const contactoNombre = ref("");
const contactoSegundoNombre = ref("");
const contactoPrimerApellido = ref("");
const contactoSegundoApellido = ref("");

// Paso 3: contacto.
const whatsappPais = ref("52");
const whatsappNumero = ref("");
const contactoEmail = ref("");
const aceptaTerminos = ref(false);

// Documentos legales (aviso de privacidad y términos) que edita el superadmin y se
// muestran al dar clic en el enlace correspondiente del registro.
const legales = ref<{
  aviso_privacidad: string | null;
  terminos: string | null;
}>({ aviso_privacidad: null, terminos: null });
const legalAbierto = ref<"aviso" | "terminos" | null>(null);
function verLegal(cual: "aviso" | "terminos"): void {
  legalAbierto.value = cual;
}

// La dirección (slug) se sugiere automáticamente del nombre; «Personalizar» permite
// cambiarla. Si no se personaliza, se manda vacía y el backend genera una única.
const personalizarSlug = ref(false);

// Anti-bots: campo trampa (honeypot, oculto) + token de reCAPTCHA v3 si hay site key.
const honeypot = ref("");
const RECAPTCHA_SITE_KEY = import.meta.env.VITE_RECAPTCHA_SITE_KEY as
  string | undefined;

let recaptchaCarga: Promise<void> | null = null;
function cargarRecaptcha(siteKey: string): Promise<void> {
  recaptchaCarga ??= new Promise<void>((resolve, reject) => {
    const s = document.createElement("script");
    s.src = `https://www.google.com/recaptcha/api.js?render=${siteKey}`;
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error("recaptcha"));
    document.head.appendChild(s);
  });
  return recaptchaCarga;
}

interface Grecaptcha {
  ready: (cb: () => void) => void;
  execute: (key: string, opts: { action: string }) => Promise<string>;
}
async function tokenRecaptcha(): Promise<string | null> {
  if (RECAPTCHA_SITE_KEY === undefined || RECAPTCHA_SITE_KEY === "") {
    return null; // Sin site key no se exige captcha (dev).
  }
  try {
    await cargarRecaptcha(RECAPTCHA_SITE_KEY);
    const grecaptcha = (window as unknown as { grecaptcha: Grecaptcha })
      .grecaptcha;
    return await new Promise<string>((resolve, reject) => {
      grecaptcha.ready(() => {
        grecaptcha
          .execute(RECAPTCHA_SITE_KEY, { action: "registro" })
          .then(resolve, reject);
      });
    });
  } catch {
    return null;
  }
}

// Ladas frecuentes (México por defecto).
const PAISES = [
  { lada: "52", nombre: "México", bandera: "🇲🇽" },
  { lada: "1", nombre: "EE. UU. / Canadá", bandera: "🇺🇸" },
  { lada: "57", nombre: "Colombia", bandera: "🇨🇴" },
  { lada: "54", nombre: "Argentina", bandera: "🇦🇷" },
  { lada: "56", nombre: "Chile", bandera: "🇨🇱" },
  { lada: "51", nombre: "Perú", bandera: "🇵🇪" },
  { lada: "34", nombre: "España", bandera: "🇪🇸" },
];

const slugDisponible = ref<boolean | null>(null);
const verificandoSlug = ref(false);
const enviando = ref(false);
const error = ref<string | null>(null);

const creado = ref<{ slug: string; nombre: string } | null>(null);
const activacion = ref<{ email: string; token: string } | null>(null);
const correo = ref("");
const reenviando = ref(false);
const reenviado = ref(false);

function aSlug(valor: string): string {
  return valor
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "") // quita acentos/diacríticos (é→e, ñ→n…)
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

watch(nombre, (v) => {
  if (!slugTocado.value) {
    slug.value = aSlug(v);
  }
});

let temporizador: ReturnType<typeof setTimeout> | null = null;
watch(slug, (v) => {
  slugDisponible.value = null;
  if (temporizador !== null) {
    clearTimeout(temporizador);
  }
  if (v.trim() === "") {
    return;
  }
  verificandoSlug.value = true;
  temporizador = setTimeout(async () => {
    try {
      const { data } = await api.get<{
        data: { slug: string; disponible: boolean };
      }>("/api/v1/registro/slug", { params: { slug: v } });
      if (data.data.slug === aSlug(v)) {
        slugDisponible.value = data.data.disponible;
      }
    } catch {
      slugDisponible.value = null;
    } finally {
      verificandoSlug.value = false;
    }
  }, 400);
});

function editarSlug(valor: string): void {
  slugTocado.value = true;
  slug.value = aSlug(valor);
}

const emailValido = computed(() =>
  /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contactoEmail.value.trim()),
);
const whatsappValido = computed(() =>
  /^[0-9 -]{7,15}$/.test(whatsappNumero.value.trim()),
);

const paso1Valido = computed(
  () =>
    nombre.value.trim() !== "" &&
    perfilNegocio.value !== "" &&
    slug.value.trim().length >= 3 &&
    // La disponibilidad solo bloquea si el dueño personalizó la dirección; si no,
    // el backend genera una única a partir del nombre.
    (!personalizarSlug.value ||
      (slugDisponible.value !== false && !verificandoSlug.value)),
);
const paso2Valido = computed(
  () =>
    contactoNombre.value.trim() !== "" &&
    contactoPrimerApellido.value.trim() !== "",
);
const paso3Valido = computed(
  () => whatsappValido.value && emailValido.value && aceptaTerminos.value,
);

const pasoValido = computed(() =>
  paso.value === 1
    ? paso1Valido.value
    : paso.value === 2
      ? paso2Valido.value
      : paso3Valido.value,
);

function siguiente(): void {
  if (paso.value < 3 && pasoValido.value) {
    trackEvent("studio_registration_step_completed", { step: paso.value });
    paso.value++;
  }
}
function atras(): void {
  if (paso.value > 1) {
    paso.value--;
  }
}

async function enviar(): Promise<void> {
  if (!paso3Valido.value) {
    return;
  }
  enviando.value = true;
  error.value = null;
  try {
    const recaptchaToken = await tokenRecaptcha();
    const { data } = await api.post<{
      data: {
        estudio: { slug: string; nombre: string };
        activacion: { email: string; token: string } | null;
      };
    }>("/api/v1/registro", {
      nombre: nombre.value,
      // Si no se personaliza, se OMITE el slug (undefined → axios no lo manda) y el
      // backend genera la dirección única del nombre; si se personalizó, va la elegida.
      slug: personalizarSlug.value ? slug.value : undefined,
      recaptcha_token: recaptchaToken,
      sitio_web: honeypot.value,
      perfil_negocio: perfilNegocio.value,
      contacto_nombre: contactoNombre.value,
      contacto_segundo_nombre: contactoSegundoNombre.value || null,
      contacto_primer_apellido: contactoPrimerApellido.value,
      contacto_segundo_apellido: contactoSegundoApellido.value || null,
      contacto_whatsapp_pais: whatsappPais.value,
      contacto_telefono: whatsappNumero.value,
      contacto_email: contactoEmail.value,
      acepta_terminos: aceptaTerminos.value,
    });
    creado.value = data.data.estudio;
    activacion.value = data.data.activacion;
    correo.value = contactoEmail.value;
    trackEvent("tenant_created", { business_profile: perfilNegocio.value });
  } catch (e) {
    error.value = mensajeDeError(e);
    trackEvent("studio_registration_failed", { step: paso.value });
    // Si el backend rechaza el slug (carrera), regresa al paso 1.
    paso.value = 1;
  } finally {
    enviando.value = false;
  }
}

async function reenviar(): Promise<void> {
  if (creado.value === null) {
    return;
  }
  reenviando.value = true;
  reenviado.value = false;
  try {
    await api.post(`/api/v1/app/${creado.value.slug}/reenviar-activacion`, {
      email: correo.value,
    });
    reenviado.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    reenviando.value = false;
  }
}

function irActivar(): void {
  if (creado.value === null) {
    return;
  }
  void router.push({
    name: "activar",
    params: { slug: creado.value.slug },
    query:
      activacion.value !== null
        ? { email: activacion.value.email, token: activacion.value.token }
        : {},
  });
}

onMounted(() => {
  trackEvent("studio_registration_started");
  void api
    .get<{
      data: { aviso_privacidad: string | null; terminos: string | null };
    }>("/api/v1/legales")
    .then(({ data }) => {
      legales.value = data.data;
    })
    .catch(() => {
      // Sin legales configurados por el superadmin: los enlaces mostrarán un aviso.
    });
});
</script>

<template>
  <section class="registro mx-auto max-w-7xl px-4 sm:px-6 py-8 sm:py-10">
    <div
      class="registro-layout"
      :class="{ 'registro-layout--creado': creado !== null }"
    >
      <div v-if="creado === null" class="registro-visual" aria-hidden="true">
        <div class="registro-collage">
          <img
            class="registro-foto registro-foto--terapia"
            :src="'/assets/landing/disciplinas/terapeutas-v1.webp'"
            alt=""
            width="1122"
            height="1402"
            decoding="async"
          />
          <img
            class="registro-foto registro-foto--yoga"
            :src="'/assets/landing/disciplinas/yoga-v1.jpg'"
            alt=""
            width="1122"
            height="1402"
            decoding="async"
          />
          <div class="registro-mini-cita">
            <span class="registro-mini-cita-icono">✓</span>
            <span>
              <small>{{ $t("registro.citaEjemplo") }}</small>
              <strong>{{ $t("registro.citaTitulo") }}</strong>
              <span>{{ $t("registro.citaDetalle") }}</span>
            </span>
          </div>
        </div>
      </div>
      <div class="registro-contenido">
        <template v-if="creado === null">
          <h1 class="tu-public-form-title">{{ $t("registro.titulo") }}</h1>

          <ol class="registro-pasos" :aria-label="$t('registro.progreso')">
            <li
              v-for="etapa in pasosRegistro"
              :key="etapa.numero"
              class="registro-paso"
              :class="{
                'es-actual': paso === etapa.numero,
                'es-completo': paso > etapa.numero,
              }"
              :aria-current="paso === etapa.numero ? 'step' : undefined"
            >
              <span class="registro-paso-circulo" aria-hidden="true">
                <svg
                  v-if="paso > etapa.numero"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2.5"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path d="m5 12 4 4L19 6" />
                </svg>
                <span v-else>{{ etapa.numero }}</span>
              </span>
              <span class="registro-paso-titulo">{{ $t(etapa.titulo) }}</span>
            </li>
          </ol>
          <p
            class="registro-intro mt-5 text-sm text-center"
            :style="{ color: 'var(--texto-suave)' }"
            aria-live="polite"
            aria-atomic="true"
          >
            {{
              paso === 1
                ? $t("registro.intro1")
                : paso === 2
                  ? $t("registro.intro2")
                  : $t("registro.intro3")
            }}
          </p>

          <p
            class="mt-3 text-xs text-center"
            :style="{ color: 'var(--texto-suave)' }"
          >
            Antes de compartir tus datos, consulta el
            <RouterLink
              to="/aviso-de-privacidad"
              target="_blank"
              rel="noopener"
              class="tu-enlace registro-aviso"
              >aviso de privacidad<span class="sr-only">
                (abre en otra pestaña)</span
              ></RouterLink
            >.
          </p>
          <form
            class="mt-5 tu-card p-6 space-y-4"
            @submit.prevent="paso === 3 ? enviar() : siguiente()"
          >
            <!-- ===== Paso 1: el lugar ===== -->
            <template v-if="paso === 1">
              <div>
                <label class="tu-label" for="nombre">{{
                  $t("registro.nombre")
                }}</label>
                <input
                  id="nombre"
                  v-model="nombre"
                  class="tu-input"
                  :placeholder="$t('registro.nombrePh')"
                  required
                />
              </div>
              <div>
                <label class="tu-label" for="perfil">{{
                  $t("registro.perfil")
                }}</label>
                <select
                  id="perfil"
                  v-model="perfilNegocio"
                  class="tu-input"
                  required
                >
                  <option value="" disabled>
                    {{ $t("registro.perfilPh") }}
                  </option>
                  <option
                    v-for="perfil in PERFILES"
                    :key="perfil"
                    :value="perfil"
                  >
                    {{ $t(`registro.perfiles.${perfil}`) }}
                  </option>
                </select>
                <p
                  class="mt-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("registro.perfilAyuda") }}
                </p>
              </div>
              <div>
                <label class="tu-label" for="slug">{{
                  $t("registro.slug")
                }}</label>
                <div v-if="!personalizarSlug" class="registro-direccion">
                  <div
                    class="tu-input registro-direccion-valor"
                    :style="{ background: 'var(--fondo)' }"
                  >
                    <span>{{ slug || "tu-negocio" }}</span>
                    <span :style="{ color: 'var(--texto-suave)' }"
                      >.agendauno.mx</span
                    >
                  </div>
                  <button
                    type="button"
                    class="tu-btn tu-btn-fantasma shrink-0"
                    @click="personalizarSlug = true"
                  >
                    {{ $t("registro.personalizar") }}
                  </button>
                </div>
                <input
                  v-else
                  id="slug"
                  :value="slug"
                  class="tu-input"
                  @input="editarSlug(($event.target as HTMLInputElement).value)"
                />
                <p
                  class="mt-1 text-xs flex items-center gap-2"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  <span>{{
                    $t("registro.slugAyuda", { slug: slug || "tu-negocio" })
                  }}</span>
                  <template v-if="personalizarSlug">
                    <span v-if="verificandoSlug">·</span>
                    <span
                      v-else-if="slugDisponible === true"
                      class="tu-badge tu-badge-exito"
                      >{{ $t("registro.slugLibre") }}</span
                    >
                    <span
                      v-else-if="slugDisponible === false"
                      style="color: var(--error)"
                      >{{ $t("registro.slugOcupado") }}</span
                    >
                  </template>
                </p>
              </div>

              <!-- Honeypot anti-bots: oculto para humanos; si se llena, es un bot. -->
              <div class="hidden" aria-hidden="true">
                <label for="sitio_web">No llenar</label>
                <input
                  id="sitio_web"
                  v-model="honeypot"
                  type="text"
                  tabindex="-1"
                  autocomplete="off"
                />
              </div>
            </template>

            <!-- ===== Paso 2: tus datos (2×2: nombres arriba, apellidos abajo) ===== -->
            <template v-else-if="paso === 2">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="tu-label" for="cnombre">{{
                    $t("registro.contactoNombre")
                  }}</label>
                  <input
                    id="cnombre"
                    v-model="contactoNombre"
                    class="tu-input"
                    required
                  />
                </div>
                <div>
                  <label class="tu-label" for="csegnombre">{{
                    $t("registro.contactoSegundoNombre")
                  }}</label>
                  <input
                    id="csegnombre"
                    v-model="contactoSegundoNombre"
                    class="tu-input registro-opcional"
                    :placeholder="$t('registro.opcional')"
                  />
                </div>
                <div>
                  <label class="tu-label" for="cpaterno">{{
                    $t("registro.contactoPrimerApellido")
                  }}</label>
                  <input
                    id="cpaterno"
                    v-model="contactoPrimerApellido"
                    class="tu-input"
                    required
                  />
                </div>
                <div>
                  <label class="tu-label" for="cmaterno">{{
                    $t("registro.contactoSegundoApellido")
                  }}</label>
                  <input
                    id="cmaterno"
                    v-model="contactoSegundoApellido"
                    class="tu-input registro-opcional"
                    :placeholder="$t('registro.opcional')"
                  />
                </div>
              </div>
            </template>

            <!-- ===== Paso 3: contacto ===== -->
            <template v-else>
              <div>
                <label class="tu-label">{{ $t("registro.whatsapp") }}</label>
                <div class="flex gap-2">
                  <select
                    v-model="whatsappPais"
                    class="tu-input shrink-0"
                    style="width: 6.25rem"
                    :aria-label="$t('registro.whatsappPais')"
                  >
                    <option v-for="p in PAISES" :key="p.lada" :value="p.lada">
                      {{ p.bandera }} +{{ p.lada }}
                    </option>
                  </select>
                  <input
                    v-model="whatsappNumero"
                    class="tu-input flex-1 min-w-0"
                    type="tel"
                    inputmode="tel"
                    :placeholder="$t('registro.whatsappNumeroPh')"
                    required
                  />
                </div>
                <p
                  class="mt-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("registro.whatsappAyuda") }}
                </p>
              </div>
              <div>
                <label class="tu-label" for="cemail">{{
                  $t("registro.contactoEmail")
                }}</label>
                <input
                  id="cemail"
                  v-model="contactoEmail"
                  class="tu-input"
                  type="email"
                  required
                />
                <p
                  class="mt-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("registro.correoAyuda") }}
                </p>
              </div>
              <div class="registro-legales flex items-start gap-3 text-sm">
                <input
                  id="acepta"
                  v-model="aceptaTerminos"
                  type="checkbox"
                  class="mt-1 shrink-0"
                  :aria-label="$t('registro.terminos')"
                  required
                />
                <span>
                  <label for="acepta" class="cursor-pointer">{{
                    $t("registro.aceptoInicio")
                  }}</label
                  >{{ " " }}
                  <button
                    type="button"
                    class="tu-enlace"
                    @click="verLegal('terminos')"
                  >
                    {{ $t("registro.terminosEnlace") }}</button
                  >{{ " "
                  }}<label for="acepta" class="cursor-pointer">{{
                    $t("registro.yEl")
                  }}</label
                  >{{ " "
                  }}<button
                    type="button"
                    class="tu-enlace"
                    @click="verLegal('aviso')"
                  >
                    {{ $t("registro.avisoEnlace") }}</button
                  >.
                </span>
              </div>
            </template>

            <p v-if="error" class="text-sm" style="color: var(--error)">
              {{ error }}
            </p>

            <!-- Navegación -->
            <div class="flex gap-2 pt-1">
              <button
                v-if="paso > 1"
                class="tu-btn tu-btn-fantasma"
                type="button"
                :disabled="enviando"
                @click="atras"
              >
                {{ $t("registro.atras") }}
              </button>
              <button
                v-if="paso < 3"
                class="tu-btn tu-btn-primario flex-1"
                type="submit"
                :disabled="!pasoValido"
              >
                {{ $t("registro.siguiente") }}
              </button>
              <button
                v-else
                class="tu-btn tu-btn-primario flex-1"
                type="submit"
                :disabled="enviando || !paso3Valido"
              >
                {{ enviando ? $t("registro.creando") : $t("registro.crear") }}
              </button>
            </div>
          </form>
        </template>

        <div v-else class="tu-card p-8 text-center">
          <div class="text-5xl" aria-hidden="true">📬</div>
          <h1 class="mt-3 tu-public-form-title">
            {{ $t("registro.pendienteTitulo") }}
          </h1>
          <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("registro.pendienteDesc", { email: correo }) }}
          </p>

          <div class="mt-5">
            <button
              class="tu-btn tu-btn-fantasma"
              :disabled="reenviando"
              @click="reenviar"
            >
              {{
                reenviando ? $t("registro.reenviando") : $t("registro.reenviar")
              }}
            </button>
            <p
              v-if="reenviado"
              class="mt-2 text-sm"
              :style="{ color: 'var(--exito)' }"
            >
              {{ $t("registro.reenviado", { email: correo }) }}
            </p>
          </div>

          <div
            v-if="activacion"
            class="mt-5 rounded-lg p-3 text-left text-sm break-all"
            :style="{ background: 'var(--superficie-2)' }"
          >
            <p class="font-semibold mb-1">{{ $t("registro.tokenDev") }}</p>
            <code>{{ activacion.token }}</code>
            <button
              class="tu-btn tu-btn-primario w-full mt-3"
              @click="irActivar"
            >
              {{ $t("registro.irActivar") }}
            </button>
          </div>

          <RouterLink
            class="tu-enlace inline-block mt-4 text-sm"
            :to="{ name: 'entrar' }"
          >
            {{ $t("nav.entrar") }}
          </RouterLink>
        </div>
      </div>
    </div>
    <!-- Modal: aviso de privacidad / términos (contenido del superadmin) -->
    <div
      v-if="legalAbierto"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
      @click.self="legalAbierto = null"
    >
      <div class="absolute inset-0 bg-black/50" @click="legalAbierto = null" />
      <div
        class="relative tu-card flex max-h-[80vh] w-full max-w-2xl flex-col p-6"
      >
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-lg font-light">
            {{
              legalAbierto === "aviso"
                ? $t("registro.avisoTitulo")
                : $t("registro.terminosTitulo")
            }}
          </h2>
          <button
            class="tu-icono-btn"
            type="button"
            :aria-label="$t('comun.cerrar')"
            @click="legalAbierto = null"
          >
            ✕
          </button>
        </div>
        <div
          class="mt-3 overflow-y-auto text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          <AvisoPrivacidadContenido
            v-if="legalAbierto === 'aviso'"
            :contenido="legales.aviso_privacidad"
          />
          <p v-else class="whitespace-pre-wrap">
            {{ legales.terminos || $t("registro.legalVacio") }}
          </p>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.registro {
  --registro-progreso: #24566b;
  --registro-acento: #4f7f90;
  --registro-enlace: #24566b;
  --registro-suave: color-mix(
    in srgb,
    var(--registro-acento) 9%,
    var(--superficie)
  );
}
.registro-pasos {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin: 2rem 0 0;
  padding: 0;
  list-style: none;
}
.registro-paso {
  position: relative;
  isolation: isolate;
  display: grid;
  justify-items: center;
  align-content: start;
  gap: 0.9rem;
  color: var(--texto-suave);
}
.registro-paso:not(:last-child)::after {
  content: "";
  position: absolute;
  z-index: -1;
  top: 1.5rem;
  left: 50%;
  width: 100%;
  height: 2px;
  background: var(--borde);
  transition: background-color 0.2s ease;
}
.registro-paso.es-completo::after {
  background: var(--registro-acento);
}
.registro-paso.es-actual {
  color: var(--registro-enlace);
}
.registro-paso-circulo {
  display: grid;
  place-items: center;
  width: 3rem;
  height: 3rem;
  border: 2px solid var(--registro-acento);
  border-radius: 50%;
  background: var(--superficie);
  color: var(--registro-enlace);
  font-size: 1.25rem;
  font-weight: 700;
}
.registro-paso-circulo svg {
  width: 1.5rem;
  height: 1.5rem;
}
.es-actual .registro-paso-circulo {
  outline: 2px solid var(--registro-acento);
  outline-offset: 4px;
}
.es-actual .registro-paso-circulo,
.es-completo .registro-paso-circulo {
  background: var(--registro-progreso);
  border-color: var(--registro-progreso);
  color: #fff;
}
.registro-paso-titulo {
  font-size: 0.875rem;
  font-weight: 600;
  text-align: center;
}
.registro-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  align-items: center;
}
.registro-contenido {
  width: 100%;
  max-width: 36rem;
  min-width: 0;
  margin-inline: auto;
}
.registro-visual {
  display: none;
}
@media (min-width: 1024px) {
  .registro-layout:not(.registro-layout--creado) {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.08fr);
    gap: clamp(2rem, 4vw, 4rem);
  }
  .registro-visual {
    display: grid;
    align-items: center;
    min-width: 0;
    min-height: 32rem;
    background: radial-gradient(
      ellipse at center,
      var(--registro-suave),
      transparent 70%
    );
  }
  .registro-collage {
    position: relative;
    width: 100%;
    height: clamp(25rem, 34vw, 31rem);
  }
  .registro-foto {
    position: absolute;
    width: 57%;
    height: 80%;
    object-fit: cover;
    border: 0.4rem solid var(--superficie);
    box-shadow: 0 1.4rem 3rem rgb(3 27 78 / 16%);
  }
  .registro-foto--terapia {
    z-index: 2;
    left: 2%;
    bottom: 1%;
    transform: rotate(-7deg);
    border-radius: 2.7rem 1.4rem 3rem 1.7rem;
    object-position: 45% center;
  }
  .registro-foto--yoga {
    z-index: 1;
    right: 2%;
    top: 1%;
    transform: rotate(6deg);
    border-radius: 1.5rem 3rem 1.8rem 2.8rem;
    object-position: center;
  }
}
.registro-opcional::placeholder {
  color: #707783;
  opacity: 1;
}
.registro-mini-cita {
  position: absolute;
  z-index: 3;
  left: -0.5rem;
  bottom: 8%;
  display: flex;
  align-items: center;
  gap: 0.8rem;
  width: min(85%, 18.5rem);
  padding: 1rem 1.15rem;
  border: 1px solid var(--borde);
  border-radius: 1.35rem;
  background: var(--superficie);
  color: var(--texto);
  box-shadow: 0 1rem 2.5rem rgb(3 27 78 / 18%);
}
.registro-mini-cita-icono {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.6rem;
  height: 2.6rem;
  border-radius: 50%;
  background: var(--primario);
  color: #fff;
  font-size: 1.25rem;
  font-weight: 700;
}
.registro-mini-cita strong,
.registro-mini-cita small,
.registro-mini-cita span > span {
  display: block;
}
.registro-mini-cita strong {
  margin-block: 0.15rem;
  font-size: 1rem;
}
.registro-mini-cita small {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.registro-mini-cita span > span {
  font-size: 0.875rem;
  color: var(--texto-suave);
}
.registro-legales {
  line-height: 1.8;
}
.registro-legales input {
  width: 1.125rem;
  height: 1.125rem;
  accent-color: var(--primario);
}
.registro-legales .tu-enlace {
  text-decoration: underline;
  text-underline-offset: 0.2em;
}
.registro .tu-btn:focus-visible,
.registro-legales button:focus-visible,
.registro-legales input:focus-visible {
  outline: 2px solid var(--acento);
  outline-offset: 3px;
}
.dark .registro {
  --registro-progreso: #376d82;
  --registro-acento: #91bdca;
  --registro-enlace: #91bdca;
  --registro-suave: color-mix(
    in srgb,
    var(--registro-acento) 15%,
    var(--superficie)
  );
}
:global(.dark) .registro-opcional::placeholder {
  color: #a4acb8;
}
@media (prefers-reduced-motion: reduce) {
  .registro-paso::after {
    transition: none;
  }
}
</style>
