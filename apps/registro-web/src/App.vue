<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, RouterView, useRoute, useRouter } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import LogoTurnoUno from "@/components/LogoTurnoUno.vue";
import NavArbol from "@/components/NavArbol.vue";
import AppToaster from "@/components/AppToaster.vue";
import type { MenuItem, NavEstado } from "@/components/nav";
import { trackEvent } from "@/lib/analytics";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { ACENTOS, useTemaStore } from "@/stores/tema";

const { t } = useI18n();
const tema = useTemaStore();
const sesion = useSesionTenantStore();
const router = useRouter();
const route = useRoute();

tema.inicializar();

// Menu lateral en ARBOL (3 niveles): grupos por area -> secciones -> sub-secciones.
const MENU: MenuItem[] = [
  {
    clave: "mi-cuenta",
    etiqueta: "nav.miCuenta",
    icono: "mi-cuenta",
    ruta: "mi-cuenta",
    soloMiembro: true,
  },
  {
    clave: "panel",
    etiqueta: "nav.panel",
    icono: "panel",
    ruta: "panel",
    permiso: "facturacion.ver",
  },
  {
    clave: "personas",
    etiqueta: "nav.grupos.personas",
    icono: "personas",
    hijos: [
      {
        clave: "miembros",
        etiqueta: "nav.miembros",
        icono: "miembros",
        ruta: "miembros",
        permiso: "miembros.ver",
        termino: "miembro",
      },
      {
        clave: "retencion",
        etiqueta: "nav.retencion",
        icono: "reportes",
        ruta: "retencion",
        permiso: "miembros.ver",
      },
      {
        clave: "instructores",
        etiqueta: "nav.instructores",
        icono: "instructores",
        ruta: "instructores",
        permiso: "agenda.gestionar",
        termino: "instructor",
      },
      {
        clave: "usuarios",
        etiqueta: "nav.usuarios",
        icono: "usuarios",
        ruta: "usuarios",
        permiso: "usuarios.gestionar",
      },
    ],
  },
  {
    clave: "operacion",
    etiqueta: "nav.grupos.operacion",
    icono: "operacion",
    hijos: [
      {
        clave: "agenda",
        etiqueta: "nav.agenda",
        icono: "agenda",
        ruta: "agenda",
        permiso: "agenda.ver",
      },
      {
        clave: "horarios",
        etiqueta: "nav.horarios",
        icono: "agenda",
        ruta: "horarios",
        permiso: "agenda.ver",
      },
      {
        clave: "oportunidades",
        etiqueta: "nav.oportunidades",
        icono: "oportunidades",
        ruta: "oportunidades",
        permiso: "reservas.gestionar",
        // Llenar lugares libres de una clase: no aplica a citas 1 a 1.
        modalidad: "clases",
      },
      {
        clave: "tareas",
        etiqueta: "nav.tareas",
        icono: "tareas",
        ruta: "tareas",
        permiso: "tareas.ver",
      },
      {
        clave: "recepcion",
        etiqueta: "nav.recepcion",
        icono: "recepcion",
        ruta: "recepcion",
        permiso: "agenda.ver",
      },
      {
        clave: "grupos",
        etiqueta: "nav.cursos",
        icono: "grupos",
        ruta: "grupos",
        permiso: "agenda.ver",
        flag: "grupos",
      },
      {
        clave: "recursos",
        etiqueta: "nav.recursos",
        icono: "recursos",
        ruta: "recursos",
        permiso: "agenda.ver",
      },
    ],
  },
  {
    clave: "comercio",
    etiqueta: "nav.grupos.comercio",
    icono: "comercio",
    hijos: [
      {
        clave: "ventas",
        etiqueta: "nav.ventas",
        icono: "ventas",
        ruta: "ventas",
        permiso: "productos.ver",
      },
      {
        clave: "catalogo",
        etiqueta: "nav.catalogo",
        icono: "ventas",
        ruta: "catalogo",
        permiso: "catalogo.ver",
      },
      {
        clave: "pos",
        etiqueta: "nav.pos",
        icono: "pos",
        ruta: "pos",
        permiso: "inventario.ver",
      },
      {
        clave: "promociones",
        etiqueta: "nav.promociones",
        icono: "promociones",
        ruta: "promociones",
        permiso: "promociones.gestionar",
      },
      {
        clave: "comunicaciones",
        etiqueta: "nav.comunicaciones",
        icono: "reportes",
        ruta: "comunicaciones",
        permiso: "comunicaciones.gestionar",
      },
      {
        clave: "lealtad",
        etiqueta: "nav.lealtad",
        icono: "lealtad",
        ruta: "lealtad",
        permiso: "lealtad.ver",
      },
      {
        clave: "facturas",
        etiqueta: "nav.facturas",
        icono: "facturas",
        ruta: "facturas",
        permiso: "ordenes.ver",
      },
      {
        clave: "cobranza",
        etiqueta: "nav.cobranza",
        icono: "facturas",
        ruta: "cobranza",
        permiso: "facturacion.ver",
      },
      {
        clave: "reportes",
        etiqueta: "nav.reportes",
        icono: "reportes",
        ruta: "reportes",
        permiso: "facturacion.ver",
      },
      {
        clave: "pasarelas",
        etiqueta: "nav.pasarelas",
        icono: "pasarelas",
        ruta: "pasarelas",
        permiso: "pagos.configurar",
      },
    ],
  },
  {
    clave: "contenido",
    etiqueta: "nav.grupos.contenido",
    icono: "contenido",
    hijos: [
      {
        clave: "documentos",
        etiqueta: "nav.documentos",
        icono: "documentos",
        ruta: "documentos",
        permiso: "documentos.subir",
      },
      {
        clave: "formularios",
        etiqueta: "nav.formularios",
        icono: "formularios",
        ruta: "formularios",
        permiso: "formularios.responder",
      },
    ],
  },
  {
    clave: "ajustes",
    etiqueta: "nav.grupos.ajustes",
    icono: "ajustes",
    hijos: [
      {
        clave: "nomina",
        etiqueta: "nav.nomina",
        icono: "nomina",
        ruta: "nomina",
        permiso: "estudio.gestionar",
      },
      {
        clave: "integraciones",
        etiqueta: "nav.integraciones",
        icono: "integraciones",
        ruta: "integraciones",
        permiso: "integraciones.configurar",
      },
      {
        clave: "datos-fiscales",
        etiqueta: "nav.datosFiscales",
        icono: "datosFiscales",
        ruta: "datos-fiscales",
        permiso: "estudio.gestionar",
      },
      {
        clave: "sedes",
        etiqueta: "nav.sedes",
        icono: "recursos",
        ruta: "sedes",
        permiso: "sucursales.ver",
      },
      {
        clave: "configuracion",
        etiqueta: "nav.configuracion",
        icono: "configuracion",
        ruta: "configuracion",
        permiso: "estudio.gestionar",
      },
    ],
  },
  // Pagos del SaaS (lo que el dueño le paga a AgendaUno): separado de la operación/venta del estudio.
  {
    clave: "suscripcion",
    etiqueta: "nav.grupos.suscripcion",
    icono: "renta",
    hijos: [
      {
        clave: "renta",
        etiqueta: "nav.renta",
        icono: "renta",
        ruta: "renta",
        permiso: "facturacion.ver",
      },
      {
        clave: "padron",
        etiqueta: "nav.padron",
        icono: "facturas",
        ruta: "padron",
        permiso: "facturacion.ver",
      },
    ],
  },
];

