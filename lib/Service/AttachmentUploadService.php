<?php

declare(strict_types=1);

namespace OCA\SharedMail\Service;

use InvalidArgumentException;
use OCP\IRequest;
use RuntimeException;

class AttachmentUploadService
{
    private const MAX_FILES = 10;

    private const MAX_FILE_BYTES = 10 * 1024 * 1024;

    private const MAX_TOTAL_BYTES = 25 * 1024 * 1024;

    /**
     * @return array<int, array{
     *     name: string,
     *     type: string,
     *     size: int,
     *     content: string
     * }>
     */
    public function getUploadedAttachments(
        IRequest $request,
        string $fieldName = 'attachments',
    ): array {
        $upload =
            $request->getUploadedFile(
                $fieldName
            );

        if (
            $upload === null
            || $upload === []
        ) {
            return [];
        }

        $files =
            $this->normalizeUploadArray(
                $upload
            );

        if (
            count($files)
            > self::MAX_FILES
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Es können maximal %d Anhänge versendet werden.',
                    self::MAX_FILES
                )
            );
        }

        $attachments = [];
        $totalBytes = 0;

        foreach ($files as $file) {
            $error =
                (int)(
                    $file['error']
                    ?? UPLOAD_ERR_NO_FILE
                );

            /*
             * Ein komplett leeres File-Feld ignorieren.
             */
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException(
                    $this->getUploadErrorMessage(
                        $error
                    )
                );
            }

            $temporaryPath =
                (string)(
                    $file['tmp_name']
                    ?? ''
                );

            if (
                $temporaryPath === ''
                || !is_file($temporaryPath)
                || !is_readable($temporaryPath)
            ) {
                throw new RuntimeException(
                    'Eine hochgeladene Datei konnte nicht gelesen werden.'
                );
            }

            $reportedSize =
                (int)(
                    $file['size']
                    ?? 0
                );

            $actualSize =
                filesize(
                    $temporaryPath
                );

            if ($actualSize === false) {
                throw new RuntimeException(
                    'Die Größe eines Anhangs konnte nicht ermittelt werden.'
                );
            }

            $size =
                (int)$actualSize;

            /*
             * Falls PHP eine Größe gemeldet hat,
             * nehmen wir sicherheitshalber den größeren Wert.
             */
            if ($reportedSize > $size) {
                $size =
                    $reportedSize;
            }

            if ($size <= 0) {
                throw new InvalidArgumentException(
                    'Leere Dateien können nicht als Anhang versendet werden.'
                );
            }

            if (
                $size
                > self::MAX_FILE_BYTES
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Der Anhang "%s" ist größer als 10 MB.',
                        $this->sanitizeFilename(
                            (string)(
                                $file['name']
                                ?? 'Datei'
                            )
                        )
                    )
                );
            }

            $totalBytes +=
                $size;

            if (
                $totalBytes
                > self::MAX_TOTAL_BYTES
            ) {
                throw new InvalidArgumentException(
                    'Die Anhänge dürfen zusammen maximal 25 MB groß sein.'
                );
            }

            $content =
                file_get_contents(
                    $temporaryPath
                );

            if ($content === false) {
                throw new RuntimeException(
                    'Ein Anhang konnte nicht gelesen werden.'
                );
            }

            $filename =
                $this->sanitizeFilename(
                    (string)(
                        $file['name']
                        ?? 'attachment'
                    )
                );

            $mimeType =
                $this->detectMimeType(
                    $temporaryPath,
                    (string)(
                        $file['type']
                        ?? ''
                    )
                );

            $attachments[] = [
                'name' =>
                    $filename,

                'type' =>
                    $mimeType,

                'size' =>
                    strlen($content),

                'content' =>
                    $content,
            ];
        }

        return $attachments;
    }

    /**
     * Normalisiert sowohl einen einzelnen PHP-Upload
     * als auch die Struktur von:
     *
     * attachments[]
     *
     * @param array<string, mixed> $upload
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeUploadArray(
        array $upload,
    ): array {
        $names =
            $upload['name']
            ?? null;

        /*
         * Einzelner Upload.
         */
        if (!is_array($names)) {
            return [
                [
                    'name' =>
                        $upload['name']
                        ?? '',

                    'type' =>
                        $upload['type']
                        ?? '',

                    'tmp_name' =>
                        $upload['tmp_name']
                        ?? '',

                    'error' =>
                        $upload['error']
                        ?? UPLOAD_ERR_NO_FILE,

                    'size' =>
                        $upload['size']
                        ?? 0,
                ],
            ];
        }

        /*
         * Mehrfachupload.
         */
        $files = [];

        foreach (
            array_keys($names)
            as $index
        ) {
            $files[] = [
                'name' =>
                    $upload['name'][$index]
                    ?? '',

                'type' =>
                    $upload['type'][$index]
                    ?? '',

                'tmp_name' =>
                    $upload['tmp_name'][$index]
                    ?? '',

                'error' =>
                    $upload['error'][$index]
                    ?? UPLOAD_ERR_NO_FILE,

                'size' =>
                    $upload['size'][$index]
                    ?? 0,
            ];
        }

        return $files;
    }

    private function sanitizeFilename(
        string $filename,
    ): string {
        /*
         * Windows-Pfade ebenfalls behandeln.
         */
        $filename =
            str_replace(
                '\\',
                '/',
                $filename
            );

        $filename =
            basename(
                $filename
            );

        /*
         * Steuerzeichen aus MIME-Headern fernhalten.
         */
        $filename =
            preg_replace(
                '/[\x00-\x1F\x7F]+/u',
                '',
                $filename
            ) ?? '';

        $filename =
            trim(
                $filename,
                " \t\n\r\0\x0B."
            );

        if ($filename === '') {
            $filename =
                'attachment';
        }

        /*
         * Extrem lange Dateinamen begrenzen.
         */
        if (
            function_exists('mb_strlen')
            && function_exists('mb_substr')
            && mb_strlen(
                $filename,
                'UTF-8'
            ) > 180
        ) {
            $filename =
                mb_substr(
                    $filename,
                    0,
                    180,
                    'UTF-8'
                );
        } elseif (
            strlen($filename)
            > 180
        ) {
            $filename =
                substr(
                    $filename,
                    0,
                    180
                );
        }

        return $filename;
    }

    private function detectMimeType(
        string $temporaryPath,
        string $reportedType,
    ): string {
        $mimeType = '';

        /*
         * Nicht dem Browser-MIME-Type vertrauen,
         * wenn PHP fileinfo verfügbar ist.
         */
        if (
            function_exists(
                'finfo_open'
            )
        ) {
            $finfo =
                finfo_open(
                    FILEINFO_MIME_TYPE
                );

            if ($finfo !== false) {
                $detected =
                    finfo_file(
                        $finfo,
                        $temporaryPath
                    );

                finfo_close(
                    $finfo
                );

                if (
                    is_string($detected)
                    && $detected !== ''
                ) {
                    $mimeType =
                        $detected;
                }
            }
        }

        if ($mimeType === '') {
            $reportedType =
                strtolower(
                    trim(
                        $reportedType
                    )
                );

            if (
                preg_match(
                    '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i',
                    $reportedType
                ) === 1
            ) {
                $mimeType =
                    $reportedType;
            }
        }

        if ($mimeType === '') {
            $mimeType =
                'application/octet-stream';
        }

        return $mimeType;
    }

    private function getUploadErrorMessage(
        int $error,
    ): string {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE =>
                'Ein Anhang überschreitet die zulässige Upload-Größe.',

            UPLOAD_ERR_PARTIAL =>
                'Ein Anhang wurde nur unvollständig hochgeladen.',

            UPLOAD_ERR_NO_TMP_DIR =>
                'Auf dem Server fehlt das temporäre Upload-Verzeichnis.',

            UPLOAD_ERR_CANT_WRITE =>
                'Ein Anhang konnte auf dem Server nicht zwischengespeichert werden.',

            UPLOAD_ERR_EXTENSION =>
                'Ein Anhang wurde durch eine PHP-Erweiterung abgewiesen.',

            default =>
                'Beim Hochladen eines Anhangs ist ein Fehler aufgetreten.',
        };
    }
}