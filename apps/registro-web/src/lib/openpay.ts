/**
 * OpenPay.js: captura la tarjeta en el navegador y la convierte en un token de un
 * solo uso, más la sesión del dispositivo que pide su antifraude. El número de
 * tarjeta va directo del navegador a OpenPay; nunca pasa por AgendaUno. Los scripts
 * se cargan solo cuando se necesitan (al activar el pago automático con OpenPay).
 */
export interface FormularioOpenPay {
  proveedor: "openpay";
  merchant_id: string;
  public_key: string;
  sandbox: boolean;
}

export interface TarjetaOpenPay {
  card_number: string;
  holder_name: string;
  expiration_month: string;
  expiration_year: string;
  cvv2: string;
}

interface OpenPayJs {
  setId(id: string): void;
  setApiKey(llave: string): void;
  setSandboxMode(sandbox: boolean): void;
  deviceData: { setup(): string };
  token: {
    create(
      tarjeta: TarjetaOpenPay,
      exito: (r: { data: { id: string } }) => void,
      error: (r: { data?: { description?: string } }) => void,
    ): void;
  };
}

declare global {
  interface Window {
    OpenPay?: OpenPayJs;
  }
}

const SCRIPTS = [
  "https://js.openpay.mx/openpay.v1.min.js",
  "https://js.openpay.mx/openpay-data.v1.min.js",
];

let cargando: Promise<void> | null = null;

function cargarScript(src: string): Promise<void> {
  return new Promise<void>((resolve, reject) => {
    const s = document.createElement("script");
    s.src = src;
    s.async = false; // openpay-data necesita a openpay ya cargado
    s.onload = () => resolve();
    s.onerror = () => reject(new Error("No se pudo cargar OpenPay."));
    document.head.appendChild(s);
  });
}

async function cargarOpenPay(): Promise<OpenPayJs> {
  if (window.OpenPay === undefined) {
    cargando ??= SCRIPTS.reduce(
      (anterior, src) => anterior.then(() => cargarScript(src)),
      Promise.resolve(),
    );
    await cargando;
  }
  if (window.OpenPay === undefined) {
    throw new Error("No se pudo cargar OpenPay.");
  }
  return window.OpenPay;
}

/**
 * Tokeniza la tarjeta con las llaves públicas del negocio. Devuelve el token y la
 * sesión del dispositivo que se mandan al API.
 */
export async function tokenizarTarjeta(
  formulario: FormularioOpenPay,
  tarjeta: TarjetaOpenPay,
): Promise<{ token_id: string; device_session_id: string }> {
  const openpay = await cargarOpenPay();
  openpay.setId(formulario.merchant_id);
  openpay.setApiKey(formulario.public_key);
  openpay.setSandboxMode(formulario.sandbox);
  const dispositivo = openpay.deviceData.setup();

  return new Promise((resolve, reject) => {
    openpay.token.create(
      tarjeta,
      (r) => resolve({ token_id: r.data.id, device_session_id: dispositivo }),
      (e) =>
        reject(
          new Error(e.data?.description ?? "Revisa los datos de la tarjeta."),
        ),
    );
  });
}
