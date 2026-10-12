/**
 * A dónde se vuelve después de entrar (ADR 0142).
 *
 * - `volverSeguro`: solo una ruta de esta app. Nada que salga a otro sitio
 *   (`//otro.com`, `/\otro.com`, `https://…`) ni que lleve a otro negocio
 *   (`/estudio/otro`, `/agendar/otro`, `?estudio=otro`) cuando se entra a uno.
 * - `volverConInteres`: lo que eligió en la página del negocio (un plan o una clase)
 *   viaja en la dirección de regreso; su cuenta lo muestra para seguir sin buscarlo.
 */

export interface RutaResuelta {
  matched: readonly unknown[];
  params: Record<string, unknown>;
  query: Record<string, unknown>;
}

const MAXIMO = 500;
const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/i;

export function volverSeguro(
  volver: unknown,
  slug: string | null,
  resolver: (ruta: string) => RutaResuelta,
): string | null {
  if (typeof volver !== "string" || volver.length > MAXIMO) return null;
  // Una ruta interna: empieza con «/» y no con «//» ni «/\»; sin barras invertidas ni
  // caracteres de control que un navegador pueda interpretar como otro sitio.
  // eslint-disable-next-line no-control-regex
  if (!/^\/(?![/\\])/.test(volver) || /[\\\u0000-\u001f]/.test(volver)) {
    return null;
  }
  let ruta: RutaResuelta;
  try {
    ruta = resolver(volver);
  } catch {
    return null;
  }
  if (ruta.matched.length === 0) return null;
  if (slug !== null && slug !== "") {
    const otro = [ruta.params.slug, ruta.query.estudio].find(
      (v): v is string => typeof v === "string" && v !== "",
    );
    if (otro !== undefined && otro.toLowerCase() !== slug.toLowerCase()) {
      return null;
    }
  }
  return volver;
}

export function volverConInteres(
  interes: { sesion?: string | null; producto?: string | null } | null,
): string | null {
  if (interes?.producto && ULID.test(interes.producto)) {
    return `/mi-cuenta?plan=${interes.producto}`;
  }
  if (interes?.sesion && ULID.test(interes.sesion)) {
    return `/mi-cuenta?clase=${interes.sesion}`;
  }
  return null;
}
