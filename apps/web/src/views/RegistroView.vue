<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRouter } from "vue-router";

import { api, camposConError, mensajeDeError } from "@/lib/api";
import { PRODUCTOS, productoActual } from "@/lib/producto";
import { tokenRecaptcha } from "@/lib/recaptcha";
import { ladaDe, separarTelefono, unirTelefono } from "@/lib/ladas";
import { girosDe } from "@/lib/modalidad";
import { opcionesPais, paisSugerido, zonaSugerida } from "@/lib/region";
import { trackEvent, type AnalyticsProperties } from "@/lib/analytics";
import { modoDePerfil, type Modo } from "@/marketing/modalidades";
import { usePreciosPublicos } from "@/marketing/preciosPublicos";
import DocumentoLegalContenido from "@/components/DocumentoLegalContenido.vue";
import CampoCelular from "@/components/CampoCelular.vue";
import IconoNav from "@/components/IconoNav.vue";
import ListaInteresados from "@/components/ListaInteresados.vue";
import SelectorBuscable from "@/components/SelectorBuscable.vue";

// Alta del negocio: crea su base completa (ver el comentario en la petición).
const TIEMPO_REGISTRO_MS = 120_000;

/**
 * Con qué llegó, ya normalizado por la ruta (lo inválido llega como `null` y se
 * ignora):
 * - `modo` (`/registro?modo=`, desde /clases o /citas): solo se ven sus giros.
 * - `giro` (`?giro=`, desde la página de un giro): ya viene elegido, con su
 *   modalidad; si es de otra modalidad que `modo`, manda el giro.
 */
const props = withDefaults(
  defineProps<{ modo?: Modo | null; giro?: string | null }>(),
  { modo: null, giro: null },
);

const router = useRouter();
const { t } = useI18n();

// Cada dominio registra negocios de su producto (ADR 0108): AgendaUno los de clases y
// TurnoUno los de citas; si aún no recibe registros, se muestra la lista de
// interesados.
const producto = productoActual();
const modoDelProducto: Modo = PRODUCTOS[producto].modalidad;
const precios = usePreciosPublicos();
const registroAbierto = computed(() => precios.datos.registro[producto]);

// Alta por pasos: filtra interesados reales y captura datos de contacto útiles.
const paso = ref(1);
const pasosRegistro = [
  { numero: 1, titulo: "registro.paso1" },
  { numero: 2, titulo: "registro.paso2" },
  { numero: 3, titulo: "registro.paso3" },
] as const;

// El giro con que llegó, si es un giro del registro, y la modalidad de llegada: la
// de ese giro (manda sobre `?modo=`) o la de `?modo=`.
const giroDeLlegada = computed(() =>
  props.giro !== null && modoDePerfil(props.giro) === modoDelProducto
    ? props.giro
    : null,
);
// La modalidad de llegada (para medir la intención): la del producto si llegó con su
// giro o con su `?modo=`; un `?modo=` de la otra no aplica aquí.
const modoDeLlegada = computed<Modo | null>(() =>
  giroDeLlegada.value !== null || props.modo === modoDelProducto
    ? modoDelProducto
    : null,
);

// Paso 1: el lugar.
const nombre = ref("");
const slug = ref("");
const slugTocado = ref(false);
const perfilNegocio = ref(giroDeLlegada.value ?? "");
// País del negocio (ADR 0103): obligatorio; se propone el del navegador (su zona
// horaria o su idioma) y, si no, México. De él salen la lada y la zona.
const pais = ref(paisSugerido());
const paises = opcionesPais();

// El giro da la modalidad del negocio, solo clases o solo citas (ADR 0104): se
// agrupan para que se vea con cuál va a trabajar. Con una modalidad de llegada solo
// se ven sus giros, y un enlace muestra los de la otra; sin ella, los dos grupos.
const modoVisible = ref<Modo | null>(modoDelProducto);
const modalidades = computed<readonly Modo[]>(() => [modoDelProducto]);
// La otra modalidad es otro producto, en su propio dominio: aquí no se ofrece.
const otroModo = computed<Modo | null>(() => null);
// Con su giro (`?giro=`), el selector se oculta tras un resumen con «Cambiar».
const selectorVisible = ref(giroDeLlegada.value === null);
const selectorPerfil = ref<HTMLSelectElement | null>(null);
// La modalidad con que nacerá el negocio: la del giro elegido.
const modoDelGiro = computed<Modo | null>(() =>
  perfilNegocio.value === "" ? null : modoDePerfil(perfilNegocio.value),
);

