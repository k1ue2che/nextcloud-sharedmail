<?php

declare(strict_types=1);

namespace OCA\SharedMail\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1005Date20261002113000 extends SimpleMigrationStep
{
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema =
            $schemaClosure();

        if (
            $schema->hasTable(
                'sharedmail_message_state'
            )
        ) {
            return null;
        }

        $table =
            $schema->createTable(
                'sharedmail_message_state'
            );

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' =>
                    true,

                'notnull' =>
                    true,

                'unsigned' =>
                    true,
            ]
        );

        $table->addColumn(
            'mailbox_id',
            Types::INTEGER,
            [
                'notnull' =>
                    true,

                'unsigned' =>
                    true,
            ]
        );

        $table->addColumn(
            'folder',
            Types::STRING,
            [
                'notnull' =>
                    true,

                'length' =>
                    512,
            ]
        );

        $table->addColumn(
            'uid',
            Types::BIGINT,
            [
                'notnull' =>
                    true,

                'unsigned' =>
                    true,
            ]
        );

        $table->addColumn(
            'status',
            Types::STRING,
            [
                'notnull' =>
                    true,

                'length' =>
                    32,

                'default' =>
                    'NEW',
            ]
        );

        $table->addColumn(
            'changed_by',
            Types::STRING,
            [
                'notnull' =>
                    false,

                'length' =>
                    255,

                'default' =>
                    null,
            ]
        );

        $table->addColumn(
            'changed_at',
            Types::BIGINT,
            [
                'notnull' =>
                    false,

                'unsigned' =>
                    true,

                'default' =>
                    null,
            ]
        );

        $table->setPrimaryKey(
            [
                'id',
            ]
        );

        /*
         * Eine Nachricht kann innerhalb eines
         * Postfachordners genau einen gemeinsamen
         * Workflow-Zustand besitzen.
         */
        $table->addUniqueIndex(
            [
                'mailbox_id',
                'folder',
                'uid',
            ],
            'sharedmail_msg_state_unique'
        );

        /*
         * Für spätere Workflow-Ansichten wie:
         *
         * - alle offenen Nachrichten
         * - alle wartenden Nachrichten
         * - erledigte Nachrichten
         */
        $table->addIndex(
            [
                'mailbox_id',
                'status',
            ],
            'sharedmail_msg_state_status'
        );

        return $schema;
    }
}