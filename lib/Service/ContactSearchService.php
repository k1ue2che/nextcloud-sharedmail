<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use OCP\Contacts\IManager;
use Throwable;

class ContactSearchService
{
    private const MAX_RESULTS = 20;

    public function __construct(
        private readonly IManager $contactsManager,
    ) {
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     email: string,
     *     label: string
     * }>
     */
    public function search(
        string $query,
    ): array {
        $query =
            trim(
                $query
            );

        /*
         * Noch keine riesigen Kontaktlisten liefern.
         */
        if (
            mb_strlen(
                $query
            ) < 2
        ) {
            return [];
        }

        /*
         * Contacts API nicht verfügbar:
         * Mail-App funktioniert trotzdem weiter.
         */
        try {
            if (
                !$this
                    ->contactsManager
                    ->isEnabled()
            ) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        try {
            /*
             * FN = Full Name
             * EMAIL = E-Mail-Adressen
             */
            $contacts =
                $this
                    ->contactsManager
                    ->search(
                        $query,
                        [
                            'FN',
                            'EMAIL',
                        ],
                        [
                            'limit' =>
                                self::MAX_RESULTS,
                        ]
                    );
        } catch (Throwable) {
            return [];
        }

        $results = [];

        $seen =
            [];

        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $name =
                trim(
                    (string)(
                        $contact['FN']
                        ?? ''
                    )
                );

            $contactId =
                trim(
                    (string)(
                        $contact['id']
                        ?? ''
                    )
                );

            $emails =
                $contact['EMAIL']
                ?? [];

            if (!is_array($emails)) {
                $emails = [
                    $emails,
                ];
            }

            foreach ($emails as $emailValue) {
                /*
                 * Bei types=true könnte EMAIL selbst
                 * ein Array mit value/type sein.
                 *
                 * Wir setzen types zwar nicht, behandeln
                 * es trotzdem robust.
                 */
                if (is_array($emailValue)) {
                    $email =
                        trim(
                            (string)(
                                $emailValue['value']
                                ?? ''
                            )
                        );
                } else {
                    $email =
                        trim(
                            (string)$emailValue
                        );
                }

                if (
                    $email === ''
                    || !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    continue;
                }

                $normalizedEmail =
                    strtolower(
                        $email
                    );

                /*
                 * Gleiche Mailadresse nicht mehrfach
                 * anzeigen, falls sie in mehreren
                 * Adressbüchern vorkommt.
                 */
                if (
                    isset(
                        $seen[
                            $normalizedEmail
                        ]
                    )
                ) {
                    continue;
                }

                $seen[
                    $normalizedEmail
                ] =
                    true;

                $label =
                    $name !== ''
                        ? sprintf(
                            '%s <%s>',
                            $name,
                            $email
                        )
                        : $email;

                $results[] = [
                    'id' =>
                        $contactId !== ''
                            ? $contactId
                            : $normalizedEmail,

                    'name' =>
                        $name,

                    'email' =>
                        $email,

                    'label' =>
                        $label,
                ];

                if (
                    count($results)
                    >= self::MAX_RESULTS
                ) {
                    return $results;
                }
            }
        }

        /*
         * Für vorhersehbare Anzeige sortieren.
         */
        usort(
            $results,
            static function (
                array $left,
                array $right,
            ): int {
                return strcasecmp(
                    $left['label'],
                    $right['label']
                );
            }
        );

        return $results;
    }
}