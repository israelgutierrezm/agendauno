import { beforeEach, vi } from "vitest";

import { cargarMensajesPanel } from "@/i18n";

// Reloj fijo, como en las pruebas del API: las fechas literales de una prueba no se vencen
// con el paso de los días. Una prueba que necesita otro momento llama a `vi.setSystemTime`.
beforeEach(() => {
  vi.setSystemTime(new Date("2026-11-01T12:00:00Z"));
});

// Las pruebas montan pantallas del panel sin pasar por el router: con todos los textos
// (ADR 0210). La guardia de la landing (textosComerciales.spec.ts) prueba sin ellos.
await cargarMensajesPanel();
