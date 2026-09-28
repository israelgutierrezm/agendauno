import { defineStore } from "pinia";
import { computed, ref } from "vue";

export type Modo = "claro" | "oscuro";
export type Densidad = "compacta" | "normal" | "comoda";

const DENSIDADES: Densidad[] = ["compacta", "normal", "comoda"];

/**
 * Colores de acento disponibles. `hex: null` = el acento por defecto del tema
 * (Azul Uno, distinto en claro/oscuro); los demas sobreescriben `--acento` y de ahi
 * se derivan los tonos "primario" para ambos modos.
 */
export const ACENTOS: { nombre: string; hex: string | null }[] = [
  { nombre: "Azul", hex: null },
  { nombre: "Indigo", hex: "#5e5ce6" },
  { nombre: "Esmeralda", hex: "#059669" },
  { nombre: "Cielo", hex: "#0284c7" },
  { nombre: "Violeta", hex: "#7c3aed" },
  { nombre: "Rosa", hex: "#e11d48" },
  { nombre: "Ambar", hex: "#d97706" },
];

/**
 * Tema (claro/oscuro) y densidad (escala base) del flujo multi-tenant. Ambos se
 * persisten en localStorage y se aplican al elemento <html>: el modo oscuro por
 * clase `.dark`, la densidad por `data-densidad`.
 */
export const useTemaStore = defineStore("tema", () => {
  const modo = ref<Modo>("claro");
  const densidad = ref<Densidad>("normal");
  const acento = ref<string | null>(null);

  const esOscuro = computed(() => modo.value === "oscuro");

  function aplicar(): void {
    const raiz = document.documentElement;
    raiz.classList.toggle("dark", modo.value === "oscuro");
    raiz.dataset.densidad = densidad.value;
    if (acento.value !== null) {
      raiz.style.setProperty("--acento", acento.value);
    } else {
      raiz.style.removeProperty("--acento");
    }
  }

  function inicializar(): void {
    try {
      const m = localStorage.getItem("tu.modo");
      const d = localStorage.getItem("tu.densidad");
      if (m === "claro" || m === "oscuro") {
        modo.value = m;
      } else {
        // Sin preferencia guardada: respeta el sistema.
        modo.value = window.matchMedia?.("(prefers-color-scheme: dark)").matches
          ? "oscuro"
          : "claro";
      }
      if (d === "compacta" || d === "normal" || d === "comoda") {
        densidad.value = d;
      }
      const a = localStorage.getItem("tu.acento");
      if (a !== null && a !== "") {
        acento.value = a;
      }
    } catch {
      // localStorage no disponible: usa valores por defecto.
    }
    aplicar();
  }

  function fijarAcento(hex: string | null): void {
    acento.value = hex;
    if (hex === null) {
      borrar("tu.acento");
    } else {
      persistir("tu.acento", hex);
    }
    aplicar();
  }

  function alternarModo(): void {
    modo.value = modo.value === "oscuro" ? "claro" : "oscuro";
    persistir("tu.modo", modo.value);
    aplicar();
  }

  function ajustarDensidad(delta: 1 | -1): void {
    const i = DENSIDADES.indexOf(densidad.value);
    const siguiente =
      DENSIDADES[Math.min(DENSIDADES.length - 1, Math.max(0, i + delta))];
    densidad.value = siguiente;
    persistir("tu.densidad", siguiente);
    aplicar();
  }

  return {
    modo,
    densidad,
    acento,
    esOscuro,
    inicializar,
    alternarModo,
    ajustarDensidad,
    fijarAcento,
  };
});

function persistir(clave: string, valor: string): void {
  try {
    localStorage.setItem(clave, valor);
  } catch {
    // Ignora si localStorage no esta disponible.
  }
}
function borrar(clave: string): void {
  try {
    localStorage.removeItem(clave);
  } catch {
    // Ignora si localStorage no esta disponible.
  }
}
