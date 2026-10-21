/**
 * El registro insertado de WhatsApp de Meta (Embedded Signup, ADR 0200): el negocio
 * conecta su número sin salir del panel. Se carga el SDK oficial de Meta solo al
 * pulsar «Conectar»; la ventana de Meta devuelve un código (que la API canjea) y, por
 * mensaje entre ventanas, el id de su cuenta y de su número.
 */
export interface DatosRegistroWhatsApp {
  app_id: string;
  config_id: string;
  version: string;
}

export interface ResultadoRegistroWhatsApp {
  codigo: string;
  waba_id: string;
  phone_number_id: string;
}

interface RespuestaLogin {
  authResponse?: { code?: string } | null;
}

interface SdkFacebook {
  init(opciones: Record<string, unknown>): void;
  login(
    alTerminar: (respuesta: RespuestaLogin) => void,
    opciones: Record<string, unknown>,
  ): void;
}

declare global {
  interface Window {
    FB?: SdkFacebook;
    fbAsyncInit?: () => void;
  }
}

const SDK = "https://connect.facebook.net/es_LA/sdk.js";

let cargando: Promise<SdkFacebook> | null = null;

function cargarSdk(datos: DatosRegistroWhatsApp): Promise<SdkFacebook> {
  if (cargando !== null) {
    return cargando;
  }
  cargando = new Promise<SdkFacebook>((resolver, rechazar) => {
    window.fbAsyncInit = () => {
      if (!window.FB) {
        rechazar(new Error("sdk"));
        return;
      }
      window.FB.init({
        appId: datos.app_id,
        autoLogAppEvents: false,
        xfbml: false,
        version: datos.version,
      });
      resolver(window.FB);
    };
    const script = document.createElement("script");
    script.src = SDK;
    script.async = true;
    script.crossOrigin = "anonymous";
    script.onerror = () => {
      cargando = null;
      rechazar(new Error("sdk"));
    };
    document.body.appendChild(script);
  });
  return cargando;
}

/** ¿El mensaje viene de una ventana de Meta? */
function deMeta(origen: string): boolean {
  try {
    const host = new URL(origen).hostname;
    return host === "facebook.com" || host.endsWith(".facebook.com");
  } catch {
    return false;
  }
}

/**
 * Abre el registro de Meta y resuelve con el código y los ids, o con null si se
 * cerró sin terminar.
 */
export async function registrarWhatsApp(
  datos: DatosRegistroWhatsApp,
): Promise<ResultadoRegistroWhatsApp | null> {
  const fb = await cargarSdk(datos);
  return new Promise((resolver) => {
    let codigo: string | null = null;
    let ids: { waba_id: string; phone_number_id: string } | null = null;
    let cerrado = false;
    const terminar = (valor: ResultadoRegistroWhatsApp | null) => {
      window.removeEventListener("message", escuchar);
      resolver(valor);
    };
    const intentar = () => {
      if (codigo !== null && ids !== null) {
        terminar({ codigo, ...ids });
      } else if (cerrado && (codigo === null || ids === null)) {
        // Meta manda el código y los ids por separado; si cerró sin uno, no terminó.
        window.setTimeout(() => {
          if (codigo === null || ids === null) {
            terminar(null);
          }
        }, 1500);
      }
    };
    function escuchar(evento: MessageEvent): void {
      if (!deMeta(evento.origin) || typeof evento.data !== "string") {
        return;
      }
      try {
        const datosEvento = JSON.parse(evento.data) as {
          type?: string;
          event?: string;
          data?: { waba_id?: string; phone_number_id?: string };
        };
        if (datosEvento.type !== "WA_EMBEDDED_SIGNUP") {
          return;
        }
        if (
          datosEvento.event === "FINISH" &&
          datosEvento.data?.waba_id &&
          datosEvento.data.phone_number_id
        ) {
          ids = {
            waba_id: datosEvento.data.waba_id,
            phone_number_id: datosEvento.data.phone_number_id,
          };
          intentar();
        } else if (
          datosEvento.event === "CANCEL" ||
          datosEvento.event === "ERROR"
        ) {
          terminar(null);
        }
      } catch {
        // Otros mensajes de Meta que no son del registro.
      }
    }
    window.addEventListener("message", escuchar);
    fb.login(
      (respuesta) => {
        codigo = respuesta.authResponse?.code ?? null;
        cerrado = true;
        intentar();
      },
      {
        config_id: datos.config_id,
        response_type: "code",
        override_default_response_type: true,
        extras: { setup: {}, sessionInfoVersion: "3" },
      },
    );
  });
}
