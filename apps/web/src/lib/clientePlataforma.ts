import axios, { type AxiosInstance, type CreateAxiosDefaults } from "axios";

/**
 * El cliente HTTP de las secciones del superadmin. Cada sección crea el suyo, pero una
 * sesión vencida (401) en cualquiera de ellas avisa a la vista madre, que vuelve a la
 * entrada en lugar de dejar la sección con errores y un «Reintentar» que siempre falla
 * (ADR 0132).
 */
export const SESION_PLATAFORMA_VENCIDA = "tu:plataforma-sesion-vencida";

export function crearClientePlataforma(
  config: CreateAxiosDefaults,
): AxiosInstance {
  const cliente = axios.create(config);
  cliente.interceptors?.response?.use(undefined, (err: unknown) => {
    if (axios.isAxiosError(err) && err.response?.status === 401) {
      window.dispatchEvent(new CustomEvent(SESION_PLATAFORMA_VENCIDA));
    }
    return Promise.reject(err);
  });
  return cliente;
}
