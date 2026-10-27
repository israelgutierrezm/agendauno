import { describe, expect, it } from "vitest";

import { llaveDeServidor } from "./pushWeb";

describe("push en la web", () => {
  it("convierte la llave pública base64url en bytes", () => {
    // 0x04 seguido de 64 bytes: el punto sin comprimir de P-256.
    const bytes = new Uint8Array(65).map((_, i) => (i === 0 ? 4 : i));
    const b64 = btoa(String.fromCharCode(...bytes))
      .replace(/\+/g, "-")
      .replace(/\//g, "_")
      .replace(/=+$/, "");
    expect(Array.from(llaveDeServidor(b64))).toEqual(Array.from(bytes));
  });
});
