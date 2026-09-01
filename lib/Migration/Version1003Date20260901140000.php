<?php

declare(strict_types=1);

namespace OCA\SharedMail\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1003Date20260901140000 extends SimpleMigrationStep
{
    public function changeSchema(
        IOutput $output,
        Closure $schema,
        array $options
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schema();

        if (!$schema->hasTable('sharedmail_read_state')) {
            return $schema;
        }

        $table = $schema->getTable('sharedmail_read_state');

        /*
         * 1002 kannte nur:
         *
         * Zeile vorhanden = gelesen
         * keine Zeile       = kein persönlicher Zustand
         *
         * Ab 1003:
         *
         * is_read = 1       = persönlich gelesen
         * is_read = 0       = persönlich ungelesen
         * keine Zeile       = IMAP-\Seen als Fallback
         */

        if (!$table->hasColumn('is_read')) {
            $table->addColumn(
                'is_read',
                Types::BOOLEAN,
                [
                    'notnull' => true,
                    'default' => true,
                ]
            );
        }

        if (!$table->hasColumn('changed_at')) {
            $table->addColumn(
                'changed_at',
                Types::BIGINT,
                [
                    'notnull' => true,
                    'unsigned' => true,
                    'default' => 0,
                ]
            );
        }

        return $schema;
    }
}