import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'features/auth/application/sesion_controller.dart';
import 'core/network/auth_token.dart';
import 'core/storage/almacen_sesion.dart';
import 'core/theme/tema_agendauno.dart';
import 'features/agenda/presentation/agenda_screen.dart';
import 'features/auth/data/sesion.dart';
import 'features/auth/presentation/login_screen.dart';
import 'features/cuenta/presentation/cuenta_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Restaura la sesión guardada (cifrada) para no pedir iniciar sesión cada vez.
  final almacen = AlmacenSesion();
  final guardada = await almacen.leer();
  final sesion = guardada != null ? Sesion.desdeAlmacen(guardada) : null;

  runApp(
    ProviderScope(
      overrides: [
        almacenSesionProvider.overrideWithValue(almacen),
        tokenInicialProvider.overrideWithValue(sesion?.bearer),
        sesionInicialProvider.overrideWithValue(sesion),
      ],
      child: const AgendaUnoApp(),
    ),
  );
}

class AgendaUnoApp extends ConsumerStatefulWidget {
  const AgendaUnoApp({super.key});

  @override
  ConsumerState<AgendaUnoApp> createState() => _AgendaUnoAppState();
}

class _AgendaUnoAppState extends ConsumerState<AgendaUnoApp> {
  @override
  void initState() {
    super.initState();
    // Una sesión restaurada se valida con el servidor (token revocado → login).
    ref.read(sesionProvider.notifier).refrescar();
  }

  @override
  Widget build(BuildContext context) {
    // Sin login global: con sesion tenant-local, el personal ve su agenda y el
    // cliente su cuenta; sin sesion, el acceso por negocio.
    final sesion = ref.watch(sesionProvider);
    final Widget inicio = sesion == null
        ? const LoginScreen()
        : (sesion.rol == 'miembro'
              ? const CuentaScreen()
              : const AgendaScreen());

    return MaterialApp(
      title: 'AgendaUno',
      debugShowCheckedModeBanner: false,
      // El mismo tema claro de la web (tokens de AgendaUno).
      theme: TemaAgendaUno.claro(),
      home: inicio,
    );
  }
}
