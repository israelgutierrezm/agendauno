import { defineStore } from "pinia";
import { ref } from "vue";

import { api } from "@/lib/api";
import { useTemaStore } from "@/stores/tema";

/** Tema resuelto del usuario (tema + sus ajustes propios), tal como lo da la API. */
export interface Apariencia {
  clave: string;
  nombre: string;
  oscuro: boolean;
  permite_personalizar: boolean;
  tokens: Record<string, string>;
  personalizacion: Record<string, string>;
}

export interface TemaDisponible {
  clave: string;
  nombre: string;
  oscuro: boolean;
  es_default: boolean;
  muestra: { barra: string; acento: string; fondo: string; superficie: string };
}

const CLAVE_CACHE = "tu.apariencia";

/**
 * Token del tema → variable CSS de la app. Casi todos coinciden en kebab-case; el
 * texto sobre el acento es `--primario-contraste`.
 */
const ALIAS: Record<string, string> = { acento_texto: "primario-contraste" };

function variable(token: string): string {
  return `--${ALIAS[token] ?? token.replaceAll("_", "-")}`;
}

/**
 * Apariencia de la app ya logueada (al estilo de Acadion): el tema que el usuario
 * eligió en su cuenta se aplica como CSS custom properties sobre <html> —así alcanza
 * también a los paneles que se montan fuera del layout— y un tema oscuro activa la
 * variante `.dark`. Se guarda una copia local para pintarlo desde el primer cuadro,
 * antes de que responda la API.
 */
export const useAparienciaStore = defineStore("apariencia", () => {
  const actual = ref<Apariencia | null>(null);
  const disponibles = ref<TemaDisponible[]>([]);
  const personalizables = ref<string[]>([]);
  let aplicados: string[] = [];

  function activar(apariencia: Apariencia | null | undefined): void {
    if (apariencia == null) {
      return;
    }
    const raiz = document.documentElement;
    for (const nombre of aplicados) {
      raiz.style.removeProperty(nombre);
    }
    aplicados = [];
    for (const [token, valor] of Object.entries(apariencia.tokens)) {
      const nombre = variable(token);
      raiz.style.setProperty(nombre, valor);
      aplicados.push(nombre);
    }
    raiz.classList.toggle("dark", apariencia.oscuro);
    actual.value = apariencia;
    try {
      localStorage.setItem(CLAVE_CACHE, JSON.stringify(apariencia));
    } catch {
      // Sin localStorage: se aplicará cuando responda la API.
    }
  }

  /** Pinta el último tema conocido mientras se valida la sesión. */
  function restaurar(): void {
    try {
      const guardada = localStorage.getItem(CLAVE_CACHE);
      if (guardada !== null) {
        activar(JSON.parse(guardada) as Apariencia);
      }
    } catch {
      // Copia ilegible: se ignora.
    }
  }

  /** Al cerrar sesión se vuelve a la apariencia pública (modo claro/oscuro). */
  function desactivar(): void {
    const raiz = document.documentElement;
    for (const nombre of aplicados) {
      raiz.style.removeProperty(nombre);
    }
    aplicados = [];
    actual.value = null;
    try {
      localStorage.removeItem(CLAVE_CACHE);
    } catch {
      // Ignora.
    }
    useTemaStore().inicializar();
  }

  async function cargarCatalogo(base: string): Promise<void> {
    const { data } = await api.get<{
      data: {
        actual: Apariencia;
        disponibles: TemaDisponible[];
        personalizables: string[];
      };
    }>(`${base}/apariencia`);
    disponibles.value = data.data.disponibles;
    personalizables.value = data.data.personalizables;
    activar(data.data.actual);
  }

  async function elegir(base: string, clave: string): Promise<void> {
    const { data } = await api.put<{ data: Apariencia }>(`${base}/apariencia`, {
      tema: clave,
    });
    activar(data.data);
  }

  async function personalizar(
    base: string,
    token: string,
    valor: string | null,
  ): Promise<void> {
    const { data } = await api.put<{ data: Apariencia }>(
      `${base}/apariencia/color`,
      { token, valor },
    );
    activar(data.data);
  }

  async function restablecer(base: string): Promise<void> {
    const { data } = await api.delete<{ data: Apariencia }>(
      `${base}/apariencia/personalizacion`,
    );
    activar(data.data);
  }

  return {
    actual,
    disponibles,
    personalizables,
    activar,
    restaurar,
    desactivar,
    cargarCatalogo,
    elegir,
    personalizar,
    restablecer,
  };
});
