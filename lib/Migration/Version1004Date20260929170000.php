<?php

declare(strict_types=1);

namespace OCA\SharedMail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1004Date20260929170000 extends SimpleMigrationStep
{
    public function __construct(
        private readonly IDBConnection $db,
    ) {
    }

    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options,
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema =
            $schemaClosure();

        if (
            !$schema->hasTable(
                'sharedmail_access'
            )
        ) {
            return $schema;
        }

        $table =
            $schema->getTable(
                'sharedmail_access'
            );

        /*
         * Der ursprüngliche Standardwert war 7:
         *
         * READ | REPLY | COMPOSE
         *
         * Ab 0.2.32 gehört MOVE ebenfalls zu den
         * Standardrechten.
         */
        if (
            $table->hasColumn(
                'permissions'
            )
        ) {
            $table
                ->getColumn(
                    'permissions'
                )
                ->setDefault(15);
        }

        return $schema;
    }

    public function postSchemaChange(
        IOutput $output,
        Closure $schemaClosure,
        array $options,
    ): void {
        /*
         * Nur Regeln mit dem alten exakten
         * Standardwert 7 migrieren.
         *
         * Andere eventuell bereits individuell
         * gesetzte Rechte bleiben unverändert.
         */
        $qb =
            $this->db
                ->getQueryBuilder();

        $qb
            ->update(
                'sharedmail_access'
            )
            ->set(
                'permissions',
                $qb->createNamedParameter(
                    15,
                    IQueryBuilder::PARAM_INT
                )
            )
            ->where(
                $qb->expr()->eq(
                    'permissions',
                    $qb->createNamedParameter(
                        7,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        $qb->executeStatement();
    }
}