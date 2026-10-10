/**
 * reCAPTCHA v3 de los formularios públicos (registro de negocios, lista de
 * interesados): carga el script una sola vez y pide un token para la acción. Sin
 * `VITE_RECAPTCHA_SITE_KEY` (desarrollo) no se exige: devuelve null y el servidor,
 * sin su llave secreta, tampoco lo pide.
 */
const SITE_KEY = import.meta.env.VITE_RECAPTCHA_SITE_KEY as string | undefined;

let carga: Promise<void> | null = null;
function cargar(siteKey: string): Promise<void> {
  carga ??= new Promise<void>((resolve, reject) => {
    const s = document.createElement("script");
    s.src = `https://www.google.com/recaptcha/api.js?render=${siteKey}`;
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => {
      carga = null;
      reject(new Error("recaptcha"));
    };
    document.head.appendChild(s);
  });
  return carga;
}

interface Grecaptcha {
  ready: (cb: () => void) => void;
  execute: (key: string, opts: { action: string }) => Promise<string>;
}

export async function tokenRecaptcha(accion: string): Promise<string | null> {
  if (SITE_KEY === undefined || SITE_KEY === "") {
    return null;
  }
  try {
    await cargar(SITE_KEY);
    const grecaptcha = (window as unknown as { grecaptcha: Grecaptcha })
      .grecaptcha;
    return await new Promise<string>((resolve, reject) => {
      grecaptcha.ready(() => {
        grecaptcha.execute(SITE_KEY, { action: accion }).then(resolve, reject);
      });
    });
  } catch {
    return null;
  }
}
