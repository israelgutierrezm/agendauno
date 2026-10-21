<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import EscanerPase from "@/components/EscanerPase.vue";
import HechoCon from "@/components/HechoCon.vue";
import { api, mensajeDeError } from "@/lib/api";
import { slugDeContexto } from "@/lib/tenant";

/**
 * Kiosco de llegada (ADR 0183): la tableta de recepción. Quien llega muestra el QR de
 * su pase a la cámara (o al lector USB) y queda su llegada y su asistencia. La tableta
 * se activa una vez con el enlace que da el panel (`#llave=…`); la llave se queda en
 * esta tableta y solo sirve para registrar llegadas. Solo dice el primer nombre.
 */
interface Llegada {
  resultado:
    | "llegada"
    | "ya_registrada"
    | "bienvenida"
    | "temprano"
    | "sin_clase"
    | "pase_invalido";
  nombre: string | null;
  clase: { nombre: string | null; hora: string; lugar: number | null } | null;
  mensaje: string;
}

const { t } = useI18n();
const route = useRoute();
const slug = computed(
  () => (route.params.slug as string | undefined) ?? slugDeContexto() ?? "",
);
const base = computed(() => `/api/v1/app/${slug.value}`);
const clave = computed(() => `kiosco:${slug.value}`);

const llave = ref<string | null>(null);
const negocio = ref<{ negocio: string | null; sucursal: string | null } | null>(
  null,
);
const error = ref<string | null>(null);
const codigo = ref("");
const entrada = ref<HTMLInputElement | null>(null);
const ultima = ref<Llegada | null>(null);
const enviando = ref(false);
const camara = ref(false);
let temporizador: ReturnType<typeof setTimeout> | undefined;
let bloqueo: { release: () => Promise<void> } | null = null;

function leerLlave(): string | null {
  try {
    return localStorage.getItem(clave.value);
  } catch {
    return null;
  }
}
function guardarLlave(valor: string | null): void {
  try {
    if (valor === null) {
      localStorage.removeItem(clave.value);
    } else {
      localStorage.setItem(clave.value, valor);
    }
  } catch {
    // Sin almacenamiento: dura mientras la página esté abierta.
  }
}

const encabezados = computed(() => ({ "X-Kiosco": llave.value ?? "" }));

async function cargar(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{
      data: { negocio: string | null; sucursal: string | null };
    }>(`${base.value}/kiosco`, { headers: encabezados.value });
    negocio.value = data.data;
    await nextTick();
    entrada.value?.focus();
  } catch (e) {
    // Dada de baja o mal copiada: hay que volver a activarla.
    negocio.value = null;
    llave.value = null;
    guardarLlave(null);
    error.value = mensajeDeError(e);
  }
}

async function registrar(valor: string): Promise<void> {
  const texto = valor.trim();
  codigo.value = "";
  if (texto === "" || enviando.value) {
    return;
  }
  enviando.value = true;
  try {
    const { data } = await api.post<{ data: Llegada }>(
      `${base.value}/kiosco/llegadas`,
      { codigo: texto },
      { headers: encabezados.value },
    );
    ultima.value = data.data;
  } catch (e) {
    ultima.value = {
      resultado: "sin_clase",
      nombre: null,
      clase: null,
      mensaje: mensajeDeError(e),
    };
  } finally {
    enviando.value = false;
    clearTimeout(temporizador);
    // Unos segundos para leerlo y la tableta vuelve a esperar al siguiente.
    temporizador = setTimeout(() => {
      ultima.value = null;
      entrada.value?.focus();
    }, 6000);
  }
}

function porCamara(valor: string): void {
  camara.value = false;
  void registrar(valor);
}

const exito = computed(
  () =>
    ultima.value !== null &&
    ["llegada", "ya_registrada", "bienvenida"].includes(ultima.value.resultado),
);
const saludo = computed(() => {
  const u = ultima.value;
  if (!u) {
    return "";
  }
  const nombre = u.nombre?.split(" ")[0] ?? "";
  return exito.value
    ? nombre
      ? t("kiosco.hola", { nombre })
      : t("kiosco.bienvenida")
    : t("kiosco.revisa");
});

