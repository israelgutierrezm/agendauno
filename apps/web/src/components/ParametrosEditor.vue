<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { useCambiosPendientes } from "@/lib/cambiosPendientes";
import { usePestanaEnUrl } from "@/lib/filtrosEnUrl";
import { coincideBusqueda, normalizar } from "@/lib/menu";
import type { Parametro } from "@/lib/parametros";
import {
  adaptarTexto,
  conTerminosActuales,
  type TerminosNegocio,
} from "@/lib/terminologia";

/**
 * Editor de parámetros configurables (ADR 0042), por categoría (el `grupo` de cada
 * uno): se ve una a la vez, elegida en un índice a la izquierda (con poco ancho, una
 * lista), y queda en la dirección (`?categoria=`). «Buscar un parámetro» filtra por
 * nombre en todas. Lo escrito en una categoría se conserva al pasar a otra y «Guardar»
 * envía lo cambiado en cualquiera. En el negocio, un campo vacío usa el valor de la
 * plataforma (se muestra como referencia); en la plataforma, un campo vacío usa el
 * inicial. Solo se envía lo que cambió. Como solo se monta la categoría elegida (el
 * navegador ya no valida las demás), antes de enviar se revisa aquí lo cambiado en
 * todas: si algo no cuadra, se abre su categoría, se dice junto al campo y no se envía.
 */
const props = defineProps<{
  parametros: Parametro[];
  modo: "negocio" | "plataforma";
  guardando?: boolean;
  // Lo que el API rechazó, por campo: la clave del parámetro sola
  // (`reservas.minutos_para_pagar`, ParametrosTenant::validar); `valores.<clave>` se
  // acepta solo por compatibilidad. Se dice junto a su campo.
  errores?: Record<string, string>;
}>();

const emit = defineEmits<{
  guardar: [valores: Record<string, number | null>];
}>();

const { t } = useI18n();

// Los textos del catálogo (API) usan las palabras base del panel (ADR 0203): «alumno»
// por el público y «clase» por lo que se reserva. En el negocio se leen con sus
// términos (socio, cliente, paciente…; cita, sesión…). En la plataforma, según a qué
// negocios aplica cada parámetro: los de clases como están, los de citas con «cliente»
// y «cita», y los de ambos con «cliente» y «clase o cita». Los que son solo de la
// plataforma ya están escritos para el superadmin: no se tocan.
const TERMINOS_PLATAFORMA: Record<
  "clases" | "citas" | "ambas",
  TerminosNegocio
> = {
  clases: { sesion: "Clase", miembro: "Alumno", instructor: "Instructor" },
  citas: { sesion: "Cita", miembro: "Cliente", instructor: "Profesional" },
  ambas: {
    sesion: "Clase o cita",
    sesiones: "Clases o citas",
    miembro: "Cliente",
    instructor: "Instructor",
  },
};
function adaptar(texto: string, p: Parametro): string {
  if (props.modo === "negocio") {
    return conTerminosActuales(texto);
  }
  return p.por_negocio === false
    ? texto
    : adaptarTexto(texto, TERMINOS_PLATAFORMA[p.modalidad ?? "ambas"]);
}

// Lo que hay en cada campo ("" = usa el de referencia). Un campo numérico puede traer
// número: v-model en un `type="number"` lo convierte.
const campos = ref<Record<string, string>>({});
// Lo que la revisión antes de enviar encontró mal, por clave (se limpia al corregirlo).
const erroresLocales = ref<Record<string, string>>({});
function reiniciar(): void {
  erroresLocales.value = {};
  campos.value = Object.fromEntries(
    props.parametros.map((p) => [
      p.clave,
      p.valor === null ? "" : String(p.valor),
    ]),
  );
}
watch(() => props.parametros, reiniciar, { immediate: true });

interface Categoria {
  clave: string;
  nombre: string;
  parametros: Parametro[];
}

