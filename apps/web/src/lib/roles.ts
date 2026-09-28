/**
 * Qué parte de la app le toca a un rol: el panel del negocio (equipo), el portal de
 * quien imparte (instructor) o la cuenta del alumno (miembro).
 */
export type Faceta = "equipo" | "instructor" | "miembro";

/** Un rol que la persona puede usar en este negocio. */
export interface RolDisponible {
  clave: string;
  faceta: Faceta;
  // Nombre de los roles propios del negocio; los de sistema se nombran con i18n.
  nombre?: string | null;
}

interface UsuarioConRol {
  rol: string;
  roles_disponibles?: RolDisponible[];
}

const FACETA_DE_SISTEMA: Record<string, Faceta> = {
  propietario: "equipo",
  admin: "equipo",
  recepcionista: "equipo",
  instructor: "instructor",
  miembro: "miembro",
};

/**
 * La faceta del rol ACTIVO (`usuario.rol`). Quien tiene varios roles entra con uno
 * y cambia desde la barra superior: el menú, su inicio y lo que la API le concede
 * son solo de ese rol.
 */
export function facetaActiva(usuario: UsuarioConRol | null): Faceta | null {
  if (usuario === null) {
    return null;
  }
  const disponible = usuario.roles_disponibles?.find(
    (r) => r.clave === usuario.rol,
  );

  return disponible?.faceta ?? FACETA_DE_SISTEMA[usuario.rol] ?? "equipo";
}

/**
 * Nombre visible de un rol: el propio del negocio si lo tiene; si no, la etiqueta
 * traducida del rol de sistema; si no, su clave.
 */
export function nombreDeRol(
  clave: string,
  roles: RolDisponible[] | undefined,
  traducir: (llave: string) => string | null,
): string {
  const propio = roles?.find((r) => r.clave === clave)?.nombre;
  return propio ?? traducir(`usuarios.rol.${clave}`) ?? clave;
}

/** ¿Entró como quien imparte clases o atiende citas? */
export function esInstructor(usuario: UsuarioConRol | null): boolean {
  return facetaActiva(usuario) === "instructor";
}

/** ¿Entró como alumno o cliente (su cuenta en el negocio)? */
export function esMiembro(usuario: UsuarioConRol | null): boolean {
  return facetaActiva(usuario) === "miembro";
}