onMounted(async () => {
  // Se activa con el enlace del panel: la llave llega en el fragmento (no viaja al
  // servidor ni queda en el historial).
  const fragmento = new URLSearchParams(window.location.hash.slice(1));
  const nueva = fragmento.get("llave");
  if (nueva) {
    guardarLlave(nueva);
    history.replaceState(null, "", window.location.pathname);
  }
  llave.value = nueva ?? leerLlave();
  if (llave.value) {
    await cargar();
  }
  try {
    const wl = (
      navigator as unknown as {
        wakeLock?: {
          request: (t: string) => Promise<{ release: () => Promise<void> }>;
        };
      }
    ).wakeLock;
    bloqueo = (await wl?.request("screen")) ?? null;
  } catch {
    // La pantalla puede apagarse sola; no es un error.
  }
});
onBeforeUnmount(() => {
  clearTimeout(temporizador);
  void bloqueo?.release().catch(() => undefined);
});
</script>

<template>
  <main class="kiosco" data-prueba="kiosco">
    <section v-if="!negocio" class="kiosco-centro">
      <h1>{{ $t("kiosco.sinActivar") }}</h1>
      <p>{{ $t("kiosco.sinActivarAyuda") }}</p>
      <p v-if="error" class="kiosco-error" role="alert">{{ error }}</p>
    </section>

    <section v-else class="kiosco-centro">
      <p class="kiosco-sede">
        {{ negocio.negocio
        }}<template v-if="negocio.sucursal"> · {{ negocio.sucursal }}</template>
      </p>

      <div
        v-if="ultima"
        class="kiosco-resultado"
        :class="exito ? 'kiosco-ok' : 'kiosco-aviso'"
        role="status"
        aria-live="assertive"
        data-prueba="kiosco-resultado"
      >
        <h1>{{ saludo }}</h1>
        <p v-if="ultima.clase" class="kiosco-clase">
          {{ ultima.clase.nombre }} · {{ ultima.clase.hora
          }}<template v-if="ultima.clase.lugar">
            · {{ $t("kiosco.lugar", { n: ultima.clase.lugar }) }}</template
          >
        </p>
        <p>{{ ultima.mensaje }}</p>
      </div>

      <template v-else>
        <h1>{{ $t("kiosco.titulo") }}</h1>
        <p>{{ $t("kiosco.instrucciones") }}</p>
        <button
          type="button"
          class="tu-btn tu-btn-primario kiosco-boton"
          data-prueba="kiosco-camara"
          @click="camara = true"
        >
          {{ $t("kiosco.camara") }}
        </button>
      </template>

      <!-- El lector USB escribe aquí el código y da Enter. -->
      <form class="kiosco-lector" @submit.prevent="registrar(codigo)">
        <label class="sr-only" for="kiosco-codigo">{{
          $t("kiosco.codigo")
        }}</label>
        <input
          id="kiosco-codigo"
          ref="entrada"
          v-model="codigo"
          autocomplete="off"
          inputmode="none"
          data-prueba="kiosco-codigo"
          :placeholder="$t('kiosco.codigo')"
          :disabled="enviando"
        />
      </form>
    </section>

    <HechoCon class="kiosco-pie" />

    <EscanerPase
      :abierto="camara"
      @codigo="porCamara"
      @cerrar="camara = false"
    />
  </main>
</template>

<style scoped>
.kiosco {
  min-height: 100dvh;
  display: grid;
  place-items: center;
  padding: 2rem 16px;
  background: var(--fondo);
  color: var(--texto);
  text-align: center;
}
.kiosco-centro {
  width: min(40rem, 100%);
  display: grid;
  gap: 1rem;
  justify-items: center;
}
.kiosco h1 {
  font-size: clamp(1.75rem, 5vw, 2.75rem);
  font-weight: 700;
  line-height: 1.1;
}
.kiosco-sede {
  color: var(--texto-suave);
}
.kiosco-boton {
  font-size: 1.15rem;
  padding: 0.9rem 1.75rem;
}
.kiosco-resultado {
  width: 100%;
  display: grid;
  gap: 0.5rem;
  padding: 2rem 1.5rem;
  border-radius: 1.25rem;
  border: 1px solid var(--borde);
  background: var(--superficie);
}
.kiosco-ok {
  border-color: var(--exito);
}
.kiosco-aviso {
  border-color: var(--aviso);
}
.kiosco-clase {
  font-size: 1.2rem;
  font-weight: 600;
}
.kiosco-lector input {
  width: min(22rem, 100%);
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.6rem;
  background: var(--superficie);
  color: var(--texto);
  text-align: center;
}
.kiosco-pie {
  align-self: end;
}
.kiosco-error {
  color: var(--error);
}
</style>