function visible(item: MenuItem): boolean {
  if (item.soloMiembro === true) {
    return sesion.usuario?.rol === "miembro";
  }
  // Congruencia por modalidad y perfil: solo lo que le sirve a este negocio.
  if (item.modalidad !== undefined && item.modalidad !== sesion.modalidad) {
    return false;
  }
  if (
    item.flag !== undefined &&
    sesion.estudio?.perfil_config?.flags[item.flag] !== true
  ) {
    return false;
  }
  return item.permiso === undefined || sesion.puede(item.permiso);
}

// Filtra el arbol por permisos: una hoja se ve si pasa su permiso; un grupo, si le
// queda al menos un hijo visible.
function filtrar(items: MenuItem[]): MenuItem[] {
  return items
    .map((item): MenuItem | null => {
      if (item.hijos !== undefined) {
        const hijos = filtrar(item.hijos);
        return hijos.length > 0 ? { ...item, hijos } : null;
      }
      if (!visible(item)) {
        return null;
      }
      // Rótulo con la terminología del perfil (p. ej. Barberos en vez de Instructores).
      return item.termino !== undefined
        ? { ...item, texto: plural(sesion.terminologia[item.termino]) }
        : item;
    })
    .filter((item): item is MenuItem => item !== null);
}

const menuVisible = computed(() => filtrar(MENU));

