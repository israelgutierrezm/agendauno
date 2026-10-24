<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { confirmarSinGuardar } from "@/lib/cambiosPendientes";
import { ISOTIPO_AGENDAUNO } from "@/lib/marca";

/**
 * El armazón del superadmin (ADR 0126, 0203): el mismo marco que el panel de un
 * negocio, con el menú lateral de sus secciones agrupadas, la barra superior y el
 * contenido en una tarjeta (el ícono y el título de la sección van dentro del contenido,
 * no en la barra). No lleva enlaces a la parte pública (ni «Inicio» ni
 * «Iniciar sesión»): de aquí solo se sale con «Cerrar sesión», para que la sesión de
 * la plataforma no se quede abierta por un clic. La sección elegida es el v-model
 * (va en la dirección, `?pestana=`). Cambiar de sección no cambia de ruta: si la
 * sección abierta tiene cambios sin guardar, se pregunta antes (y antes de salir).
 */
export interface SeccionPlataforma {
  clave: string;
  texto: string;
  icono: string;
  cuenta?: number | string;
}
export interface GrupoPlataforma {
  clave: string;
  texto: string;
  secciones: SeccionPlataforma[];
}

defineProps<{
  grupos: GrupoPlataforma[];
  operador: string | null;
}>();
const seccion = defineModel<string>({ required: true });
const emit = defineEmits<{ salir: [] }>();
const { t } = useI18n();

// Cajón en el teléfono y barra contraída (solo íconos) en escritorio, como el panel.
const menuLateral = ref(false);
const compacto = ref(false);
const compactoEfectivo = computed(() => compacto.value && !menuLateral.value);
const CLAVE_COMPACTA = "tu.plataforma.compacta";
function alternarCompacto(): void {
  compacto.value = !compacto.value;
  try {
    localStorage.setItem(CLAVE_COMPACTA, compacto.value ? "1" : "0");
  } catch {
    // Sin localStorage: solo en esta visita.
  }
}
onMounted(() => {
  try {
    compacto.value = localStorage.getItem(CLAVE_COMPACTA) === "1";
  } catch {
    // Ignora.
  }
});

async function sinPerderCambios(): Promise<boolean> {
  return confirmarSinGuardar(
    t("comun.cambiosSinGuardar"),
    t("comun.salirSinGuardar"),
  );
}
async function elegir(clave: string): Promise<void> {
  menuLateral.value = false;
  if (clave === seccion.value || !(await sinPerderCambios())) {
    return;
  }
  seccion.value = clave;
}
async function salir(): Promise<void> {
  if (await sinPerderCambios()) {
    emit("salir");
  }
}
</script>

