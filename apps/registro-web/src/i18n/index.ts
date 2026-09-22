import { createI18n } from "vue-i18n";

import esMX from "./locales/es-MX";

export const i18n = createI18n({
  legacy: false,
  locale: "es-MX",
  fallbackLocale: "es-MX",
  messages: {
    "es-MX": esMX,
  },
});