const hogar = computed(() => ({ name: sesion.rutaInicio }));

const puedeConfigurar = computed(() => sesion.puede("estudio.gestionar"));

// Aplana las hojas para localizar la seccion activa (titulo + icono del encabezado).
function hojas(items: MenuItem[]): MenuItem[] {
  return items.flatMap((i) => (i.hijos !== undefined ? hojas(i.hijos) : [i]));
}
const enlaceActivo = computed(
  () => hojas(MENU).find((e) => e.ruta === route.name) ?? null,
);
const tituloSeccion = computed(() =>
  enlaceActivo.value !== null
    ? t(enlaceActivo.value.etiqueta)
    : (sesion.estudio?.nombre ?? ""),
);

// ---- Estado del arbol (expandir/colapsar grupos) ----
const abiertos = ref<Set<string>>(new Set());

// La clave del grupo que contiene la ruta activa (para auto-expandirlo).
function grupoDe(ruta: string, items: MenuItem[] = MENU): string | null {
  for (const item of items) {
    if (item.hijos !== undefined) {
      if (
        item.hijos.some((h) => h.ruta === ruta) ||
        grupoDe(ruta, item.hijos) !== null
      ) {
        return item.clave;
      }
    }
  }
  return null;
}

function alternar(clave: string): void {
  // En modo rail, expandir un grupo primero descompacta la barra.
  if (compacto.value) {
    compacto.value = false;
  }
  const s = new Set(abiertos.value);
  if (s.has(clave)) {
    s.delete(clave);
  } else {
    s.add(clave);
  }
  abiertos.value = s;
}

function abrirGrupoActivo(): void {
  const g = grupoDe(String(route.name));
  if (g !== null) {
    abiertos.value = new Set(abiertos.value).add(g);
  }
}

watch(() => route.name, abrirGrupoActivo);

const navEstado: NavEstado = {
  abiertos,
  compacto: computed(() => compactoEfectivo.value),
  alternar,
  cerrarCajon: () => {
    menuLateral.value = false;
  },
};
provide("navEstado", navEstado);

function siglas(nombre: string | undefined): string {
  return (
    (nombre ?? "")
      .split(" ")
      .slice(0, 2)
      .map((p) => p.charAt(0))
      .join("")
      .toUpperCase() || "·"
  );
}
const inicialesEstudio = computed(() => siglas(sesion.estudio?.nombre));
const inicialesUsuario = computed(() => siglas(sesion.usuario?.nombre));

// Estado de la interfaz.
const menuLateral = ref(false); // cajón en móvil
const compacto = ref(false); // barra contraída (solo iconos) en escritorio
const menuPerfil = ref(false);
const menuApariencia = ref(false);

// La contracción solo aplica en escritorio; con el cajón abierto se ve completo.
const compactoEfectivo = computed(() => compacto.value && !menuLateral.value);

function alternarCompacto(): void {
  compacto.value = !compacto.value;
  try {
    localStorage.setItem("tu.barra.compacta", compacto.value ? "1" : "0");
  } catch {
    // Ignora si no hay localStorage.
  }
}

async function salir(): Promise<void> {
  menuPerfil.value = false;
  await sesion.cerrarSesion();
  void router.push({ name: "inicio" });
}

onMounted(() => {
  try {
    compacto.value = localStorage.getItem("tu.barra.compacta") === "1";
  } catch {
    // Ignora.
  }
  abrirGrupoActivo();
});
</script>

