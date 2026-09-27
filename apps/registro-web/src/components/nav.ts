import type { Ref } from "vue";

import type { ModalidadServicio } from "@/stores/sesionTenant";

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
  // Permiso requerido para verlo; con varios, hacen falta todos (p. ej. la
  // pantalla también carga datos que exigen otro permiso).
  permiso?: string | string[];
  soloMiembro?: boolean;
  // Solo para quien imparte clases o atiende citas (su portal).
  soloInstructor?: boolean;
  // Solo se muestra en negocios de esta modalidad (clases con cupo o citas 1 a 1).
  modalidad?: ModalidadServicio;
  // Solo se muestra si el perfil de negocio activa este flag.
  flag?: "grupos" | "niveles" | "acceso_abierto";
  // La etiqueta es el plural del término del perfil (p. ej. Barberos, Clientes).
  termino?: "miembro" | "instructor";
  // Etiqueta ya resuelta; sustituye a la clave i18n.
  texto?: string;
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