async function enfocarSelector(): Promise<void> {
  await nextTick();
  selectorPerfil.value?.focus();
}
// «Cambiar» (en el paso 1 o en el resumen del paso 3): vuelve el selector, con los
// giros de la modalidad del elegido.
function cambiarGiro(): void {
  paso.value = 1;
  selectorVisible.value = true;
  void enfocarSelector();
}
// «¿Das clases? Ver giros de clases»: los giros de la otra modalidad, sin perder lo
// escrito. El giro elegido, si era de la que se deja, se suelta: ya no está en la lista.
function verOtraModalidad(): void {
  if (otroModo.value === null) {
    return;
  }
  modoVisible.value = otroModo.value;
  if (modoDelGiro.value !== null && modoDelGiro.value !== modoVisible.value) {
    perfilNegocio.value = "";
  }
  void enfocarSelector();
}
// Otro `?modo=` o `?giro=` con la vista abierta (la ruta la reutiliza).
watch(giroDeLlegada, (giro) => {
  selectorVisible.value = giro === null;
  if (giro !== null) {
    perfilNegocio.value = giro;
  }
});
// «Barbería · Citas 1 a 1»: lo que se va a crear, en el paso 1 cuando el giro llegó
// elegido y en el resumen del paso 3.
const resumenGiro = computed(() =>
  [
    t(`registro.perfiles.${perfilNegocio.value}`),
    modoDelGiro.value === null
      ? null
      : t(`modalidadNegocio.nombres.${modoDelGiro.value}`),
  ]
    .filter((parte) => parte !== null)
    .join(" · "),
);

// La intención de llegada (`mode_intent` y, con `?giro=`, `business_profile_intent`)
// va en los eventos del registro, para compararla con lo que de verdad se crea
// (`mode` y `business_profile` en tenant_created).
function conIntencion(datos: AnalyticsProperties = {}): AnalyticsProperties {
  return {
    ...datos,
    ...(modoDeLlegada.value === null
      ? {}
      : { mode_intent: modoDeLlegada.value }),
    ...(giroDeLlegada.value === null
      ? {}
      : { business_profile_intent: giroDeLlegada.value }),
  };
}

// Paso 2: quién eres.
const contactoNombre = ref("");
const contactoSegundoNombre = ref("");
const contactoPrimerApellido = ref("");
const contactoSegundoApellido = ref("");

// Paso 3: contacto. El WhatsApp con su lada («+57 3001234567»): la del país del
// negocio, salvo que elija otra.
const whatsapp = ref("");
const partesWhatsApp = computed(() =>
  separarTelefono(whatsapp.value, ladaDe(pais.value) ?? "52"),
);
const whatsappPais = computed(() => partesWhatsApp.value.lada);
const whatsappNumero = computed(() => partesWhatsApp.value.numero);
// Otro país: si el número aún tiene la lada del anterior, pasa a la del nuevo.
watch(pais, (nuevo, anterior) => {
  const ladaNueva = ladaDe(nuevo);
  if (
    ladaNueva !== null &&
    whatsappNumero.value !== "" &&
    whatsappPais.value === ladaDe(anterior)
  ) {
    whatsapp.value = unirTelefono(ladaNueva, whatsappNumero.value);
  }
});
const contactoEmail = ref("");
const aceptaTerminos = ref(false);

// WhatsApp con los dueños (ADR 0070): si la plataforma lo tiene encendido, el dueño
// puede aceptar avisos por WhatsApp; para eso confirma su número con un código. Es
// opcional: sin marcarlo, el registro sigue igual.
const whatsappDisponible = ref(false);
const quiereWhatsApp = ref(false);
const codigoEnviado = ref(false);
const codigo = ref("");
// Comprobante de que el número se verificó (se manda al crear el negocio).
const verificacion = ref<string | null>(null);
const enviandoCodigo = ref(false);
const errorWhatsApp = ref<string | null>(null);
const esperaReenvio = ref(0);
let cuentaRegresiva: ReturnType<typeof setInterval> | undefined;

function reiniciarWhatsApp(): void {
  codigoEnviado.value = false;
  codigo.value = "";
  verificacion.value = null;
  errorWhatsApp.value = null;
}

