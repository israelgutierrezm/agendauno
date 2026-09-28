import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'features/auth/application/sesion_controller.dart';
import 'core/network/auth_token.dart';
import 'core/storage/almacen_sesion.dart';
import 'core/theme/tema_agendauno.dart';
import 'features/auth/data/sesion.dart';
import 'features/auth/presentation/elegir_rol_screen.dart';
import 'features/auth/presentation/login_screen.dart';
import 'features/cuenta/presentation/cuenta_screen.dart';
import 'features/inicio/presentation/equipo_screen.dart';
import 'features/instructor/presentation/instructor_screen.dart';
import 'features/notificaciones/application/push_controller.dart';

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
  // Para mostrar las notificaciones push que llegan con la app abierta.
  final _avisos = GlobalKey<ScaffoldMessengerState>();

  @override
  void initState() {
    super.initState();
    // Una sesión restaurada se valida con el servidor (token revocado → login).
    ref.read(sesionProvider.notifier).refrescar();
    _iniciarPush();
  }

  Future<void> _iniciarPush() async {
    final push = ref.read(pushProvider);
    await push.iniciar(_mostrarAviso);
    final sesion = ref.read(sesionProvider);
    if (sesion != null) {
      await push.registrar(sesion);
    }
  }

  void _mostrarAviso(String titulo, String cuerpo) {
    _avisos.currentState?.showSnackBar(
      SnackBar(
        content: Text([titulo, cuerpo].where((t) => t.isNotEmpty).join('\n')),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    // Sin login global: con sesion tenant-local, el cliente ve su cuenta, quien
    // solo imparte su portal (sus clases) y el resto del personal la agenda; sin
    // sesion, el acceso por negocio.
    final sesion = ref.watch(sesionProvider);
    // Al iniciar sesión (o cambiar de negocio), este teléfono recibe sus push.
    ref.listen(sesionProvider, (anterior, nueva) {
      if (nueva != null && anterior?.bearer != nueva.bearer) {
        ref.read(pushProvider).registrar(nueva);
      }
    });
    // Con varios roles, al entrar elige con cuál; luego, la pantalla de ese rol.
    final Widget inicio = sesion == null
        ? const LoginScreen()
        : sesion.eligiendoRol
        ? const ElegirRolScreen()
        : sesion.esMiembro
        ? const CuentaScreen()
        : sesion.esInstructorAcotado
        ? const InstructorScreen()
        : const EquipoScreen();

    return MaterialApp(
      title: 'AgendaUno',
      debugShowCheckedModeBanner: false,
      scaffoldMessengerKey: _avisos,
      // El mismo tema claro de la web (tokens de AgendaUno).
      theme: TemaAgendaUno.claro(),
      home: inicio,
    );
  }
}
