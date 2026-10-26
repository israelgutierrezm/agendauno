import { api } from "@/lib/api";

/**
 * Descarga un archivo de la API (que pide la sesión: no basta un enlace) y lo guarda
 * con su nombre. Un error lo maneja quien llama.
 */
export async function descargarDeApi(
  ruta: string,
  nombre: string,
): Promise<void> {
  const { data } = await api.get<Blob>(ruta, { responseType: "blob" });
  const url = URL.createObjectURL(data);
  const enlace = document.createElement("a");
  enlace.href = url;
  enlace.download = nombre;
  document.body.appendChild(enlace);
  enlace.click();
  enlace.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
