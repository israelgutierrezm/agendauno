import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/aviso_sin_sucursal.dart';
import '../../../core/error_de_carga.dart';
import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../agenda/data/agenda_models.dart';
import '../../agenda/data/agenda_repository.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/presentation/cuenta_widgets.dart';
import '../data/caja_models.dart';
import '../data/caja_repository.dart';
import 'caja_sheets.dart';

/// La caja de la sucursal desde la app del equipo (ADR 0175), como la web: abrirla
/// con su fondo, registrar retiros e ingresos de efectivo y, al cerrar, contarla a
/// ciegas (quien solo opera no ve lo esperado antes de contar). Si no cuadra, se ve
/// la diferencia de cada forma de pago y se cierra con el motivo, para que el
/// administrador la revise. Con varias sedes, primero se elige cuál.
class CajaScreen extends ConsumerStatefulWidget {
  const CajaScreen({super.key});

  @override
  ConsumerState<CajaScreen> createState() => _CajaScreenState();
}

class _CajaScreenState extends ConsumerState<CajaScreen> {
  /// Las sedes de quien opera (null mientras se cargan).
  List<SucursalAgenda>? _sedes;
  bool _errorSedes = false;
  String? _sede;

  /// La caja de la sede elegida (null mientras se carga).
  CajaSede? _caja;
  bool _errorCarga = false;

  /// El cierre que se acaba de hacer: si cuadró o con qué diferencia.
  TurnoCaja? _ultimoCierre;

  final _fondo = TextEditingController();
  final _nota = TextEditingController();
  final _motivoDiferencia = TextEditingController();
  bool _guardando = false;
  String? _errorAbrir;
  String? _errorCierre;

  @override
  void initState() {
    super.initState();
    _cargarSedes();
  }

  @override
  void dispose() {
    _fondo.dispose();
    _nota.dispose();
    _motivoDiferencia.dispose();
    super.dispose();
  }

  String get _moneda => ref.read(sesionProvider)?.moneda ?? 'MXN';

  String _fecha(String? iso) => Formato.fechaHora(
    iso,
    desfaseMin: ref.read(sesionProvider)?.desfaseNegocioMin,
  );

  static String _conSigno(int minor, String moneda) => minor > 0
      ? '+${Formato.dinero(minor, moneda)}'
      : Formato.dinero(minor, moneda);

