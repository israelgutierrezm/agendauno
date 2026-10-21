import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { i18n } from "@/i18n";
import PaseEnWallet from "./PaseEnWallet.vue";

/*
| El pase en la cartera del teléfono (ADR 0195): solo se ofrece la que hay, y
| renovarlo se confirma.
*/

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/lib/api", () => ({ api, mensajeDeError: () => "Error" }));
const confirmar = vi.hoisted(() => vi.fn(() => Promise.resolve(true)));
vi.mock("@/lib/confirmar", () => ({ confirmar }));

const global = { plugins: [i18n] };
const BASE = "/api/v1/app/fluo";

beforeEach(() => {
  vi.clearAllMocks();
});

describe("PaseEnWallet", () => {
  it("sin ninguna cartera, no se muestra", async () => {
    api.get.mockResolvedValue({
      data: { data: { apple: false, google: false } },
    });
    const w = mount(PaseEnWallet, { props: { base: BASE }, global });
    await flushPromises();
    expect(w.find('[data-prueba="pase-wallet"]').exists()).toBe(false);
  });

  it("ofrece solo la que hay y renovarlo se confirma", async () => {
    api.get.mockResolvedValue({
      data: { data: { apple: false, google: true } },
    });
    api.post.mockResolvedValue({ data: { data: { renovado: true } } });
    const w = mount(PaseEnWallet, { props: { base: BASE }, global });
    await flushPromises();

    expect(w.find('[data-prueba="wallet-apple"]').exists()).toBe(false);
    expect(w.find('[data-prueba="wallet-google"]').exists()).toBe(true);

    await w.get('[data-prueba="wallet-renovar"]').trigger("click");
    await flushPromises();
    expect(confirmar).toHaveBeenCalled();
    expect(api.post).toHaveBeenCalledWith(`${BASE}/mi/pase/wallet/renovar`);
  });
});
