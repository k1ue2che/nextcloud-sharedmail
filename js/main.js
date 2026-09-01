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

    let activeMailboxId = null
    let activeFolderName = null

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

    function formatMessageDate(message) {
        let date = null

        if (
            message.timestamp !== null
            && message.timestamp !== undefined
        ) {
            date = new Date(
                Number(message.timestamp) * 1000
            )
        } else if (message.date) {
            date = new Date(message.date)
        }

        if (
            !date
            || Number.isNaN(date.getTime())
        ) {
            return ''
        }

        const now = new Date()

        const isToday =
            date.getFullYear() === now.getFullYear()
            && date.getMonth() === now.getMonth()
            && date.getDate() === now.getDate()

        if (isToday) {
            return new Intl.DateTimeFormat(
                'de-DE',
                {
                    hour: '2-digit',
                    minute: '2-digit',
                }
            ).format(date)
        }

        const yesterday = new Date(now)

        yesterday.setDate(
            yesterday.getDate() - 1
        )

        const isYesterday =
            date.getFullYear()
                === yesterday.getFullYear()
            && date.getMonth()
                === yesterday.getMonth()
            && date.getDate()
                === yesterday.getDate()

        if (isYesterday) {
            return 'Gestern'
        }

        if (
            date.getFullYear()
            === now.getFullYear()
        ) {
            return new Intl.DateTimeFormat(
                'de-DE',
                {
                    day: '2-digit',
                    month: '2-digit',
                }
            ).format(date)
        }

        return new Intl.DateTimeFormat(
            'de-DE',
            {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
            }
        ).format(date)
    }

    function getSenderText(message) {
        const name =
            message.from?.name?.trim() || ''

        const email =
            message.from?.email?.trim() || ''

        if (name !== '') {
            return name
        }

        if (email !== '') {
            return email
        }

        return 'Unbekannter Absender'
    }

    function renderMessageLoading(folder) {
        if (!messageArea) {
            return
        }

        messageArea.textContent = ''

        const heading =
            document.createElement('h3')

        heading.textContent =
            folder.label || folder.name

        const loading =
            document.createElement('p')

        loading.className =
            'sharedmail-message-loading'

        loading.textContent =
            'Nachrichten werden geladen …'

        messageArea.appendChild(heading)
        messageArea.appendChild(loading)
    }

    function renderMessageError(
        folder,
        message
    ) {
        if (!messageArea) {
            return
        }

        messageArea.textContent = ''

        const heading =
            document.createElement('h3')

        heading.textContent =
            folder.label || folder.name

        const error =
            document.createElement('div')

        error.className =
            'sharedmail-message-error'

        error.textContent = message

        messageArea.appendChild(heading)
        messageArea.appendChild(error)
    }

    function renderMessages(
        result,
        folder
    ) {
        if (!messageArea) {
            return
        }

        messageArea.textContent = ''

        const header =
            document.createElement('div')

        header.className =
            'sharedmail-message-list-header'


        const heading =
            document.createElement('h3')

        heading.textContent =
            folder.label || folder.name


        const count =
            document.createElement('span')

        count.className =
            'sharedmail-message-count'

        const total =
            Number(result.total || 0)

        count.textContent =
            total === 1
                ? '1 Nachricht'
                : `${total} Nachrichten`


        header.appendChild(heading)
        header.appendChild(count)

        messageArea.appendChild(header)


        const messages =
            Array.isArray(result.messages)
                ? result.messages
                : []

        if (messages.length === 0) {
            const empty =
                document.createElement('div')

            empty.className =
                'sharedmail-message-empty'

            empty.textContent =
                'Dieser Ordner enthält keine Nachrichten.'

            messageArea.appendChild(empty)

            return
        }


        const list =
            document.createElement('div')

        list.className =
            'sharedmail-message-list'


        messages.forEach((message) => {
            const row =
                document.createElement('button')

            row.type = 'button'

            row.className =
                'sharedmail-message-row'

            row.dataset.uid =
                String(message.uid)

            row.dataset.folderName =
                folder.name

            if (!message.seen) {
                row.classList.add(
                    'sharedmail-message-unread'
                )
            }


            const unreadMarker =
                document.createElement('span')

            unreadMarker.className =
                'sharedmail-message-unread-marker'

            unreadMarker.textContent =
                message.seen ? '' : '●'


            const sender =
                document.createElement('span')

            sender.className =
                'sharedmail-message-sender'

            sender.textContent =
                getSenderText(message)

            if (message.from?.email) {
                sender.title =
                    message.from.email
            }


            const subject =
                document.createElement('span')

            subject.className =
                'sharedmail-message-subject'

            subject.textContent =
                message.subject
                || '(Kein Betreff)'


            const flags =
                document.createElement('span')

            flags.className =
                'sharedmail-message-flags'

            if (message.flagged) {
                const star =
                    document.createElement('span')

                star.className =
                    'sharedmail-message-flagged'

                star.textContent = '★'

                star.title = 'Markiert'

                flags.appendChild(star)
            }

            if (message.answered) {
                const answered =
                    document.createElement('span')

                answered.className =
                    'sharedmail-message-answered'

                answered.textContent = '↩'

                answered.title =
                    'Beantwortet'

                flags.appendChild(answered)
            }


            const date =
                document.createElement('span')

            date.className =
                'sharedmail-message-date'

            date.textContent =
                formatMessageDate(message)


            row.appendChild(
                unreadMarker
            )

            row.appendChild(
                sender
            )

            row.appendChild(
                subject
            )

            row.appendChild(
                flags
            )

            row.appendChild(
                date
            )


            row.addEventListener(
                'click',
                () => {
                    document
                        .querySelectorAll(
                            '.sharedmail-message-row.active'
                        )
                        .forEach((item) => {
                            item.classList.remove(
                                'active'
                            )
                        })

                    row.classList.add(
                        'active'
                    )

                    /*
                     * 0.2.9:
                     * Hier laden wir später den
                     * eigentlichen Nachrichteninhalt
                     * anhand der stabilen IMAP-UID.
                     */
                }
            )

            list.appendChild(row)
        })


        messageArea.appendChild(list)


        if (result.hasMore) {
            const loadMoreWrapper =
                document.createElement('div')

            loadMoreWrapper.className =
                'sharedmail-load-more-wrapper'


            const loadMore =
                document.createElement('button')

            loadMore.type = 'button'

            loadMore.className =
                'sharedmail-load-more'

            loadMore.textContent =
                'Weitere Nachrichten laden'


            loadMore.addEventListener(
                'click',
                () => {
                    loadMessages(
                        folder,
                        null,
                        Number(result.offset || 0)
                            + messages.length
                    )
                }
            )


            loadMoreWrapper.appendChild(
                loadMore
            )

            messageArea.appendChild(
                loadMoreWrapper
            )
        }
    }

    async function loadMessages(
        folder,
        folderButton = null,
        offset = 0
    ) {
        if (
            !activeMailboxId
            || !folder
            || !folder.name
        ) {
            return
        }

        activeFolderName =
            folder.name

        if (folderButton) {
            document
                .querySelectorAll(
                    '.sharedmail-folder.active'
                )
                .forEach((item) => {
                    item.classList.remove(
                        'active'
                    )
                })

            folderButton.classList.add(
                'active'
            )
        }

        renderMessageLoading(folder)

        try {
            const url =
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${activeMailboxId}/messages`
                )
                + '?folder='
                + encodeURIComponent(
                    folder.name
                )
                + '&limit=50'
                + '&offset='
                + encodeURIComponent(
                    String(offset)
                )

            const response =
                await fetch(
                    url,
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
                        JSON.parse(
                            responseText
                        )
                } catch (error) {
                    console.error(
                        'SharedMail: Ungültige Nachrichten-Antwort.',
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
                    || 'Die Nachrichten konnten nicht geladen werden.'
                )
            }

            /*
             * Benutzer könnte zwischenzeitlich
             * einen anderen Ordner gewählt haben.
             */
            if (
                activeFolderName
                !== folder.name
            ) {
                return
            }

            renderMessages(
                result,
                folder
            )
        } catch (error) {
            console.error(
                'SharedMail: Nachrichten konnten nicht geladen werden.',
                error
            )

            renderMessageError(
                folder,
                error?.message
                || 'Die Nachrichten konnten nicht geladen werden.'
            )
        }
    }

    function renderFolders(folders) {
        folderList.textContent = ''

        if (
            !Array.isArray(folders)
            || folders.length === 0
        ) {
            const empty =
                document.createElement('div')

            empty.className =
                'sharedmail-folder-empty'

            empty.textContent =
                'Auf dem IMAP-Server wurden keine Ordner gefunden.'

            folderList.appendChild(
                empty
            )

            return
        }

        let inboxEntry = null
        let firstSelectableEntry = null

        folders.forEach((folder) => {
            const button =
                document.createElement('button')

            button.type = 'button'

            button.className =
                'sharedmail-folder'

            if (!folder.selectable) {
                button.classList.add(
                    'sharedmail-folder-disabled'
                )

                button.disabled = true
            }

            button.dataset.folderName =
                folder.name


            const icon =
                document.createElement('span')

            icon.className =
                'sharedmail-folder-icon'

            icon.textContent =
                getFolderIcon(folder)


            const label =
                document.createElement('span')

            label.className =
                'sharedmail-folder-label'

            label.textContent =
                folder.label
                || folder.name


            const counters =
                document.createElement('span')

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

                counters.appendChild(
                    unseen
                )
            }


            if (folder.messages !== null) {
                const messages =
                    document.createElement('span')

                messages.className =
                    'sharedmail-folder-total'

                messages.textContent =
                    String(folder.messages)

                counters.appendChild(
                    messages
                )
            }


            button.appendChild(icon)
            button.appendChild(label)
            button.appendChild(counters)


            if (folder.selectable) {
                const entry = {
                    folder,
                    button,
                }

                if (!firstSelectableEntry) {
                    firstSelectableEntry =
                        entry
                }

                if (
                    folder.specialUse
                    === 'inbox'
                    || folder.name
                        .toUpperCase()
                        === 'INBOX'
                ) {
                    inboxEntry =
                        entry
                }

                button.addEventListener(
                    'click',
                    () => {
                        loadMessages(
                            folder,
                            button,
                            0
                        )
                    }
                )
            }


            folderList.appendChild(
                button
            )
        })


        /*
         * Nach dem Laden eines Postfachs
         * automatisch INBOX öffnen.
         */
        const initialEntry =
            inboxEntry
            || firstSelectableEntry

        if (initialEntry) {
            loadMessages(
                initialEntry.folder,
                initialEntry.button,
                0
            )
        }
    }

    async function loadFolders(button) {
        const mailboxId =
            button.dataset.mailboxId

        if (!mailboxId) {
            return
        }

        activeMailboxId =
            mailboxId

        activeFolderName =
            null

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
                'Postfach wird geladen …'

            messageArea.appendChild(
                info
            )
        }


        try {
            const response =
                await fetch(
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
                        JSON.parse(
                            responseText
                        )
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


            /*
             * Inzwischen könnte ein anderes
             * Postfach gewählt worden sein.
             */
            if (
                activeMailboxId
                !== mailboxId
            ) {
                return
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

    mailboxButtons.forEach(
        (button) => {
            button.addEventListener(
                'click',
                () => {
                    loadFolders(
                        button
                    )
                }
            )
        }
    )

    /*
     * Erstes verfügbares Postfach
     * automatisch öffnen.
     */
    loadFolders(
        mailboxButtons[0]
    )
})