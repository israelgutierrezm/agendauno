/**
 * Avisos en este navegador (push en la web, ADR 0225): suscribir el navegador con la
 * llave pública VAPID de la plataforma y darlo de alta en el negocio, o quitarlo. Usa el
 * service worker de la app (`/sw.js`), que muestra el aviso al llegar.
 */
import { api } from "@/lib/api";

export type EstadoPushWeb =
  "no_soportado" | "no_disponible" | "bloqueado" | "activo" | "inactivo";

/** ¿El navegador puede recibir push (service worker, PushManager y notificaciones)? */
export function pushWebSoportado(): boolean {
  return (
    typeof window !== "undefined" &&
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    "Notification" in window
  );
}

/** La llave pública (base64url) como la pide `pushManager.subscribe`. */
export function llaveDeServidor(base64url: string): Uint8Array<ArrayBuffer> {
  const relleno = "=".repeat((4 - (base64url.length % 4)) % 4);
  const binario = atob(
    (base64url + relleno).replace(/-/g, "+").replace(/_/g, "/"),
  );
  const bytes = new Uint8Array(new ArrayBuffer(binario.length));
  for (let i = 0; i < binario.length; i++) bytes[i] = binario.charCodeAt(i);
  return bytes;
}

async function registro(): Promise<ServiceWorkerRegistration> {
  const existente = await navigator.serviceWorker.getRegistration("/");
  return existente ?? navigator.serviceWorker.register("/sw.js");
}

async function suscripcionActual(): Promise<PushSubscription | null> {
  const r = await navigator.serviceWorker.getRegistration("/");
  return r ? r.pushManager.getSubscription() : null;
}

/** El estado en este navegador para este negocio (`base`: `/api/v1/app/{slug}`). */
export async function estadoPushWeb(base: string): Promise<EstadoPushWeb> {
  if (!pushWebSoportado()) return "no_soportado";
  const actual = await suscripcionActual();
  const { data } = await api.get<{
    data: { disponible: boolean; activa: boolean };
  }>(`${base}/mi/push-web`, {
    params: actual ? { endpoint: actual.endpoint } : {},
  });
  if (!data.data.disponible) return "no_disponible";
  if (Notification.permission === "denied") return "bloqueado";
  return data.data.activa ? "activo" : "inactivo";
}

/** Pide permiso, suscribe el navegador y lo da de alta. */
export async function activarPushWeb(base: string): Promise<EstadoPushWeb> {
  const permiso = await Notification.requestPermission();
  if (permiso !== "granted") return "bloqueado";
  const { data } = await api.get<{
    data: { disponible: boolean; llave_publica: string | null };
  }>(`${base}/mi/push-web`);
  if (!data.data.disponible || data.data.llave_publica === null) {
    return "no_disponible";
  }
  const r = await registro();
  await navigator.serviceWorker.ready;
  const suscripcion =
    (await r.pushManager.getSubscription()) ??
    (await r.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: llaveDeServidor(data.data.llave_publica),
    }));
  const json = suscripcion.toJSON();
  await api.post(`${base}/mi/push-web`, {
    endpoint: suscripcion.endpoint,
    keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
  });
  return "activo";
}

/** Lo quita de este negocio (el navegador sigue suscrito para otros negocios). */
export async function desactivarPushWeb(base: string): Promise<EstadoPushWeb> {
  const actual = await suscripcionActual();
  if (actual !== null) {
    await api.delete(`${base}/mi/push-web`, {
      data: { endpoint: actual.endpoint },
    });
  }
  return "inactivo";
}
