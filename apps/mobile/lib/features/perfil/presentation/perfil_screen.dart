import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/calendario/logo_calendario.dart';
import '../../../core/google/google_auth.dart';
import '../../../core/flechas_que_se_mueven.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../auth/presentation/elegir_rol_screen.dart';
import '../../cuenta/presentation/cuenta_screen.dart' show hacerConAviso;

/// Géneros (opcionales), lista breve e incluyente: la misma que en la web y el API.
const generos = <String, String>{
  'mujer': 'Mujer',
  'hombre': 'Hombre',
  'no_binario': 'No binario',
  'otro': 'Otro',
  'prefiero_no_decir': 'Prefiero no decirlo',
};

/// Mi perfil: foto, nombre y contraseña de quien tiene la sesión, y cerrar sesión.
class PerfilScreen extends ConsumerStatefulWidget {
  const PerfilScreen({super.key});

  @override
  ConsumerState<PerfilScreen> createState() => _PerfilScreenState();
}

class _PerfilScreenState extends ConsumerState<PerfilScreen> {
  final _nombre = TextEditingController();
  final _primerApellido = TextEditingController();
  final _segundoApellido = TextEditingController();
  final _celular = TextEditingController();
  final _actual = TextEditingController();
  final _nueva = TextEditingController();
  final _confirmacion = TextEditingController();
  bool _guardando = false;
  // De su ficha (opcionales): fecha de nacimiento (AAAA-MM-DD) y género.
  String? _fechaNacimiento;
  String? _genero;

  @override
  void initState() {
    super.initState();
    final sesion = ref.read(sesionProvider);
    _nombre.text = sesion?.nombrePila ?? sesion?.nombre ?? '';
    _primerApellido.text = sesion?.primerApellido ?? '';
    _segundoApellido.text = sesion?.segundoApellido ?? '';
    _celular.text = sesion?.celular ?? '';
    _fechaNacimiento = sesion?.fechaNacimiento;
    _genero = generos.containsKey(sesion?.genero) ? sesion?.genero : null;
  }

  /// Elige la fecha de nacimiento en el calendario del sistema.
  Future<void> _elegirNacimiento() async {
    final hoy = DateTime.now();
    final actual = _fechaNacimiento != null
        ? DateTime.tryParse(_fechaNacimiento!)
        : null;
    final elegida = await showDatePicker(
      context: context,
      initialDate: actual ?? DateTime(hoy.year - 25, hoy.month, hoy.day),
      firstDate: DateTime(1900, 1, 2),
      lastDate: hoy,
      initialEntryMode: DatePickerEntryMode.input,
      helpText: 'Fecha de nacimiento',
    );
    if (elegida != null && mounted) {
      setState(() => _fechaNacimiento = fechaIso(elegida));
    }
  }

