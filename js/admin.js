document.addEventListener('DOMContentLoaded', () => {
    const addButton = document.getElementById('sharedmail-add-mailbox')
    const cancelButton = document.getElementById('sharedmail-cancel-mailbox')
    const wrapper = document.getElementById('sharedmail-mailbox-form-wrapper')
    const form = document.getElementById('sharedmail-mailbox-form')

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
    })

    form.addEventListener('submit', async (event) => {
        event.preventDefault()

        const submitButton = form.querySelector('button[type="submit"]')
        submitButton.disabled = true

        try {
            const formData = new FormData(form)

            const data = Object.fromEntries(formData.entries())

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
})