async function enviarCodigo(): Promise<void> {
  enviandoCodigo.value = true;
  errorWhatsApp.value = null;
  try {
    await api.post("/api/v1/registro/whatsapp/codigo", {
      contacto_whatsapp_pais: whatsappPais.value,
      contacto_telefono: whatsappNumero.value,
      recaptcha_token: await tokenRecaptcha("registro"),
    });
    codigoEnviado.value = true;
    codigo.value = "";
    esperaReenvio.value = 60;
    clearInterval(cuentaRegresiva);
    cuentaRegresiva = setInterval(() => {
      esperaReenvio.value = Math.max(0, esperaReenvio.value - 1);
      if (esperaReenvio.value === 0) {
        clearInterval(cuentaRegresiva);
      }
    }, 1000);
  } catch (e) {
    errorWhatsApp.value = mensajeDeError(e);
  } finally {
    enviandoCodigo.value = false;
  }
}

async function verificarCodigo(): Promise<void> {
  errorWhatsApp.value = null;
  try {
    const { data } = await api.post<{ data: { verificacion: string } }>(
      "/api/v1/registro/whatsapp/verificar",
      {
        contacto_whatsapp_pais: whatsappPais.value,
        contacto_telefono: whatsappNumero.value,
        codigo: codigo.value,
      },
    );
    verificacion.value = data.data.verificacion;
    clearInterval(cuentaRegresiva);
  } catch (e) {
    errorWhatsApp.value = mensajeDeError(e);
  }
}

// Con los 6 dígitos se verifica solo.
watch(codigo, (valor) => {
  if (/^\d{6}$/.test(valor) && verificacion.value === null) {
    void verificarCodigo();
  }
});
// Otro número (u otra lada), o ya no lo quiere: la verificación ya no aplica.
watch([whatsappPais, whatsappNumero], reiniciarWhatsApp);
watch(quiereWhatsApp, (quiere) => {
  if (!quiere) {
    reiniciarWhatsApp();
  }
});

// Documentos legales (aviso de privacidad y términos) que edita el superadmin y se
// muestran al dar clic en el enlace correspondiente del registro.
// Se manda de vuelta qué versión se leyó: si cambió mientras tanto, la API pide
// revisarla de nuevo.
interface VersionLegal {
  version: number;
  vigente_desde: string;
}
const legales = ref<{
  aviso_privacidad: string | null;
  terminos: string | null;
  versiones?: {
    aviso_privacidad: VersionLegal | null;
    terminos: VersionLegal | null;
  };
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
const slugDisponible = ref<boolean | null>(null);
const verificandoSlug = ref(false);
const enviando = ref(false);
const error = ref<string | null>(null);

const creado = ref<{ slug: string; nombre: string } | null>(null);
const activacion = ref<{ email: string; token: string } | null>(null);
const correo = ref("");
const reenviando = ref(false);
const reenviado = ref(false);

// Igual que la API: la dirección mide a lo más 40 (con ella se nombra la base del
// negocio y el subdominio); con un nombre largo se recorta, sin guion al final.
const LARGO_MAXIMO_SLUG = 40;

function aSlug(valor: string): string {
  return valor
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "") // quita acentos/diacríticos (é→e, ñ→n…)
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, LARGO_MAXIMO_SLUG)
    .replace(/-+$/, "");
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
const whatsappValido = computed(() => /^\d{7,15}$/.test(whatsappNumero.value));

