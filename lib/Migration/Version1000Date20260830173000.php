<?php

declare(strict_types=1);

namespace OCA\SharedMail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260830173000 extends SimpleMigrationStep
{
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options,
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('sharedmail_mailboxes')) {
            return null;
        }

        $table = $schema->createTable('sharedmail_mailboxes');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);

        $table->addColumn('name', Types::STRING, [
            'notnull' => true,
            'length' => 128,
        ]);

        $table->addColumn('description', Types::TEXT, [
            'notnull' => false,
        ]);

        $table->addColumn('email', Types::STRING, [
            'notnull' => true,
            'length' => 254,
        ]);

        $table->addColumn('imap_host', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);

        $table->addColumn('imap_port', Types::INTEGER, [
            'notnull' => true,
            'unsigned' => true,
            'default' => 993,
        ]);

        $table->addColumn('imap_security', Types::STRING, [
            'notnull' => true,
            'length' => 16,
            'default' => 'ssl',
        ]);

        $table->addColumn('imap_username', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);

        $table->addColumn('imap_password', Types::TEXT, [
            'notnull' => false,
        ]);

        $table->addColumn('smtp_host', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);

        $table->addColumn('smtp_port', Types::INTEGER, [
            'notnull' => true,
            'unsigned' => true,
            'default' => 465,
        ]);

        $table->addColumn('smtp_security', Types::STRING, [
            'notnull' => true,
            'length' => 16,
            'default' => 'ssl',
        ]);

        $table->addColumn('smtp_username', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);

        $table->addColumn('smtp_password', Types::TEXT, [
            'notnull' => false,
        ]);

        $table->addColumn('enabled', Types::BOOLEAN, [
            'notnull' => true,
            'default' => true,
        ]);

        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'unsigned' => true,
        ]);

        $table->addColumn('updated_at', Types::BIGINT, [
            'notnull' => true,
            'unsigned' => true,
        ]);

        $table->setPrimaryKey(['id']);

        $table->addIndex(
            ['enabled'],
            'sharedmail_enabled_idx'
        );

        return $schema;
    }
}