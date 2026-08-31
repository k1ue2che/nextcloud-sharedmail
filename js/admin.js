document.addEventListener('DOMContentLoaded', () => {
    const addButton = document.getElementById('sharedmail-add-mailbox')
    const cancelButton = document.getElementById('sharedmail-cancel-mailbox')
    const saveButton = document.getElementById('sharedmail-save-mailbox')
    const testButton = document.getElementById('sharedmail-test-connection')

    const wrapper = document.getElementById('sharedmail-mailbox-form-wrapper')
    const form = document.getElementById('sharedmail-mailbox-form')
    const formTitle = document.getElementById('sharedmail-form-title')

    const resultBox = document.getElementById('sharedmail-connection-result')

    const imapPasswordHint = document.getElementById(
        'sharedmail-imap-password-hint'
    )

    const smtpPasswordHint = document.getElementById(
        'sharedmail-smtp-password-hint'
    )

    if (!addButton || !wrapper || !form) {
        return
    }

    let editingMailboxId = null

    /*
     * Hilfsfunktionen
     */

    function getField(name) {
        return form.elements.namedItem(name)
    }

    function setField(name, value) {
        const field = getField(name)

        if (!field) {
            return
        }

        field.value = value ?? ''
    }

    function clearConnectionResult() {
        if (!resultBox) {
            return
        }

        resultBox.style.display = 'none'
        resultBox.textContent = ''
    }

    function setPasswordEditMode(editMode) {
        const imapPassword = getField('imapPassword')
        const smtpPassword = getField('smtpPassword')

        if (imapPassword) {
            imapPassword.value = ''
            imapPassword.required = !editMode
        }

        if (smtpPassword) {
            smtpPassword.value = ''
            smtpPassword.required = !editMode
        }

        if (imapPasswordHint) {
            imapPasswordHint.style.display = editMode
                ? 'block'
                : 'none'
        }

        if (smtpPasswordHint) {
            smtpPasswordHint.style.display = editMode
                ? 'block'
                : 'none'
        }
    }

    function resetGroups() {
        const groupSelect = getField('groupIds[]')

        if (!groupSelect) {
            return
        }

        Array.from(groupSelect.options).forEach((option) => {
            option.selected = false
        })
    }

    function selectGroups(groupIds) {
        const groupSelect = getField('groupIds[]')

        if (!groupSelect) {
            return
        }

        const selectedIds = new Set(
            groupIds.map((id) => String(id))
        )

        Array.from(groupSelect.options).forEach((option) => {
            option.selected = selectedIds.has(option.value)
        })
    }

    function resetFormDefaults() {
        form.reset()

        editingMailboxId = null

        /*
         * Standardwerte für ein neues Postfach.
         */
        setField('imapPort', '993')
        setField('imapSecurity', 'ssl')

        setField('smtpPort', '465')
        setField('smtpSecurity', 'ssl')

        resetGroups()
        setPasswordEditMode(false)
        clearConnectionResult()

        if (formTitle) {
            formTitle.textContent = 'Postfach hinzufügen'
        }

        if (saveButton) {
            saveButton.textContent = 'Postfach speichern'
        }
    }

    function openForm() {
        wrapper.style.display = 'block'
        addButton.style.display = 'none'

        wrapper.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        })
    }

    function closeForm() {
        wrapper.style.display = 'none'
        addButton.style.display = ''

        resetFormDefaults()
    }

    /*
     * Neues Postfach
     */

    addButton.addEventListener('click', () => {
        resetFormDefaults()
        openForm()
    })

    /*
     * Bearbeiten
     */

    document.querySelectorAll('.sharedmail-edit-mailbox').forEach((button) => {
        button.addEventListener('click', () => {
            resetFormDefaults()

            editingMailboxId = button.dataset.mailboxId

            if (!editingMailboxId) {
                return
            }

            setField(
                'name',
                button.dataset.name
            )

            setField(
                'description',
                button.dataset.description
            )

            setField(
                'email',
                button.dataset.email
            )

            setField(
                'imapHost',
                button.dataset.imapHost
            )

            setField(
                'imapPort',
                button.dataset.imapPort
            )

            setField(
                'imapSecurity',
                button.dataset.imapSecurity
            )

            setField(
                'imapUsername',
                button.dataset.imapUsername
            )

            setField(
                'smtpHost',
                button.dataset.smtpHost
            )

            setField(
                'smtpPort',
                button.dataset.smtpPort
            )

            setField(
                'smtpSecurity',
                button.dataset.smtpSecurity
            )

            setField(
                'smtpUsername',
                button.dataset.smtpUsername
            )

            /*
             * Gruppen aus dem JSON-data-Attribut lesen.
             */
            let groupIds = []

            try {
                groupIds = JSON.parse(
                    button.dataset.groupIds || '[]'
                )

                if (!Array.isArray(groupIds)) {
                    groupIds = []
                }
            } catch (error) {
                console.error(
                    'Zugriffsgruppen konnten nicht gelesen werden.',
                    error
                )

                groupIds = []
            }

            selectGroups(groupIds)

            /*
             * Passwörter werden niemals vom Server zurückgegeben.
             *
             * Leer lassen bedeutet beim Speichern:
             * vorhandenes Passwort beibehalten.
             */
            setPasswordEditMode(true)

            clearConnectionResult()

            if (formTitle) {
                formTitle.textContent = 'Postfach bearbeiten'
            }

            if (saveButton) {
                saveButton.textContent = 'Änderungen speichern'
            }

            openForm()
        })
    })

    /*
     * Abbrechen
     */

    cancelButton?.addEventListener('click', () => {
        closeForm()
    })

    /*
     * IMAP / SMTP Verbindung testen
     */

    testButton?.addEventListener('click', async () => {
        if (!resultBox) {
            return
        }

        const imapPassword = getField('imapPassword')?.value ?? ''
        const smtpPassword = getField('smtpPassword')?.value ?? ''

        /*
         * Beim Bearbeiten kennen wir die gespeicherten Passwörter
         * absichtlich nicht im Browser.
         *
         * Für einen neuen Verbindungstest müssen deshalb beide
         * Passwörter neu eingegeben werden.
         */
        if (
            editingMailboxId !== null
            && (
                imapPassword === ''
                || smtpPassword === ''
            )
        ) {
            resultBox.style.display = 'block'
            resultBox.textContent =
                'Für einen neuen Verbindungstest beim Bearbeiten bitte IMAP- und SMTP-Passwort eingeben.'

            return
        }

        testButton.disabled = true

        resultBox.style.display = 'block'
        resultBox.textContent = 'Verbindung wird getestet …'

        try {
            const formData = new FormData(form)

            const data = Object.fromEntries(
                formData.entries()
            )

            data.imapPort = Number(data.imapPort)
            data.smtpPort = Number(data.smtpPort)

            const response = await fetch(
                OC.generateUrl(
                    '/apps/sharedmail/api/mailboxes/test'
                ),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken,
                    },
                    body: JSON.stringify(data),
                }
            )

            const result = await response.json()

            if (!response.ok) {
                resultBox.textContent =
                    result.error
                    ?? 'Verbindungstest fehlgeschlagen.'

                return
            }

            /*
             * Kein innerHTML nötig.
             * Damit können Servermeldungen keinen HTML-Code erzeugen.
             */
            resultBox.textContent = ''

            const imapResult = document.createElement('div')
            const smtpResult = document.createElement('div')

            imapResult.textContent =
                `${result.imap.success ? '✓' : '✗'} ${result.imap.message}`

            smtpResult.textContent =
                `${result.smtp.success ? '✓' : '✗'} ${result.smtp.message}`

            resultBox.appendChild(imapResult)
            resultBox.appendChild(smtpResult)
        } catch (error) {
            console.error(error)

            resultBox.textContent =
                'Verbindungstest konnte nicht ausgeführt werden.'
        } finally {
            testButton.disabled = false
        }
    })

    /*
     * Neu anlegen oder bestehendes Postfach aktualisieren
     */

    form.addEventListener('submit', async (event) => {
        event.preventDefault()

        const submitButton = form.querySelector(
            'button[type="submit"]'
        )

        if (!submitButton) {
            return
        }

        submitButton.disabled = true

        try {
            const formData = new FormData(form)

            const data = Object.fromEntries(
                formData.entries()
            )

            /*
             * Multi-Select separat auslesen.
             */
            data.groupIds = formData.getAll('groupIds[]')

            data.imapPort = Number(data.imapPort)
            data.smtpPort = Number(data.smtpPort)

            const isEditing = editingMailboxId !== null

            const url = isEditing
                ? OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${editingMailboxId}`
                )
                : OC.generateUrl(
                    '/apps/sharedmail/api/mailboxes'
                )

            const method = isEditing
                ? 'PUT'
                : 'POST'

            const response = await fetch(
                url,
                {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken,
                    },
                    body: JSON.stringify(data),
                }
            )

            const result = await response.json()

            if (!response.ok) {
                throw new Error(
                    result.error
                    || (
                        isEditing
                            ? 'Postfach konnte nicht aktualisiert werden.'
                            : 'Postfach konnte nicht gespeichert werden.'
                    )
                )
            }

            OC.Notification.showTemporary(
                isEditing
                    ? 'Postfach wurde aktualisiert.'
                    : 'Postfach wurde gespeichert.'
            )

            window.location.reload()
        } catch (error) {
            console.error(error)

            OC.Notification.showTemporary(
                error.message
                || 'Postfach konnte nicht gespeichert werden.'
            )

            submitButton.disabled = false
        }
    })

    /*
     * Postfach aus Shared Mail löschen.
     *
     * Das echte E-Mail-Konto und dessen Nachrichten
     * werden dadurch nicht gelöscht.
     */

    document.querySelectorAll('.sharedmail-delete-mailbox').forEach((button) => {
        button.addEventListener('click', async () => {
            const mailboxId = button.dataset.mailboxId

            const mailboxName =
                button.dataset.mailboxName
                || 'dieses Postfach'

            if (!mailboxId) {
                return
            }

            const confirmed = window.confirm(
                `Postfach "${mailboxName}" wirklich aus Shared Mail löschen?\n\n`
                + 'Das echte Mailkonto und die Nachrichten auf dem Mailserver werden nicht gelöscht.'
            )

            if (!confirmed) {
                return
            }

            button.disabled = true

            try {
                const response = await fetch(
                    OC.generateUrl(
                        `/apps/sharedmail/api/mailboxes/${mailboxId}`
                    ),
                    {
                        method: 'DELETE',
                        headers: {
                            'requesttoken': OC.requestToken,
                        },
                    }
                )

                const result = await response.json()

                if (!response.ok) {
                    throw new Error(
                        result.error
                        || 'Postfach konnte nicht gelöscht werden.'
                    )
                }

                OC.Notification.showTemporary(
                    'Postfach wurde aus Shared Mail gelöscht.'
                )

                window.location.reload()
            } catch (error) {
                console.error(error)

                OC.Notification.showTemporary(
                    error.message
                    || 'Postfach konnte nicht gelöscht werden.'
                )

                button.disabled = false
            }
        })
    })
})