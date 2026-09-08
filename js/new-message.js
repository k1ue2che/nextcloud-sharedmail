document.addEventListener(
    'DOMContentLoaded',
    () => {
        const messageArea =
            document.getElementById(
                'sharedmail-message-area'
            )

        const header =
            document.querySelector(
                '.sharedmail-main-header'
            )

        if (
            !messageArea
            || !header
        ) {
            return
        }

        /*
         * Attachment-Limits.
         *
         * Diese Werte müssen mit
         * AttachmentUploadService übereinstimmen.
         */
        const MAX_ATTACHMENTS = 10
        const MAX_FILE_BYTES = 10 * 1024 * 1024
        const MAX_TOTAL_BYTES = 25 * 1024 * 1024

        let activeEditor = null
        let savedContent = null
        let draftOpenInProgress = false


        /*
         * Nextcloud CSRF-Token.
         */
        function getRequestToken() {
            if (
                window.OC
                && typeof OC.requestToken === 'string'
                && OC.requestToken !== ''
            ) {
                return OC.requestToken
            }

            const meta =
                document.querySelector(
                    'head meta[name="requesttoken"]'
                )

            if (meta) {
                return (
                    meta.getAttribute(
                        'content'
                    )
                    || ''
                )
            }

            return ''
        }


        function getActiveMailboxButton() {
            return document.querySelector(
                '.sharedmail-mailbox-button.active'
            )
        }


        function getActiveMailbox() {
            const button =
                getActiveMailboxButton()

            if (!button) {
                return null
            }

            const id =
                Number(
                    button.dataset.mailboxId
                    || 0
                )

            if (
                !Number.isInteger(id)
                || id <= 0
            ) {
                return null
            }

            return {
                id,

                name:
                    String(
                        button.dataset.mailboxName
                        || ''
                    ),

                email:
                    String(
                        button.dataset.mailboxEmail
                        || ''
                    ),
            }
        }


        /*
         * Plaintext für CKEditor in HTML umwandeln.
         */
        function escapeHtml(value) {
            return String(
                value
                ?? ''
            )
                .replaceAll(
                    '&',
                    '&amp;'
                )
                .replaceAll(
                    '<',
                    '&lt;'
                )
                .replaceAll(
                    '>',
                    '&gt;'
                )
                .replaceAll(
                    '"',
                    '&quot;'
                )
                .replaceAll(
                    "'",
                    '&#039;'
                )
        }


        function plainTextToHtml(value) {
            const text =
                String(
                    value
                    || ''
                )
                    .replace(
                        /\r\n/g,
                        '\n'
                    )
                    .replace(
                        /\r/g,
                        '\n'
                    )

            if (text === '') {
                return '<p></p>'
            }

            return text
                .split('\n')
                .map(
                    (line) => {
                        if (line === '') {
                            return '<p>&nbsp;</p>'
                        }

                        return (
                            '<p>'
                            + escapeHtml(line)
                            + '</p>'
                        )
                    }
                )
                .join('')
        }


        function getDraftInitialHtml(
            draft
        ) {
            const content =
                String(
                    draft?.body?.content
                    || ''
                )

            if (
                String(
                    draft?.body?.type
                    || ''
                ).toLowerCase() === 'html'
            ) {
                return (
                    content !== ''
                        ? content
                        : '<p></p>'
                )
            }

            return plainTextToHtml(
                content
            )
        }


        /*
         * Anhänge.
         */
        function formatFileSize(bytes) {
            const value =
                Number(
                    bytes
                    || 0
                )

            if (value < 1024) {
                return `${value} B`
            }

            if (
                value
                < 1024 * 1024
            ) {
                return `${
                    (
                        value
                        / 1024
                    ).toFixed(1)
                } KB`
            }

            return `${
                (
                    value
                    / 1024
                    / 1024
                ).toFixed(1)
            } MB`
        }


        function getTotalAttachmentSize(
            attachments
        ) {
            return attachments.reduce(
                (
                    total,
                    file
                ) => {
                    return (
                        total
                        + Number(
                            file?.size
                            || 0
                        )
                    )
                },
                0
            )
        }


        function getFileIdentity(
            file
        ) {
            return [
                file.name,
                file.size,
                file.lastModified,
            ].join(':')
        }


        function validateAttachmentSelection(
            currentAttachments,
            newFiles
        ) {
            const attachments = [
                ...currentAttachments,
            ]

            const existingIds =
                new Set(
                    attachments.map(
                        getFileIdentity
                    )
                )

            for (const file of newFiles) {
                if (
                    !(file instanceof File)
                ) {
                    continue
                }

                if (
                    file.size
                    > MAX_FILE_BYTES
                ) {
                    throw new Error(
                        `Der Anhang "${file.name}" ist größer als 10 MB.`
                    )
                }

                if (file.size <= 0) {
                    throw new Error(
                        `Der Anhang "${file.name}" ist leer.`
                    )
                }

                const identity =
                    getFileIdentity(
                        file
                    )

                if (
                    existingIds.has(
                        identity
                    )
                ) {
                    continue
                }

                attachments.push(
                    file
                )

                existingIds.add(
                    identity
                )
            }

            if (
                attachments.length
                > MAX_ATTACHMENTS
            ) {
                throw new Error(
                    `Es können maximal ${MAX_ATTACHMENTS} Anhänge verwendet werden.`
                )
            }

            if (
                getTotalAttachmentSize(
                    attachments
                )
                > MAX_TOTAL_BYTES
            ) {
                throw new Error(
                    'Die Anhänge dürfen zusammen maximal 25 MB groß sein.'
                )
            }

            return attachments
        }


        /*
         * CKEditor.
         */
        async function destroyEditor() {
            if (!activeEditor) {
                return
            }

            try {
                await activeEditor.destroy()
            } catch (error) {
                console.error(
                    'SharedMail: Editor konnte nicht sauber beendet werden.',
                    error
                )
            }

            activeEditor = null
        }


        /*
         * Aktuelle Nachrichtenliste zwischenspeichern.
         */
        function saveCurrentView() {
            const fragment =
                document.createDocumentFragment()

            while (
                messageArea.firstChild
            ) {
                fragment.appendChild(
                    messageArea.firstChild
                )
            }

            savedContent =
                fragment
        }


        async function restorePreviousView() {
            await destroyEditor()

            messageArea.replaceChildren()

            if (savedContent) {
                messageArea.appendChild(
                    savedContent
                )
            }

            savedContent =
                null
        }


        async function reloadPreviousView() {
            await destroyEditor()

            messageArea.replaceChildren()

            savedContent =
                null

            if (
                window.SharedMailUI
                && typeof window.SharedMailUI
                    .reloadCurrentFolder
                    === 'function'
            ) {
                await window.SharedMailUI
                    .reloadCurrentFolder()
            }
        }


        /*
         * Eingabefelder.
         */
        function createField(
            labelText,
            input
        ) {
            const row =
                document.createElement(
                    'label'
                )

            row.className =
                'sharedmail-composer-field'

            const label =
                document.createElement(
                    'span'
                )

            label.textContent =
                labelText

            row.appendChild(
                label
            )

            row.appendChild(
                input
            )

            return row
        }


        function createInput() {
            const input =
                document.createElement(
                    'input'
                )

            input.type =
                'text'

            input.className =
                'sharedmail-composer-input'

            input.autocomplete =
                'off'

            return input
        }


        /*
         * Draft vom Server laden.
         */
        async function getDraft(
            mailbox,
            uid
        ) {
            const url =
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${mailbox.id}/drafts/${uid}`
                )

            const response =
                await fetch(
                    url,
                    {
                        method:
                            'GET',

                        headers: {
                            Accept:
                                'application/json',
                        },
                    }
                )

            let data = null

            try {
                data =
                    await response.json()
            } catch (error) {
                throw new Error(
                    'Der Server hat keine gültige Antwort geliefert.'
                )
            }

            if (
                !response.ok
                || !data?.success
                || !data?.draft
            ) {
                throw new Error(
                    data?.message
                    || 'Der Entwurf konnte nicht geladen werden.'
                )
            }

            return data.draft
        }


        /*
         * Bereits vorhandenen Draft-Anhang laden.
         */
        function getDraftAttachmentUrl(
            mailbox,
            draft,
            attachment
        ) {
            return (
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${mailbox.id}/messages/${draft.uid}/attachment`
                )
                + '?folder='
                + encodeURIComponent(
                    draft.folder
                    || 'Drafts'
                )
                + '&mimeId='
                + encodeURIComponent(
                    attachment.mimeId
                )
            )
        }


        async function loadDraftAttachments(
            mailbox,
            draft
        ) {
            const metadata =
                Array.isArray(
                    draft?.attachments
                )
                    ? draft.attachments
                    : []

            if (
                metadata.length
                === 0
            ) {
                return []
            }

            const files = []

            for (
                const attachment
                of metadata
            ) {
                const mimeId =
                    String(
                        attachment?.mimeId
                        || ''
                    )

                if (mimeId === '') {
                    throw new Error(
                        'Ein Anhang besitzt keine gültige MIME-ID.'
                    )
                }

                const response =
                    await fetch(
                        getDraftAttachmentUrl(
                            mailbox,
                            draft,
                            attachment
                        ),
                        {
                            method:
                                'GET',

                            headers: {
                                Accept:
                                    '*/*',
                            },
                        }
                    )

                if (!response.ok) {
                    throw new Error(
                        `Der Anhang "${
                            attachment.name
                            || 'Anhang'
                        }" konnte nicht geladen werden.`
                    )
                }

                const blob =
                    await response.blob()

                const name =
                    String(
                        attachment.name
                        || 'Anhang'
                    )

                const type =
                    String(
                        attachment.contentType
                        || blob.type
                        || 'application/octet-stream'
                    )

                files.push(
                    new File(
                        [
                            blob,
                        ],
                        name,
                        {
                            type,

                            lastModified:
                                Date.now(),
                        }
                    )
                )
            }

            return validateAttachmentSelection(
                [],
                files
            )
        }


        /*
         * Neue Nachricht senden.
         */
        async function sendMessage(
            mailbox,
            payload,
            attachments
        ) {
            const url =
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${mailbox.id}/compose`
                )

            const formData =
                new FormData()

            formData.append(
                'to',
                payload.to
            )

            formData.append(
                'cc',
                payload.cc
            )

            formData.append(
                'bcc',
                payload.bcc
            )

            formData.append(
                'subject',
                payload.subject
            )

            formData.append(
                'html',
                payload.html
            )

            /*
             * WICHTIG:
             *
             * Falls diese Mail aus einem gespeicherten
             * Draft heraus gesendet wird, muss dessen
             * aktuelle IMAP-UID an den Controller.
             *
             * Der Controller löscht den Draft erst
             * NACH erfolgreichem SMTP-Versand.
             */
            if (
                Number(
                    payload.draftUid
                ) > 0
            ) {
                formData.append(
                    'draftUid',
                    String(
                        payload.draftUid
                    )
                )
            }

            for (
                const file
                of attachments
            ) {
                formData.append(
                    'attachments[]',
                    file,
                    file.name
                )
            }

            const response =
                await fetch(
                    url,
                    {
                        method:
                            'POST',

                        headers: {
                            Accept:
                                'application/json',

                            requesttoken:
                                getRequestToken(),
                        },

                        body:
                            formData,
                    }
                )

            let data = null

            try {
                data =
                    await response.json()
            } catch (error) {
                throw new Error(
                    'Der Server hat keine gültige Antwort geliefert.'
                )
            }

            if (
                !response.ok
                || !data?.success
            ) {
                console.error(
                    'SharedMail Compose API Fehler:',
                    data
                )

                throw new Error(
                    data?.message
                    || 'Die Nachricht konnte nicht gesendet werden.'
                )
            }

            return data
        }


        /*
         * Gespeicherten Antwort-Draft senden.
         */
        async function sendReplyDraft(
            mailbox,
            draft,
            payload,
            attachments
        ) {
            const sourceUid =
                Number(
                    draft?.sourceUid
                    || 0
                )

            const sourceFolder =
                String(
                    draft?.sourceFolder
                    || ''
                )

            if (
                sourceUid <= 0
                || sourceFolder === ''
            ) {
                throw new Error(
                    'Die Originalnachricht der Antwort konnte nicht bestimmt werden.'
                )
            }

            const url =
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${mailbox.id}/messages/${sourceUid}/reply`
                )

            const formData =
                new FormData()

            formData.append(
                'folder',
                sourceFolder
            )

            formData.append(
                'to',
                payload.to
            )

            formData.append(
                'subject',
                payload.subject
            )

            formData.append(
                'html',
                payload.html
            )

            /*
             * Auch Antwort-Drafts müssen nach
             * erfolgreichem Versand gelöscht werden.
             */
            if (
                Number(
                    payload.draftUid
                ) > 0
            ) {
                formData.append(
                    'draftUid',
                    String(
                        payload.draftUid
                    )
                )
            }

            for (
                const file
                of attachments
            ) {
                formData.append(
                    'attachments[]',
                    file,
                    file.name
                )
            }

            const response =
                await fetch(
                    url,
                    {
                        method:
                            'POST',

                        headers: {
                            Accept:
                                'application/json',

                            requesttoken:
                                getRequestToken(),
                        },

                        body:
                            formData,
                    }
                )

            let data = null

            try {
                data =
                    await response.json()
            } catch (error) {
                throw new Error(
                    'Der Server hat keine gültige Antwort geliefert.'
                )
            }

            if (
                !response.ok
                || !data?.success
            ) {
                console.error(
                    'SharedMail Reply API Fehler:',
                    data
                )

                throw new Error(
                    data?.message
                    || 'Die Antwort konnte nicht gesendet werden.'
                )
            }

            return data
        }


        /*
         * Draft speichern oder vorhandenen ersetzen.
         */
        async function saveDraft(
            mailbox,
            payload,
            attachments,
            draftUid,
            sourceFolder = '',
            sourceUid = 0
        ) {
            const url =
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${mailbox.id}/drafts`
                )

            const formData =
                new FormData()

            formData.append(
                'to',
                payload.to
            )

            formData.append(
                'cc',
                payload.cc
            )

            formData.append(
                'bcc',
                payload.bcc
            )

            formData.append(
                'subject',
                payload.subject
            )

            formData.append(
                'html',
                payload.html
            )

            if (
                Number(
                    draftUid
                ) > 0
            ) {
                formData.append(
                    'draftUid',
                    String(
                        draftUid
                    )
                )
            }

            if (
                sourceFolder !== ''
                && Number(
                    sourceUid
                ) > 0
            ) {
                formData.append(
                    'sourceFolder',
                    sourceFolder
                )

                formData.append(
                    'sourceUid',
                    String(
                        sourceUid
                    )
                )
            }

            for (
                const file
                of attachments
            ) {
                formData.append(
                    'attachments[]',
                    file,
                    file.name
                )
            }

            const response =
                await fetch(
                    url,
                    {
                        method:
                            'POST',

                        headers: {
                            Accept:
                                'application/json',

                            requesttoken:
                                getRequestToken(),
                        },

                        body:
                            formData,
                    }
                )

            let data = null

            try {
                data =
                    await response.json()
            } catch (error) {
                throw new Error(
                    'Der Server hat keine gültige Antwort geliefert.'
                )
            }

            if (
                !response.ok
                || !data?.success
            ) {
                console.error(
                    'SharedMail Draft API Fehler:',
                    data
                )

                throw new Error(
                    data?.message
                    || 'Der Entwurf konnte nicht gespeichert werden.'
                )
            }

            return data
        }


        /*
         * Composer öffnen.
         *
         * initialDraft === null:
         * neue Nachricht.
         *
         * initialDraft !== null:
         * vorhandenen IMAP-Draft bearbeiten.
         */
        async function openComposer(
            initialDraft = null
        ) {
            const mailbox =
                getActiveMailbox()

            if (!mailbox) {
                return
            }

            if (!window.SharedMailEditor) {
                console.error(
                    'SharedMailEditor wurde nicht geladen.'
                )

                return
            }

            /*
             * Nicht mehrere Composer gleichzeitig.
             */
            if (activeEditor) {
                return
            }

            saveCurrentView()

            const isDraft =
                initialDraft !== null
                && Number(
                    initialDraft?.uid
                    || 0
                ) > 0

            const draftKind =
                String(
                    initialDraft?.kind
                    || 'compose'
                )
                    .trim()
                    .toLowerCase()

            const isReplyDraft =
                isDraft
                && draftKind === 'reply'

            const sourceFolder =
                String(
                    initialDraft?.sourceFolder
                    || ''
                )

            const sourceUid =
                Number(
                    initialDraft?.sourceUid
                    || 0
                )

            let attachments = []

            /*
             * Ganz wichtig:
             *
             * Diese Variable enthält immer die
             * AKTUELLE Draft-UID.
             *
             * Beim erneuten Speichern ändert sich
             * die IMAP-UID. Deshalb wird sie weiter
             * unten nach jedem Save aktualisiert.
             */
            let currentDraftUid =
                isDraft
                    ? Number(
                        initialDraft.uid
                    )
                    : 0

            let viewNeedsReload =
                false


            /*
             * Composer.
             */
            const composer =
                document.createElement(
                    'div'
                )

            composer.className =
                'sharedmail-composer'


            const composerHeader =
                document.createElement(
                    'div'
                )

            composerHeader.className =
                'sharedmail-composer-header'


            const heading =
                document.createElement(
                    'h2'
                )

            if (isReplyDraft) {
                heading.textContent =
                    'Antwortentwurf bearbeiten'
            } else if (isDraft) {
                heading.textContent =
                    'Entwurf bearbeiten'
            } else {
                heading.textContent =
                    'Neue Nachricht'
            }

            composerHeader.appendChild(
                heading
            )

            composer.appendChild(
                composerHeader
            )


            /*
             * Felder.
             */
            const fields =
                document.createElement(
                    'div'
                )

            fields.className =
                'sharedmail-composer-fields'


            const fromInput =
                createInput()

            fromInput.value =
                mailbox.name !== ''
                    ? `${mailbox.name} <${mailbox.email}>`
                    : mailbox.email

            fromInput.readOnly =
                true


            const toInput =
                createInput()

            toInput.value =
                String(
                    initialDraft?.to
                    || ''
                )


            const ccInput =
                createInput()

            ccInput.value =
                String(
                    initialDraft?.cc
                    || ''
                )


            const bccInput =
                createInput()

            bccInput.value =
                String(
                    initialDraft?.bcc
                    || ''
                )


            const subjectInput =
                createInput()

            subjectInput.value =
                String(
                    initialDraft?.subject
                    || ''
                )


            fields.appendChild(
                createField(
                    'Von',
                    fromInput
                )
            )

            fields.appendChild(
                createField(
                    'An',
                    toInput
                )
            )

            /*
             * Reply-Endpunkt unterstützt derzeit
             * nur einen Empfänger und kein CC/BCC.
             */
            if (!isReplyDraft) {
                fields.appendChild(
                    createField(
                        'CC',
                        ccInput
                    )
                )

                fields.appendChild(
                    createField(
                        'BCC',
                        bccInput
                    )
                )
            }

            fields.appendChild(
                createField(
                    'Betreff',
                    subjectInput
                )
            )

            composer.appendChild(
                fields
            )


            /*
             * CKEditor.
             */
            const editorWrapper =
                document.createElement(
                    'div'
                )

            editorWrapper.className =
                'sharedmail-composer-editor-wrapper'


            const editorElement =
                document.createElement(
                    'div'
                )

            editorElement.className =
                'sharedmail-composer-editor'


            editorWrapper.appendChild(
                editorElement
            )

            composer.appendChild(
                editorWrapper
            )


            /*
             * Anhänge.
             */
            const attachmentArea =
                document.createElement(
                    'div'
                )

            attachmentArea.className =
                'sharedmail-composer-attachments'


            const attachmentToolbar =
                document.createElement(
                    'div'
                )

            attachmentToolbar.className =
                'sharedmail-composer-attachment-toolbar'


            const attachmentButton =
                document.createElement(
                    'button'
                )

            attachmentButton.type =
                'button'

            attachmentButton.className =
                'sharedmail-composer-attachment-button'

            attachmentButton.textContent =
                '📎 Datei anhängen'


            const attachmentInput =
                document.createElement(
                    'input'
                )

            attachmentInput.type =
                'file'

            attachmentInput.multiple =
                true

            attachmentInput.hidden =
                true


            const attachmentSummary =
                document.createElement(
                    'span'
                )

            attachmentSummary.className =
                'sharedmail-composer-attachment-summary'


            const attachmentList =
                document.createElement(
                    'div'
                )

            attachmentList.className =
                'sharedmail-composer-attachment-list'


            attachmentToolbar.appendChild(
                attachmentButton
            )

            attachmentToolbar.appendChild(
                attachmentSummary
            )

            attachmentToolbar.appendChild(
                attachmentInput
            )

            attachmentArea.appendChild(
                attachmentToolbar
            )

            attachmentArea.appendChild(
                attachmentList
            )

            composer.appendChild(
                attachmentArea
            )


            /*
             * Status.
             */
            const status =
                document.createElement(
                    'div'
                )

            status.className =
                'sharedmail-composer-status'

            composer.appendChild(
                status
            )


            /*
             * Footer.
             */
            const footer =
                document.createElement(
                    'div'
                )

            footer.className =
                'sharedmail-composer-footer'


            const cancelButton =
                document.createElement(
                    'button'
                )

            cancelButton.type =
                'button'

            cancelButton.className =
                'sharedmail-composer-cancel'

            cancelButton.textContent =
                'Abbrechen'


            const draftButton =
                document.createElement(
                    'button'
                )

            draftButton.type =
                'button'

            draftButton.className =
                'sharedmail-composer-draft'

            draftButton.textContent =
                isDraft
                    ? 'Entwurf aktualisieren'
                    : 'Entwurf speichern'


            const sendButton =
                document.createElement(
                    'button'
                )

            sendButton.type =
                'button'

            sendButton.className =
                'sharedmail-composer-send primary'

            sendButton.textContent =
                'Senden'


            footer.appendChild(
                cancelButton
            )

            footer.appendChild(
                draftButton
            )

            footer.appendChild(
                sendButton
            )

            composer.appendChild(
                footer
            )

            messageArea.appendChild(
                composer
            )


            function setBusy(
                busy
            ) {
                draftButton.disabled =
                    busy

                sendButton.disabled =
                    busy

                cancelButton.disabled =
                    busy

                attachmentButton.disabled =
                    busy
            }


            function renderAttachments() {
                attachmentList.replaceChildren()

                const totalBytes =
                    getTotalAttachmentSize(
                        attachments
                    )

                if (
                    attachments.length
                    === 0
                ) {
                    attachmentSummary.textContent =
                        'Keine Anhänge'

                    return
                }

                attachmentSummary.textContent =
                    `${attachments.length} Datei${
                        attachments.length === 1
                            ? ''
                            : 'en'
                    } · ${formatFileSize(totalBytes)}`

                attachments.forEach(
                    (
                        file,
                        index
                    ) => {
                        const item =
                            document.createElement(
                                'div'
                            )

                        item.className =
                            'sharedmail-composer-attachment-item'


                        const info =
                            document.createElement(
                                'span'
                            )

                        info.className =
                            'sharedmail-composer-attachment-info'

                        info.textContent =
                            `${file.name} · ${formatFileSize(file.size)}`


                        const removeButton =
                            document.createElement(
                                'button'
                            )

                        removeButton.type =
                            'button'

                        removeButton.className =
                            'sharedmail-composer-attachment-remove'

                        removeButton.textContent =
                            'Entfernen'


                        removeButton.addEventListener(
                            'click',
                            () => {
                                attachments =
                                    attachments.filter(
                                        (
                                            currentFile,
                                            currentIndex
                                        ) => {
                                            return (
                                                currentIndex
                                                !== index
                                            )
                                        }
                                    )

                                status.textContent =
                                    ''

                                renderAttachments()
                            }
                        )

                        item.appendChild(
                            info
                        )

                        item.appendChild(
                            removeButton
                        )

                        attachmentList.appendChild(
                            item
                        )
                    }
                )
            }


            attachmentButton.addEventListener(
                'click',
                () => {
                    attachmentInput.click()
                }
            )


            attachmentInput.addEventListener(
                'change',
                () => {
                    try {
                        attachments =
                            validateAttachmentSelection(
                                attachments,
                                Array.from(
                                    attachmentInput.files
                                    || []
                                )
                            )

                        status.textContent =
                            ''

                        renderAttachments()
                    } catch (error) {
                        status.textContent =
                            error instanceof Error
                                ? error.message
                                : 'Der Anhang konnte nicht hinzugefügt werden.'
                    } finally {
                        attachmentInput.value =
                            ''
                    }
                }
            )


            renderAttachments()


            /*
             * Editor starten.
             */
            try {
                activeEditor =
                    await window
                        .SharedMailEditor
                        .create(
                            editorElement,

                            isDraft
                                ? getDraftInitialHtml(
                                    initialDraft
                                )
                                : '<p></p>'
                        )
            } catch (error) {
                console.error(
                    'SharedMail: Editor konnte nicht gestartet werden.',
                    error
                )

                status.textContent =
                    'Der Editor konnte nicht geladen werden.'

                return
            }


            /*
             * Bereits vorhandene Anhänge eines
             * Drafts erneut laden.
             */
            if (
                isDraft
                && Array.isArray(
                    initialDraft.attachments
                )
                && initialDraft
                    .attachments
                    .length > 0
            ) {
                setBusy(
                    true
                )

                status.textContent =
                    'Anhänge des Entwurfs werden geladen …'

                try {
                    attachments =
                        await loadDraftAttachments(
                            mailbox,
                            initialDraft
                        )

                    renderAttachments()

                    status.textContent =
                        'Entwurf wurde vollständig geladen.'

                    setBusy(
                        false
                    )
                } catch (error) {
                    console.error(
                        'SharedMail: Draft-Anhänge konnten nicht geladen werden.',
                        error
                    )

                    status.textContent =
                        error instanceof Error
                            ? error.message
                            : 'Die Anhänge des Entwurfs konnten nicht geladen werden.'

                    /*
                     * Nicht speichern/senden, solange
                     * bestehende Anhänge fehlen.
                     */
                    draftButton.disabled =
                        true

                    sendButton.disabled =
                        true

                    attachmentButton.disabled =
                        true

                    cancelButton.disabled =
                        false
                }
            }


            toInput.focus()


            /*
             * Abbrechen.
             */
            cancelButton.addEventListener(
                'click',
                async () => {
                    if (viewNeedsReload) {
                        await reloadPreviousView()

                        return
                    }

                    await restorePreviousView()
                }
            )


            /*
             * Entwurf speichern bzw. aktualisieren.
             */
            draftButton.addEventListener(
                'click',
                async () => {
                    if (!activeEditor) {
                        status.textContent =
                            'Der Editor ist noch nicht bereit.'

                        return
                    }


                    const payload = {
                        to:
                            String(
                                toInput.value
                                || ''
                            ).trim(),

                        cc:
                            isReplyDraft
                                ? ''
                                : String(
                                    ccInput.value
                                    || ''
                                ).trim(),

                        bcc:
                            isReplyDraft
                                ? ''
                                : String(
                                    bccInput.value
                                    || ''
                                ).trim(),

                        subject:
                            String(
                                subjectInput.value
                                || ''
                            ).trim(),

                        html:
                            String(
                                activeEditor.getData()
                                || ''
                            ).trim(),
                    }


                    const oldButtonText =
                        draftButton.textContent


                    setBusy(
                        true
                    )

                    draftButton.textContent =
                        'Wird gespeichert …'

                    status.textContent =
                        'Entwurf wird gespeichert …'


                    try {
                        const result =
                            await saveDraft(
                                mailbox,
                                payload,
                                attachments,
                                currentDraftUid,
                                sourceFolder,
                                sourceUid
                            )


                        const returnedUid =
                            Number(
                                result.draftUid
                                || 0
                            )


                        if (returnedUid > 0) {
                            /*
                             * Sehr wichtig:
                             *
                             * Der Server legt beim
                             * Update einen neuen Draft
                             * an und entfernt danach den
                             * alten.
                             *
                             * Deshalb ab jetzt die NEUE
                             * UID verwenden.
                             */
                            currentDraftUid =
                                returnedUid
                        }


                        viewNeedsReload =
                            true


                        if (result.warning) {
                            console.warn(
                                'SharedMail:',
                                result.warning
                            )
                        }


                        status.textContent =
                            result.message
                            || 'Der Entwurf wurde gespeichert.'


                        draftButton.textContent =
                            'Entwurf aktualisieren'
                    } catch (error) {
                        console.error(
                            'SharedMail: Entwurf konnte nicht gespeichert werden.',
                            error
                        )

                        status.textContent =
                            error instanceof Error
                                ? error.message
                                : 'Der Entwurf konnte nicht gespeichert werden.'

                        draftButton.textContent =
                            oldButtonText
                    } finally {
                        setBusy(
                            false
                        )
                    }
                }
            )


            /*
             * Nachricht senden.
             */
            sendButton.addEventListener(
                'click',
                async () => {
                    if (!activeEditor) {
                        return
                    }


                    const html =
                        String(
                            activeEditor.getData()
                            || ''
                        ).trim()


                    const to =
                        String(
                            toInput.value
                            || ''
                        ).trim()


                    if (to === '') {
                        status.textContent =
                            'Bitte mindestens einen Empfänger angeben.'

                        toInput.focus()

                        return
                    }


                    if (html === '') {
                        status.textContent =
                            'Bitte einen Nachrichtentext eingeben.'

                        activeEditor
                            .editing
                            .view
                            .focus()

                        return
                    }


                    /*
                     * ENTSCHEIDENDER BLOCK:
                     *
                     * Hier muss currentDraftUid
                     * mitgesendet werden.
                     *
                     * Genau das fehlte in deiner
                     * bisherigen Datei.
                     */
                    const payload = {
                        to,

                        cc:
                            isReplyDraft
                                ? ''
                                : String(
                                    ccInput.value
                                    || ''
                                ).trim(),

                        bcc:
                            isReplyDraft
                                ? ''
                                : String(
                                    bccInput.value
                                    || ''
                                ).trim(),

                        subject:
                            String(
                                subjectInput.value
                                || ''
                            ).trim(),

                        html,

                        draftUid:
                            currentDraftUid,
                    }


                    setBusy(
                        true
                    )

                    sendButton.textContent =
                        'Wird gesendet …'

                    status.textContent =
                        isReplyDraft
                            ? 'Antwort wird versendet …'
                            : 'Nachricht wird versendet …'


                    try {
                        let result = null


                        if (
                            isReplyDraft
                            && sourceUid > 0
                            && sourceFolder !== ''
                        ) {
                            result =
                                await sendReplyDraft(
                                    mailbox,
                                    initialDraft,
                                    payload,
                                    attachments
                                )
                        } else {
                            result =
                                await sendMessage(
                                    mailbox,
                                    payload,
                                    attachments
                                )
                        }


                        /*
                         * Debug-Hilfe.
                         *
                         * Nach erfolgreichem Versand
                         * können wir hier sehen, ob der
                         * Server den Draft gelöscht hat.
                         */
                        console.log(
                            'SharedMail Send Result:',
                            result
                        )


                        if (result.warning) {
                            console.warn(
                                'SharedMail:',
                                result.warning
                            )
                        }


                        if (
                            currentDraftUid > 0
                            && result.draftDeleted === false
                        ) {
                            console.warn(
                                'SharedMail: Nachricht wurde gesendet, aber der Draft wurde serverseitig nicht gelöscht.',
                                {
                                    draftUid:
                                        currentDraftUid,

                                    result,
                                }
                            )
                        }


                        await destroyEditor()


                        messageArea.replaceChildren()


                        savedContent =
                            null


                        attachments =
                            []


                        /*
                         * Mail wurde erfolgreich
                         * versendet.
                         *
                         * currentDraftUid kann jetzt
                         * verworfen werden.
                         */
                        currentDraftUid =
                            0


                        /*
                         * Ordner neu laden.
                         *
                         * Dadurch:
                         * - Draft verschwindet sofort
                         * - Draft-Zähler aktualisiert sich
                         * - Sent-Zähler aktualisiert sich
                         */
                        if (
                            window.SharedMailUI
                            && typeof window.SharedMailUI
                                .reloadCurrentFolder
                                === 'function'
                        ) {
                            await window.SharedMailUI
                                .reloadCurrentFolder()
                        }
                    } catch (error) {
                        console.error(
                            'SharedMail: Nachricht konnte nicht gesendet werden.',
                            error
                        )

                        status.textContent =
                            error instanceof Error
                                ? error.message
                                : 'Die Nachricht konnte nicht gesendet werden.'

                        sendButton.textContent =
                            'Erneut senden'

                        setBusy(
                            false
                        )
                    }
                }
            )
        }


        /*
         * Vorhandenen IMAP-Draft öffnen.
         */
        async function openDraftByUid(
            uid
        ) {
            if (draftOpenInProgress) {
                return
            }


            const mailbox =
                getActiveMailbox()


            const draftUid =
                Number(
                    uid
                    || 0
                )


            if (
                !mailbox
                || !Number.isInteger(
                    draftUid
                )
                || draftUid <= 0
            ) {
                return
            }


            draftOpenInProgress =
                true


            const loading =
                document.createElement(
                    'div'
                )

            loading.className =
                'sharedmail-message-loading'

            loading.textContent =
                'Entwurf wird geladen …'


            /*
             * Liste bleibt sichtbar.
             */
            messageArea.prepend(
                loading
            )


            try {
                const draft =
                    await getDraft(
                        mailbox,
                        draftUid
                    )


                loading.remove()


                await openComposer(
                    draft
                )
            } catch (error) {
                loading.remove()


                console.error(
                    'SharedMail: Entwurf konnte nicht geöffnet werden.',
                    error
                )


                const errorElement =
                    document.createElement(
                        'div'
                    )

                errorElement.className =
                    'sharedmail-message-error'

                errorElement.textContent =
                    error instanceof Error
                        ? error.message
                        : 'Der Entwurf konnte nicht geöffnet werden.'


                messageArea.prepend(
                    errorElement
                )


                window.setTimeout(
                    () => {
                        errorElement.remove()
                    },
                    8000
                )
            } finally {
                draftOpenInProgress =
                    false
            }
        }


        /*
         * Öffentliche Schnittstelle für main.js.
         */
        window.SharedMailCompose =
            Object.freeze({
                openDraftByUid,
            })


        /*
         * Neue-Mail-Button.
         */
        const composeButton =
            document.createElement(
                'button'
            )

        composeButton.type =
            'button'

        composeButton.className =
            'sharedmail-new-message-button primary'

        composeButton.textContent =
            '+ Neue Mail'


        composeButton.addEventListener(
            'click',
            () => {
                openComposer()
            }
        )


        header.appendChild(
            composeButton
        )
    }
)