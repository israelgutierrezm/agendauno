import type { Ref } from "vue";

/**
 * Item del menu lateral. Un item con `hijos` es un GRUPO colapsable (nivel 1/2); un
 * item con `ruta` es una hoja navegable. La estructura es recursiva (admite 3+
 * niveles).
 */
export interface MenuItem {
  clave: string;
  etiqueta: string; // clave i18n
  icono?: string;
  ruta?: string; // nombre de ruta (hoja)
  permiso?: string; // permiso requerido para verlo
  soloMiembro?: boolean;
  hijos?: MenuItem[];
}

/**
 * Estado compartido del arbol de navegacion (provisto por App, inyectado por NavArbol).
 */
export interface NavEstado {
  abiertos: Ref<Set<string>>;
  compacto: Readonly<Ref<boolean>>;
  alternar: (clave: string) => void;
  cerrarCajon: () => void;
}