const paso1Valido = computed(
  () =>
    nombre.value.trim() !== "" &&
    perfilNegocio.value !== "" &&
    ladaDe(pais.value) !== null &&
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
  () =>
    whatsappValido.value &&
    emailValido.value &&
    aceptaTerminos.value &&
    // Si quiere avisos por WhatsApp, primero confirma su número.
    (!quiereWhatsApp.value || verificacion.value !== null),
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
    trackEvent(
      "studio_registration_step_completed",
      conIntencion({ step: paso.value }),
    );
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
    const recaptchaToken = await tokenRecaptcha("registro");
    const { data } = await api.post<{
      data: {
        estudio: { slug: string; nombre: string };
        activacion: { email: string; token: string } | null;
      };
    }>(
      "/api/v1/registro",
      {
        nombre: nombre.value,
        // Si no se personaliza, se OMITE el slug (undefined → axios no lo manda) y el
        // backend genera la dirección única del nombre; si se personalizó, va la elegida.
        slug: personalizarSlug.value ? slug.value : undefined,
        recaptcha_token: recaptchaToken,
        sitio_web: honeypot.value,
        perfil_negocio: perfilNegocio.value,
        producto,
        // El país y la zona horaria con que nace (la zona, la del navegador si es de
        // ese país); se corrigen después en «País, moneda y zona horaria».
        pais: pais.value,
        zona_horaria: zonaSugerida(pais.value) ?? undefined,
        contacto_nombre: contactoNombre.value,
        contacto_segundo_nombre: contactoSegundoNombre.value || null,
        contacto_primer_apellido: contactoPrimerApellido.value,
        contacto_segundo_apellido: contactoSegundoApellido.value || null,
        contacto_whatsapp_pais: whatsappPais.value,
        contacto_telefono: whatsappNumero.value,
        contacto_email: contactoEmail.value,
        whatsapp_verificacion:
          quiereWhatsApp.value && verificacion.value !== null
            ? verificacion.value
            : undefined,
        acepta_terminos: aceptaTerminos.value,
        aviso_version: legales.value.versiones?.aviso_privacidad?.version,
        terminos_version: legales.value.versiones?.terminos?.version,
      },
      // Crear el negocio aprovisiona su base completa: puede tardar más que el
      // límite general de 30 s. Se espera más que el servidor (60 s): si la web se
      // rindiera antes, el negocio quedaría creado y el dueño lo intentaría de nuevo.
      { timeout: TIEMPO_REGISTRO_MS },
    );
    creado.value = data.data.estudio;
    activacion.value = data.data.activacion;
    correo.value = contactoEmail.value;
    trackEvent(
      "tenant_created",
      conIntencion({
        business_profile: perfilNegocio.value,
        mode: modoDelGiro.value,
      }),
    );
  } catch (e) {
    error.value = mensajeDeError(e);
    trackEvent(
      "studio_registration_failed",
      conIntencion({ step: paso.value }),
    );
    // Regresa al paso del dato que rechazó el servidor (p. ej. el slug que alguien
    // ocupó antes, o el correo); sin un dato rechazado (sin red, legales), se queda.
    paso.value = pasoDelError(e);
  } finally {
    enviando.value = false;
  }
}

// Paso 1: el negocio; paso 2: el nombre de quien registra; lo demás (WhatsApp,
// correo, legales), el 3.
const CAMPOS_PASO_1 = [
  "nombre",
  "slug",
  "perfil_negocio",
  "pais",
  "zona_horaria",
];
const CAMPOS_PASO_2 = [
  "contacto_nombre",
  "contacto_segundo_nombre",
  "contacto_primer_apellido",
  "contacto_segundo_apellido",
];
function pasoDelError(e: unknown): number {
  const pasos = camposConError(e).map((campo) =>
    CAMPOS_PASO_1.includes(campo) ? 1 : CAMPOS_PASO_2.includes(campo) ? 2 : 3,
  );
  return pasos.length > 0 ? Math.min(...pasos) : paso.value;
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
  trackEvent("studio_registration_started", conIntencion());
  void api
    .get<{
      data: {
        aviso_privacidad: string | null;
        terminos: string | null;
        versiones?: {
          aviso_privacidad: VersionLegal | null;
          terminos: VersionLegal | null;
        };
      };
    }>("/api/v1/legales")
    .then(({ data }) => {
      legales.value = data.data;
    })
    .catch(() => {
      // Sin legales configurados por el superadmin: los enlaces mostrarán un aviso.
    });
  void api
    .get<{ data: { disponible?: boolean } }>("/api/v1/registro/whatsapp")
    .then(({ data }) => {
      whatsappDisponible.value = data.data.disponible === true;
    })
    .catch(() => {
      whatsappDisponible.value = false;
    });
});

onBeforeUnmount(() => clearInterval(cuentaRegresiva));
</script>