<template>
  <div class="tu-marco flex min-h-screen" data-prueba="layout-plataforma">
    <!-- Velo del cajón (teléfono) -->
    <div
      v-if="menuLateral"
      class="fixed inset-0 z-40 bg-black/50 lg:hidden"
      @click="menuLateral = false"
    />

    <aside
      class="tu-sin-imprimir fixed lg:sticky top-0 z-50 h-screen w-64 shrink-0 flex flex-col transition-all duration-200"
      :class="[
        compacto ? 'lg:w-16' : 'lg:w-64',
        menuLateral ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
      ]"
      :style="{ background: 'var(--barra)', color: 'var(--barra-texto)' }"
    >
      <!-- Marca: sin enlace (no lleva a la parte pública). -->
      <div class="flex items-center gap-3 h-16 px-4 shrink-0">
        <img
          :src="ISOTIPO_AGENDAUNO"
          alt=""
          class="h-9 w-9 object-contain shrink-0"
        />
        <span v-show="!compactoEfectivo" class="min-w-0">
          <span
            class="block text-sm font-semibold truncate"
            :style="{ color: 'var(--barra-titulo, #ffffff)' }"
            >{{ t("plataformaAdmin.marca") }}</span
          >
          <span class="block text-[11px] opacity-60 truncate">{{
            t("marca")
          }}</span>
        </span>
      </div>

      <nav
        class="lp-nav tu-lateral-desplazable flex-1 overflow-y-auto px-3 py-4"
        :aria-label="t('plataformaAdmin.menu')"
      >
        <div v-for="(g, i) in grupos" :key="g.clave" class="lp-grupo">
          <p v-if="!compactoEfectivo" class="lp-titulo">{{ g.texto }}</p>
          <hr v-else-if="i > 0" class="lp-separador" />
          <button
            v-for="s in g.secciones"
            :key="s.clave"
            type="button"
            class="tu-side-link"
            :class="{
              'tu-side-activo': seccion === s.clave,
              'lg:justify-center': compactoEfectivo,
            }"
            :aria-current="seccion === s.clave ? 'page' : undefined"
            :title="compactoEfectivo ? s.texto : undefined"
            :aria-label="compactoEfectivo ? s.texto : undefined"
            :data-pestana="s.clave"
            :data-prueba="`seccion-${s.clave}`"
            @click="elegir(s.clave)"
          >
            <IconoNav :nombre="s.icono" :tam="20" class="shrink-0" />
            <template v-if="!compactoEfectivo">
              <span class="min-w-0 flex-1 truncate text-left">{{
                s.texto
              }}</span>
              <span v-if="s.cuenta !== undefined" class="lp-cuenta">{{
                s.cuenta
              }}</span>
            </template>
          </button>
        </div>
      </nav>

      <!-- Contraer o expandir (solo escritorio). -->
      <div
        class="hidden lg:block p-3 border-t"
        :style="{ borderColor: 'var(--barra-borde)' }"
      >
        <button
          type="button"
          class="tu-side-link"
          :class="compactoEfectivo ? 'tu-expandir' : 'w-full'"
          :title="compactoEfectivo ? t('nav.expandir') : t('nav.contraer')"
          :aria-label="compactoEfectivo ? t('nav.expandir') : t('nav.contraer')"
          :aria-expanded="!compactoEfectivo"
          @click="alternarCompacto"
        >
          <IconoNav
            nombre="chevron"
            :tam="18"
            class="shrink-0 transition-transform"
            :class="{ 'rotate-180': !compacto }"
          />
          <span v-show="!compactoEfectivo">{{ t("nav.contraer") }}</span>
        </button>
      </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0">
      <header
        class="tu-sin-imprimir tu-barra-superior sticky top-0 z-30 h-16 flex items-center justify-between gap-3 px-4 sm:px-6"
      >
        <div class="flex flex-1 items-center gap-3 min-w-0">
          <button
            type="button"
            class="tu-icono-btn tu-barra-menu relative"
            :aria-label="t('nav.menu')"
            data-prueba="boton-menu-plataforma"
            @click="menuLateral = true"
          >
            <svg
              class="h-5 w-5"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              aria-hidden="true"
            >
              <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
          </button>
        </div>
        <div class="flex items-center gap-3 shrink-0">
          <span
            v-if="operador"
            class="hidden sm:inline text-sm truncate max-w-[14rem]"
            data-prueba="operador-plataforma"
            >{{ operador }}</span
          >
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            data-prueba="salir-plataforma"
            @click="salir"
          >
            <IconoNav nombre="salir" :tam="16" />
            {{ t("plataformaAdmin.cerrarSesion") }}
          </button>
        </div>
      </header>

      <main class="tu-tarjeta-panel flex-1">
        <div class="tu-lienzo" :class="{ 'tu-lienzo-compacto': compacto }">
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>

<style scoped>
.lp-nav {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.lp-grupo {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.lp-titulo {
  padding: 0 0.7rem 0.3rem;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--barra-texto);
  opacity: 0.7;
}
.lp-separador {
  margin: 0 0.5rem 0.4rem;
  border: 0;
  border-top: 1px solid var(--barra-borde);
}
.lp-cuenta {
  flex-shrink: 0;
  font-size: 0.72rem;
  font-variant-numeric: tabular-nums;
  opacity: 0.75;
}
</style>
