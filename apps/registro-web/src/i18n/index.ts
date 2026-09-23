import { createI18n } from "vue-i18n";

import agendaVisual from "./locales/agendaVisual.es-MX";
import apariencia from "./locales/apariencia.es-MX";
import cobro from "./locales/cobro.es-MX";
import {
  expediente,
  listados,
  miPerfil,
  profesional,
} from "./locales/equipo.es-MX";
import esMX from "./locales/es-MX";
import recepcionVisual from "./locales/recepcionVisual.es-MX";

export const i18n = createI18n({
  legacy: false,
  locale: "es-MX",
  fallbackLocale: "es-MX",
  messages: {
    "es-MX": {
      ...esMX,
      agendaVisual,
      apariencia,
      cobro,
      recepcionVisual,
      miPerfil,
      expediente,
      profesional,
      listados,
    },
  },
});