// La clave de una categoría en la dirección: su nombre (el del catálogo, igual en todos
// los negocios) sin acentos ni signos.
function claveDe(grupo: string): string {
  return normalizar(grupo)
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

// Las categorías en el orden del catálogo.
const categorias = computed<Categoria[]>(() => {
  const mapa = new Map<string, Categoria>();
  for (const p of props.parametros) {
    const clave = claveDe(p.grupo);
    const c = mapa.get(clave) ?? {
      clave,
      nombre: adaptar(p.grupo, p),
      parametros: [],
    };
    c.parametros.push(p);
    mapa.set(clave, c);
  }
  return [...mapa.values()];
});
// Con una sola categoría no hace falta índice ni buscador.
const conIndice = computed(() => categorias.value.length > 1);

const categoria = usePestanaEnUrl(() => categorias.value.map((c) => c.clave), {
  clave: "categoria",
});

const busqueda = ref("");
const buscando = computed(() => busqueda.value.trim() !== "");

// Lo que se ve: la categoría elegida o, al buscar, lo que coincide en todas (por el
// nombre del parámetro o el de su categoría).
const visibles = computed<Categoria[]>(() => {
  if (!buscando.value) {
    return categorias.value.filter((c) => c.clave === categoria.value);
  }
  return categorias.value
    .map((c) => ({
      ...c,
      parametros: coincideBusqueda(c.nombre, busqueda.value)
        ? c.parametros
        : c.parametros.filter((p) =>
            coincideBusqueda(adaptar(p.etiqueta, p), busqueda.value),
          ),
    }))
    .filter((c) => c.parametros.length > 0);
});
const totalResultados = computed(() =>
  visibles.value.reduce((n, c) => n + c.parametros.length, 0),
);

function elegir(clave: string): void {
  busqueda.value = "";
  categoria.value = clave;
}

function referencia(p: Parametro): number {
  return (props.modo === "negocio" ? p.plataforma : p.defecto) ?? 0;
}
function texto(p: Parametro, valor: number): string {
  const etiqueta = p.etiquetas?.[String(valor)];
  if (etiqueta) {
    return adaptar(etiqueta, p);
  }
  if (p.tipo === "si_no") {
    return valor === 1 ? t("parametrosConfig.si") : t("parametrosConfig.no");
  }
  return p.unidad ? `${valor} ${adaptar(p.unidad, p)}` : String(valor);
}

const cambios = computed(() => {
  const out: Record<string, number | null> = {};
  for (const p of props.parametros) {
    const actual = campos.value[p.clave] ?? "";
    const nuevo = actual === "" ? null : Number(actual);
    if (nuevo !== p.valor) {
      out[p.clave] = nuevo;
    }
  }
  return out;
});
const totalCambios = computed(() => Object.keys(cambios.value).length);
const hayCambios = computed(() => totalCambios.value > 0);
// Cuántos cambios sin guardar hay en cada categoría (se ven en el índice).
const cambiosPorCategoria = computed(() => {
  const cuenta: Record<string, number> = {};
  for (const p of props.parametros) {
    if (p.clave in cambios.value) {
      const clave = claveDe(p.grupo);
      cuenta[clave] = (cuenta[clave] ?? 0) + 1;
    }
  }
  return cuenta;
});
// Salir con cambios sin guardar pregunta antes (ADR 0166). Cambiar de categoría no
// sale de la pantalla: no pregunta y no pierde lo escrito.
useCambiosPendientes(() => hayCambios.value);

// Lo que el API rechazó de un parámetro (ver la prop `errores`).
function errorDelApi(p: Parametro): string | undefined {
  return props.errores?.[p.clave] ?? props.errores?.[`valores.${p.clave}`];
}
// El error junto al campo: el de la revisión local (más reciente) o el del API.
function errorDe(p: Parametro): string | undefined {
  return erroresLocales.value[p.clave] ?? errorDelApi(p);
}
// Las categorías con algún campo con error: el índice y la lista las marcan, para
// que un error en otra categoría no quede escondido.
const categoriasConError = computed(() => {
  const claves = new Set<string>();
  for (const p of props.parametros) {
    if (errorDe(p) !== undefined) {
      claves.add(claveDe(p.grupo));
    }
  }
  return claves;
});
// El texto de cada categoría en la lista de poco ancho (con sus avisos).
function textoDeCategoria(c: Categoria): string {
  const partes = [c.nombre];
  if (categoriasConError.value.has(c.clave)) {
    partes.push(t("parametrosConfig.conErrores"));
  }
  const pendientes = cambiosPorCategoria.value[c.clave];
  if (pendientes) {
    partes.push(t("parametrosConfig.sinGuardar", pendientes));
  }
  return partes.join(" · ");
}

// Lo mismo que revisa el API (ParametrosTenant::validar): entero, una de las opciones
// y dentro del mínimo y el máximo. Vacío vale (usa el de referencia).
function revisar(
  p: Parametro,
  valor: string | number | undefined,
): string | undefined {
  const escrito = String(valor ?? "").trim();
  if (escrito === "") {
    return undefined;
  }
  const n = Number(escrito);
  if (!Number.isInteger(n)) {
    return t("parametrosConfig.errorEntero");
  }
  const opciones = p.opciones ?? [];
  if (opciones.length > 0 && !opciones.includes(n)) {
    return t("parametrosConfig.errorOpcion");
  }
  if (n < p.minimo || n > p.maximo) {
    return t("parametrosConfig.errorRango", {
      minimo: texto(p, p.minimo),
      maximo: texto(p, p.maximo),
    });
  }
  return undefined;
}
// Al corregir un campo marcado, su error local se va (o cambia, si sigue mal).
watch(
  campos,
  (actuales) => {
    if (Object.keys(erroresLocales.value).length === 0) {
      return;
    }
    const siguen: Record<string, string> = {};
    for (const p of props.parametros) {
      if (!(p.clave in erroresLocales.value)) {
        continue;
      }
      const error = revisar(p, actuales[p.clave]);
      if (error !== undefined) {
        siguen[p.clave] = error;
      }
    }
    erroresLocales.value = siguen;
  },
  { deep: true },
);

function seVe(clave: string): boolean {
  return visibles.value.some((c) =>
    c.parametros.some((p) => p.clave === clave),
  );
}

const formulario = ref<HTMLFormElement | null>(null);

// «Guardar»: revisa lo cambiado en todas las categorías antes de enviarlo. Si algo no
// cuadra no se envía: se abre la categoría del primer campo mal, con su error junto a
// él, y el foco va a ese campo.
async function enviar(): Promise<void> {
  const mal: Record<string, string> = {};
  let primero: Parametro | undefined;
  for (const p of props.parametros) {
    if (!(p.clave in cambios.value)) {
      continue;
    }
    const error = revisar(p, campos.value[p.clave]);
    if (error !== undefined) {
      mal[p.clave] = error;
      if (primero === undefined) {
        primero = p;
      }
    }
  }
  erroresLocales.value = mal;
  if (primero === undefined) {
    emit("guardar", cambios.value);
    return;
  }
  if (!seVe(primero.clave)) {
    elegir(claveDe(primero.grupo));
  }
  await nextTick();
  const campo = formulario.value?.querySelector<HTMLElement>(
    `[id="par-${primero.clave}"]`,
  );
  campo?.focus();
}

// Lo rechazado puede estar en otra categoría: se abre la del primer campo con error.
watch(
  () => props.errores,
  () => {
    const conError = props.parametros.find((p) => errorDelApi(p) !== undefined);
    if (conError !== undefined && !seVe(conError.clave)) {
      elegir(claveDe(conError.grupo));
    }
  },
);
</script>

<template>
  <form ref="formulario" @submit.prevent="enviar">
    <div class="pe">
      <div v-if="conIndice" class="pe-barra">
        <!-- Enter aquí solo busca: no envía el formulario (no guarda) -->
        <label class="tu-buscar">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="busqueda"
            type="search"
            class="tu-input"
            :placeholder="$t('parametrosConfig.buscar')"
            :aria-label="$t('parametrosConfig.buscar')"
            data-prueba="buscar-parametro"
            @keydown.enter.prevent
          />
        </label>
        <!-- Con poco ancho, la categoría en una lista -->
        <select
          class="tu-input pe-lista"
          :value="buscando ? '' : categoria"
          :aria-label="$t('parametrosConfig.categorias')"
          data-prueba="categoria-parametros"
          @change="elegir(($event.target as HTMLSelectElement).value)"
        >
          <option v-if="buscando" value="" disabled>
            {{ $t("parametrosConfig.resultadosBusqueda") }}
          </option>
          <option v-for="c in categorias" :key="c.clave" :value="c.clave">
            {{ textoDeCategoria(c) }}
          </option>
        </select>
      </div>

      <div class="pe-cuerpo" :class="{ 'pe-con-indice': conIndice }">
        <!-- Con ancho de sobra, el índice de categorías a la izquierda -->
        <nav
          v-if="conIndice"
          class="pe-indice"
          :aria-label="$t('parametrosConfig.categorias')"
          data-prueba="categorias-parametros"
        >
          <button
            v-for="c in categorias"
            :key="c.clave"
            type="button"
            class="pe-categoria"
            :aria-pressed="!buscando && categoria === c.clave"
            :data-categoria="c.clave"
            @click="elegir(c.clave)"
          >
            <span class="min-w-0 flex-1">
              <span class="block">{{ c.nombre }}</span>
              <!-- Algún campo con error (API o revisión): punto + texto -->
              <span
                v-if="categoriasConError.has(c.clave)"
                class="tu-estado pe-con-errores"
                :style="{ '--tono': 'var(--error)' }"
                :data-prueba="`con-errores-${c.clave}`"
                >{{ $t("parametrosConfig.conErrores") }}</span
              >
            </span>
            <template v-if="cambiosPorCategoria[c.clave]">
              <span
                class="tu-badge"
                aria-hidden="true"
                :title="
                  $t(
                    'parametrosConfig.sinGuardar',
                    cambiosPorCategoria[c.clave],
                  )
                "
                >{{ cambiosPorCategoria[c.clave] }}</span
              >
              <span class="sr-only">{{
                $t("parametrosConfig.sinGuardar", cambiosPorCategoria[c.clave])
              }}</span>
            </template>
          </button>
        </nav>

        <div class="min-w-0 space-y-6">
          <div
            v-if="buscando"
            class="flex flex-wrap items-center justify-between gap-2"
          >
            <p
              class="text-sm"
              role="status"
              :style="{ color: 'var(--texto-suave)' }"
              :data-prueba="
                totalResultados === 0
                  ? 'sin-resultados-parametros'
                  : 'resultados-parametros'
              "
            >
              {{
                totalResultados === 0
                  ? $t("parametrosConfig.sinResultados", {
                      q: busqueda.trim(),
                    })
                  : $t(
                      "parametrosConfig.resultados",
                      { n: totalResultados, q: busqueda.trim() },
                      totalResultados,
                    )
              }}
            </p>
            <button
              type="button"
              class="tu-btn tu-btn-discreto tu-btn-chico"
              @click="busqueda = ''"
            >
              {{ $t("parametrosConfig.limpiarBusqueda") }}
            </button>
          </div>

          <fieldset v-for="c in visibles" :key="c.clave">
            <legend class="text-sm font-semibold">{{ c.nombre }}</legend>
            <div
              class="mt-2 divide-y divide-[var(--borde)] border-y"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <div
                v-for="p in c.parametros"
                :key="p.clave"
                class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
              >
                <label :for="`par-${p.clave}`" class="min-w-0 flex-1">
                  <span class="block font-medium">{{
                    adaptar(p.etiqueta, p)
                  }}</span>
                  <span
                    class="block text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ p.ayuda ? `${adaptar(p.ayuda, p)} ` : ""
                    }}{{
                      modo === "negocio"
                        ? $t("parametrosConfig.dePlataforma", {
                            valor: texto(p, referencia(p)),
                          })
                        : $t("parametrosConfig.inicial", {
                            valor: texto(p, referencia(p)),
                          })
                    }}</span
                  >
                </label>
                <select
                  v-if="p.tipo === 'si_no'"
                  :id="`par-${p.clave}`"
                  v-model="campos[p.clave]"
                  class="tu-input w-auto"
                  :aria-invalid="errorDe(p) ? 'true' : undefined"
                >
                  <option value="">
                    {{ $t("parametrosConfig.usarReferencia") }}
                  </option>
                  <option value="1">{{ $t("parametrosConfig.si") }}</option>
                  <option value="0">{{ $t("parametrosConfig.no") }}</option>
                </select>
                <select
                  v-else-if="(p.opciones ?? []).length > 0"
                  :id="`par-${p.clave}`"
                  v-model="campos[p.clave]"
                  class="tu-input w-auto"
                  :aria-invalid="errorDe(p) ? 'true' : undefined"
                >
                  <option value="">
                    {{ $t("parametrosConfig.usarReferencia") }}
                  </option>
                  <option v-for="o in p.opciones" :key="o" :value="String(o)">
                    {{ texto(p, o) }}
                  </option>
                </select>
                <span v-else class="flex items-center gap-2 shrink-0">
                  <input
                    :id="`par-${p.clave}`"
                    v-model="campos[p.clave]"
                    type="number"
                    :min="p.minimo"
                    :max="p.maximo"
                    step="1"
                    class="tu-input w-24"
                    :placeholder="String(referencia(p))"
                    :aria-invalid="errorDe(p) ? 'true' : undefined"
                  />
                  <span
                    class="w-10 text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ adaptar(p.unidad, p) }}</span
                  >
                </span>
                <p
                  v-if="errorDe(p)"
                  class="w-full text-xs"
                  role="alert"
                  :style="{ color: 'var(--error)' }"
                  :data-prueba="`error-${p.clave}`"
                >
                  {{ errorDe(p) }}
                </p>
              </div>
            </div>
          </fieldset>
        </div>
      </div>
    </div>

    <!-- «Guardar» siempre a la vista: envía lo cambiado en todas las categorías. -->
    <div class="tu-pie-acciones">
      <span
        class="mr-auto text-sm"
        aria-live="polite"
        :style="{ color: 'var(--texto-suave)' }"
        data-prueba="cambios-sin-guardar"
        >{{
          hayCambios ? $t("parametrosConfig.sinGuardar", totalCambios) : ""
        }}</span
      >
      <button
        v-if="hayCambios"
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="guardando"
        @click="reiniciar"
      >
        {{ $t("parametrosConfig.descartar") }}
      </button>
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        data-prueba="guardar-parametros"
        :disabled="guardando || !hayCambios"
        :aria-busy="guardando || undefined"
      >
        {{ guardando ? $t("comun.guardando") : $t("parametrosConfig.guardar") }}
      </button>
    </div>
  </form>
