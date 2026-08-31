document.addEventListener('DOMContentLoaded', () => {
    const addButton = document.getElementById('sharedmail-add-mailbox')
    const cancelButton = document.getElementById('sharedmail-cancel-mailbox')
    const wrapper = document.getElementById('sharedmail-mailbox-form-wrapper')
    const form = document.getElementById('sharedmail-mailbox-form')

    const testButton = document.getElementById('sharedmail-test-connection')
    const resultBox = document.getElementById('sharedmail-connection-result')

    if (!addButton || !wrapper || !form) {
        return
    }

    addButton.addEventListener('click', () => {
        wrapper.style.display = 'block'
        addButton.style.display = 'none'
    })

    cancelButton?.addEventListener('click', () => {
        wrapper.style.display = 'none'
        addButton.style.display = ''
        form.reset()

        if (resultBox) {
            resultBox.style.display = 'none'
            resultBox.textContent = ''
        }
    })

    if (testButton && resultBox) {
        testButton.addEventListener('click', async () => {
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
                    OC.generateUrl('/apps/sharedmail/api/mailboxes/test'),
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
                        result.error ?? 'Verbindungstest fehlgeschlagen.'

                    return
                }

                resultBox.innerHTML = `
                    <div>
                        ${result.imap.success ? '✓' : '✗'}
                        ${escapeHtml(result.imap.message)}
                    </div>

                    <div>
                        ${result.smtp.success ? '✓' : '✗'}
                        ${escapeHtml(result.smtp.message)}
                    </div>
                `
            } catch (error) {
                console.error(error)

                resultBox.textContent =
                    'Verbindungstest konnte nicht ausgeführt werden.'
            } finally {
                testButton.disabled = false
            }
        })
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault()

        const submitButton = form.querySelector('button[type="submit"]')

        if (!submitButton) {
            return
        }

        submitButton.disabled = true

        try {
            const formData = new FormData(form)

            const data = Object.fromEntries(formData.entries())

            data.groupIds = formData.getAll('groupIds[]')
            data.imapPort = Number(data.imapPort)
            data.smtpPort = Number(data.smtpPort)

            const response = await fetch(
                OC.generateUrl('/apps/sharedmail/api/mailboxes'),
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
                throw new Error(
                    result.error || 'Postfach konnte nicht gespeichert werden.'
                )
            }

            OC.Notification.showTemporary(
                'Postfach wurde gespeichert.'
            )

            window.location.reload()
        } catch (error) {
            console.error(error)

            OC.Notification.showTemporary(
                error.message || 'Postfach konnte nicht gespeichert werden.'
            )
        } finally {
            submitButton.disabled = false
        }
    })

    document.querySelectorAll('.sharedmail-delete-mailbox').forEach((button) => {
        button.addEventListener('click', async () => {
            const mailboxId = button.dataset.mailboxId
            const mailboxName = button.dataset.mailboxName || 'dieses Postfach'

            const confirmed = window.confirm(
                `Postfach "${mailboxName}" wirklich aus Shared Mail löschen?\n\n` +
                'Das echte Mailkonto und die Nachrichten auf dem Mailserver werden nicht gelöscht.'
            )

            if (!confirmed) {
                return
            }

            button.disabled = true

            try {
                const response = await fetch(
                    OC.generateUrl(`/apps/sharedmail/api/mailboxes/${mailboxId}`),
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
                        result.error || 'Postfach konnte nicht gelöscht werden.'
                    )
                }

                OC.Notification.showTemporary(
                    'Postfach wurde aus Shared Mail gelöscht.'
                )

                window.location.reload()
            } catch (error) {
                console.error(error)

                OC.Notification.showTemporary(
                    error.message || 'Postfach konnte nicht gelöscht werden.'
                )

                button.disabled = false
            }
        })
    })

    function escapeHtml(value) {
        const div = document.createElement('div')
        div.textContent = String(value)

        return div.innerHTML
    }
})