  @override
  void dispose() {
    for (final c in [
      _nombre,
      _primerApellido,
      _segundoApellido,
      _celular,
      _actual,
      _nueva,
      _confirmacion,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  /// Elige la foto (galería o cámara), la reduce y la sube.
  Future<void> _elegirFoto(ImageSource origen) async {
    final foto = await ImagePicker().pickImage(
      source: origen,
      maxWidth: 1024,
      imageQuality: 85,
    );
    if (foto == null || !mounted) {
      return;
    }
    final bytes = await foto.readAsBytes();
    if (!mounted) {
      return;
    }
    setState(() => _guardando = true);
    await hacerConAviso(
      context,
      () => ref.read(sesionProvider.notifier).subirFoto(bytes, foto.name),
      exito: 'Foto actualizada.',
    );
    if (mounted) {
      setState(() => _guardando = false);
    }
  }

  Future<void> _opcionesFoto({required bool tieneFoto}) async {
    final elegido = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Elegir de la galería'),
              onTap: () => Navigator.of(context).pop('galeria'),
            ),
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Tomar foto'),
              onTap: () => Navigator.of(context).pop('camara'),
            ),
            if (tieneFoto)
              ListTile(
                leading: const Icon(
                  Icons.delete_outline,
                  color: TemaAgendaUno.error,
                ),
                title: const Text(
                  'Quitar foto',
                  style: TextStyle(color: TemaAgendaUno.error),
                ),
                onTap: () => Navigator.of(context).pop('quitar'),
              ),
          ],
        ),
      ),
    );
    if (!mounted || elegido == null) {
      return;
    }
    if (elegido == 'quitar') {
      await hacerConAviso(
        context,
        () => ref.read(sesionProvider.notifier).quitarFoto(),
        exito: 'Foto quitada.',
      );
      return;
    }
    await _elegirFoto(
      elegido == 'camara' ? ImageSource.camera : ImageSource.gallery,
    );
  }

  String? _opcional(TextEditingController c) =>
      c.text.trim().isEmpty ? null : c.text.trim();

  Future<void> _guardarNombre() async {
    setState(() => _guardando = true);
    await hacerConAviso(
      context,
      () => ref
          .read(sesionProvider.notifier)
          .guardarPerfil(
            nombre: _nombre.text.trim(),
            primerApellido: _opcional(_primerApellido),
            segundoApellido: _opcional(_segundoApellido),
            // El celular, la fecha de nacimiento y el género son de su ficha de
            // cliente o alumno.
            celular: _opcional(_celular),
            fechaNacimiento: _fechaNacimiento,
            genero: _genero,
            conCelular: ref.read(sesionProvider)?.tieneFicha ?? false,
          ),
      exito: 'Perfil actualizado.',
    );
    if (mounted) {
      setState(() => _guardando = false);
    }
  }

  /// Conecta Google (ADR 0093): desde entonces puede entrar con él.
  Future<void> _conectarGoogle() async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _guardando = true);
    try {
      final token = await ref.read(googleAuthProvider).idToken();
      if (token == null || !mounted) {
        return;
      }
      await hacerConAviso(
        context,
        () => ref.read(sesionProvider.notifier).conectarGoogle(token),
        exito: 'Listo: ya puedes entrar con Google.',
      );
    } catch (_) {
      messenger.showSnackBar(
        const SnackBar(content: Text('No se pudo conectar con Google.')),
      );
    } finally {
      if (mounted) {
        setState(() => _guardando = false);
      }
    }
  }

  Future<void> _quitarGoogle() async {
    setState(() => _guardando = true);
    await hacerConAviso(
      context,
      () => ref.read(sesionProvider.notifier).desconectarGoogle(),
      exito: 'Quitaste Google: entra con tu correo y contraseña.',
    );
    if (mounted) {
      setState(() => _guardando = false);
    }
  }

  Future<void> _cambiarContrasena() async {
    if (_nueva.text != _confirmacion.text) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Las contraseñas no coinciden.')),
      );
      return;
    }
    setState(() => _guardando = true);
    await hacerConAviso(context, () async {
      await ref
          .read(sesionProvider.notifier)
          .cambiarContrasena(
            actualContrasena: _opcional(_actual),
            nueva: _nueva.text,
            confirmacion: _confirmacion.text,
          );
      _actual.clear();
      _nueva.clear();
      _confirmacion.clear();
    }, exito: 'Contraseña actualizada.');
    if (mounted) {
      setState(() => _guardando = false);
    }
  }

  /// Pide el correo nuevo (y la contraseña, si tiene) y manda el enlace.
  Future<void> _cambiarCorreo(bool pideContrasena) async {
    final correo = TextEditingController();
    final clave = TextEditingController();
    final enviar = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Cambiar correo'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Te enviaremos un enlace al correo nuevo. El cambio se aplica al abrirlo.',
            ),
            const SizedBox(height: 12),
            TextField(
              controller: correo,
              decoration: const InputDecoration(labelText: 'Correo nuevo'),
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
            ),
            if (pideContrasena) ...[
              const SizedBox(height: 12),
              TextField(
                controller: clave,
                obscureText: true,
                decoration: const InputDecoration(labelText: 'Tu contraseña'),
              ),
            ],
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Enviar enlace'),
          ),
        ],
      ),
    );
    final datos = (correo.text.trim(), clave.text);
    correo.dispose();
    clave.dispose();
    if (enviar != true || datos.$1.isEmpty || !mounted) {
      return;
    }

    setState(() => _guardando = true);
    await hacerConAviso(
      context,
      () => ref
          .read(sesionProvider.notifier)
          .pedirCambioCorreo(
            email: datos.$1,
            contrasena: datos.$2.isEmpty ? null : datos.$2,
          ),
      exito: 'Te enviamos un enlace a ${datos.$1}.',
    );
    if (mounted) {
      setState(() => _guardando = false);
    }
  }

  Future<void> _cancelarCambioCorreo() async {
    setState(() => _guardando = true);
    await hacerConAviso(
      context,
      () => ref.read(sesionProvider.notifier).cancelarCambioCorreo(),
      exito: 'Cancelamos el cambio de correo.',
    );
    if (mounted) {
      setState(() => _guardando = false);
    }
  }

  /// Abre la suscripción al calendario personal (webcal) en la app de calendario.
  Future<void> _agregarCalendario() async {
    final messenger = ScaffoldMessenger.of(context);
    await hacerConAviso(context, () async {
      final enlace = await ref.read(sesionProvider.notifier).enlaceCalendario();
      final abierto =
          enlace != null &&
          await launchUrl(
            Uri.parse(enlace),
            mode: LaunchMode.externalApplication,
          );
      if (!abierto) {
        messenger.showSnackBar(
          const SnackBar(
            content: Text(
              'No encontramos una app de calendario. Conéctalo desde la web en Mi perfil.',
            ),
          ),
        );
      }
    });
  }

  /// «Cambiar de rol»: elige otro de sus roles; la app vuelve a su inicio con el
  /// rol nuevo (lo que ve y lo que el servidor le concede son de ese rol).
  Future<void> _cambiarRol() async {
    final navegador = Navigator.of(context);
    final elegido = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (hoja) {
        final sesion = ref.read(sesionProvider);
        if (sesion == null) {
          return const SizedBox.shrink();
        }
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Cambiar de rol',
                  style: Theme.of(hoja).textTheme.titleLarge,
                ),
                const SizedBox(height: 4),
                const Text(
                  'Cada rol muestra sus propias opciones y permisos.',
                  style: TextStyle(color: TemaAgendaUno.textoSuave),
                ),
                const SizedBox(height: 12),
                ListaRoles(
                  sesion: sesion,
                  etiquetaMarca: 'Activo',
                  onElegir: (clave) => Navigator.of(hoja).pop(clave),
                ),
              ],
            ),
          ),
        );
      },
    );
    if (elegido == null || elegido == ref.read(sesionProvider)?.rol) {
      return;
    }
    setState(() => _guardando = true);
    try {
      await ref.read(sesionProvider.notifier).cambiarRol(elegido);
      navegador.popUntil((r) => r.isFirst);
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('No se pudo cambiar de rol.')),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _guardando = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final sesion = ref.watch(sesionProvider);
    if (sesion == null) {
      return const Scaffold();
    }
    final iniciales = sesion.nombre
        .split(' ')
        .where((p) => p.isNotEmpty)
        .take(2)
        .map((p) => p[0].toUpperCase())
        .join();

    return Scaffold(
      appBar: AppBar(title: const Text('Mi perfil')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          Row(
            children: [
              CircleAvatar(
                radius: 32,
                backgroundColor: TemaAgendaUno.acentoSuave,
                foregroundImage: sesion.fotoUrl != null
                    ? NetworkImage(sesion.fotoUrl!)
                    : null,
                child: Text(
                  iniciales,
                  style: const TextStyle(
                    color: TemaAgendaUno.acento,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      sesion.nombre,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    if (sesion.email != null)
                      Text(
                        sesion.email!,
                        style: const TextStyle(color: TemaAgendaUno.textoSuave),
                      ),
                    TextButton(
                      style: TextButton.styleFrom(padding: EdgeInsets.zero),
                      onPressed: _guardando
                          ? null
                          : () => _opcionesFoto(
                              tieneFoto: sesion.fotoUrl != null,
                            ),
                      child: const Text('Cambiar foto'),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 24),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Correo de acceso',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 8),
                  Text(sesion.email ?? ''),
                  if (sesion.emailPendiente != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      'Falta confirmar ${sesion.emailPendiente}: abre el enlace que te enviamos (vence en 24 horas).',
                      style: const TextStyle(color: TemaAgendaUno.textoSuave),
                    ),
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: _guardando ? null : _cancelarCambioCorreo,
                      child: const Text('Cancelar cambio'),
                    ),
                  ] else ...[
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: _guardando
                          ? null
                          : () => _cambiarCorreo(sesion.tieneContrasena),
                      child: const Text('Cambiar correo'),
                    ),
                  ],
                ],
              ),
            ),
          ),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Datos personales',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _nombre,
                    decoration: const InputDecoration(labelText: 'Nombre(s)'),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _primerApellido,
                    decoration: const InputDecoration(
                      labelText: 'Primer apellido',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _segundoApellido,
                    decoration: const InputDecoration(
                      labelText: 'Segundo apellido (opcional)',
                    ),
                  ),
                  if (ref.watch(sesionProvider)?.tieneFicha ?? false) ...[
                    const SizedBox(height: 12),
                    TextField(
                      controller: _celular,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'Celular',
                        helperText:
                            'Para avisarte de tus clases y citas (también por WhatsApp, si lo activas).',
                        helperMaxLines: 2,
                      ),
                    ),
                    const SizedBox(height: 12),
                    InkWell(
                      key: const Key('fecha-nacimiento'),
                      onTap: _guardando ? null : _elegirNacimiento,
                      child: InputDecorator(
                        decoration: InputDecoration(
                          labelText: 'Fecha de nacimiento (opcional)',
                          suffixIcon: _fechaNacimiento == null
                              ? const Icon(Icons.calendar_today_outlined)
                              : IconButton(
                                  tooltip: 'Quitar',
                                  icon: const Icon(Icons.close),
                                  onPressed: () =>
                                      setState(() => _fechaNacimiento = null),
                                ),
                        ),
                        child: Text(
                          _fechaNacimiento == null
                              ? 'Sin especificar'
                              : fechaLarga(_fechaNacimiento!),
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String?>(
                      key: const Key('genero'),
                      initialValue: _genero,
                      decoration: const InputDecoration(
                        labelText: 'Género (opcional)',
                        helperText: 'Solo tú y el equipo del negocio lo ven.',
                      ),
                      items: [
                        const DropdownMenuItem<String?>(
                          child: Text('Sin especificar'),
                        ),
                        for (final g in generos.entries)
                          DropdownMenuItem<String?>(
                            value: g.key,
                            child: Text(g.value),
                          ),
                      ],
                      onChanged: _guardando
                          ? null
                          : (valor) => setState(() => _genero = valor),
                    ),
                  ],
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _guardando ? null : _guardarNombre,
                    child: const Text('Guardar'),
                  ),
                ],
              ),
            ),
          ),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Contraseña',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 12),
                  if (sesion.tieneContrasena) ...[
                    TextField(
                      controller: _actual,
                      obscureText: true,
                      decoration: const InputDecoration(
                        labelText: 'Contraseña actual',
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],
                  TextField(
                    controller: _nueva,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Nueva contraseña (mínimo 8)',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _confirmacion,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Confirma la nueva contraseña',
                    ),
                  ),
                  const SizedBox(height: 16),
                  OutlinedButton(
                    onPressed: _guardando ? null : _cambiarContrasena,
                    child: const Text('Cambiar contraseña'),
                  ),
                ],
              ),
            ),
          ),
          // Entrar con Google: se conecta aquí (Google no crea cuentas).
          if (sesion.googleConectado ||
              ref.watch(googleAuthProvider).disponible)
            Card(
              key: const Key('google'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      'Entrar con Google',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      sesion.googleConectado
                          ? 'Google conectado: puedes entrar con él en la app y en la web.'
                          : 'Conecta tu cuenta de Google para entrar sin contraseña. '
                                'Puede ser un Gmail distinto a tu correo de acceso.',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    const SizedBox(height: 12),
                    if (sesion.googleConectado)
                      OutlinedButton(
                        key: const Key('quitar-google'),
                        onPressed: _guardando ? null : _quitarGoogle,
                        child: const Text('Quitar Google'),
                      )
                    else
                      FilledButton.tonal(
                        key: const Key('conectar-google'),
                        onPressed: _guardando ? null : _conectarGoogle,
                        child: const Text('Conectar Google'),
                      ),
                  ],
                ),
              ),
            ),
          Card(
            child: ListTile(
              leading: const Icon(Icons.calendar_month_outlined),
              title: const Text('Agregar a mi calendario'),
              // Funciona con Google Calendar, Apple Calendar y Outlook (como en la web).
              subtitle: const Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Tus clases y citas en el calendario del teléfono; se actualiza solo.',
                  ),
                  SizedBox(height: 6),
                  Row(
                    children: [
                      LogoCalendario(MarcaCalendario.google, tam: 18),
                      SizedBox(width: 6),
                      LogoCalendario(MarcaCalendario.apple, tam: 18),
                      SizedBox(width: 6),
                      LogoCalendario(MarcaCalendario.outlook, tam: 18),
                    ],
                  ),
                ],
              ),
              onTap: _guardando ? null : _agregarCalendario,
            ),
          ),
          if (sesion.tieneVariosRoles)
            Card(
              child: ListTile(
                key: const Key('cambiar-rol'),
                // Tiene otro rol con el cual entrar: las flechas se mueven (como en
                // la web).
                leading: const FlechasQueSeMueven(
                  key: Key('flechas-cambiar-rol'),
                ),
                title: const Text('Cambiar de rol'),
                subtitle: Text(
                  'Ahora: ${nombreDeRol(sesion.rolesDisponibles.firstWhere(
                    (r) => r.clave == sesion.rol,
                    orElse: () => RolDisponible(clave: sesion.rol, faceta: sesion.facetaActiva),
                  ), sesion.terminologia)}',
                ),
                onTap: _guardando ? null : _cambiarRol,
              ),
            ),
          const SizedBox(height: 8),
          TextButton.icon(
            style: TextButton.styleFrom(foregroundColor: TemaAgendaUno.error),
            icon: const Icon(Icons.logout),
            label: const Text('Cerrar sesión'),
            onPressed: () async {
              final navegador = Navigator.of(context);
              await ref.read(sesionProvider.notifier).cerrar();
              navegador.popUntil((r) => r.isFirst);
            },
          ),
        ],
      ),
    );
  }
}

/// Una fecha como AAAA-MM-DD (la del API).
String fechaIso(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

const _meses = [
  'enero',
  'febrero',
  'marzo',
  'abril',
  'mayo',
  'junio',
  'julio',
  'agosto',
  'septiembre',
  'octubre',
  'noviembre',
  'diciembre',
];

/// «14 de marzo de 1994» a partir de AAAA-MM-DD.
String fechaLarga(String iso) {
  final d = DateTime.tryParse(iso);
  if (d == null) {
    return iso;
  }
  return '${d.day} de ${_meses[d.month - 1]} de ${d.year}';
}
