/**
 * Plural en español de un término del perfil de negocio, para rotular menús y
 * encabezados sin forks por industria: Barbero → Barberos, Cliente → Clientes,
 * Profesional → Profesionales, Coach → Coaches.
 */
export function plural(palabra: string): string {
  const p = palabra.trim();
  if (p === "") {
    return p;
  }
  const ultima = p.slice(-1).toLowerCase();
  if ("aeiouáéó".includes(ultima)) {
    return `${p}s`;
  }
  if (ultima === "z") {
    return `${p.slice(0, -1)}ces`;
  }
  return `${p}es`;
}
