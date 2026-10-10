/*
| Service worker de la app instalable (PWA) de cada negocio (ADR 0110). Vive en el
| subdominio del negocio ({slug}.agendauno.mx, {slug}.turnouno.mx): cada negocio es un
| origen distinto, con su propia caché; nada se comparte entre negocios.
|
| Qué guarda y qué no:
| - La API (/api/) y los archivos de los negocios (/storage/) NUNCA pasan por aquí: van
|   siempre a la red, con su sesión. Nada privado queda en caché.
| - Las páginas (navegación): primero la red; sin conexión, la página «Sin conexión».
|   Nunca se muestra una página vieja ni se confirma algo sin el servidor.
| - Los recursos con hash de la compilación (/assets/…): de la caché si ya están (no
|   cambian).
*/
const VERSION = "agendauno-pwa-v1";
const CACHE_RECURSOS = `${VERSION}-recursos`;
const CACHE_BASE = `${VERSION}-base`;
const SIN_CONEXION = "/offline.html";

self.addEventListener("install", (evento) => {
  evento.waitUntil(
    caches
      .open(CACHE_BASE)
      .then((cache) =>
        cache.add(new Request(SIN_CONEXION, { cache: "reload" })),
      )
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (evento) => {
  evento.waitUntil(
    caches
      .keys()
      .then((claves) =>
        Promise.all(
          claves
            .filter((clave) => !clave.startsWith(VERSION))
            .map((clave) => caches.delete(clave)),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (evento) => {
  const peticion = evento.request;
  if (peticion.method !== "GET") {
    return;
  }
  const url = new URL(peticion.url);
  if (url.origin !== self.location.origin) {
    return;
  }
  if (
    url.pathname.startsWith("/api/") ||
    url.pathname.startsWith("/storage/")
  ) {
    return;
  }

  if (peticion.mode === "navigate") {
    evento.respondWith(
      fetch(peticion).catch(() =>
        caches.match(SIN_CONEXION).then((pagina) => pagina ?? Response.error()),
      ),
    );
    return;
  }

  // Solo los archivos con hash de la compilación (/assets/nombre-HASH.js), no las
  // imágenes de /assets/{carpeta}/, que pueden cambiar con el mismo nombre.
  if (/^\/assets\/[^/]+$/.test(url.pathname)) {
    evento.respondWith(
      caches.open(CACHE_RECURSOS).then((cache) =>
        cache.match(peticion).then(
          (guardada) =>
            guardada ??
            fetch(peticion).then((respuesta) => {
              if (respuesta.ok && respuesta.type === "basic") {
                cache.put(peticion, respuesta.clone());
              }
              return respuesta;
            }),
        ),
      ),
    );
  }
});