<template>
  <!-- Producto que aún no recibe registros (TurnoUno antes de su lanzamiento). -->
  <section
    v-if="!registroAbierto"
    class="registro mx-auto max-w-3xl px-4 sm:px-6 py-8 sm:py-10"
    data-prueba="registro-cerrado"
  >
    <ListaInteresados :producto="producto" />
  </section>
  <section v-else class="registro mx-auto max-w-7xl px-4 sm:px-6 py-8 sm:py-10">
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
            <span class="registro-mini-cita-icono"
              ><IconoNav nombre="hecho" :tam="20"
            /></span>
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
              <!-- Llegó con su giro (?giro=): ya está elegido; «Cambiar» abre el
                   selector con los giros de su modalidad. -->
              <div
                v-if="!selectorVisible"
                class="registro-resumen registro-giro"
                data-prueba="giro-elegido"
              >
                <p class="min-w-0">
                  <span class="registro-giro-etiqueta">{{
                    $t("registro.perfilElegido")
                  }}</span
                  >{{ " "
                  }}<strong class="registro-giro-valor">{{
                    resumenGiro
                  }}</strong>
                </p>
                <button
                  type="button"
                  class="tu-enlace registro-resumen-cambiar shrink-0 text-sm"
                  data-prueba="cambiar-giro"
                  :aria-label="$t('modalidadNegocio.resumen.cambiarEtiqueta')"
                  @click="cambiarGiro"
                >
                  {{ $t("modalidadNegocio.resumen.cambiar") }}
                </button>
              </div>
              <div v-else>
                <label class="tu-label" for="perfil">{{
                  $t("registro.perfil")
                }}</label>
                <select
                  id="perfil"
                  ref="selectorPerfil"
                  v-model="perfilNegocio"
                  class="tu-input"
                  aria-describedby="perfil-ayuda"
                  required
                >
                  <option value="" disabled>
                    {{ $t("registro.perfilPh") }}
                  </option>
                  <optgroup
                    v-for="m in modalidades"
                    :key="m"
                    :label="$t(`modalidadNegocio.nombres.${m}`)"
                  >
                    <option
                      v-for="perfil in girosDe(m)"
                      :key="perfil"
                      :value="perfil"
                    >
                      {{ $t(`registro.perfiles.${perfil}`) }}
                    </option>
                  </optgroup>
                </select>
                <p
                  id="perfil-ayuda"
                  class="mt-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("registro.perfilAyuda") }}
                </p>
                <!-- Solo se ven los giros de una modalidad: este enlace muestra los
                     de la otra, sin perder lo escrito. -->
                <p
                  v-if="otroModo !== null"
                  class="registro-otra-modalidad text-xs"
                  data-prueba="otra-modalidad"
                >
                  <span>{{
                    $t(
                      `modalidadNegocio.registroModo.otra.${otroModo}.pregunta`,
                    )
                  }}</span>
                  <button
                    type="button"
                    class="tu-enlace registro-otra-modalidad-enlace"
                    @click="verOtraModalidad"
                  >
                    {{
                      $t(
                        `modalidadNegocio.registroModo.otra.${otroModo}.enlace`,
                      )
                    }}
                  </button>
                </p>
              </div>
              <div>
                <label class="tu-label" for="pais">{{
                  $t("registro.pais")
                }}</label>
                <SelectorBuscable
                  id="pais"
                  v-model="pais"
                  :opciones="paises"
                  required
                  data-prueba="pais-negocio"
                />
                <p
                  class="mt-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("registro.paisAyuda") }}
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
                      >.{{ PRODUCTOS[producto].dominio }}</span
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
                  :maxlength="LARGO_MAXIMO_SLUG"
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
              <!-- Lo que se va a crear: la modalidad ya no la cambia el negocio. -->
              <div class="registro-resumen" data-prueba="resumen-modalidad">
                <div class="min-w-0">
                  <p class="registro-resumen-etiqueta">
                    {{ $t("modalidadNegocio.resumen.etiqueta") }}
                  </p>
                  <p class="registro-resumen-valor">{{ resumenGiro }}</p>
                  <p class="registro-resumen-ayuda">
                    {{ $t("modalidadNegocio.resumen.ayuda") }}
                  </p>
                </div>
                <button
                  type="button"
                  class="tu-enlace registro-resumen-cambiar shrink-0 text-sm"
                  :aria-label="$t('modalidadNegocio.resumen.cambiarEtiqueta')"
                  :disabled="enviando"
                  @click="cambiarGiro"
                >
                  {{ $t("modalidadNegocio.resumen.cambiar") }}
                </button>
              </div>
              <div>
                <label class="tu-label" for="cwhatsapp">{{
                  $t("registro.whatsapp")
                }}</label>
                <CampoCelular
                  id="cwhatsapp"
                  v-model="whatsapp"
                  :pais="pais"
                  maxlength="20"
                  autocomplete="tel-national"
                  :placeholder="$t('registro.whatsappNumeroPh')"
                  required
                />
                <p
                  v-if="!whatsappDisponible"
                  class="mt-1 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("registro.whatsappAyuda") }}
                </p>
                <!-- Avisos por WhatsApp: opcional, confirma el número con un código. -->
                <div v-else class="mt-2 text-sm" data-prueba="whatsapp-dueno">
                  <p
                    v-if="verificacion"
                    class="registro-wa-ok"
                    data-prueba="whatsapp-verificado"
                  >
                    <span class="registro-wa-punto" aria-hidden="true"></span>
                    {{ $t("registro.whatsappVerificado") }}
                  </p>
                  <label v-else class="flex items-center gap-2 cursor-pointer">
                    <input
                      v-model="quiereWhatsApp"
                      type="checkbox"
                      data-prueba="quiere-whatsapp"
                    />
                    <span :style="{ color: 'var(--texto-suave)' }">{{
                      $t("registro.whatsappAvisos")
                    }}</span>
                  </label>
                  <div v-if="quiereWhatsApp && !verificacion" class="mt-2 pl-6">
                    <template v-if="!codigoEnviado">
                      <p
                        class="text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{ $t("registro.whatsappExplica") }}
                      </p>
                      <button
                        type="button"
                        class="tu-enlace mt-1 text-sm"
                        data-prueba="enviar-codigo"
                        :disabled="!whatsappValido || enviandoCodigo"
                        @click="enviarCodigo"
                      >
                        {{ $t("registro.whatsappEnviar") }}
                      </button>
                    </template>
                    <template v-else>
                      <label class="tu-label" for="wa-codigo">{{
                        $t("registro.whatsappCodigo")
                      }}</label>
                      <input
                        id="wa-codigo"
                        v-model="codigo"
                        class="tu-input registro-codigo"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                      />
                      <p
                        class="mt-1 text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        <span v-if="esperaReenvio > 0">{{
                          $t("registro.whatsappReenviarEn", {
                            s: esperaReenvio,
                          })
                        }}</span>
                        <button
                          v-else
                          type="button"
                          class="tu-enlace"
                          :disabled="enviandoCodigo"
                          @click="enviarCodigo"
                        >
                          {{ $t("registro.whatsappReenviar") }}
                        </button>
                      </p>
                    </template>
                    <p
                      v-if="errorWhatsApp"
                      class="mt-1 text-xs"
                      role="alert"
                      style="color: var(--error)"
                    >
                      {{ errorWhatsApp }}
                    </p>
                  </div>
                </div>
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

            <p
              v-if="error"
              class="text-sm"
              role="alert"
              style="color: var(--error)"
            >
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
            <IconoNav nombre="cerrar" :tam="18" />
          </button>
        </div>
        <div
          class="mt-3 overflow-y-auto text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          <DocumentoLegalContenido
            v-if="legalAbierto === 'aviso'"
            :contenido="legales.aviso_privacidad"
          />
          <DocumentoLegalContenido
            v-else
            tipo="terminos"
            :contenido="legales.terminos"
          />
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
.registro-wa-ok {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}
.registro-wa-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--exito);
}
/* Paso 1 con el giro ya elegido: el mismo recuadro que el resumen del paso 3. */
.registro-giro {
  align-items: center;
}
.registro-giro-etiqueta {
  color: var(--texto-suave);
}
.registro-giro-valor {
  font-weight: 500;
  overflow-wrap: anywhere;
}
/* Enlace discreto a los giros de la otra modalidad. */
.registro-otra-modalidad {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  column-gap: 0.35rem;
  color: var(--texto-suave);
}
.registro-otra-modalidad-enlace {
  min-height: 44px;
  padding-inline: 0.25rem;
  text-decoration: underline;
  text-underline-offset: 0.2em;
}
.registro-resumen {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--fondo);
}
.registro-resumen-etiqueta {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.registro-resumen-valor {
  margin-top: 0.15rem;
  font-weight: 500;
  overflow-wrap: anywhere;
}
.registro-resumen-cambiar {
  min-height: 44px;
  padding-inline: 0.25rem;
  text-decoration: underline;
  text-underline-offset: 0.2em;
}
.registro-resumen-ayuda {
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.registro-codigo {
  max-width: 9rem;
  letter-spacing: 0.3em;
  font-variant-numeric: tabular-nums;
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
.registro-resumen-cambiar:focus-visible,
.registro-otra-modalidad-enlace:focus-visible,
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
