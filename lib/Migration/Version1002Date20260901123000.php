<?php

declare(strict_types=1);

namespace OCA\SharedMail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1002Date20260901123000 extends SimpleMigrationStep
{
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options,
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (
            !$schema->hasTable(
                'sharedmail_read_state'
            )
        ) {
            $table =
                $schema->createTable(
                    'sharedmail_read_state'
                );

            $table->addColumn(
                'id',
                'bigint',
                [
                    'autoincrement' => true,
                    'notnull' => true,
                    'unsigned' => true,
                ]
            );

            $table->addColumn(
                'mailbox_id',
                'bigint',
                [
                    'notnull' => true,
                    'unsigned' => true,
                ]
            );

            $table->addColumn(
                'user_id',
                'string',
                [
                    'notnull' => true,
                    'length' => 64,
                ]
            );

            $table->addColumn(
                'folder',
                'string',
                [
                    'notnull' => true,
                    'length' => 255,
                ]
            );

            /*
             * IMAP-UIDs sind keine laufenden
             * Sequenznummern.
             *
             * Wir speichern ausschließlich die
             * stabile UID einer Nachricht.
             */
            $table->addColumn(
                'uid',
                'bigint',
                [
                    'notnull' => true,
                    'unsigned' => true,
                ]
            );

            /*
             * Unix-Zeitstempel des ersten
             * persönlichen Lesens.
             */
            $table->addColumn(
                'read_at',
                'bigint',
                [
                    'notnull' => true,
                    'unsigned' => true,
                ]
            );

            $table->setPrimaryKey(
                [
                    'id',
                ]
            );

            /*
             * Pro Benutzer darf es für eine
             * Nachricht nur genau einen
             * persönlichen Lesestatus geben.
             */
            $table->addUniqueIndex(
                [
                    'mailbox_id',
                    'user_id',
                    'folder',
                    'uid',
                ],
                'sharedmail_read_unique'
            );

            /*
             * Beschleunigt die Abfrage einer
             * Nachrichtenliste für einen Benutzer.
             */
            $table->addIndex(
                [
                    'mailbox_id',
                    'user_id',
                    'folder',
                ],
                'sharedmail_read_folder_idx'
            );
        }

        return $schema;
    }
}