<template>
  <!-- ===================== APP AUTENTICADA (panel con barra lateral) ===================== -->
  <div v-if="sesion.autenticado" class="flex min-h-screen">
    <!-- Velo del cajón (móvil) -->
    <div
      v-if="menuLateral"
      class="fixed inset-0 z-40 bg-black/50 lg:hidden"
      @click="menuLateral = false"
    />

    <!-- Barra lateral (oscura) -->
    <aside
      class="fixed lg:sticky top-0 z-50 h-screen w-64 shrink-0 flex flex-col transition-all duration-200"
      :class="[
        compacto ? 'lg:w-16' : 'lg:w-64',
        menuLateral ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
      ]"
      :style="{ background: 'var(--barra)', color: 'var(--barra-texto)' }"
    >
      <!-- Marca -->
      <RouterLink
        :to="hogar"
        class="flex items-center gap-3 h-16 px-4 shrink-0 border-b"
        :style="{ borderColor: 'var(--barra-borde)' }"
        @click="menuLateral = false"
      >
        <img
          v-if="sesion.estudio?.logo_url"
          :src="sesion.estudio.logo_url"
          :alt="sesion.estudio?.nombre"
          class="h-9 w-9 rounded-xl object-cover shrink-0"
        />
        <span
          v-else
          class="h-9 w-9 rounded-xl inline-flex items-center justify-center text-white text-sm font-bold shrink-0"
          :style="{ background: 'var(--barra-activo)' }"
          aria-hidden="true"
          >{{ inicialesEstudio }}</span
        >
        <span v-show="!compactoEfectivo" class="min-w-0">
          <span class="block text-sm font-semibold text-white truncate">{{
            sesion.estudio?.nombre ?? $t("marca")
          }}</span>
          <span class="block text-[11px] opacity-60 truncate">{{
            $t("marca")
          }}</span>
        </span>
      </RouterLink>

      <!-- Navegación (árbol de 3 niveles: grupos por área → secciones → sub-secciones) -->
      <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-1">
        <NavArbol :items="menuVisible" :nivel="1" />
      </nav>

      <!-- Contraer (solo escritorio) -->
      <div
        class="hidden lg:block p-3 border-t"
        :style="{ borderColor: 'var(--barra-borde)' }"
      >
        <button
          type="button"
          class="tu-side-link w-full"
          :class="{ 'lg:justify-center': compactoEfectivo }"
          :title="$t('nav.contraer')"
          @click="alternarCompacto"
        >
          <span class="shrink-0" aria-hidden="true">{{
            compacto ? "»" : "«"
          }}</span>
          <span v-show="!compactoEfectivo">{{ $t("nav.contraer") }}</span>
        </button>
      </div>
    </aside>

    <!-- Columna principal -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Encabezado (claro, translúcido) -->
      <header
        class="sticky top-0 z-30 h-16 flex items-center justify-between gap-3 px-4 sm:px-6 border-b backdrop-blur"
        :style="{
          background: 'color-mix(in srgb, var(--superficie) 85%, transparent)',
          borderColor: 'var(--borde)',
        }"
      >
        <div class="flex items-center gap-3 min-w-0">
          <button
            type="button"
            class="lg:hidden tu-icono-btn"
            :aria-label="$t('nav.menu')"
            @click="menuLateral = true"
          >
            <span aria-hidden="true">☰</span>
          </button>
          <span
            v-if="enlaceActivo"
            class="hidden sm:inline-flex h-9 w-9 rounded-xl items-center justify-center shrink-0"
            :style="{
              background: 'var(--primario-suave)',
              color: 'var(--primario-fuerte)',
            }"
            aria-hidden="true"
          >
            <IconoNav :nombre="enlaceActivo.icono ?? 'punto'" :tam="18" />
          </span>
          <h1 class="text-base font-semibold truncate">{{ tituloSeccion }}</h1>
        </div>

        <div class="flex items-center gap-1 sm:gap-2 shrink-0">
          <!-- Apariencia -->
          <div class="relative">
            <button
              type="button"
              class="tu-icono-btn"
              :aria-label="$t('tema.apariencia')"
              :title="$t('tema.apariencia')"
              @click="
                menuApariencia = !menuApariencia;
                menuPerfil = false;
              "
            >
              <IconoNav nombre="configuracion" :tam="18" />
            </button>
            <div
              v-if="menuApariencia"
              class="absolute right-0 top-full mt-2 w-64 tu-card p-4 z-50 space-y-4"
            >
              <div>
                <p class="tu-label">{{ $t("tema.modo") }}</p>
                <div class="grid grid-cols-2 gap-2">
                  <button
                    type="button"
                    class="tu-btn"
                    :class="
                      !tema.esOscuro ? 'tu-btn-primario' : 'tu-btn-fantasma'
                    "
                    @click="tema.esOscuro && tema.alternarModo()"
                  >
                    ☀ {{ $t("tema.claroCorto") }}
                  </button>
                  <button
                    type="button"
                    class="tu-btn"
                    :class="
                      tema.esOscuro ? 'tu-btn-primario' : 'tu-btn-fantasma'
                    "
                    @click="!tema.esOscuro && tema.alternarModo()"
                  >
                    ☾ {{ $t("tema.oscuroCorto") }}
                  </button>
                </div>
              </div>

              <div>
                <p class="tu-label">{{ $t("tema.densidad") }}</p>
                <div class="flex items-center gap-2">
                  <button
                    type="button"
                    class="tu-btn tu-btn-fantasma flex-1"
                    @click="tema.ajustarDensidad(-1)"
                  >
                    −
                  </button>
                  <span
                    class="text-sm"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ tema.densidad }}</span
                  >
                  <button
                    type="button"
                    class="tu-btn tu-btn-fantasma flex-1"
                    @click="tema.ajustarDensidad(1)"
                  >
                    +
                  </button>
                </div>
              </div>

              <div>
                <p class="tu-label">{{ $t("tema.acento") }}</p>
                <div class="flex items-center gap-2">
                  <button
                    v-for="a in ACENTOS"
                    :key="a.nombre"
                    type="button"
                    class="h-6 w-6 rounded-full border-2 transition-transform hover:scale-110"
                    :style="{
                      background: a.hex ?? '#0071e3',
                      borderColor:
                        tema.acento === a.hex ? 'var(--texto)' : 'transparent',
                    }"
                    :title="a.nombre"
                    :aria-label="a.nombre"
                    @click="tema.fijarAcento(a.hex)"
                  />
                </div>
              </div>
            </div>
          </div>

          <!-- Perfil -->
          <div class="relative">
            <button
              type="button"
              class="flex items-center gap-2 rounded-xl p-1 pr-2 hover:bg-black/5"
              :aria-expanded="menuPerfil"
              @click="
                menuPerfil = !menuPerfil;
                menuApariencia = false;
              "
            >
              <span
                class="h-8 w-8 rounded-lg inline-flex items-center justify-center text-white text-xs font-bold shrink-0"
                :style="{ background: 'var(--primario)' }"
                aria-hidden="true"
                >{{ inicialesUsuario }}</span
              >
              <span class="hidden sm:block text-left leading-tight">
                <span
                  class="block text-[13px] font-semibold truncate max-w-[8rem]"
                  >{{ sesion.usuario?.nombre }}</span
                >
                <span
                  class="block text-[11px] truncate"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ sesion.usuario?.rol }}</span
                >
              </span>
            </button>
            <div
              v-if="menuPerfil"
              class="absolute right-0 top-full mt-2 w-60 tu-card p-1.5 z-50"
            >
              <div
                class="px-2.5 py-2 border-b"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <p class="text-sm font-semibold truncate">
                  {{ sesion.usuario?.nombre }}
                </p>
                <p
                  class="text-xs truncate"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ sesion.usuario?.email }}
                </p>
              </div>
              <RouterLink
                v-if="puedeConfigurar"
                class="tu-menu-item mt-1"
                :to="{ name: 'configuracion' }"
                @click="menuPerfil = false"
              >
                <IconoNav nombre="configuracion" :tam="16" />
                {{ $t("nav.configuracion") }}
              </RouterLink>
              <button
                type="button"
                class="tu-menu-item"
                style="color: var(--error)"
                @click="salir"
              >
                <span aria-hidden="true">⎋</span>
                {{ $t("panel.salir") }}
              </button>
            </div>
          </div>
        </div>
      </header>

      <main class="flex-1" :style="{ background: 'var(--fondo)' }">
        <div class="mx-auto max-w-7xl md:px-4 lg:px-8">
          <RouterView />
        </div>
      </main>
    </div>

    <!-- Cierra menús flotantes al hacer clic fuera -->
    <div
      v-if="menuPerfil || menuApariencia"
      class="fixed inset-0 z-20"
      @click="
        menuPerfil = false;
        menuApariencia = false;
      "
    />
  </div>

  <!-- ===================== APP PÚBLICA ===================== -->
  <div v-else class="min-h-screen flex flex-col">
    <header class="tu-public-nav">
      <div
        class="mx-auto max-w-6xl px-4 h-14 flex items-center justify-between gap-4"
      >
        <RouterLink
          :to="{ name: 'inicio' }"
          class="flex items-center gap-2 font-bold text-lg shrink-0"
        >
          <LogoTurnoUno :tam="32" />
          <span class="hidden sm:inline">{{ $t("marca") }}</span>
        </RouterLink>

        <nav class="flex items-center gap-1 sm:gap-2 shrink-0">
          <RouterLink
            class="tu-btn tu-btn-fantasma tu-public-community"
            :to="{ name: 'directorio' }"
            @click="
              trackEvent('marketing_cta_clicked', {
                placement: 'navigation',
                destination: 'directory',
              })
            "
          >
            {{ $t("nav.directorio") }}
          </RouterLink>
          <RouterLink class="tu-btn tu-btn-fantasma" :to="{ name: 'entrar' }">
            {{ $t("nav.entrar") }}
          </RouterLink>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma px-2.5"
            :title="tema.esOscuro ? $t('tema.claro') : $t('tema.oscuro')"
            :aria-label="tema.esOscuro ? $t('tema.claro') : $t('tema.oscuro')"
            @click="tema.alternarModo()"
          >
            <svg
              v-if="tema.esOscuro"
              aria-hidden="true"
              width="17"
              height="17"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
            >
              <circle cx="12" cy="12" r="4" />
              <path
                d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"
              />
            </svg>
            <svg
              v-else
              aria-hidden="true"
              width="17"
              height="17"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path
                d="M20.5 15.2A8.5 8.5 0 0 1 8.8 3.5 8.5 8.5 0 1 0 20.5 15.2Z"
              />
            </svg>
          </button>
          <RouterLink
            class="tu-btn tu-btn-primario"
            :to="{ name: 'registro' }"
            @click="
              trackEvent('marketing_cta_clicked', {
                placement: 'navigation',
                destination: 'register',
              })
            "
          >
            {{ $t("nav.registrar") }}
          </RouterLink>
        </nav>
      </div>
    </header>

    <main class="flex-1">
      <RouterView />
    </main>

    <footer
      class="text-sm"
      :style="{ color: 'var(--texto-suave)', background: 'var(--fondo)' }"
    >
      <div
        class="mx-auto max-w-6xl px-4 py-8 flex items-center justify-between"
      >
        <span>© {{ new Date().getFullYear() }} {{ $t("marca") }}</span>
        <RouterLink class="tu-enlace" :to="{ name: 'directorio' }">{{
          $t("nav.directorio")
        }}</RouterLink>
      </div>
    </footer>
  </div>

  <!-- Notificaciones flotantes (toasts), montadas una sola vez para toda la app. -->
  <AppToaster />
