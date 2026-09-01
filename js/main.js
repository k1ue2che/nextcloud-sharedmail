document.addEventListener('DOMContentLoaded', () => {
    const mailboxButtons = Array.from(
        document.querySelectorAll('.sharedmail-mailbox-button')
    )

    const folderList = document.getElementById(
        'sharedmail-folder-list'
    )

    const loadingBox = document.getElementById(
        'sharedmail-folder-loading'
    )

    const errorBox = document.getElementById(
        'sharedmail-folder-error'
    )

    const currentMailboxName = document.getElementById(
        'sharedmail-current-mailbox-name'
    )

    const currentMailboxEmail = document.getElementById(
        'sharedmail-current-mailbox-email'
    )

    const messageArea = document.getElementById(
        'sharedmail-message-area'
    )

    if (
        mailboxButtons.length === 0
        || !folderList
    ) {
        return
    }

    function setLoading(loading) {
        if (!loadingBox) {
            return
        }

        loadingBox.style.display =
            loading ? 'block' : 'none'
    }

    function clearError() {
        if (!errorBox) {
            return
        }

        errorBox.style.display = 'none'
        errorBox.textContent = ''
    }

    function showError(message) {
        if (!errorBox) {
            return
        }

        errorBox.style.display = 'block'
        errorBox.textContent = message
    }

    function getFolderIcon(folder) {
        switch (folder.specialUse) {
            case 'inbox':
                return '📥'

            case 'sent':
                return '📤'

            case 'drafts':
                return '📝'

            case 'trash':
                return '🗑'

            case 'junk':
                return '🚫'

            case 'archive':
                return '🗄'

            case 'flagged':
                return '⭐'

            default:
                return '📁'
        }
    }

    function renderFolders(folders) {
        folderList.textContent = ''

        if (!Array.isArray(folders) || folders.length === 0) {
            const empty = document.createElement('div')

            empty.className = 'sharedmail-folder-empty'
            empty.textContent =
                'Auf dem IMAP-Server wurden keine Ordner gefunden.'

            folderList.appendChild(empty)

            return
        }

        folders.forEach((folder) => {
            const button = document.createElement('button')

            button.type = 'button'
            button.className = 'sharedmail-folder'

            if (!folder.selectable) {
                button.classList.add(
                    'sharedmail-folder-disabled'
                )

                button.disabled = true
            }

            button.dataset.folderName =
                folder.name

            const icon = document.createElement('span')

            icon.className =
                'sharedmail-folder-icon'

            icon.textContent =
                getFolderIcon(folder)


            const label = document.createElement('span')

            label.className =
                'sharedmail-folder-label'

            label.textContent =
                folder.label || folder.name


            const counters = document.createElement('span')

            counters.className =
                'sharedmail-folder-counters'

            if (
                folder.unseen !== null
                && Number(folder.unseen) > 0
            ) {
                const unseen =
                    document.createElement('strong')

                unseen.className =
                    'sharedmail-folder-unseen'

                unseen.textContent =
                    String(folder.unseen)

                counters.appendChild(unseen)
            }

            if (folder.messages !== null) {
                const messages =
                    document.createElement('span')

                messages.className =
                    'sharedmail-folder-total'

                messages.textContent =
                    String(folder.messages)

                counters.appendChild(messages)
            }

            button.appendChild(icon)
            button.appendChild(label)
            button.appendChild(counters)

            button.addEventListener(
                'click',
                () => {
                    document
                        .querySelectorAll(
                            '.sharedmail-folder.active'
                        )
                        .forEach((item) => {
                            item.classList.remove(
                                'active'
                            )
                        })

                    button.classList.add(
                        'active'
                    )

                    if (messageArea) {
                        messageArea.textContent = ''

                        const heading =
                            document.createElement('h3')

                        heading.textContent =
                            folder.label
                            || folder.name

                        const info =
                            document.createElement('p')

                        info.textContent =
                            'Nachrichten werden im nächsten Schritt geladen.'

                        messageArea.appendChild(
                            heading
                        )

                        messageArea.appendChild(
                            info
                        )
                    }
                }
            )

            folderList.appendChild(button)
        })
    }

    async function loadFolders(button) {
        const mailboxId =
            button.dataset.mailboxId

        if (!mailboxId) {
            return
        }

        clearError()
        setLoading(true)

        folderList.textContent = ''

        mailboxButtons.forEach(
            (mailboxButton) => {
                mailboxButton.classList.remove(
                    'active'
                )
            }
        )

        button.classList.add(
            'active'
        )

        if (currentMailboxName) {
            currentMailboxName.textContent =
                button.dataset.mailboxName
                || 'Shared Mail'
        }

        if (currentMailboxEmail) {
            currentMailboxEmail.textContent =
                button.dataset.mailboxEmail
                || ''
        }

        if (messageArea) {
            messageArea.textContent = ''

            const info =
                document.createElement('p')

            info.textContent =
                'Wähle einen Ordner aus.'

            messageArea.appendChild(info)
        }

        try {
            const response = await fetch(
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${mailboxId}/folders`
                ),
                {
                    method: 'GET',
                    headers: {
                        'Accept':
                            'application/json',
                    },
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
                        'SharedMail: Ungültige Serverantwort.',
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
                    || 'Die IMAP-Ordner konnten nicht geladen werden.'
                )
            }

            renderFolders(
                result.folders ?? []
            )
        } catch (error) {
            console.error(
                'SharedMail: Ordner konnten nicht geladen werden.',
                error
            )

            showError(
                error?.message
                || 'Die IMAP-Ordner konnten nicht geladen werden.'
            )
        } finally {
            setLoading(false)
        }
    }

    mailboxButtons.forEach((button) => {
        button.addEventListener(
            'click',
            () => {
                loadFolders(button)
            }
        )
    })

    /*
     * Erstes verfügbares Postfach beim Start
     * automatisch öffnen.
     */
    loadFolders(
        mailboxButtons[0]
    )
})