  Future<void> _cargarSedes() async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return;
    }
    setState(() {
      _sedes = null;
      _errorSedes = false;
    });
    try {
      final sedes = await repo.sucursales();
      if (!mounted) {
        return;
      }
      setState(() {
        _sedes = sedes;
        // Con una sola (la suya), esa; con varias, se elige.
        if (sedes.length == 1) {
          _sede = sedes.single.id;
        }
      });
      if (_sede != null) {
        await _cargar();
      }
    } on DioException {
      if (mounted) {
        setState(() => _errorSedes = true);
      }
    }
  }

  Future<void> _cargar() async {
    final repo = ref.read(cajaRepositoryProvider);
    final sede = _sede;
    if (repo == null || sede == null) {
      return;
    }
    setState(() => _errorCarga = false);
    try {
      final caja = await repo.actual(sede);
      // Si mientras tanto eligió otra sede, esta respuesta ya no es la suya.
      if (mounted && sede == _sede) {
        setState(() => _caja = caja);
      }
    } on DioException {
      if (mounted && sede == _sede) {
        setState(() => _errorCarga = true);
      }
    }
  }

  void _elegirSede(String? sede) {
    if (sede == null || sede == _sede) {
      return;
    }
    setState(() {
      _sede = sede;
      _caja = null;
      _ultimoCierre = null;
      _errorAbrir = null;
      _errorCierre = null;
    });
    _cargar();
  }

  void _avisar(String texto) => ScaffoldMessenger.of(
    context,
  ).showSnackBar(SnackBar(content: Text(texto)));

  Future<void> _abrir() async {
    final repo = ref.read(cajaRepositoryProvider);
    final sede = _sede;
    final texto = _fondo.text.trim();
    final fondo = texto.isEmpty ? 0 : Formato.aMinor(texto);
    if (fondo == null) {
      setState(() => _errorAbrir = 'Escribe un monto válido.');
      return;
    }
    if (repo == null || sede == null) {
      return;
    }
    setState(() {
      _guardando = true;
      _errorAbrir = null;
    });
    try {
      final turno = await repo.abrir(sede, fondoMinor: fondo, nota: _nota.text);
      if (!mounted) {
        return;
      }
      _fondo.clear();
      _nota.clear();
      setState(() {
        _caja = CajaSede(usaCaja: true, turno: turno);
        _ultimoCierre = null;
        _guardando = false;
      });
      _avisar('Caja abierta.');
    } on DioException catch (e) {
      if (!mounted) {
        return;
      }
      // Alguien más ya la abrió (o falta cerrar la anterior): se dice y se ve
      // cómo está.
      if (e.response?.statusCode == 409) {
        setState(() => _guardando = false);
        _avisar(mensajeDeError(e));
        await _cargar();
        return;
      }
      setState(() {
        _guardando = false;
        _errorAbrir = mensajeDeError(e);
      });
    }
  }

  Future<void> _mover(TurnoCaja turno, String tipo) async {
    final actualizado = await mostrarMovimientoCaja(
      context,
      turnoId: turno.id,
      tipo: tipo,
    );
    if (!mounted) {
      return;
    }
    // Sin registrar (se cerró, o la caja cambió en otro lado): se lee de nuevo.
    if (actualizado == null) {
      await _cargar();
      return;
    }
    setState(() => _caja = CajaSede(usaCaja: true, turno: actualizado));
    _avisar('Movimiento registrado.');
  }

  Future<void> _contar(TurnoCaja turno) async {
    final resultado = await mostrarConteoCaja(context, turnoId: turno.id);
    if (!mounted) {
      return;
    }
    if (resultado == null) {
      await _cargar();
      return;
    }
    setState(() {
      if (resultado.cuadra) {
        _ultimoCierre = resultado.turno;
        _caja = const CajaSede(usaCaja: true);
      } else {
        _caja = CajaSede(usaCaja: true, turno: resultado.turno);
      }
    });
    if (resultado.cuadra) {
      _avisar('La caja cuadró y quedó cerrada.');
    }
  }

  Future<void> _cerrarConMotivo(TurnoCaja turno) async {
    final repo = ref.read(cajaRepositoryProvider);
    if (repo == null) {
      return;
    }
    setState(() {
      _guardando = true;
      _errorCierre = null;
    });
    try {
      final cerrado = await repo.cerrar(turno.id, _motivoDiferencia.text);
      if (!mounted) {
        return;
      }
      _motivoDiferencia.clear();
      setState(() {
        _ultimoCierre = cerrado;
        _caja = const CajaSede(usaCaja: true);
        _guardando = false;
      });
      _avisar('Caja cerrada. Quedó para revisión.');
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _guardando = false;
          _errorCierre = mensajeDeError(e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final sesion = ref.watch(sesionProvider);
    final sedes = _sedes;
    final suave = TextStyle(color: context.colores.textoSuave);

    final Widget cuerpo;
    if (_errorSedes) {
      cuerpo = ErrorDeCarga(
        mensaje: 'No se pudieron cargar las sucursales.',
        onReintentar: _cargarSedes,
      );
    } else if (sedes == null) {
      cuerpo = const Center(child: CircularProgressIndicator());
    } else {
      final nombreSede =
          sedes.where((s) => s.id == _sede).firstOrNull?.nombre ?? '';
      cuerpo = RefreshIndicator(
        onRefresh: _cargar,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          children: [
            if (sesion?.sinSucursal ?? false) const AvisoSinSucursal(),
            if (sedes.length > 1) ...[
              DropdownButtonFormField<String>(
                key: const Key('sede-caja'),
                initialValue: _sede,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Sucursal'),
                hint: const Text(
                  'Elige la sucursal de la caja',
                  overflow: TextOverflow.ellipsis,
                ),
                items: [
                  for (final s in sedes)
                    DropdownMenuItem(
                      value: s.id,
                      child: Text(s.nombre, overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: _guardando ? null : _elegirSede,
              ),
              const SizedBox(height: 16),
            ],
            if (sedes.isEmpty)
              Text('No tienes sucursales para abrir una caja.', style: suave)
            else if (_sede == null)
              Text('Elige la sucursal de la caja.', style: suave)
            else if (_errorCarga)
              ErrorDeCarga(
                mensaje: 'No pudimos cargar la caja.',
                onReintentar: _cargar,
              )
            else if (_caja == null)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 48),
                child: Center(child: CircularProgressIndicator()),
              )
            else
              ..._contenido(_caja!, nombreSede),
          ],
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Caja')),
      body: cuerpo,
    );
  }

  List<Widget> _contenido(CajaSede caja, String nombreSede) {
    final turno = caja.turno;
    if (turno == null) {
      return _cerrada(caja, nombreSede);
    }
    if (turno.contada) {
      return _noCuadra(turno);
    }
    return _abierta(turno);
  }

  /// Sin caja abierta: el resultado del cierre recién hecho y abrir otra.
  List<Widget> _cerrada(CajaSede caja, String nombreSede) {
    final suave = TextStyle(color: context.colores.textoSuave);
    final ultimo = _ultimoCierre;
    final cuadro = (ultimo?.diferenciaMinor ?? 0) == 0;
    return [
      if (ultimo != null)
        Card(
          key: const Key('ultimo-cierre'),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                EstadoPunto(
                  cuadro
                      ? 'La caja cuadró y quedó cerrada.'
                      : 'Caja cerrada. Quedó para revisión.',
                  color: cuadro ? context.colores.exito : context.colores.aviso,
                ),
                if (!cuadro) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Diferencia total '
                    '${_conSigno(ultimo.diferenciaMinor ?? 0, ultimo.moneda)}',
                    style: suave,
                  ),
                ],
              ],
            ),
          ),
        ),
      Card(
        key: const Key('abrir-caja'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              EstadoPunto('Cerrada', color: context.colores.textoSuave),
              const SizedBox(height: 6),
              Text(
                nombreSede.isEmpty
                    ? 'La caja está cerrada.'
                    : 'La caja de $nombreSede está cerrada.',
              ),
              if (!caja.usaCaja) ...[
                const SizedBox(height: 4),
                Text(
                  'Al abrirla por primera vez, cobrar en el mostrador de esta '
                  'sucursal pedirá la caja abierta.',
                  style: suave,
                ),
              ],
              const SizedBox(height: 16),
              TextField(
                key: const Key('fondo'),
                controller: _fondo,
                enabled: !_guardando,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'Fondo inicial (efectivo)',
                  helperText: 'El cambio con el que empiezas el turno.',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('nota-apertura'),
                controller: _nota,
                enabled: !_guardando,
                maxLength: 300,
                decoration: const InputDecoration(labelText: 'Nota (opcional)'),
              ),
              if (_errorAbrir != null) ...[
                Text(
                  _errorAbrir!,
                  key: const Key('error-abrir'),
                  style: TextStyle(color: context.colores.error),
                ),
                const SizedBox(height: 8),
              ],
              const SizedBox(height: 8),
              FilledButton(
                key: const Key('abrir'),
                onPressed: _guardando ? null : _abrir,
                child: Text(_guardando ? 'Abriendo…' : 'Abrir caja'),
              ),
            ],
          ),
        ),
      ),
    ];
  }

  /// La caja abierta: quién y cuándo, el fondo, lo esperado (solo quien
  /// supervisa), mover efectivo, contar y el libro del turno.
  List<Widget> _abierta(TurnoCaja turno) {
    final suave = TextStyle(color: context.colores.textoSuave);
    final esperado = turno.esperado;
    return [
      Card(
        key: const Key('caja-abierta'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              EstadoPunto('Abierta', color: context.colores.exito),
              const SizedBox(height: 6),
              Text(
                'Abierta por ${turno.abiertaPor ?? '—'} · '
                '${_fecha(turno.abiertaEn)}',
              ),
              const SizedBox(height: 2),
              Text(
                'Fondo ${Formato.dinero(turno.fondoMinor, turno.moneda)}',
                style: suave,
              ),
              if (turno.notaApertura != null) ...[
                const SizedBox(height: 2),
                Text(turno.notaApertura!, style: suave),
              ],
              const SizedBox(height: 16),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  OutlinedButton(
                    key: const Key('retiro'),
                    onPressed: () => _mover(turno, 'retiro'),
                    child: const Text('Retiro'),
                  ),
                  OutlinedButton(
                    key: const Key('ingreso'),
                    onPressed: () => _mover(turno, 'ingreso'),
                    child: const Text('Ingreso'),
                  ),
                  FilledButton(
                    key: const Key('contar'),
                    onPressed: () => _contar(turno),
                    child: const Text('Contar y cerrar'),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
      if (esperado != null)
        Card(
          key: const Key('esperado'),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Esperado ahora',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 16,
                  runSpacing: 10,
                  children: [
                    for (final m in MetodosCaja.todos)
                      SizedBox(
                        width: 140,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              MetodosCaja.nombre(m),
                              style: suave.copyWith(fontSize: 12),
                            ),
                            Text(
                              Formato.dinero(esperado[m] ?? 0, turno.moneda),
                              style: const TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  'Lo que debería haber en la caja: el fondo más lo cobrado, '
                  'menos lo que salió.',
                  style: suave.copyWith(fontSize: 12),
                ),
              ],
            ),
          ),
        )
      else
        Padding(
          key: const Key('a-ciegas'),
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
          child: Text(
            'El conteo es a ciegas: lo esperado se ve después de registrar lo '
            'que cuentes.',
            style: suave,
          ),
        ),
      const SizedBox(height: 12),
      const Padding(
        padding: EdgeInsets.symmetric(horizontal: 4),
        child: Text(
          'Movimientos del turno',
          style: TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
      if (turno.movimientos.isEmpty)
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 8, 4, 0),
          child: Text('Aún no hay movimientos.', style: suave),
        )
      else
        for (final m in turno.movimientos)
          ListTile(
            key: Key('movimiento-${m.id}'),
            contentPadding: const EdgeInsets.symmetric(horizontal: 4),
            title: Text(
              [
                m.tipoTexto,
                MetodosCaja.nombre(m.metodo),
                if (m.motivo != null && m.motivo!.trim().isNotEmpty) m.motivo!,
              ].join(' · '),
            ),
            subtitle: Text(
              [_fecha(m.fecha), if (m.quien != null) m.quien!].join(' · '),
            ),
            trailing: Text(
              _conSigno(m.montoMinor, turno.moneda),
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
          ),
    ];
  }

  /// Contada y no cuadró: lo esperado contra lo contado y el motivo para cerrar.
  List<Widget> _noCuadra(TurnoCaja turno) {
    final suave = TextStyle(color: context.colores.textoSuave);
    final moneda = turno.moneda.isEmpty ? _moneda : turno.moneda;
    return [
      Card(
        key: const Key('no-cuadra'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              EstadoPunto('El conteo no cuadró', color: context.colores.aviso),
              const SizedBox(height: 4),
              Text(
                'Escribe el motivo de la diferencia para cerrar la caja. El '
                'cierre queda para que lo revise el administrador.',
                style: suave,
              ),
              const SizedBox(height: 8),
              for (final c in turno.conteos)
                Padding(
                  key: Key('conteo-${c.metodo}'),
                  padding: const EdgeInsets.only(top: 10),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              MetodosCaja.nombre(c.metodo),
                              style: const TextStyle(
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                            Text(
                              'Esperado ${Formato.dinero(c.esperadoMinor, moneda)}'
                              ' · Contado ${Formato.dinero(c.contadoMinor, moneda)}',
                              style: suave.copyWith(fontSize: 12),
                            ),
                          ],
                        ),
                      ),
                      Text(
                        _conSigno(c.diferenciaMinor, moneda),
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          color: c.diferenciaMinor != 0
                              ? context.colores.error
                              : null,
                        ),
                      ),
                    ],
                  ),
                ),
              const SizedBox(height: 16),
              TextField(
                key: const Key('motivo-diferencia'),
                controller: _motivoDiferencia,
                enabled: !_guardando,
                minLines: 2,
                maxLines: 4,
                maxLength: 500,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(
                  labelText: 'Motivo de la diferencia',
                  hintText: 'Ej. Di mal un cambio, un retiro que no registré',
                ),
              ),
              if (_errorCierre != null) ...[
                Text(
                  _errorCierre!,
                  key: const Key('error-cierre'),
                  style: TextStyle(color: context.colores.error),
                ),
                const SizedBox(height: 8),
              ],
              const SizedBox(height: 8),
              FilledButton(
                key: const Key('cerrar-con-motivo'),
                onPressed:
                    _guardando || _motivoDiferencia.text.trim().length < 3
                    ? null
                    : () => _cerrarConMotivo(turno),
                child: const Text('Cerrar caja'),
              ),
            ],
          ),
        ),
      ),
    ];
  }
}
