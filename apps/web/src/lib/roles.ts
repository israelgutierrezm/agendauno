/**
 * ¿Imparte clases o atiende citas? (rol principal o uno más). Sin dependencias: lo
 * usan el store de sesión (ruta de inicio) y el menú.
 */
export function esInstructor(
  usuario: { rol: string; roles?: string[] } | null,
): boolean {
  return (
    usuario !== null &&
    (usuario.rol === "instructor" ||
      (usuario.roles ?? []).includes("instructor"))
  );
}
