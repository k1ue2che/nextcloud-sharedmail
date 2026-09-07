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

        const MAX_ATTACHMENTS = 10
        const MAX_FILE_BYTES = 10 * 1024 * 1024
        const MAX_TOTAL_BYTES = 25 * 1024 * 1024

        let activeEditor = null
        let savedContent = null


        function getRequestToken() {
            return (
                window.OC?.requestToken
                || document
                    .querySelector(
                        'head meta[name="requesttoken"]'
                    )
                    ?.getAttribute(
                        'content'
                    )
                || ''
            )
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
                    button.dataset
                        .mailboxId
                    || 0
                )

            if (id <= 0) {
                return null
            }

            return {
                id,

                name:
                    String(
                        button.dataset
                            .mailboxName
                        || ''
                    ),

                email:
                    String(
                        button.dataset
                            .mailboxEmail
                        || ''
                    ),
            }
        }


        function formatFileSize(bytes) {
            const value =
                Number(
                    bytes
                    || 0
                )

            if (value < 1024) {
                return `${value} B`
            }

            if (value < 1024 * 1024) {
                return `${(
                    value / 1024
                ).toFixed(1)} KB`
            }

            return `${(
                value
                / 1024
                / 1024
            ).toFixed(1)} MB`
        }


        function getTotalAttachmentSize(
            attachments
        ) {
            return attachments.reduce(
                (
                    total,
                    file
                ) =>
                    total
                    + Number(
                        file?.size
                        || 0
                    ),
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
            const attachments =
                [
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
                    `Es können maximal ${MAX_ATTACHMENTS} Anhänge versendet werden.`
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


        async function destroyEditor() {
            if (!activeEditor) {
                return
            }

            try {
                await activeEditor.destroy()
            } catch (error) {
                console.error(
                    'SharedMail: Editor konnte nicht beendet werden.',
                    error
                )
            }

            activeEditor = null
        }


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

            for (const file of attachments) {
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


        async function saveDraft(
            mailbox,
            payload,
            attachments,
            draftUid
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

            if (draftUid > 0) {
                formData.append(
                    'draftUid',
                    String(draftUid)
                )
            }

            for (const file of attachments) {
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


        async function openComposer() {
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

            saveCurrentView()

            let attachments = []
            let currentDraftUid = 0


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

            heading.textContent =
                'Neue Nachricht'


            composerHeader.appendChild(
                heading
            )

            composer.appendChild(
                composerHeader
            )


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

            const ccInput =
                createInput()

            const bccInput =
                createInput()

            const subjectInput =
                createInput()


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

            fields.appendChild(
                createField(
                    'Betreff',
                    subjectInput
                )
            )

            composer.appendChild(
                fields
            )


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
             * Anhänge
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
             * Footer
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
                'Entwurf speichern'


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


            function renderAttachments() {
                attachmentList.replaceChildren()

                const totalBytes =
                    getTotalAttachmentSize(
                        attachments
                    )

                if (attachments.length === 0) {
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
                                        ) =>
                                            currentIndex
                                            !== index
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


            try {
                activeEditor =
                    await window
                        .SharedMailEditor
                        .create(
                            editorElement,
                            '<p></p>'
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


            toInput.focus()


            cancelButton.addEventListener(
                'click',
                async () => {
                    await restorePreviousView()
                }
            )


            /*
             * Entwurf speichern
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
                            String(
                                ccInput.value
                                || ''
                            ).trim(),

                        bcc:
                            String(
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

                    draftButton.disabled =
                        true

                    sendButton.disabled =
                        true

                    cancelButton.disabled =
                        true

                    attachmentButton.disabled =
                        true

                    const oldText =
                        draftButton.textContent

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
                                currentDraftUid
                            )

                        const returnedUid =
                            Number(
                                result.draftUid
                                || 0
                            )

                        if (returnedUid > 0) {
                            currentDraftUid =
                                returnedUid
                        }

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
                            oldText
                    } finally {
                        draftButton.disabled =
                            false

                        sendButton.disabled =
                            false

                        cancelButton.disabled =
                            false

                        attachmentButton.disabled =
                            false
                    }
                }
            )


            /*
             * Mail senden
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

                    sendButton.disabled =
                        true

                    draftButton.disabled =
                        true

                    cancelButton.disabled =
                        true

                    attachmentButton.disabled =
                        true

                    sendButton.textContent =
                        'Wird gesendet …'

                    status.textContent =
                        'Nachricht wird versendet …'

                    try {
                        const result =
                            await sendMessage(
                                mailbox,
                                {
                                    to,

                                    cc:
                                        String(
                                            ccInput.value
                                            || ''
                                        ).trim(),

                                    bcc:
                                        String(
                                            bccInput.value
                                            || ''
                                        ).trim(),

                                    subject:
                                        String(
                                            subjectInput.value
                                            || ''
                                        ).trim(),

                                    html,
                                },
                                attachments
                            )

                        if (result.warning) {
                            console.warn(
                                'SharedMail:',
                                result.warning
                            )
                        }

                        await destroyEditor()

                        messageArea.replaceChildren()

                        savedContent =
                            null

                        attachments =
                            []

                        currentDraftUid =
                            0

                        if (
                            window.SharedMailUI
                            && typeof window.SharedMailUI.reloadCurrentFolder
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

                        sendButton.disabled =
                            false

                        draftButton.disabled =
                            false

                        cancelButton.disabled =
                            false

                        attachmentButton.disabled =
                            false
                    }
                }
            )
        }


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