</template>

<style scoped>
/* El editor decide por su propio ancho (en el negocio va junto al lateral de
   Configuración; en la plataforma, solo junto al menú). */
.pe {
  container-type: inline-size;
}
.pe-barra {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.pe-lista {
  flex: 1 1 12rem;
  width: auto;
}
.pe-indice {
  display: none;
}
/* Con ancho de sobra: el índice como columna fija a la izquierda, con su propio
   desplazamiento si no cabe (no estira la página). */
@container (min-width: 46rem) {
  .pe-lista {
    display: none;
  }
  .pe-con-indice {
    display: grid;
    grid-template-columns: 13.5rem minmax(0, 1fr);
    gap: 1.5rem;
    align-items: start;
  }
  .pe-indice {
    position: sticky;
    top: 5rem;
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    max-height: calc(100vh - 11rem);
    overflow-y: auto;
  }
}
.pe-categoria {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.4rem 0.6rem 0.4rem 0.75rem;
  border: 0;
  border-left: 2px solid transparent;
  border-radius: 0 0.5rem 0.5rem 0;
  background: transparent;
  color: var(--texto-suave);
  font-size: 0.875rem;
  line-height: 1.3;
  text-align: left;
  cursor: pointer;
}
.pe-categoria:hover {
  color: var(--texto);
  background: var(--superficie-2);
}
.pe-categoria[aria-pressed="true"] {
  border-left-color: var(--primario);
  background: var(--primario-suave);
  color: var(--primario);
  font-weight: 600;
}
/* «Con errores» debajo del nombre (en su propia línea, también para el lector de
   pantalla), chico y en el tono de error (también en la categoría elegida). */
.pe-con-errores {
  display: flex;
  margin-top: 0.1rem;
  color: var(--error);
  font-size: 0.75rem;
  font-weight: 500;
}
</style>
