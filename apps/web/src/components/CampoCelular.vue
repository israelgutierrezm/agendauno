<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import {
  esFrecuente,
  ladaDe,
  PAIS_POR_OMISION,
  paisDeLada,
  paisesOrdenados,
  separarInternacional,
  separarTelefono,
  unirTelefono,
} from "@/lib/ladas";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Un celular (o teléfono) con su lada (ADR 0103): la lada se elige de la lista
 * completa de países y el número se escribe aparte. El valor es «+<lada> <número>»
 * (p. ej. «+57 3001234567»), o vacío sin número. La lada inicial es la del negocio
 * (`pais` o `lada`; sin ellos, la de la sesión). Un valor guardado sin «+» se muestra
 * con la lada del negocio y no se reescribe mientras no se toque.
 *
 * Los atributos (`id`, `required`, `placeholder`, `data-prueba`…) van al número, así
 * funciona el `<label for>` de quien lo usa.
 */
defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    // País (ISO) o lada del negocio: de dónde se supone el número sin «+».
    pais?: string | null;
    lada?: string | null;
    deshabilitado?: boolean;
  }>(),
  { pais: null, lada: null, deshabilitado: false },
);
const modelo = defineModel<string>({ default: "" });

const { t } = useI18n();

// En páginas públicas no hay sesión (ni a veces Pinia): ahí rige lo que se pasa.
let sesion: { pais?: string; lada?: string } | null = null;
try {
  sesion = useSesionTenantStore();
} catch {
  sesion = null;
}

/** El país que se supone para un número sin lada. */
const paisPorOmision = computed(() => {
  if (props.pais && ladaDe(props.pais)) {
    return props.pais.toUpperCase();
  }
  const delNegocio =
    typeof sesion?.pais === "string" && ladaDe(sesion.pais)
      ? sesion.pais.toUpperCase()
      : PAIS_POR_OMISION;
  const ladaNegocio =
    props.lada ?? (typeof sesion?.lada === "string" ? sesion.lada : null);
  if (ladaNegocio) {
    return paisDeLada(ladaNegocio, delNegocio) ?? delNegocio;
  }
  return delNegocio;
});

const paisElegido = ref(paisPorOmision.value);
const numero = ref("");
const ladaActual = computed(() => ladaDe(paisElegido.value) ?? "");
// Quien eligió otra lada ya no la ve cambiar sola.
let ladaTocada = false;
// Lo último que se mandó: al volver por el modelo no se vuelve a leer.
let ultimo: string | null = null;

const paises = paisesOrdenados();
const frecuentes = paises.filter((p) => esFrecuente(p.codigo));
const demas = paises.filter((p) => !esFrecuente(p.codigo));

function leer(valor: string | null | undefined): void {
  const texto = (valor ?? "").trim();
  // Vacío desde fuera (un formulario nuevo): otra vez la lada del negocio.
  if (texto === "") {
    numero.value = "";
    ladaTocada = false;
    paisElegido.value = paisPorOmision.value;
    return;
  }
  const partes = separarTelefono(texto, ladaDe(paisPorOmision.value) ?? "");
  paisElegido.value =
    paisDeLada(partes.lada, paisElegido.value) ??
    paisDeLada(partes.lada, paisPorOmision.value) ??
    paisElegido.value;
  numero.value = partes.numero;
}
watch(
  modelo,
  (valor) => {
    if (valor !== ultimo) {
      leer(valor);
    }
  },
  { immediate: true },
);
// La lada del negocio llegó después (o cambió): si aún no hay número, se adopta.
watch(paisPorOmision, (nuevo) => {
  if (!ladaTocada && numero.value === "") {
    paisElegido.value = nuevo;
  }
});

function emitir(): void {
  const texto = numero.value.trim();
  if (texto.startsWith("+")) {
    // A medio escribir la lada («+», «+5»): todavía no hay número. Con más dígitos y
    // sin una lada de la lista, ya no la habrá: va tal cual se escribió.
    ultimo = texto.replace(/\D/g, "").length > 3 ? texto : "";
  } else {
    ultimo = unirTelefono(ladaActual.value, numero.value);
  }
  modelo.value = ultimo;
}
function alElegirPais(): void {
  ladaTocada = true;
  if (numero.value !== "") {
    emitir();
  }
}
function alEscribir(e: Event): void {
  const campo = e.target as HTMLInputElement;
  // Pegó o escribió el número completo («+57 300…»): de ahí sale la lada, en cuanto
  // se reconoce una seguida del número. Mientras («+», «+57»), se deja como va.
  const partes = separarInternacional(campo.value);
  if (partes !== null && partes.numero !== "") {
    paisElegido.value =
      paisDeLada(partes.lada, paisElegido.value) ?? paisElegido.value;
    ladaTocada = true;
    numero.value = partes.numero;
    campo.value = partes.numero;
  } else {
    numero.value = campo.value;
  }
  emitir();
}
</script>

<template>
  <div class="cc">
    <div
      class="tu-input cc-lada"
      :class="{ 'cc-deshabilitado': deshabilitado }"
    >
      <span aria-hidden="true" class="cc-texto" data-prueba="lada-celular"
        >{{ paisElegido }} +{{ ladaActual }}</span
      >
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="cc-flecha"
      >
        <path d="M6 9l6 6 6-6" />
      </svg>
      <select
        v-model="paisElegido"
        class="cc-select"
        :aria-label="t('campoCelular.lada')"
        data-prueba="elegir-lada"
        :disabled="deshabilitado"
        @change="alElegirPais"
      >
        <optgroup :label="t('campoCelular.frecuentes')">
          <option v-for="p in frecuentes" :key="p.codigo" :value="p.codigo">
            {{ p.nombre }} +{{ p.lada }}
          </option>
        </optgroup>
        <optgroup :label="t('campoCelular.todos')">
          <option v-for="p in demas" :key="p.codigo" :value="p.codigo">
            {{ p.nombre }} +{{ p.lada }}
          </option>
        </optgroup>
      </select>
    </div>
    <input
      v-bind="$attrs"
      :value="numero"
      class="tu-input cc-numero"
      type="tel"
      inputmode="tel"
      :disabled="deshabilitado"
      @input="alEscribir"
    />
  </div>
</template>

<style scoped>
.cc {
  display: flex;
  gap: 0.5rem;
  min-width: 0;
}
/* La lada se ve corta («MX +52»); al tocarla se abre la lista con los nombres. */
.cc-lada {
  position: relative;
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  gap: 0.3rem;
  width: 6.5rem;
  padding-right: 0.6rem;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.cc-lada:focus-within {
  border-color: var(--acento);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--acento) 18%, transparent);
}
.cc-deshabilitado {
  opacity: 0.6;
}
.cc-flecha {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
  color: var(--texto-suave);
}
.cc-select {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  cursor: pointer;
  /* 16 px: el teléfono no acerca la página al abrirla. */
  font-size: 16px;
}
.cc-numero {
  flex: 1 1 auto;
  min-width: 0;
}
</style>
