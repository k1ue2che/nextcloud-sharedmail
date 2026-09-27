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
                    'div'
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
         * Empfänger-Chips.
         *
         * Intern arbeitet das Backend weiterhin mit
         * kommagetrennten E-Mail-Adressen. Die Chips
         * sind ausschließlich die UI-Darstellung.
         */
        function parseRecipientToken(value) {
            const token =
                String(
                    value
                    || ''
                ).trim()

            if (token === '') {
                return null
            }

            let name = ''
            let email = token

            const angleMatch =
                token.match(
                    /^\s*"?([^"<>]*)"?\s*<([^<>\s,;@]+@[^<>\s,;@]+)>\s*$/
                )

            if (angleMatch) {
                name =
                    String(
                        angleMatch[1]
                        || ''
                    ).trim()

                email =
                    String(
                        angleMatch[2]
                        || ''
                    ).trim()
            }

            if (
                !/^[^\s,;<>@]+@[^\s,;<>@]+$/.test(
                    email
                )
            ) {
                return null
            }

            return {
                email,
                name,
            }
        }


        function createRecipientControl(
            initialValue = '',
            options = {}
        ) {
            const maxRecipients =
                Number.isFinite(
                    options.maxRecipients
                )
                    ? Math.max(
                        1,
                        Number(
                            options.maxRecipients
                        )
                    )
                    : Infinity

            const control =
                document.createElement(
                    'div'
                )

            control.className =
                'sharedmail-recipient-control'

            const chips =
                document.createElement(
                    'div'
                )

            chips.className =
                'sharedmail-recipient-chips'

            const input =
                createInput()

            input.classList.add(
                'sharedmail-recipient-input'
            )

            input.placeholder =
                options.placeholder
                || 'Empfänger eingeben …'

            const recipients = []
            let externallyDisabled = false

            control.appendChild(
                chips
            )

            control.appendChild(
                input
            )


            function render() {
                chips.replaceChildren()

                recipients.forEach(
                    (
                        recipient,
                        index
                    ) => {
                        const chip =
                            document.createElement(
                                'span'
                            )

                        chip.className =
                            'sharedmail-recipient-chip'

                        chip.title =
                            recipient.name !== ''
                                ? `${recipient.name} <${recipient.email}>`
                                : recipient.email

                        const text =
                            document.createElement(
                                'span'
                            )

                        text.className =
                            'sharedmail-recipient-chip-text'

                        text.textContent =
                            recipient.name !== ''
                                ? recipient.name
                                : recipient.email

                        const removeButton =
                            document.createElement(
                                'button'
                            )

                        removeButton.type =
                            'button'

                        removeButton.className =
                            'sharedmail-recipient-chip-remove'

                        removeButton.textContent =
                            '×'

                        removeButton.setAttribute(
                            'aria-label',
                            `Empfänger ${recipient.email} entfernen`
                        )

                        removeButton.addEventListener(
                            'click',
                            () => {
                                recipients.splice(
                                    index,
                                    1
                                )

                                render()

                                input.focus()
                            }
                        )

                        chip.appendChild(
                            text
                        )

                        chip.appendChild(
                            removeButton
                        )

                        chips.appendChild(
                            chip
                        )
                    }
                )

                input.disabled =
                    externallyDisabled
                    || recipients.length
                        >= maxRecipients
            }


            function add(
                email,
                name = ''
            ) {
                const parsed =
                    parseRecipientToken(
                        email
                    )

                if (!parsed) {
                    return false
                }

                parsed.name =
                    String(
                        name
                        || parsed.name
                        || ''
                    ).trim()

                const key =
                    parsed.email.toLowerCase()

                const duplicate =
                    recipients.some(
                        (recipient) => {
                            return (
                                recipient.email
                                    .toLowerCase()
                                === key
                            )
                        }
                    )

                if (duplicate) {
                    input.value = ''
                    return true
                }

                if (
                    recipients.length
                    >= maxRecipients
                ) {
                    return false
                }

                recipients.push(
                    parsed
                )

                input.value = ''
                render()

                return true
            }


            function commitInput() {
                const parsed =
                    parseRecipientToken(
                        input.value
                    )

                if (!parsed) {
                    return false
                }

                return add(
                    parsed.email,
                    parsed.name
                )
            }


            function consumeSeparatedInput() {
                const value =
                    String(
                        input.value
                        || ''
                    )

                if (!/[;,]/.test(value)) {
                    return
                }

                const endsWithSeparator =
                    /[;,]\s*$/.test(
                        value
                    )

                const parts =
                    value.split(
                        /[;,]/
                    )

                const remainder =
                    endsWithSeparator
                        ? ''
                        : String(
                            parts.pop()
                            || ''
                        ).trimStart()

                const unresolved = []

                for (const part of parts) {
                    const token =
                        String(
                            part
                            || ''
                        ).trim()

                    if (token === '') {
                        continue
                    }

                    const parsed =
                        parseRecipientToken(
                            token
                        )

                    if (
                        parsed
                        && add(
                            parsed.email,
                            parsed.name
                        )
                    ) {
                        continue
                    }

                    unresolved.push(
                        token
                    )
                }

                if (remainder !== '') {
                    unresolved.push(
                        remainder
                    )
                }

                input.value =
                    unresolved.join(
                        ', '
                    )
            }


            function getValue() {
                const values =
                    recipients.map(
                        (recipient) => {
                            return recipient.email
                        }
                    )

                const pending =
                    String(
                        input.value
                        || ''
                    ).trim()

                if (pending !== '') {
                    values.push(
                        pending
                    )
                }

                return values.join(
                    ', '
                )
            }


            function setDisabled(disabled) {
                externallyDisabled =
                    Boolean(
                        disabled
                    )

                render()
            }


            input.addEventListener(
                'input',
                () => {
                    consumeSeparatedInput()
                }
            )

            input.addEventListener(
                'keydown',
                (event) => {
                    if (event.isComposing) {
                        return
                    }

                    if (
                        event.key === ','
                        || event.key === ';'
                    ) {
                        if (
                            String(
                                input.value
                                || ''
                            ).trim() === ''
                        ) {
                            event.preventDefault()
                            return
                        }

                        const committed =
                            commitInput()

                        if (committed) {
                            event.preventDefault()
                        }
                    } else if (
                        event.key === 'Backspace'
                        && input.value === ''
                        && recipients.length > 0
                    ) {
                        recipients.pop()
                        render()
                    }
                }
            )

            input.addEventListener(
                'blur',
                () => {
                    commitInput()
                }
            )


            const initialParts =
                String(
                    initialValue
                    || ''
                ).split(
                    /[;,]/
                )

            const unresolvedInitial = []

            for (const part of initialParts) {
                const token =
                    String(
                        part
                        || ''
                    ).trim()

                if (token === '') {
                    continue
                }

                const parsed =
                    parseRecipientToken(
                        token
                    )

                if (
                    parsed
                    && add(
                        parsed.email,
                        parsed.name
                    )
                ) {
                    continue
                }

                unresolvedInitial.push(
                    token
                )
            }

            input.value =
                unresolvedInitial.join(
                    ', '
                )

            render()

            return {
                element:
                    control,

                input,

                add,

                commitInput,

                getValue,

                setDisabled,

                focus() {
                    input.focus()
                },
            }
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


            const toRecipients =
                createRecipientControl(
                    String(
                        initialDraft?.to
                        || ''
                    ),
                    {
                        maxRecipients:
                            isReplyDraft
                                ? 1
                                : Infinity,

                        placeholder:
                            'Empfänger eingeben …',
                    }
                )

            const toInput =
                toRecipients.input


            const ccRecipients =
                createRecipientControl(
                    String(
                        initialDraft?.cc
                        || ''
                    ),
                    {
                        placeholder:
                            'CC-Empfänger eingeben …',
                    }
                )

            const ccInput =
                ccRecipients.input


            const bccRecipients =
                createRecipientControl(
                    String(
                        initialDraft?.bcc
                        || ''
                    ),
                    {
                        placeholder:
                            'BCC-Empfänger eingeben …',
                    }
                )

            const bccInput =
                bccRecipients.input


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
                    toRecipients.element
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
                        ccRecipients.element
                    )
                )

                fields.appendChild(
                    createField(
                        'BCC',
                        bccRecipients.element
                    )
                )
            }

            fields.appendChild(
                createField(
                    'Betreff',
                    subjectInput
                )
            )


            /*
             * Nextcloud-Kontakte für die
             * Empfänger-Chip-Felder.
             */
            function attachContactAutocomplete(
                recipientField,
                fieldName
            ) {
                const input =
                    recipientField.input

                if (!input.parentNode) {
                    return
                }

                const wrapper =
                    document.createElement(
                        'div'
                    )

                wrapper.className =
                    'sharedmail-recipient-input-wrapper'

                input.parentNode.insertBefore(
                    wrapper,
                    input
                )

                wrapper.appendChild(
                    input
                )

                const list =
                    document.createElement(
                        'div'
                    )

                list.id =
                    'sharedmail-contacts-'
                    + fieldName

                list.className =
                    'sharedmail-contact-suggestions'

                list.setAttribute(
                    'role',
                    'listbox'
                )

                list.setAttribute(
                    'aria-label',
                    'Kontaktvorschläge'
                )

                list.hidden = true

                wrapper.appendChild(
                    list
                )

                input.setAttribute(
                    'autocomplete',
                    'off'
                )

                input.setAttribute(
                    'role',
                    'combobox'
                )

                input.setAttribute(
                    'aria-autocomplete',
                    'list'
                )

                input.setAttribute(
                    'aria-controls',
                    list.id
                )

                input.setAttribute(
                    'aria-expanded',
                    'false'
                )

                let timer
                let controller
                let generation = 0
                let contacts = []
                let active = -1
                let composing = false


                function close() {
                    clearTimeout(
                        timer
                    )

                    if (controller) {
                        controller.abort()
                    }

                    generation += 1
                    contacts = []
                    active = -1

                    list.replaceChildren()
                    list.hidden = true

                    input.setAttribute(
                        'aria-expanded',
                        'false'
                    )

                    input.removeAttribute(
                        'aria-activedescendant'
                    )
                }


                function select(index) {
                    const contact =
                        contacts[
                            index
                        ]

                    if (!contact) {
                        return
                    }

                    const added =
                        recipientField.add(
                            contact.email,
                            String(
                                contact.name
                                || ''
                            )
                        )

                    if (!added) {
                        return
                    }

                    close()
                    recipientField.focus()
                }


                function highlight(index) {
                    active = index

                    Array.from(
                        list.children
                    ).forEach(
                        (
                            option,
                            optionIndex
                        ) => {
                            option.setAttribute(
                                'aria-selected',
                                String(
                                    optionIndex
                                    === active
                                )
                            )

                            option.classList.toggle(
                                'active',
                                optionIndex
                                    === active
                            )
                        }
                    )

                    const option =
                        list.children[
                            active
                        ]

                    if (option) {
                        input.setAttribute(
                            'aria-activedescendant',
                            option.id
                        )

                        option.scrollIntoView({
                            block:
                                'nearest',
                        })
                    }
                }


                function search() {
                    close()

                    const query =
                        String(
                            input.value
                            || ''
                        ).trim()

                    if (
                        composing
                        || query.length < 2
                        || input.disabled
                    ) {
                        return
                    }

                    const value =
                        input.value

                    const requestGeneration =
                        generation

                    timer =
                        window.setTimeout(
                            async () => {
                                controller =
                                    new AbortController()

                                try {
                                    const response =
                                        await fetch(
                                            OC.generateUrl(
                                                '/apps/sharedmail/api/contacts'
                                            )
                                            + '?query='
                                            + encodeURIComponent(
                                                query
                                            ),
                                            {
                                                headers: {
                                                    Accept:
                                                        'application/json',
                                                },

                                                signal:
                                                    controller.signal,
                                            }
                                        )

                                    if (!response.ok) {
                                        return
                                    }

                                    const data =
                                        await response.json()

                                    if (
                                        requestGeneration
                                            !== generation
                                        || input.value
                                            !== value
                                        || !input.isConnected
                                        || document.activeElement
                                            !== input
                                    ) {
                                        return
                                    }

                                    if (
                                        data.success === false
                                        || !Array.isArray(
                                            data.contacts
                                        )
                                    ) {
                                        return
                                    }

                                    const seen =
                                        new Set()

                                    contacts =
                                        data.contacts
                                            .filter(
                                                (contact) => {
                                                    if (
                                                        !contact
                                                        || typeof contact.email
                                                            !== 'string'
                                                        || !/^[^\s,;<>@]+@[^\s,;<>@]+$/.test(
                                                            contact.email
                                                        )
                                                    ) {
                                                        return false
                                                    }

                                                    const key =
                                                        contact.email
                                                            .toLowerCase()

                                                    if (
                                                        seen.has(
                                                            key
                                                        )
                                                    ) {
                                                        return false
                                                    }

                                                    seen.add(
                                                        key
                                                    )

                                                    return true
                                                }
                                            )
                                            .slice(
                                                0,
                                                20
                                            )

                                    contacts.forEach(
                                        (
                                            contact,
                                            index
                                        ) => {
                                            const option =
                                                document.createElement(
                                                    'div'
                                                )

                                            option.id =
                                                list.id
                                                + '-'
                                                + index

                                            option.className =
                                                'sharedmail-contact-suggestion'

                                            option.setAttribute(
                                                'role',
                                                'option'
                                            )

                                            option.setAttribute(
                                                'aria-selected',
                                                'false'
                                            )

                                            option.textContent =
                                                contact.name
                                                    ? contact.name
                                                        + ' <'
                                                        + contact.email
                                                        + '>'
                                                    : contact.email

                                            option.addEventListener(
                                                'mousedown',
                                                (event) => {
                                                    event.preventDefault()
                                                }
                                            )

                                            option.addEventListener(
                                                'click',
                                                () => {
                                                    select(
                                                        index
                                                    )
                                                }
                                            )

                                            list.appendChild(
                                                option
                                            )
                                        }
                                    )

                                    list.hidden =
                                        contacts.length === 0

                                    input.setAttribute(
                                        'aria-expanded',
                                        String(
                                            contacts.length > 0
                                        )
                                    )
                                } catch (error) {
                                    if (
                                        error?.name
                                        !== 'AbortError'
                                    ) {
                                        /*
                                         * Auch bei nicht erreichbarem
                                         * Adressbuch bleiben manuelle
                                         * Empfänger möglich.
                                         */
                                    }
                                }
                            },
                            250
                        )
                }


                input.addEventListener(
                    'input',
                    search
                )

                input.addEventListener(
                    'click',
                    () => {
                        if (
                            String(
                                input.value
                                || ''
                            ).trim() !== ''
                        ) {
                            search()
                        }
                    }
                )

                input.addEventListener(
                    'blur',
                    close
                )

                input.addEventListener(
                    'compositionstart',
                    () => {
                        composing = true
                        close()
                    }
                )

                input.addEventListener(
                    'compositionend',
                    () => {
                        composing = false
                        search()
                    }
                )

                input.addEventListener(
                    'keydown',
                    (event) => {
                        if (event.isComposing) {
                            return
                        }

                        if (
                            event.key
                            === 'Escape'
                        ) {
                            if (!list.hidden) {
                                event.preventDefault()
                                event.stopPropagation()
                            }

                            close()

                            return
                        }

                        if (
                            !list.hidden
                            && contacts.length
                        ) {
                            if (
                                event.key
                                    === 'ArrowDown'
                                || event.key
                                    === 'ArrowUp'
                            ) {
                                event.preventDefault()

                                highlight(
                                    active < 0
                                        ? (
                                            event.key
                                                === 'ArrowDown'
                                                ? 0
                                                : contacts.length - 1
                                        )
                                        : (
                                            active
                                            + (
                                                event.key
                                                    === 'ArrowDown'
                                                    ? 1
                                                    : -1
                                            )
                                            + contacts.length
                                        )
                                        % contacts.length
                                )

                                return
                            }

                            if (
                                event.key
                                === 'Enter'
                            ) {
                                event.preventDefault()
                                event.stopPropagation()

                                select(
                                    active < 0
                                        ? 0
                                        : active
                                )

                                return
                            }

                            if (
                                event.key
                                === 'Tab'
                            ) {
                                close()
                            }
                        } else if (
                            event.key
                            === 'Enter'
                        ) {
                            const committed =
                                recipientField
                                    .commitInput()

                            if (committed) {
                                event.preventDefault()
                                event.stopPropagation()
                                close()
                            }
                        }
                    }
                )
            }


            attachContactAutocomplete(
                toRecipients,
                'to'
            )

            if (!isReplyDraft) {
                attachContactAutocomplete(
                    ccRecipients,
                    'cc'
                )

                attachContactAutocomplete(
                    bccRecipients,
                    'bcc'
                )
            }


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

                toRecipients.setDisabled(
                    busy
                )

                if (!isReplyDraft) {
                    ccRecipients.setDisabled(
                        busy
                    )

                    bccRecipients.setDisabled(
                        busy
                    )
                }

                subjectInput.disabled =
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
                            toRecipients
                                .getValue()
                                .trim(),

                        cc:
                            isReplyDraft
                                ? ''
                                : ccRecipients
                                    .getValue()
                                    .trim(),

                        bcc:
                            isReplyDraft
                                ? ''
                                : bccRecipients
                                    .getValue()
                                    .trim(),

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
                        toRecipients
                            .getValue()
                            .trim()


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
                                : ccRecipients
                                    .getValue()
                                    .trim(),

                        bcc:
                            isReplyDraft
                                ? ''
                                : bccRecipients
                                    .getValue()
                                    .trim(),

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