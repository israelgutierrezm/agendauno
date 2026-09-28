/**
 * Integracion con Google Identity Services (GIS) para el SSO tenant-local. Carga el
 * script de Google una sola vez y renderiza el boton oficial; su callback entrega
 * el `credential` (ID token) que el backend verifica en /app/{slug}/auth/google.
 *
 * Se activa solo si `VITE_GOOGLE_CLIENT_ID` esta configurado; si no, la UI muestra
 * un aviso de "proximamente".
 */
interface CredentialResponse {
  credential: string;
}

interface GoogleAccountsId {
  initialize(config: {
    client_id: string;
    callback: (r: CredentialResponse) => void;
  }): void;
  renderButton(parent: HTMLElement, options: Record<string, unknown>): void;
}

declare global {
  interface Window {
    google?: { accounts: { id: GoogleAccountsId } };
  }
}

export function clientIdGoogle(): string | undefined {
  const id = import.meta.env.VITE_GOOGLE_CLIENT_ID as string | undefined;
  return typeof id === "string" && id !== "" ? id : undefined;
}

let cargando: Promise<void> | null = null;

function cargarScript(): Promise<void> {
  if (cargando !== null) {
    return cargando;
  }
  cargando = new Promise<void>((resolve, reject) => {
    if (window.google?.accounts?.id) {
      resolve();
      return;
    }
    const s = document.createElement("script");
    s.src = "https://accounts.google.com/gsi/client";
    s.async = true;
    s.defer = true;
    s.onload = () => resolve();
    s.onerror = () =>
      reject(new Error("No se pudo cargar Google Identity Services."));
    document.head.appendChild(s);
  });
  return cargando;
}

/**
 * Renderiza el boton de Google dentro de `el` y llama `onCredential` con el ID
 * token cuando el usuario completa el acceso. No hace nada si no hay client_id.
 */
export async function renderizarBotonGoogle(
  el: HTMLElement,
  onCredential: (credential: string) => void,
): Promise<void> {
  const clientId = clientIdGoogle();
  if (clientId === undefined) {
    return;
  }
  await cargarScript();
  const id = window.google?.accounts?.id;
  if (id === undefined) {
    return;
  }
  id.initialize({
    client_id: clientId,
    callback: (r) => onCredential(r.credential),
  });
  id.renderButton(el, {
    theme: "outline",
    size: "large",
    text: "continue_with",
    width: 320,
    locale: "es-419",
  });
}
