import { describe, expect, it } from "vitest";

import { getCorrelationId } from "./correlationId";

describe("getCorrelationId", () => {
  it("devuelve el mismo id en llamadas sucesivas (estable por pestana)", () => {
    const a = getCorrelationId();
    const b = getCorrelationId();
    expect(a).toBe(b);
    expect(a).toMatch(/[0-9a-f-]{36}/);
  });
});
