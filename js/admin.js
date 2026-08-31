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
        console.error(
            'SharedMail: Grundelemente des Adminformulars wurden nicht gefunden.'
        )
        return
    }

    /*
     * ------------------------------------------------------------
     * Hilfsfunktionen
     * ------------------------------------------------------------
     */

    function getField(name) {
        return form.elements.namedItem(name)
    }

    function setField(name, value) {
        const field = getField(name)

        if (!field) {
            console.warn(
                `SharedMail: Formularfeld "${name}" wurde nicht gefunden.`
            )
            return
        }

        field.value = value ?? ''
    }

    function getEditingMailboxId() {
        const mailboxId = form.dataset.mailboxId

        if (!mailboxId) {
            return null
        }

        return mailboxId
    }

    function setEditingMailboxId(mailboxId) {
        if (!mailboxId) {
            delete form.dataset.mailboxId
            return
        }

        form.dataset.mailboxId = String(mailboxId)
    }

    function showFormMessage(message, isError = false) {
        if (!resultBox) {
            window.alert(message)
            return
        }

        resultBox.style.display = 'block'
        resultBox.textContent = message

        if (isError) {
            resultBox.dataset.error = 'true'
        } else {
            delete resultBox.dataset.error
        }
    }

    function clearConnectionResult() {
        if (!resultBox) {
            return
        }

        resultBox.style.display = 'none'
        resultBox.textContent = ''
        delete resultBox.dataset.error
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

        setEditingMailboxId(null)

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
     * ------------------------------------------------------------
     * Neues Postfach
     * ------------------------------------------------------------
     */

    addButton.addEventListener('click', () => {
        resetFormDefaults()
        openForm()

        console.log('SharedMail: Neues Postfach geöffnet')
    })

    /*
     * ------------------------------------------------------------
     * Vorhandenes Postfach bearbeiten
     * ------------------------------------------------------------
     */

    document
        .querySelectorAll('.sharedmail-edit-mailbox')
        .forEach((button) => {
            button.addEventListener('click', () => {
                resetFormDefaults()

                const mailboxId = button.dataset.mailboxId

                if (!mailboxId) {
                    console.error(
                        'SharedMail: Bearbeiten-Button enthält keine Mailbox-ID.'
                    )
                    return
                }

                setEditingMailboxId(mailboxId)

                console.log(
                    'SharedMail: Bearbeiten geöffnet, Mailbox-ID =',
                    mailboxId
                )

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
                 * Gruppen aus dem JSON-data-Attribut laden.
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
                        'SharedMail: Zugriffsgruppen konnten nicht gelesen werden.',
                        error
                    )

                    groupIds = []
                }

                selectGroups(groupIds)

                /*
                 * Passwörter werden beim Bearbeiten niemals
                 * aus der Datenbank an den Browser übertragen.
                 *
                 * Leere Passwortfelder bedeuten:
                 * vorhandenes Passwort beibehalten.
                 */
                setPasswordEditMode(true)

                clearConnectionResult()

                if (formTitle) {
                    formTitle.textContent =
                        'Postfach bearbeiten'
                }

                if (saveButton) {
                    saveButton.textContent =
                        'Änderungen speichern'
                }

                openForm()
            })
        })

    /*
     * ------------------------------------------------------------
     * Abbrechen
     * ------------------------------------------------------------
     */

    cancelButton?.addEventListener('click', () => {
        closeForm()
    })

    /*
     * ------------------------------------------------------------
     * IMAP / SMTP Verbindung testen
     * ------------------------------------------------------------
     */

    testButton?.addEventListener('click', async () => {
        if (!resultBox) {
            return
        }

        const editingMailboxId =
            getEditingMailboxId()

        const imapPassword =
            getField('imapPassword')?.value ?? ''

        const smtpPassword =
            getField('smtpPassword')?.value ?? ''

        /*
         * Beim Bearbeiten liegen die vorhandenen Passwörter
         * bewusst nicht im Browser vor.
         *
         * Für einen neuen Verbindungstest müssen sie deshalb
         * neu eingegeben werden.
         */
        if (
            editingMailboxId !== null
            && (
                imapPassword === ''
                || smtpPassword === ''
            )
        ) {
            showFormMessage(
                'Für einen neuen Verbindungstest beim Bearbeiten bitte IMAP- und SMTP-Passwort eingeben.',
                true
            )

            return
        }

        testButton.disabled = true

        showFormMessage(
            'Verbindung wird getestet …'
        )

        try {
            const formData =
                new FormData(form)

            const data =
                Object.fromEntries(
                    formData.entries()
                )

            data.imapPort =
                Number(data.imapPort)

            data.smtpPort =
                Number(data.smtpPort)

            const response = await fetch(
                OC.generateUrl(
                    '/apps/sharedmail/api/mailboxes/test'
                ),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/json',

                        'requesttoken':
                            OC.requestToken,
                    },
                    body: JSON.stringify(data),
                }
            )

            const responseText =
                await response.text()

            let result = {}

            if (responseText !== '') {
                try {
                    result =
                        JSON.parse(responseText)
                } catch (error) {
                    console.error(
                        'SharedMail: Verbindungstest lieferte kein gültiges JSON.',
                        responseText
                    )

                    throw new Error(
                        'Der Server hat eine ungültige Antwort geliefert.'
                    )
                }
            }

            if (!response.ok) {
                throw new Error(
                    result.error
                    || 'Verbindungstest fehlgeschlagen.'
                )
            }

            resultBox.textContent = ''

            const imapResult =
                document.createElement('div')

            const smtpResult =
                document.createElement('div')

            imapResult.textContent =
                `${result.imap.success ? '✓' : '✗'} ${result.imap.message}`

            smtpResult.textContent =
                `${result.smtp.success ? '✓' : '✗'} ${result.smtp.message}`

            resultBox.appendChild(
                imapResult
            )

            resultBox.appendChild(
                smtpResult
            )
        } catch (error) {
            console.error(
                'SharedMail: Verbindungstest fehlgeschlagen.',
                error
            )

            showFormMessage(
                error?.message
                || 'Verbindungstest konnte nicht ausgeführt werden.',
                true
            )
        } finally {
            testButton.disabled = false
        }
    })

    /*
     * ------------------------------------------------------------
     * Neues Postfach speichern oder vorhandenes aktualisieren
     * ------------------------------------------------------------
     */

    form.addEventListener('submit', async (event) => {
        event.preventDefault()

        console.log(
            'SM 1: Submit gestartet'
        )

        const editingMailboxId =
            getEditingMailboxId()

        console.log(
            'SM 2: editingMailboxId =',
            editingMailboxId
        )

        const submitButton =
            form.querySelector(
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
            console.log(
                'SM 3: FormData wird erzeugt'
            )

            const formData =
                new FormData(form)

            console.log(
                'SM 4: FormData wurde erzeugt'
            )

            const data =
                Object.fromEntries(
                    formData.entries()
                )

            /*
             * Multi-Select muss separat gelesen werden.
             */
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
             * Keine Zugangsdaten in die Konsole schreiben.
             */
            console.log(
                'SM 6: groupIds =',
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

            const method =
                isEditing
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
             * Damit der Button bei einem hängenden Serverrequest
             * nicht dauerhaft deaktiviert bleibt.
             */
            const abortController =
                new AbortController()

            const timeoutId =
                window.setTimeout(
                    () => {
                        abortController.abort()
                    },
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
                            'Content-Type':
                                'application/json',

                            'requesttoken':
                                OC.requestToken,
                        },

                        body:
                            JSON.stringify(data),

                        signal:
                            abortController.signal,
                    }
                )
            } finally {
                window.clearTimeout(
                    timeoutId
                )
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
                    result =
                        JSON.parse(responseText)
                } catch (error) {
                    console.error(
                        'SM FEHLER: Serverantwort ist kein gültiges JSON.',
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

            console.log(
                'SM 14: Speichern erfolgreich'
            )

            window.location.reload()
        } catch (error) {
            if (
                error?.name ===
                'AbortError'
            ) {
                console.error(
                    'SM FEHLER: Request-Timeout'
                )

                showFormMessage(
                    'Die Serveranfrage hat zu lange gedauert.',
                    true
                )
            } else {
                console.error(
                    'SM FEHLER:',
                    error
                )

                showFormMessage(
                    error?.message
                    || 'Postfach konnte nicht gespeichert werden.',
                    true
                )
            }
        } finally {
            /*
             * Falls kein Reload erfolgt, wird der Button
             * garantiert wieder freigegeben.
             */
            submitButton.disabled = false

            console.log(
                'SM ENDE: Submit-Handler beendet'
            )
        }
    })

    /*
     * ------------------------------------------------------------
     * Postfach löschen
     * ------------------------------------------------------------
     */

    document
        .querySelectorAll('.sharedmail-delete-mailbox')
        .forEach((button) => {
            button.addEventListener(
                'click',
                async () => {
                    const mailboxId =
                        button.dataset.mailboxId

                    const mailboxName =
                        button.dataset.mailboxName
                        || 'dieses Postfach'

                    if (!mailboxId) {
                        console.error(
                            'SharedMail: Löschen-Button enthält keine Mailbox-ID.'
                        )
                        return
                    }

                    const confirmed =
                        window.confirm(
                            `Postfach "${mailboxName}" wirklich aus Shared Mail löschen?\n\n`
                            + 'Das echte Mailkonto und die Nachrichten auf dem Mailserver werden nicht gelöscht.'
                        )

                    if (!confirmed) {
                        return
                    }

                    button.disabled = true

                    try {
                        const response =
                            await fetch(
                                OC.generateUrl(
                                    `/apps/sharedmail/api/mailboxes/${mailboxId}`
                                ),
                                {
                                    method:
                                        'DELETE',

                                    headers: {
                                        'requesttoken':
                                            OC.requestToken,
                                    },
                                }
                            )

                        const responseText =
                            await response.text()

                        let result = {}

                        if (
                            responseText !== ''
                        ) {
                            try {
                                result =
                                    JSON.parse(
                                        responseText
                                    )
                            } catch (error) {
                                throw new Error(
                                    'Der Server hat eine ungültige Antwort geliefert.'
                                )
                            }
                        }

                        if (!response.ok) {
                            throw new Error(
                                result.error
                                || 'Postfach konnte nicht gelöscht werden.'
                            )
                        }

                        window.location.reload()
                    } catch (error) {
                        console.error(
                            'SharedMail: Löschen fehlgeschlagen.',
                            error
                        )

                        window.alert(
                            error?.message
                            || 'Postfach konnte nicht gelöscht werden.'
                        )
                    } finally {
                        button.disabled = false
                    }
                }
            )
        })
})