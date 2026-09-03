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
            payload
        ) {
            const url =
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${mailbox.id}/compose`
                )

            const response =
                await fetch(
                    url,
                    {
                        method:
                            'POST',

                        headers: {
                            'Content-Type':
                                'application/json',

                            'Accept':
                                'application/json',

                            'requesttoken':
                                getRequestToken(),
                        },

                        body:
                            JSON.stringify(
                                payload
                            ),
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
                throw new Error(
                    data?.message
                    || 'Die Nachricht konnte nicht gesendet werden.'
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


            const status =
                document.createElement(
                    'div'
                )

            status.className =
                'sharedmail-composer-status'

            composer.appendChild(
                status
            )


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
                sendButton
            )

            composer.appendChild(
                footer
            )


            messageArea.appendChild(
                composer
            )


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

                    cancelButton.disabled =
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
                                }
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

                        if (
                            window.SharedMailUI
                            && typeof window.SharedMailUI.reloadCurrentFolder
                                === 'function'
                        ) {
                            await window.SharedMailUI
                                .reloadCurrentFolder()
                        }

                        /*
                         * Nach erfolgreichem Versand bleibt
                         * Senden deaktiviert, damit nicht
                         * versehentlich doppelt gesendet wird.
                         */
                        sendButton.disabled =
                            true
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

                        cancelButton.disabled =
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