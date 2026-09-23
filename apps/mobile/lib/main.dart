import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'features/auth/application/sesion_controller.dart';
import 'features/agenda/presentation/agenda_screen.dart';
import 'features/auth/presentation/login_screen.dart';
import 'features/cuenta/presentation/cuenta_screen.dart';

void main() {
  runApp(const ProviderScope(child: AgendaUnoApp()));
}

class AgendaUnoApp extends ConsumerWidget {
  const AgendaUnoApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Sin login global: con sesion tenant-local, el personal ve su agenda y el
    // cliente su cuenta; sin sesion, el acceso por negocio.
    final sesion = ref.watch(sesionProvider);
    final Widget inicio = sesion == null
        ? const LoginScreen()
        : (sesion.rol == 'miembro' ? const CuentaScreen() : const AgendaScreen());

    return MaterialApp(
      title: 'AgendaUno',
      debugShowCheckedModeBanner: false,
      // Azul de marca AgendaUno (#0070FF).
      theme: ThemeData(colorSchemeSeed: const Color(0xFF0070FF), useMaterial3: true),
      home: inicio,
    );
  }
}
