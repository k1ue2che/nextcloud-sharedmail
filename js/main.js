document.addEventListener('DOMContentLoaded', () => {
    const mailboxButtons = Array.from(
        document.querySelectorAll('.sharedmail-mailbox-button')
    )

    const currentMailboxName =
        document.getElementById('sharedmail-current-mailbox-name')

    const currentMailboxEmail =
        document.getElementById('sharedmail-current-mailbox-email')

    const messageArea =
        document.getElementById('sharedmail-message-area')

    let activeMailboxId = null
    let activeMailboxButton = null

    let activeFolderName = null
    let activeFolder = null
    let activeFolderButton = null

    let activeFolders = []

    if (mailboxButtons.length === 0) {
        return
    }


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

        if (
            typeof window.requesttoken === 'string'
            && window.requesttoken !== ''
        ) {
            return window.requesttoken
        }

        return ''
    }


    function getFolderHost(mailboxId) {
        return document.querySelector(
            `.sharedmail-mailbox-folder-host[data-folder-host-for="${mailboxId}"]`
        )
    }


    function getFolderList(mailboxId) {
        return getFolderHost(mailboxId)
            ?.querySelector('.sharedmail-folder-list') || null
    }


    function setFolderLoading(mailboxId, loading) {
        const host = getFolderHost(mailboxId)

        if (!host) {
            return
        }

        const loadingElement =
            host.querySelector('.sharedmail-folder-loading')

        if (!loadingElement) {
            return
        }

        loadingElement.hidden = !loading
    }


    function clearFolderError(mailboxId) {
        const host = getFolderHost(mailboxId)

        if (!host) {
            return
        }

        const error =
            host.querySelector('.sharedmail-folder-error')

        if (!error) {
            return
        }

        error.hidden = true
        error.textContent = ''
    }


    function showFolderError(mailboxId, message) {
        const host = getFolderHost(mailboxId)

        if (!host) {
            return
        }

        const error =
            host.querySelector('.sharedmail-folder-error')

        if (!error) {
            return
        }

        error.textContent = message
        error.hidden = false
    }


    function selectMailboxHost(mailboxId) {
        document
            .querySelectorAll('.sharedmail-mailbox-folder-host')
            .forEach((host) => {
                host.hidden =
                    host.dataset.folderHostFor !== String(mailboxId)
            })
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


    function getMoveFolderLabel(folder) {
        const label =
            folder.label
            || folder.name
            || 'Ordner'

        if (
            folder.name
            && folder.name !== label
        ) {
            return `${getFolderIcon(folder)} ${label} — ${folder.name}`
        }

        return `${getFolderIcon(folder)} ${label}`
    }


    /*
     * Flache IMAP-Ordnerliste in einen Baum umwandeln.
     */
    function buildFolderTree(folders) {
        const nodes = new Map()

        folders.forEach((folder) => {
            nodes.set(
                folder.name,
                {
                    folder,
                    children: [],
                }
            )
        })

        const roots = []

        nodes.forEach((node) => {
            const folder = node.folder
            const delimiter = folder.delimiter

            if (
                !delimiter
                || !folder.name.includes(delimiter)
            ) {
                roots.push(node)
                return
            }

            const position =
                folder.name.lastIndexOf(delimiter)

            if (position <= 0) {
                roots.push(node)
                return
            }

            const parentName =
                folder.name.substring(0, position)

            const parent =
                nodes.get(parentName)

            if (parent) {
                parent.children.push(node)
            } else {
                roots.push(node)
            }
        })

        return roots
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
            date.getFullYear() === yesterday.getFullYear()
            && date.getMonth() === yesterday.getMonth()
            && date.getDate() === yesterday.getDate()

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


    function formatFullDate(message) {
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

        return new Intl.DateTimeFormat(
            'de-DE',
            {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }
        ).format(date)
    }


    function formatBytes(bytes) {
        const size =
            Number(bytes || 0)

        if (size < 1024) {
            return `${size} B`
        }

        if (size < 1024 * 1024) {
            return `${(size / 1024).toFixed(1)} KB`
        }

        return `${
            (
                size
                / 1024
                / 1024
            ).toFixed(1)
        } MB`
    }


    function getSenderText(message) {
        const name =
            message.from?.name?.trim()
            || ''

        const email =
            message.from?.email?.trim()
            || ''

        if (name !== '') {
            return name
        }

        if (email !== '') {
            return email
        }

        return 'Unbekannter Absender'
    }


    function formatAddress(address) {
        const name =
            address?.name?.trim()
            || ''

        const email =
            address?.email?.trim()
            || ''

        if (
            name !== ''
            && email !== ''
        ) {
            return `${name} <${email}>`
        }

        if (email !== '') {
            return email
        }

        return name
    }


    function formatAddresses(addresses) {
        if (!Array.isArray(addresses)) {
            return ''
        }

        return addresses
            .map(formatAddress)
            .filter(
                (value) =>
                    value !== ''
            )
            .join(', ')
    }


    function updateMessageRowReadState(
        row,
        isRead
    ) {
        if (!row) {
            return
        }

        if (isRead) {
            row.classList.remove(
                'sharedmail-message-unread'
            )
        } else {
            row.classList.add(
                'sharedmail-message-unread'
            )
        }

        const marker =
            row.querySelector(
                '.sharedmail-message-unread-marker'
            )

        if (marker) {
            marker.textContent =
                isRead
                    ? ''
                    : '●'
        }
    }


    /*
     * Gemeinsamer POST für persönlichen Lesestatus.
     */
    async function setMessageReadState(
        mailboxId,
        folder,
        uid,
        isRead
    ) {
        const action =
            isRead
                ? 'read'
                : 'unread'

        const url =
            OC.generateUrl(
                `/apps/sharedmail/api/mailboxes/${mailboxId}/messages/${uid}/${action}`
            )
            + '?folder='
            + encodeURIComponent(folder)

        const headers = {
            Accept: 'application/json',
        }

        const requestToken =
            getRequestToken()

        if (requestToken !== '') {
            headers.requesttoken =
                requestToken
        }

        const response =
            await fetch(
                url,
                {
                    method: 'POST',
                    headers,
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
                    'SharedMail: Ungültige Read-State-Antwort.',
                    responseText
                )

                throw new Error(
                    'Der Lesestatus konnte nicht gespeichert werden.'
                )
            }
        }

        if (!response.ok) {
            throw new Error(
                result.message
                || result.error
                || 'Der Lesestatus konnte nicht gespeichert werden.'
            )
        }

        return result
    }


    async function markMessageRead(
        mailboxId,
        folder,
        uid
    ) {
        return setMessageReadState(
            mailboxId,
            folder,
            uid,
            true
        )
    }


    async function markMessageUnread(
        mailboxId,
        folder,
        uid
    ) {
        return setMessageReadState(
            mailboxId,
            folder,
            uid,
            false
        )
    }


    /*
     * Echte IMAP-Nachricht verschieben.
     */
    async function moveMessage(
        mailboxId,
        sourceFolder,
        uid,
        targetFolder
    ) {
        const url =
            OC.generateUrl(
                `/apps/sharedmail/api/mailboxes/${mailboxId}/messages/${uid}/move`
            )
            + '?folder='
            + encodeURIComponent(sourceFolder)
            + '&target='
            + encodeURIComponent(targetFolder)

        const headers = {
            Accept: 'application/json',
        }

        const requestToken =
            getRequestToken()

        if (requestToken !== '') {
            headers.requesttoken =
                requestToken
        }

        const response =
            await fetch(
                url,
                {
                    method: 'POST',
                    headers,
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
                    'SharedMail: Ungültige Move-Antwort.',
                    responseText
                )

                throw new Error(
                    'Der Server hat eine ungültige Antwort geliefert.'
                )
            }
        }

        if (!response.ok) {
            throw new Error(
                result.details
                || result.message
                || result.error
                || 'Die Nachricht konnte nicht verschoben werden.'
            )
        }

        if (!result.success) {
            throw new Error(
                result.message
                || 'Die Nachricht konnte nicht verschoben werden.'
            )
        }

        return result
    }


    function renderMessageLoading(folder) {
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
            folder.label
            || folder.name

        const loading =
            document.createElement('span')

        loading.className =
            'sharedmail-message-loading'

        loading.textContent =
            'Nachrichten werden geladen …'

        header.appendChild(heading)
        header.appendChild(loading)

        messageArea.appendChild(header)
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
            folder.label
            || folder.name

        const error =
            document.createElement('div')

        error.className =
            'sharedmail-message-error'

        error.textContent =
            message

        messageArea.appendChild(heading)
        messageArea.appendChild(error)
    }


    function createSafeHtmlFrame(html) {
        const iframe =
            document.createElement('iframe')

        iframe.className =
            'sharedmail-html-frame'

        iframe.setAttribute(
            'sandbox',
            ''
        )

        const csp = `
            default-src 'none';
            img-src data:;
            style-src 'unsafe-inline';
            font-src 'none';
            media-src 'none';
            frame-src 'none';
            connect-src 'none';
            form-action 'none';
            base-uri 'none';
        `

        iframe.srcdoc = `
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta
    http-equiv="Content-Security-Policy"
    content="${csp.replace(/\s+/g, ' ').trim()}"
>
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<style>
    html,
    body {
        margin: 0;
        padding: 0;
        background: transparent;
        color: #222;
        font-family:
            system-ui,
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            sans-serif;
        font-size: 14px;
        line-height: 1.5;
    }

    body {
        padding: 4px;
        overflow-wrap: anywhere;
    }

    img {
        max-width: 100%;
        height: auto;
    }

    table {
        max-width: 100%;
    }

    pre {
        white-space: pre-wrap;
    }

    a {
        color: inherit;
        text-decoration: underline;
        pointer-events: none;
    }
</style>
</head>
<body>
${html || ''}
</body>
</html>
        `

        return iframe
    }

    function getAttachmentDownloadUrl(
        mailboxId,
        folder,
        uid,
        mimeId
    ) {
        return OC.generateUrl(
            `/apps/sharedmail/api/mailboxes/${mailboxId}/messages/${uid}/attachment`
        )
            + '?folder='
            + encodeURIComponent(folder)
            + '&mimeId='
            + encodeURIComponent(mimeId)
    }

    function getAttachmentViewUrl(
        mailboxId,
        folder,
        uid,
        mimeId
    ) {
        return OC.generateUrl(
            `/apps/sharedmail/api/mailboxes/${mailboxId}/messages/${uid}/attachment/view`
        )
            + '?folder='
            + encodeURIComponent(folder)
            + '&mimeId='
            + encodeURIComponent(mimeId)
    }


    function isInlineViewableAttachment(
        attachment
    ) {
        const contentType =
            String(
                attachment?.contentType
                || ''
            )
                .trim()
                .toLowerCase()

        return [
            'application/pdf',

            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/avif',
        ].includes(
            contentType
        )
    }

    function renderMessageViewer(message) {
        if (!messageArea) {
            return
        }

        messageArea.textContent = ''

        const viewer =
            document.createElement('div')

        viewer.className =
            'sharedmail-viewer'


        const header =
            document.createElement('div')

        header.className =
            'sharedmail-viewer-header'


        const subject =
            document.createElement('h2')

        subject.className =
            'sharedmail-viewer-subject'

        subject.textContent =
            message.subject
            || '(Kein Betreff)'


        const date =
            document.createElement('div')

        date.className =
            'sharedmail-viewer-date'

        date.textContent =
            formatFullDate(message)


        header.appendChild(subject)
        header.appendChild(date)

        viewer.appendChild(header)


        const meta =
            document.createElement('div')

        meta.className =
            'sharedmail-viewer-meta'


        const fromRow =
            document.createElement('div')

        fromRow.className =
            'sharedmail-viewer-meta-row'


        const fromLabel =
            document.createElement('strong')

        fromLabel.textContent =
            'Von:'


        const fromValue =
            document.createElement('span')

        fromValue.textContent =
            formatAddress(message.from)


        fromRow.appendChild(fromLabel)
        fromRow.appendChild(fromValue)

        meta.appendChild(fromRow)


        const toText =
            formatAddresses(message.to)

        if (toText !== '') {
            const row =
                document.createElement('div')

            row.className =
                'sharedmail-viewer-meta-row'

            const label =
                document.createElement('strong')

            label.textContent =
                'An:'

            const value =
                document.createElement('span')

            value.textContent =
                toText

            row.appendChild(label)
            row.appendChild(value)

            meta.appendChild(row)
        }


        const ccText =
            formatAddresses(message.cc)

        if (ccText !== '') {
            const row =
                document.createElement('div')

            row.className =
                'sharedmail-viewer-meta-row'

            const label =
                document.createElement('strong')

            label.textContent =
                'CC:'

            const value =
                document.createElement('span')

            value.textContent =
                ccText

            row.appendChild(label)
            row.appendChild(value)

            meta.appendChild(row)
        }


        viewer.appendChild(meta)


        /*
         * Statuszeile.
         */
        const status =
            document.createElement('div')

        status.className =
            'sharedmail-viewer-status'


        function renderViewerStatus() {
            status.textContent = ''

            if (!message.seen) {
                const unread =
                    document.createElement('span')

                unread.textContent =
                    '● Ungelesen'

                status.appendChild(unread)
            }


            if (message.flagged) {
                const flagged =
                    document.createElement('span')

                flagged.textContent =
                    '★ Markiert'

                status.appendChild(flagged)
            }


            if (message.answered) {
                const answered =
                    document.createElement('span')

                answered.textContent =
                    '↩ Beantwortet'

                status.appendChild(answered)
            }

            status.hidden =
                status.children.length === 0
        }


        renderViewerStatus()

        viewer.appendChild(status)


        const body =
            document.createElement('div')

        body.className =
            'sharedmail-viewer-body'


        if (
            message.body?.type === 'html'
            && message.body?.content
        ) {
            body.appendChild(
                createSafeHtmlFrame(
                    message.body.content
                )
            )
        } else {
            const plain =
                document.createElement('div')

            plain.className =
                'sharedmail-plain-body'

            plain.textContent =
                message.body?.content
                || ''

            body.appendChild(plain)
        }


        viewer.appendChild(body)


        const attachments =
            Array.isArray(message.attachments)
                ? message.attachments
                : []


        if (attachments.length > 0) {
            const attachmentArea =
                document.createElement('div')

            attachmentArea.className =
                'sharedmail-attachments'


            const attachmentHeading =
                document.createElement('h3')

            attachmentHeading.textContent =
                attachments.length === 1
                    ? '1 Anhang'
                    : `${attachments.length} Anhänge`


            attachmentArea.appendChild(
                attachmentHeading
            )


            const attachmentList =
                document.createElement('div')

            attachmentList.className =
                'sharedmail-attachment-list'


            attachments.forEach(
                (attachment) => {
                    const item =
                        document.createElement('div')

                    item.className =
                        'sharedmail-attachment'


                    const icon =
                        document.createElement('span')

                    icon.className =
                        'sharedmail-attachment-icon'

                    icon.textContent =
                        '📎'


                    const info =
                        document.createElement('div')

                    info.className =
                        'sharedmail-attachment-info'


                    const name =
                        document.createElement('strong')

                    name.textContent =
                        attachment.name
                        || 'Anhang'


                    const details =
                        document.createElement('span')

                    details.className =
                        'sharedmail-attachment-details'

                    details.textContent =
                        [
                            attachment.contentType,
                            formatBytes(
                                attachment.size
                            ),
                        ]
                            .filter(Boolean)
                            .join(' · ')


                    info.appendChild(name)
                    info.appendChild(details)


                    const actions =
                        document.createElement('div')

                    actions.className =
                        'sharedmail-attachment-actions'

                    if (
                        isInlineViewableAttachment(
                            attachment
                        )
                    ) {
                        const open =
                            document.createElement('a')

                        open.className =
                            'sharedmail-attachment-open'

                        open.textContent =
                            'Öffnen'

                        open.href =
                            getAttachmentViewUrl(
                                activeMailboxId,
                                message.folder
                                    || activeFolderName
                                    || 'INBOX',
                                message.uid,
                                attachment.mimeId
                            )

                        /*
                        * Separater Tab.
                        */
                        open.target =
                            '_blank'

                        open.rel =
                            'noopener noreferrer'

                        actions.appendChild(
                            open
                        )
                    }

                    const download =
                        document.createElement('a')

                    download.className =
                        'sharedmail-attachment-download'

                    download.textContent =
                        'Herunterladen'

                    download.href =
                        getAttachmentDownloadUrl(
                            activeMailboxId,
                            message.folder
                                || activeFolderName
                                || 'INBOX',
                            message.uid,
                            attachment.mimeId
                        )

                    /*
                    * Normales Browser-Download-Verhalten.
                    */
                    download.setAttribute(
                        'download',
                        attachment.name
                        || 'Anhang'
                    )


                    actions.appendChild(
                        download
                    )


                    item.appendChild(icon)
                    item.appendChild(info)
                    item.appendChild(actions)


                    /*
                    * Auch ein Klick auf die Anhangsbox
                    * löst den Download aus.
                    *
                    * Klick auf den Link selbst nicht doppelt
                    * behandeln.
                    */
                    item.addEventListener(
                        'click',
                        (event) => {
                           if (
                                event.target.closest(
                                    '.sharedmail-attachment-download'
                                )
                                || event.target.closest(
                                    '.sharedmail-attachment-open'
                                )
                            ) {
                                return
                            }

                            window.location.href =
                                download.href
                        }
                    )


                    item.style.cursor =
                        'pointer'


                    attachmentList.appendChild(
                        item
                    )
                }
            )


            attachmentArea.appendChild(
                attachmentList
            )

            viewer.appendChild(
                attachmentArea
            )
        }


        /*
         * Aktionen.
         */
        const footer =
            document.createElement('div')

        footer.className =
            'sharedmail-viewer-footer'


        const sourceFolder =
            message.folder
            || activeFolderName
            || ''

        const messageUid =
            Number(message.uid || 0)


        /*
         * Persönlicher gelesen/ungelesen-Status.
         */
        const readStateArea =
            document.createElement('div')

        readStateArea.className =
            'sharedmail-viewer-read-state'


        const readStateButton =
            document.createElement('button')

        readStateButton.type =
            'button'

        readStateButton.className =
            'sharedmail-read-state-button'


        const readStateStatus =
            document.createElement('span')

        readStateStatus.className =
            'sharedmail-read-state-status'


        function updateReadStateButton() {
            readStateButton.textContent =
                message.seen
                    ? 'Als ungelesen markieren'
                    : 'Als gelesen markieren'

            readStateButton.title =
                message.seen
                    ? 'Nur für mich als ungelesen markieren'
                    : 'Nur für mich als gelesen markieren'
        }


        updateReadStateButton()


        readStateButton.addEventListener(
            'click',
            async () => {
                if (
                    !activeMailboxId
                    || sourceFolder === ''
                    || messageUid <= 0
                ) {
                    return
                }

                const requestedMailboxId =
                    activeMailboxId

                const requestedMailboxButton =
                    activeMailboxButton

                const newReadState =
                    !message.seen


                readStateButton.disabled =
                    true

                readStateStatus.textContent =
                    newReadState
                        ? 'Wird als gelesen markiert …'
                        : 'Wird als ungelesen markiert …'


                try {
                    if (newReadState) {
                        await markMessageRead(
                            requestedMailboxId,
                            sourceFolder,
                            messageUid
                        )
                    } else {
                        await markMessageUnread(
                            requestedMailboxId,
                            sourceFolder,
                            messageUid
                        )
                    }


                    message.seen =
                        newReadState

                    renderViewerStatus()
                    updateReadStateButton()


                    readStateStatus.textContent =
                        newReadState
                            ? 'Als gelesen markiert.'
                            : 'Als ungelesen markiert.'


                    /*
                     * Persönliche Ordnerzähler und
                     * Nachrichtenliste aktualisieren.
                     *
                     * Wir bleiben dabei im selben Ordner.
                     */
                    if (
                        activeMailboxId
                        === requestedMailboxId
                        && requestedMailboxButton
                    ) {
                        await loadFolders(
                            requestedMailboxButton,
                            sourceFolder
                        )
                    }
                } catch (error) {
                    console.error(
                        'SharedMail: Lesestatus konnte nicht geändert werden.',
                        error
                    )

                    readStateStatus.textContent =
                        error?.message
                        || 'Der Lesestatus konnte nicht geändert werden.'

                    readStateButton.disabled =
                        false
                }
            }
        )


        readStateArea.appendChild(
            readStateButton
        )

        readStateArea.appendChild(
            readStateStatus
        )

        footer.appendChild(
            readStateArea
        )


        /*
         * Verschieben nach ...
         */
        const moveArea =
            document.createElement('div')

        moveArea.className =
            'sharedmail-viewer-move'


        const moveSelect =
            document.createElement('select')

        moveSelect.className =
            'sharedmail-move-select'

        moveSelect.setAttribute(
            'aria-label',
            'Zielordner'
        )


        const placeholder =
            document.createElement('option')

        placeholder.value = ''
        placeholder.textContent =
            'Verschieben nach …'

        moveSelect.appendChild(
            placeholder
        )


        const moveTargets =
            activeFolders.filter(
                (folder) =>
                    folder
                    && folder.selectable
                    && folder.name
                    && folder.name !== sourceFolder
            )


        moveTargets.forEach(
            (folder) => {
                const option =
                    document.createElement('option')

                option.value =
                    folder.name

                option.textContent =
                    getMoveFolderLabel(folder)

                moveSelect.appendChild(
                    option
                )
            }
        )


        const moveButton =
            document.createElement('button')

        moveButton.type =
            'button'

        moveButton.className =
            'sharedmail-move-button'

        moveButton.textContent =
            'Verschieben'

        moveButton.disabled =
            true


        const moveStatus =
            document.createElement('span')

        moveStatus.className =
            'sharedmail-move-status'


        if (moveTargets.length === 0) {
            moveSelect.disabled =
                true

            moveStatus.textContent =
                'Kein anderer Zielordner verfügbar.'
        }


        moveSelect.addEventListener(
            'change',
            () => {
                moveButton.disabled =
                    moveSelect.value === ''
            }
        )


        moveButton.addEventListener(
            'click',
            async () => {
                const targetFolder =
                    moveSelect.value

                if (
                    !activeMailboxId
                    || sourceFolder === ''
                    || messageUid <= 0
                    || targetFolder === ''
                ) {
                    return
                }


                const requestedMailboxId =
                    activeMailboxId

                const requestedMailboxButton =
                    activeMailboxButton


                moveSelect.disabled =
                    true

                moveButton.disabled =
                    true

                moveButton.textContent =
                    'Wird verschoben …'

                moveStatus.textContent =
                    ''


                try {
                    const result =
                        await moveMessage(
                            requestedMailboxId,
                            sourceFolder,
                            messageUid,
                            targetFolder
                        )


                    console.log(
                        'SharedMail: Nachricht verschoben.',
                        result
                    )


                    moveStatus.textContent =
                        'Nachricht wurde verschoben.'


                    if (
                        activeMailboxId
                        !== requestedMailboxId
                    ) {
                        return
                    }


                    if (requestedMailboxButton) {
                        await loadFolders(
                            requestedMailboxButton,
                            sourceFolder
                        )
                    }
                } catch (error) {
                    console.error(
                        'SharedMail: Nachricht konnte nicht verschoben werden.',
                        error
                    )

                    moveStatus.textContent =
                        error?.message
                        || 'Die Nachricht konnte nicht verschoben werden.'

                    moveSelect.disabled =
                        false

                    moveButton.disabled =
                        moveSelect.value === ''

                    moveButton.textContent =
                        'Verschieben'
                }
            }
        )


        moveArea.appendChild(
            moveSelect
        )

        moveArea.appendChild(
            moveButton
        )

        moveArea.appendChild(
            moveStatus
        )

        footer.appendChild(
            moveArea
        )


        /*
         * Zurück.
         */
        const back =
            document.createElement('button')

        back.type =
            'button'

        back.textContent =
            '← Zurück zur Nachrichtenliste'


        back.addEventListener(
            'click',
            () => {
                if (
                    activeFolder
                    && activeFolderButton
                ) {
                    loadMessages(
                        activeFolder,
                        activeFolderButton,
                        0
                    )
                }
            }
        )


        footer.appendChild(back)

        viewer.appendChild(footer)

        messageArea.appendChild(viewer)
    }


    /*
     * Einzelne Nachricht öffnen.
     *
     * Öffnen setzt ausschließlich den
     * persönlichen Read-State.
     */
    async function loadMessage(
        folder,
        uid,
        row
    ) {
        if (
            !activeMailboxId
            || !folder
            || !uid
        ) {
            return
        }

        const requestedMailboxId =
            activeMailboxId

        const requestedFolder =
            folder


        document
            .querySelectorAll(
                '.sharedmail-message-row.active'
            )
            .forEach((item) => {
                item.classList.remove(
                    'active'
                )
            })


        if (row) {
            row.classList.add(
                'active'
            )
        }


        if (messageArea) {
            messageArea.textContent = ''

            const loading =
                document.createElement('p')

            loading.className =
                'sharedmail-message-loading'

            loading.textContent =
                'Nachricht wird geladen …'

            messageArea.appendChild(
                loading
            )
        }


        try {
            const url =
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${requestedMailboxId}/messages/${uid}`
                )
                + '?folder='
                + encodeURIComponent(
                    requestedFolder
                )


            const response =
                await fetch(
                    url,
                    {
                        method: 'GET',
                        headers: {
                            Accept:
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
                        'SharedMail: Ungültige Mail-Antwort.',
                        responseText
                    )

                    throw new Error(
                        'Der Server hat eine ungültige Antwort geliefert.'
                    )
                }
            }


            if (!response.ok) {
                throw new Error(
                    result.message
                    || result.error
                    || 'Die Nachricht konnte nicht geladen werden.'
                )
            }


            if (
                activeMailboxId
                    !== requestedMailboxId
                || activeFolderName
                    !== requestedFolder
            ) {
                return
            }


            const message =
                result.message


            /*
             * Beim Öffnen persönlich gelesen setzen.
             *
             * Kein IMAP-\Seen.
             */
            if (
                message
                && !message.seen
            ) {
                try {
                    await markMessageRead(
                        requestedMailboxId,
                        requestedFolder,
                        uid
                    )

                    message.seen =
                        true

                    updateMessageRowReadState(
                        row,
                        true
                    )
                } catch (error) {
                    console.error(
                        'SharedMail: Persönlicher Lesestatus konnte nicht gespeichert werden.',
                        error
                    )
                }
            }


            renderMessageViewer(
                message
            )
        } catch (error) {
            console.error(
                'SharedMail: Nachricht konnte nicht geladen werden.',
                error
            )

            if (messageArea) {
                messageArea.textContent = ''

                const errorElement =
                    document.createElement('div')

                errorElement.className =
                    'sharedmail-message-error'

                errorElement.textContent =
                    error?.message
                    || 'Die Nachricht konnte nicht geladen werden.'

                messageArea.appendChild(
                    errorElement
                )
            }
        }
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
            folder.label
            || folder.name


        const count =
            document.createElement('span')

        count.className =
            'sharedmail-message-count'


        const total =
            Number(
                result.total || 0
            )

        count.textContent =
            total === 1
                ? '1 Nachricht'
                : `${total} Nachrichten`


        header.appendChild(
            heading
        )

        header.appendChild(
            count
        )

        messageArea.appendChild(
            header
        )


        const messages =
            Array.isArray(
                result.messages
            )
                ? result.messages
                : []


        if (
            messages.length === 0
        ) {
            const empty =
                document.createElement('div')

            empty.className =
                'sharedmail-message-empty'

            empty.textContent =
                'Dieser Ordner enthält keine Nachrichten.'

            messageArea.appendChild(
                empty
            )

            return
        }


        const list =
            document.createElement('div')

        list.className =
            'sharedmail-message-list'


        messages.forEach(
            (message) => {
                const row =
                    document.createElement(
                        'button'
                    )

                row.type =
                    'button'

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
                    document.createElement(
                        'span'
                    )

                unreadMarker.className =
                    'sharedmail-message-unread-marker'

                unreadMarker.textContent =
                    message.seen
                        ? ''
                        : '●'


                const sender =
                    document.createElement(
                        'span'
                    )

                sender.className =
                    'sharedmail-message-sender'

                sender.textContent =
                    getSenderText(message)


                if (message.from?.email) {
                    sender.title =
                        message.from.email
                }


                const subject =
                    document.createElement(
                        'span'
                    )

                subject.className =
                    'sharedmail-message-subject'

                subject.textContent =
                    message.subject
                    || '(Kein Betreff)'


                const flags =
                    document.createElement(
                        'span'
                    )

                flags.className =
                    'sharedmail-message-flags'


                if (message.flagged) {
                    const star =
                        document.createElement(
                            'span'
                        )

                    star.className =
                        'sharedmail-message-flagged'

                    star.textContent =
                        '★'

                    star.title =
                        'Markiert'

                    flags.appendChild(
                        star
                    )
                }


                if (message.answered) {
                    const answered =
                        document.createElement(
                            'span'
                        )

                    answered.className =
                        'sharedmail-message-answered'

                    answered.textContent =
                        '↩'

                    answered.title =
                        'Beantwortet'

                    flags.appendChild(
                        answered
                    )
                }


                const date =
                    document.createElement(
                        'span'
                    )

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
                        loadMessage(
                            folder.name,
                            message.uid,
                            row
                        )
                    }
                )


                list.appendChild(
                    row
                )
            }
        )


        messageArea.appendChild(
            list
        )


        if (result.hasMore) {
            const wrapper =
                document.createElement(
                    'div'
                )

            wrapper.className =
                'sharedmail-load-more-wrapper'


            const button =
                document.createElement(
                    'button'
                )

            button.type =
                'button'

            button.className =
                'sharedmail-load-more'

            button.textContent =
                'Weitere Nachrichten laden'


            button.addEventListener(
                'click',
                () => {
                    loadMessages(
                        folder,
                        activeFolderButton,
                        Number(
                            result.offset
                            || 0
                        )
                        + messages.length
                    )
                }
            )


            wrapper.appendChild(
                button
            )

            messageArea.appendChild(
                wrapper
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

        activeFolder =
            folder


        if (folderButton) {
            activeFolderButton =
                folderButton


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


        renderMessageLoading(
            folder
        )


        const requestedMailboxId =
            activeMailboxId


        try {
            const url =
                OC.generateUrl(
                    `/apps/sharedmail/api/mailboxes/${requestedMailboxId}/messages`
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
                            Accept:
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
                    result.message
                    || result.error
                    || 'Die Nachrichten konnten nicht geladen werden.'
                )
            }


            if (
                activeMailboxId
                    !== requestedMailboxId
                || activeFolderName
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


    function renderFolders(
        folders,
        folderList,
        preferredFolderName = null
    ) {
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


        const tree =
            buildFolderTree(folders)


        let inboxEntry = null
        let firstSelectableEntry = null
        let preferredEntry = null


        function renderNode(
            node,
            depth = 0
        ) {
            const folder =
                node.folder


            const wrapper =
                document.createElement('div')

            wrapper.className =
                'sharedmail-folder-node'


            const row =
                document.createElement('div')

            row.className =
                'sharedmail-folder-row'

            row.style.setProperty(
                '--sharedmail-folder-depth',
                String(depth)
            )


            let toggle = null


            if (
                node.children.length > 0
            ) {
                toggle =
                    document.createElement('button')

                toggle.type =
                    'button'

                toggle.className =
                    'sharedmail-folder-toggle'

                toggle.textContent =
                    '▾'

                toggle.setAttribute(
                    'aria-expanded',
                    'true'
                )

                row.appendChild(
                    toggle
                )
            } else {
                const placeholder =
                    document.createElement('span')

                placeholder.className =
                    'sharedmail-folder-toggle-placeholder'

                row.appendChild(
                    placeholder
                )
            }


            const button =
                document.createElement('button')

            button.type =
                'button'

            button.className =
                'sharedmail-folder'

            button.dataset.folderName =
                folder.name


            if (!folder.selectable) {
                button.disabled =
                    true

                button.classList.add(
                    'sharedmail-folder-disabled'
                )
            }


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


            if (
                folder.messages !== null
            ) {
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


            row.appendChild(button)
            wrapper.appendChild(row)


            let childrenContainer =
                null


            if (
                node.children.length > 0
            ) {
                childrenContainer =
                    document.createElement('div')

                childrenContainer.className =
                    'sharedmail-folder-children'


                node.children.forEach(
                    (child) => {
                        childrenContainer.appendChild(
                            renderNode(
                                child,
                                depth + 1
                            )
                        )
                    }
                )


                wrapper.appendChild(
                    childrenContainer
                )


                toggle.addEventListener(
                    'click',
                    () => {
                        const willOpen =
                            childrenContainer.hidden

                        childrenContainer.hidden =
                            !willOpen

                        toggle.textContent =
                            willOpen
                                ? '▾'
                                : '▸'

                        toggle.setAttribute(
                            'aria-expanded',
                            willOpen
                                ? 'true'
                                : 'false'
                        )
                    }
                )
            }


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
                    preferredFolderName
                    && folder.name
                        === preferredFolderName
                ) {
                    preferredEntry =
                        entry
                }


                if (
                    folder.specialUse === 'inbox'
                    || folder.name
                        .toUpperCase() === 'INBOX'
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


            return wrapper
        }


        tree.forEach(
            (node) => {
                folderList.appendChild(
                    renderNode(
                        node,
                        0
                    )
                )
            }
        )


        const initialEntry =
            preferredEntry
            || inboxEntry
            || firstSelectableEntry


        if (initialEntry) {
            loadMessages(
                initialEntry.folder,
                initialEntry.button,
                0
            )
        }
    }


    /*
     * preferredFolderName wird nach Statusänderung
     * oder Move verwendet, damit wir im bisherigen
     * Ordner bleiben.
     */
    async function loadFolders(
        mailboxButton,
        preferredFolderName = null
    ) {
        const mailboxId =
            mailboxButton
                .dataset
                .mailboxId


        if (!mailboxId) {
            return
        }


        activeMailboxId =
            mailboxId

        activeMailboxButton =
            mailboxButton

        activeFolderName =
            null

        activeFolder =
            null

        activeFolderButton =
            null

        activeFolders =
            []


        mailboxButtons.forEach(
            (button) => {
                button.classList.remove(
                    'active'
                )
            }
        )


        mailboxButton.classList.add(
            'active'
        )


        selectMailboxHost(
            mailboxId
        )

        clearFolderError(
            mailboxId
        )

        setFolderLoading(
            mailboxId,
            true
        )


        const folderList =
            getFolderList(
                mailboxId
            )


        if (folderList) {
            folderList.textContent =
                ''
        }


        if (currentMailboxName) {
            currentMailboxName.textContent =
                mailboxButton
                    .dataset
                    .mailboxName
                || 'Shared Mail'
        }


        if (currentMailboxEmail) {
            currentMailboxEmail.textContent =
                mailboxButton
                    .dataset
                    .mailboxEmail
                || ''
        }


        if (messageArea) {
            messageArea.textContent =
                ''

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
                            Accept:
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
                        'SharedMail: Ungültige Ordner-Antwort.',
                        responseText
                    )

                    throw new Error(
                        'Der Server hat eine ungültige Antwort geliefert.'
                    )
                }
            }


            if (!response.ok) {
                throw new Error(
                    result.message
                    || result.error
                    || 'Die IMAP-Ordner konnten nicht geladen werden.'
                )
            }


            if (
                activeMailboxId
                !== mailboxId
            ) {
                return
            }


            if (!folderList) {
                throw new Error(
                    'Die Ordneransicht konnte nicht initialisiert werden.'
                )
            }


            activeFolders =
                Array.isArray(
                    result.folders
                )
                    ? result.folders
                    : []


            renderFolders(
                activeFolders,
                folderList,
                preferredFolderName
            )
        } catch (error) {
            console.error(
                'SharedMail: Ordner konnten nicht geladen werden.',
                error
            )

            showFolderError(
                mailboxId,
                error?.message
                || 'Die IMAP-Ordner konnten nicht geladen werden.'
            )
        } finally {
            setFolderLoading(
                mailboxId,
                false
            )
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


    loadFolders(
        mailboxButtons[0]
    )
})