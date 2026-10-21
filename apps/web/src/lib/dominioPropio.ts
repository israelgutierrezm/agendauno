import { api } from "@/lib/api";
import { esProducto, fijarProductoDeSesion } from "@/lib/producto";
import { esHostAjeno, fijarDominioPropio } from "@/lib/tenant";

/**
 * En el dominio propio de un negocio (ADR 0189), antes de la primera ruta: de qué
 * negocio es la página. La API está en el mismo dominio (nginx pasa /api) y lo dice
 * por el host. Fuera de un host ajeno no pregunta; si no responde o el dominio no es
 * de nadie, la página sigue como en cualquier otro host.
 */
export async function resolverDominioPropio(
  host: string = window.location.hostname,
): Promise<boolean> {
  if (!esHostAjeno(host)) {
    return false;
  }
  try {
    const { data } = await api.get<{
      data?: { slug?: unknown; producto?: unknown };
    }>("/api/v1/dominio-propio");
    const slug = data.data?.slug;
    if (typeof slug !== "string" || slug === "") {
      return false;
    }
    fijarDominioPropio({ host, slug });
    if (esProducto(data.data?.producto)) {
      fijarProductoDeSesion(data.data.producto);
    }
    return true;
  } catch {
    return false;
  }
}
