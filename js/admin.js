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
        console.error('SharedMail: Grundelemente des Formulars fehlen.')
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
            console.warn(`SharedMail: Feld "${name}" nicht gefunden.`)
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

            editingMailboxId = button.dataset.mailboxId ?? null

            console.log(
                'SharedMail: Bearbeiten geöffnet, Mailbox-ID =',
                editingMailboxId
            )

            if (!editingMailboxId) {
                console.error('SharedMail: Keine Mailbox-ID vorhanden.')
                return
            }

            setField('name', button.dataset.name)
            setField('description', button.dataset.description)
            setField('email', button.dataset.email)

            setField('imapHost', button.dataset.imapHost)
            setField('imapPort', button.dataset.imapPort)
            setField('imapSecurity', button.dataset.imapSecurity)
            setField('imapUsername', button.dataset.imapUsername)

            setField('smtpHost', button.dataset.smtpHost)
            setField('smtpPort', button.dataset.smtpPort)
            setField('smtpSecurity', button.dataset.smtpSecurity)
            setField('smtpUsername', button.dataset.smtpUsername)

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
                    'SharedMail: Zugriffsgruppen konnten nicht gelesen werden.',
                    error
                )

                groupIds = []
            }

            selectGroups(groupIds)
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
     * IMAP / SMTP testen
     */

    testButton?.addEventListener('click', async () => {
        if (!resultBox) {
            return
        }

        const imapPassword =
            getField('imapPassword')?.value ?? ''

        const smtpPassword =
            getField('smtpPassword')?.value ?? ''

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
            console.error(
                'SharedMail: Verbindungstest fehlgeschlagen.',
                error
            )

            resultBox.textContent =
                'Verbindungstest konnte nicht ausgeführt werden.'
        } finally {
            testButton.disabled = false
        }
    })

    /*
     * Neu anlegen oder bearbeiten
     */

    form.addEventListener('submit', async (event) => {
        event.preventDefault()

        console.log('SM 1: Submit gestartet')
        console.log(
            'SM 2: editingMailboxId =',
            editingMailboxId
        )

        const submitButton = form.querySelector(
            'button[type="submit"]'
        )

        if (!submitButton) {
            console.error(
                'SM STOP: Submit-Button wurde nicht gefunden.'
            )
            return
        }

        submitButton.disabled = true

        try {
            console.log('SM 3: Erzeuge FormData')

            const formData = new FormData(form)

            console.log('SM 4: FormData wurde erzeugt')

            const data = Object.fromEntries(
                formData.entries()
            )

            data.groupIds =
                formData.getAll('groupIds[]')

            data.imapPort =
                Number(data.imapPort)

            data.smtpPort =
                Number(data.smtpPort)

            console.log(
                'SM 5: Formulardaten vorbereitet'
            )

            /*
             * Zugangsdaten NICHT vollständig in die Konsole schreiben.
             * Insbesondere keine Passwörter loggen.
             */
            console.log(
                'SM 6: Gruppen =',
                data.groupIds
            )

            const isEditing =
                editingMailboxId !== null

            console.log(
                'SM 7: isEditing =',
                isEditing
            )

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

            console.log(
                'SM 8: URL =',
                url
            )

            console.log(
                'SM 9: Methode =',
                method
            )

            /*
             * Sicherheitsnetz:
             * Falls der Server gar nicht antwortet,
             * brechen wir nach 20 Sekunden ab.
             */
            const controller = new AbortController()

            const timeoutId = window.setTimeout(
                () => controller.abort(),
                20000
            )

            console.log(
                'SM 10: fetch() wird gestartet'
            )

            let response

            try {
                response = await fetch(
                    url,
                    {
                        method,
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': OC.requestToken,
                        },
                        body: JSON.stringify(data),
                        signal: controller.signal,
                    }
                )
            } finally {
                window.clearTimeout(timeoutId)
            }

            console.log(
                'SM 11: HTTP-Antwort erhalten, Status =',
                response.status
            )

            const responseText =
                await response.text()

            console.log(
                'SM 12: Antworttext erhalten'
            )

            let result = {}

            if (responseText !== '') {
                try {
                    result = JSON.parse(responseText)
                } catch (error) {
                    console.error(
                        'SM FEHLER: Serverantwort ist kein gültiges JSON:',
                        responseText
                    )

                    throw new Error(
                        'Der Server hat eine ungültige Antwort geliefert.'
                    )
                }
            }

            console.log(
                'SM 13: Antwort verarbeitet'
            )

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

            console.log(
                'SM 14: Erfolgreich, Seite wird neu geladen'
            )

            window.location.reload()
        } catch (error) {
            if (error?.name === 'AbortError') {
                console.error(
                    'SM FEHLER: Request-Timeout'
                )

                OC.Notification.showTemporary(
                    'Die Serveranfrage hat zu lange gedauert.'
                )
            } else {
                console.error(
                    'SM FEHLER:',
                    error
                )

                OC.Notification.showTemporary(
                    error?.message
                    || 'Postfach konnte nicht gespeichert werden.'
                )
            }
        } finally {
            /*
             * Falls kein Reload erfolgt, muss der Button
             * auf jeden Fall wieder benutzbar werden.
             */
            submitButton.disabled = false

            console.log(
                'SM ENDE: Submit-Handler beendet'
            )
        }
    })

    /*
     * Löschen
     */

    document.querySelectorAll('.sharedmail-delete-mailbox').forEach((button) => {
        button.addEventListener('click', async () => {
            const mailboxId =
                button.dataset.mailboxId

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
                console.error(
                    'SharedMail: Löschen fehlgeschlagen.',
                    error
                )

                OC.Notification.showTemporary(
                    error?.message
                    || 'Postfach konnte nicht gelöscht werden.'
                )
            } finally {
                button.disabled = false
            }
        })
    })
})