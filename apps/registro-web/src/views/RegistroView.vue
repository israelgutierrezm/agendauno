<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { trackEvent } from "@/lib/analytics";

const router = useRouter();

// Alta por pasos: filtra interesados reales y captura datos de contacto útiles.
const paso = ref(1);

// Paso 1: el lugar.
const nombre = ref("");
const slug = ref("");
const slugTocado = ref(false);
const perfilNegocio = ref("");

const PERFILES = [
  "pilates",
  "pole",
  "yoga",
  "danza",
  "gimnasio",
  "natacion",
  "academia",
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

// Documentos legales (aviso de privacidad y terminos) que edita el superadmin y se
// muestran al dar clic en el enlace correspondiente del registro.
const legales = ref<{
  aviso_privacidad: string | null;
  terminos: string | null;
}>({ aviso_privacidad: null, terminos: null });
const legalAbierto = ref<"aviso" | "terminos" | null>(null);
function verLegal(cual: "aviso" | "terminos"): void {
  legalAbierto.value = cual;
}

// La direccion (slug) se sugiere automaticamente del nombre; «Personalizar» permite
// cambiarla. Si no se personaliza, se manda vacia y el backend genera una unica.
const personalizarSlug = ref(false);

// Anti-bots: campo trampa (honeypot, oculto) + token de reCAPTCHA v3 si hay site key.
const honeypot = ref("");
const RECAPTCHA_SITE_KEY = import.meta.env.VITE_RECAPTCHA_SITE_KEY as
  | string
  | undefined;

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
    .replace(/[\u0300-\u036f]/g, "") // quita acentos/diacríticos (é→e, ñ→n…)
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
    // La disponibilidad solo bloquea si el dueno personalizo la direccion; si no,
    // el backend genera una unica a partir del nombre.
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
      // Vacio = el backend genera la direccion unica del nombre; si el dueno la
      // personalizo, se manda la elegida.
      slug: personalizarSlug.value ? slug.value : "",
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
      // Sin legales configurados por el superadmin: los enlaces mostraran un aviso.
    });
});
</script>

<template>
  <section class="mx-auto max-w-lg px-4 py-10">
    <template v-if="creado === null">
      <h1 class="text-3xl font-extrabold">{{ $t("registro.titulo") }}</h1>

      <!-- Indicador de pasos -->
      <div class="mt-4 flex items-center gap-2">
        <template v-for="n in 3" :key="n">
          <div
            class="h-1.5 flex-1 rounded-full transition"
            :style="{
              background: n <= paso ? 'var(--primario)' : 'var(--fondo-suave)',
            }"
          />
        </template>
      </div>
      <p class="mt-2 text-sm font-medium">
        {{ $t("registro.pasoActual", { n: paso }) }} ·
        {{
          paso === 1
            ? $t("registro.paso1")
            : paso === 2
              ? $t("registro.paso2")
              : $t("registro.paso3")
        }}
      </p>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{
          paso === 1
            ? $t("registro.intro1")
            : paso === 2
              ? $t("registro.intro2")
              : $t("registro.intro3")
        }}
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
              <option value="" disabled>{{ $t("registro.perfilPh") }}</option>
              <option v-for="perfil in PERFILES" :key="perfil" :value="perfil">
                {{ $t(`registro.perfiles.${perfil}`) }}
              </option>
            </select>
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("registro.perfilAyuda") }}
            </p>
          </div>
          <div>
            <label class="tu-label" for="slug">{{ $t("registro.slug") }}</label>
            <div v-if="!personalizarSlug" class="flex items-center gap-2">
              <div
                class="tu-input flex min-w-0 flex-1 items-center"
                :style="{ background: 'var(--fondo-suave)' }"
              >
                <span class="truncate">{{ slug || "tu-negocio" }}</span>
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
                class="tu-input"
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
                class="tu-input"
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
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
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
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("registro.correoAyuda") }}
            </p>
          </div>
          <div class="flex items-start gap-2 text-sm">
            <input
              id="acepta"
              v-model="aceptaTerminos"
              type="checkbox"
              class="mt-1 shrink-0"
              required
            />
            <span>
              <label for="acepta" class="cursor-pointer">{{
                $t("registro.aceptoInicio")
              }}</label>
              <button
                type="button"
                class="tu-enlace"
                @click="verLegal('terminos')"
              >
                {{ $t("registro.terminosEnlace") }}</button
              ><label for="acepta" class="cursor-pointer">
                {{ $t("registro.yEl") }} </label
              ><button type="button" class="tu-enlace" @click="verLegal('aviso')">
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
      <h1 class="mt-3 text-2xl font-extrabold">
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
          {{ reenviando ? $t("registro.reenviando") : $t("registro.reenviar") }}
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
        <button class="tu-btn tu-btn-primario w-full mt-3" @click="irActivar">
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

    <!-- Modal: aviso de privacidad / terminos (contenido del superadmin) -->
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
          <h2 class="text-lg font-bold">
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
          class="mt-3 overflow-y-auto whitespace-pre-wrap text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{
            (legalAbierto === "aviso"
              ? legales.aviso_privacidad
              : legales.terminos) || $t("registro.legalVacio")
          }}
        </div>
      </div>
    </div>
  </section>
</template>
