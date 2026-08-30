<?php

declare(strict_types=1);

namespace OCA\SharedMail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1001Date20260830220000 extends SimpleMigrationStep
{
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options,
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('sharedmail_access')) {
            return null;
        }

        $table = $schema->createTable('sharedmail_access');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);

        $table->addColumn('mailbox_id', Types::BIGINT, [
            'notnull' => true,
            'unsigned' => true,
        ]);

        $table->addColumn('principal_type', Types::STRING, [
            'notnull' => true,
            'length' => 16,
        ]);

        $table->addColumn('principal_id', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);

        $table->addColumn('permissions', Types::INTEGER, [
            'notnull' => true,
            'unsigned' => true,
            'default' => 7,
        ]);

        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'unsigned' => true,
        ]);

        $table->setPrimaryKey(['id']);

        $table->addUniqueIndex(
            ['mailbox_id', 'principal_type', 'principal_id'],
            'sharedmail_access_unique'
        );

        $table->addIndex(
            ['principal_type', 'principal_id'],
            'sharedmail_principal_idx'
        );

        return $schema;
    }
}