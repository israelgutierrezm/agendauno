package com.agendauno.app

import androidx.core.content.FileProvider

/**
 * Comparte con el visor del teléfono (solo lectura) los documentos que la app bajó a su
 * caché (`compartidos/`). Una clase propia para no chocar con el FileProvider de otro
 * plugin en el manifiesto.
 */
class ArchivosProvider : FileProvider()
