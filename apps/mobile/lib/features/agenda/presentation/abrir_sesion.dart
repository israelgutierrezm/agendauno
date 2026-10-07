import 'package:flutter/material.dart';

import '../../auth/data/sesion.dart';
import '../data/agenda_models.dart';
import 'cita_sheet.dart';
import 'pase_lista_screen.dart';

/// Abre lo que corresponde al tipo de la sesión (ADR 0104): la hoja de una cita o
/// el pase de lista de una clase.
void abrirSesion(BuildContext context, SesionAgenda s) {
  switch (s.tipo) {
    case TipoSesion.cita:
      mostrarHojaCita(context, s);
    case TipoSesion.clase:
      Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => PaseListaScreen(sesion: s)),
      );
  }
}

/// Qué hace tocar una sesión de la agenda, según su tipo; null si no hay nada que
/// abrir: una cita sin cliente (cancelada o aún sin titular), una clase cancelada
/// o, sin permiso de ver reservas, el pase de lista.
VoidCallback? alTocarSesion(
  BuildContext context,
  SesionAgenda s,
  Sesion? sesion,
) {
  final sePuede = switch (s.tipo) {
    TipoSesion.cita => s.cita != null,
    TipoSesion.clase =>
      s.programada && (sesion?.puede('reservas.ver') ?? false),
  };
  return sePuede ? () => abrirSesion(context, s) : null;
}