</template>

<style>
/* Navegación pública discreta: fija, translúcida y sin elevación artificial. */
.tu-public-nav {
  position: sticky;
  top: 0;
  z-index: 40;
  background: color-mix(in srgb, var(--superficie) 84%, transparent);
  border-bottom: 1px solid color-mix(in srgb, var(--borde) 70%, transparent);
  backdrop-filter: saturate(180%) blur(20px);
  -webkit-backdrop-filter: saturate(180%) blur(20px);
}
.tu-public-logo {
  border-radius: 10px;
  background: var(--texto);
  color: var(--superficie);
  letter-spacing: -0.04em;
}
.tu-public-community {
  display: none;
}
@media (min-width: 640px) {
  .tu-public-community {
    display: inline-flex;
  }
}

/* Enlaces del sidebar OSCURO (estilo panel). */
.tu-side-link {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.6rem 0.7rem;
  border-radius: 0.75rem;
  font-weight: 500;
  font-size: 0.9rem;
  color: var(--barra-texto);
  text-decoration: none;
  cursor: pointer;
  white-space: nowrap;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.tu-side-link:hover {
  background: var(--barra-suave);
  color: #ffffff;
}
.tu-side-link.router-link-active {
  background: var(--barra-activo);
  color: #ffffff;
  font-weight: 600;
}

/* Botón de icono del encabezado. */
.tu-icono-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.25rem;
  width: 2.25rem;
  border-radius: 980px;
  color: var(--texto-suave);
  cursor: pointer;
}
.tu-icono-btn:hover {
  background: var(--superficie-2);
  color: var(--texto);
}

/* Elementos de menús flotantes (perfil, apariencia) sobre fondo claro. */
.tu-menu-item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.5rem 0.6rem;
  border-radius: 0.6rem;
  font-weight: 600;
  font-size: 0.88rem;
  color: var(--texto);
  text-decoration: none;
  cursor: pointer;
  text-align: left;
}
.tu-menu-item:hover {
  background: var(--superficie-2);
}
</style>
