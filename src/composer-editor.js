import {
    ClassicEditor,
    Essentials,
    Paragraph,
    Bold,
    Italic,
    Underline,
    Strikethrough,
    Link,
    List,
    BlockQuote,
} from 'ckeditor5'

import 'ckeditor5/ckeditor5.css'


const MAX_ATTACHMENTS = 10
const MAX_FILE_BYTES = 10 * 1024 * 1024
const MAX_TOTAL_BYTES = 25 * 1024 * 1024

let activeEditor = null


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


function getActiveMailboxId() {
    const button =
        document.querySelector(
            '.sharedmail-mailbox-button.active'
        )

    const mailboxId =
        Number(
            button?.dataset
                ?.mailboxId
            || 0
        )

    return Number.isInteger(mailboxId)
        ? mailboxId
        : 0
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
        if (!(file instanceof File)) {
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


async function saveReplyDraft(
    message,
    to,
    subject,
    html,
    attachments,
    draftUid
) {
    const mailboxId =
        getActiveMailboxId()

    const sourceUid =
        Number(
            message?.uid
            || 0
        )

    const sourceFolder =
        String(
            message?.folder
            || 'INBOX'
        )

    if (
        mailboxId <= 0
        || sourceUid <= 0
    ) {
        throw new Error(
            'Postfach oder Nachricht konnte nicht bestimmt werden.'
        )
    }

    const url =
        OC.generateUrl(
            `/apps/sharedmail/api/mailboxes/${mailboxId}/drafts`
        )

    const formData =
        new FormData()

    formData.append(
        'to',
        to
    )

    formData.append(
        'cc',
        ''
    )

    formData.append(
        'bcc',
        ''
    )

    formData.append(
        'subject',
        subject
    )

    formData.append(
        'html',
        html
    )

    formData.append(
        'sourceFolder',
        sourceFolder
    )

    formData.append(
        'sourceUid',
        String(sourceUid)
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

    const data =
        await response.json()

    if (
        !response.ok
        || !data?.success
    ) {
        throw new Error(
            data?.message
            || 'Der Entwurf konnte nicht gespeichert werden.'
        )
    }

    return data
}


async function sendReply(
    message,
    to,
    subject,
    html,
    attachments
) {
    const mailboxId =
        getActiveMailboxId()

    const uid =
        Number(
            message?.uid
            || 0
        )

    const folder =
        String(
            message?.folder
            || 'INBOX'
        )

    if (
        mailboxId <= 0
        || uid <= 0
    ) {
        throw new Error(
            'Postfach oder Nachricht konnte nicht bestimmt werden.'
        )
    }

    const url =
        OC.generateUrl(
            `/apps/sharedmail/api/mailboxes/${mailboxId}/messages/${uid}/reply`
        )

    const formData =
        new FormData()

    formData.append(
        'folder',
        folder
    )

    formData.append(
        'to',
        to
    )

    formData.append(
        'subject',
        subject
    )

    formData.append(
        'html',
        html
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

    const data =
        await response.json()

    if (
        !response.ok
        || !data?.success
    ) {
        throw new Error(
            data?.message
            || 'Die Antwort konnte nicht gesendet werden.'
        )
    }

    return data
}


window.SharedMailEditor = Object.freeze({
    async create(
        element,
        initialData = ''
    ) {
        if (!element) {
            throw new Error(
                'Shared Mail Editor konnte nicht initialisiert werden.'
            )
        }

        return ClassicEditor.create(
            element,
            {
                licenseKey: 'GPL',

                plugins: [
                    Essentials,
                    Paragraph,
                    Bold,
                    Italic,
                    Underline,
                    Strikethrough,
                    Link,
                    List,
                    BlockQuote,
                ],

                toolbar: {
                    items: [
                        'undo',
                        'redo',
                        '|',
                        'bold',
                        'italic',
                        'underline',
                        'strikethrough',
                        '|',
                        'link',
                        '|',
                        'bulletedList',
                        'numberedList',
                        '|',
                        'blockQuote',
                    ],

                    shouldNotGroupWhenFull:
                        false,
                },

                link: {
                    addTargetToExternalLinks:
                        true,

                    defaultProtocol:
                        'https://',
                },

                initialData,
            }
        )
    },
})


function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;')
}


function getAddressText(address) {
    const name =
        String(
            address?.name
            || ''
        ).trim()

    const email =
        String(
            address?.email
            || ''
        ).trim()

    if (
        name !== ''
        && email !== ''
    ) {
        return `${name} <${email}>`
    }

    return email || name
}


function getReplySubject(subject) {
    const value =
        String(
            subject
            || ''
        ).trim()

    if (value === '') {
        return 'Re:'
    }

    if (
        /^re\s*:/i.test(
            value
        )
    ) {
        return value
    }

    return `Re: ${value}`
}


function getMessageDate(message) {
    let date = null

    if (
        message?.timestamp !== null
        && message?.timestamp !== undefined
    ) {
        date =
            new Date(
                Number(
                    message.timestamp
                ) * 1000
            )
    } else if (message?.date) {
        date =
            new Date(
                message.date
            )
    }

    if (
        !date
        || Number.isNaN(
            date.getTime()
        )
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


function getOriginalMessageText(message) {
    const content =
        String(
            message?.body?.content
            || ''
        )

    if (
        message?.body?.type !== 'html'
    ) {
        return content.trim()
    }

    try {
        const documentObject =
            new DOMParser()
                .parseFromString(
                    content,
                    'text/html'
                )

        return String(
            documentObject.body
                ?.innerText
            || documentObject.body
                ?.textContent
            || ''
        ).trim()
    } catch (error) {
        console.error(
            'SharedMail: HTML-Mail konnte nicht in Text umgewandelt werden.',
            error
        )

        return ''
    }
}


function textToParagraphs(text) {
    const lines =
        String(text || '')
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n')
            .split('\n')

    return lines
        .map(
            line =>
                line.trim() === ''
                    ? '<p>&nbsp;</p>'
                    : `<p>${escapeHtml(line)}</p>`
        )
        .join('')
}


function buildReplyInitialData(message) {
    const sender =
        getAddressText(
            message?.from
        )
        || 'Unbekannter Absender'

    const date =
        getMessageDate(
            message
        )

    const originalText =
        getOriginalMessageText(
            message
        )

    const intro =
        date !== ''
            ? `Am ${date} schrieb ${sender}:`
            : `${sender} schrieb:`

    return `
        <p>&nbsp;</p>
        <p>${escapeHtml(intro)}</p>
        <blockquote>
            ${textToParagraphs(originalText)}
        </blockquote>
    `
}


async function destroyActiveEditor() {
    if (!activeEditor) {
        return
    }

    try {
        await activeEditor.destroy()
    } catch (error) {
        console.error(
            'SharedMail: CKEditor konnte nicht sauber beendet werden.',
            error
        )
    }

    activeEditor = null
}


async function openReplyComposer(
    viewer,
    message,
    options = {}
) {
    if (!viewer || !message) {
        return
    }

    await destroyActiveEditor()

    const container =
        viewer.parentElement

    if (!container) {
        return
    }

    const originalViewer =
        viewer

    let attachments = []
    let currentDraftUid = 0


    const composer =
        document.createElement(
            'div'
        )

    composer.className =
        'sharedmail-composer'


    const header =
        document.createElement(
            'div'
        )

    header.className =
        'sharedmail-composer-header'


    const heading =
        document.createElement(
            'h2'
        )

    heading.textContent =
        'Antwort verfassen'


    header.appendChild(
        heading
    )

    composer.appendChild(
        header
    )


    const fields =
        document.createElement(
            'div'
        )

    fields.className =
        'sharedmail-composer-fields'


    const toRow =
        document.createElement(
            'label'
        )

    toRow.className =
        'sharedmail-composer-field'


    const toLabel =
        document.createElement(
            'span'
        )

    toLabel.textContent =
        'An'


    const toInput =
        document.createElement(
            'input'
        )

    toInput.type =
        'text'

    toInput.className =
        'sharedmail-composer-input'

    toInput.value =
        getAddressText(
            message.from
        )


    toRow.appendChild(
        toLabel
    )

    toRow.appendChild(
        toInput
    )


    const subjectRow =
        document.createElement(
            'label'
        )

    subjectRow.className =
        'sharedmail-composer-field'


    const subjectLabel =
        document.createElement(
            'span'
        )

    subjectLabel.textContent =
        'Betreff'


    const subjectInput =
        document.createElement(
            'input'
        )

    subjectInput.type =
        'text'

    subjectInput.className =
        'sharedmail-composer-input'

    subjectInput.value =
        getReplySubject(
            message.subject
        )


    subjectRow.appendChild(
        subjectLabel
    )

    subjectRow.appendChild(
        subjectInput
    )


    fields.appendChild(
        toRow
    )

    fields.appendChild(
        subjectRow
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


    const attachmentArea =
        document.createElement(
            'div'
        )

    attachmentArea.className =
        'sharedmail-composer-attachments'


    const attachmentButton =
        document.createElement(
            'button'
        )

    attachmentButton.type =
        'button'

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


    const attachmentList =
        document.createElement(
            'div'
        )

    attachmentList.className =
        'sharedmail-composer-attachment-list'


    attachmentArea.appendChild(
        attachmentButton
    )

    attachmentArea.appendChild(
        attachmentInput
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


    container.replaceChild(
        composer,
        originalViewer
    )


    function renderAttachments() {
        attachmentList.replaceChildren()

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

                info.textContent =
                    `${file.name} · ${formatFileSize(file.size)}`


                const removeButton =
                    document.createElement(
                        'button'
                    )

                removeButton.type =
                    'button'

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
                                    currentIndex !== index
                            )

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


    try {
        activeEditor =
            await window.SharedMailEditor.create(
                editorElement,
                buildReplyInitialData(
                    message
                )
            )
    } catch (error) {
        status.textContent =
            'Der Antworteditor konnte nicht geladen werden.'

        return
    }


    cancelButton.addEventListener(
        'click',
        async () => {
            await destroyActiveEditor()

            if (composer.parentElement) {
                composer.parentElement
                    .replaceChild(
                        originalViewer,
                        composer
                    )
            }
        }
    )


    draftButton.addEventListener(
        'click',
        async () => {
            if (!activeEditor) {
                return
            }

            draftButton.disabled =
                true

            sendButton.disabled =
                true

            cancelButton.disabled =
                true

            status.textContent =
                'Entwurf wird gespeichert …'

            try {
                const result =
                    await saveReplyDraft(
                        message,
                        String(
                            toInput.value
                            || ''
                        ).trim(),
                        String(
                            subjectInput.value
                            || ''
                        ).trim(),
                        String(
                            activeEditor.getData()
                            || ''
                        ).trim(),
                        attachments,
                        currentDraftUid
                    )

                const uid =
                    Number(
                        result.draftUid
                        || 0
                    )

                if (uid > 0) {
                    currentDraftUid =
                        uid
                }

                status.textContent =
                    result.message
                    || 'Der Entwurf wurde gespeichert.'

                draftButton.textContent =
                    'Entwurf aktualisieren'
            } catch (error) {
                status.textContent =
                    error instanceof Error
                        ? error.message
                        : 'Der Entwurf konnte nicht gespeichert werden.'
            } finally {
                draftButton.disabled =
                    false

                sendButton.disabled =
                    false

                cancelButton.disabled =
                    false
            }
        }
    )


    sendButton.addEventListener(
        'click',
        async () => {
            if (!activeEditor) {
                return
            }

            const to =
                String(
                    toInput.value
                    || ''
                ).trim()

            const subject =
                String(
                    subjectInput.value
                    || ''
                ).trim()

            const html =
                String(
                    activeEditor.getData()
                    || ''
                ).trim()

            if (to === '') {
                status.textContent =
                    'Bitte einen Empfänger angeben.'

                return
            }

            sendButton.disabled =
                true

            draftButton.disabled =
                true

            cancelButton.disabled =
                true

            status.textContent =
                'Antwort wird versendet …'

            try {
                const result =
                    await sendReply(
                        message,
                        to,
                        subject,
                        html,
                        attachments
                    )

                if (result.warning) {
                    console.warn(
                        'SharedMail:',
                        result.warning
                    )
                }

                await destroyActiveEditor()

                currentDraftUid =
                    0

                if (composer.parentElement) {
                    composer.remove()
                }

                if (
                    window.SharedMailUI
                    && typeof window.SharedMailUI.reloadCurrentFolder
                        === 'function'
                ) {
                    await window.SharedMailUI
                        .reloadCurrentFolder()
                }
            } catch (error) {
                status.textContent =
                    error instanceof Error
                        ? error.message
                        : 'Die Antwort konnte nicht gesendet werden.'

                sendButton.disabled =
                    false

                draftButton.disabled =
                    false

                cancelButton.disabled =
                    false
            }
        }
    )
}


function attachReplyButton(
    viewer,
    message,
    options = {}
) {
    if (
        !viewer
        || !message
    ) {
        return
    }

    if (
        viewer.querySelector(
            '.sharedmail-reply-button'
        )
    ) {
        return
    }

    const footer =
        viewer.querySelector(
            '.sharedmail-viewer-footer'
        )

    if (!footer) {
        return
    }

    const replyButton =
        document.createElement(
            'button'
        )

    replyButton.type =
        'button'

    replyButton.className =
        'sharedmail-reply-button'

    replyButton.textContent =
        '↩ Antworten'


    replyButton.addEventListener(
        'click',
        () => {
            openReplyComposer(
                viewer,
                message,
                options
            )
        }
    )


    footer.insertBefore(
        replyButton,
        footer.firstChild
    )
}


window.SharedMailReplyComposer =
    Object.freeze({
        attach:
            attachReplyButton,

        open:
            openReplyComposer,

        destroy:
            destroyActiveEditor,
    })