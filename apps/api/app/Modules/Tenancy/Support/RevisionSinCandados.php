<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Application\VerificarAgendaTenant;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corre una operación de prueba que no se queda (la revisión de un archivo que se va a
 * importar) sin tomarle candados a nadie (ADR 0230): en una sola transacción que SIEMPRE se
 * revierte, con las mismas reglas de siempre, pero sin que las citas y reservas en línea
 * tengan que esperarla.
 *
 * Lo que aquí queda sin candado: `lockForUpdate` (sin `for update`), la escritura sin
 * cambios con que {@see VerificarAgendaTenant::bloquear()}
 * pone en fila al profesional y al recurso, y el candado compartido que InnoDB toma de la
 * fila padre en cada llave foránea (el profesional de una sesión nueva): por eso se apagan
 * sus revisiones durante la prueba, que nunca se confirma. Solo quedan bloqueadas las filas
 * que la prueba crea y las pocas que cambia.
 *
 * Es solo una revisión: lo que se guarda de verdad pasa otra vez por las reglas con sus
 * candados. En SQLite (desarrollo y pruebas) no hay candados de fila que quitar.
 */
final class RevisionSinCandados
{
    private static bool $activa = false;

    public static function activa(): bool
    {
        return self::$activa;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $revisar
     * @return T
     */
    public static function correr(Closure $revisar): mixed
    {
        $db = DB::connection('tenant');
        $esMySql = $db->getDriverName() === 'mysql';
        $gramatica = $db->getQueryGrammar();
        $nivel = $db->transactionLevel();

        if ($esMySql) {
            Schema::connection('tenant')->disableForeignKeyConstraints();
            $db->setQueryGrammar(new class($db) extends MySqlGrammar
            {
                protected function compileLock(Builder $query, $value)
                {
                    return '';
                }
            });
        }
        self::$activa = true;
        $db->beginTransaction();
        try {
            return $revisar();
        } finally {
            while ($db->transactionLevel() > $nivel) {
                $db->rollBack();
            }
            self::$activa = false;
            if ($esMySql) {
                $db->setQueryGrammar($gramatica);
                Schema::connection('tenant')->enableForeignKeyConstraints();
            }
        }
    }
}
