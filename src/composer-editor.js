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


async function sendReply(
    message,
    to,
    subject,
    html
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
                    JSON.stringify({
                        folder,
                        to,
                        subject,
                        html,
                    }),
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
            || 'Die Antwort konnte nicht gesendet werden.'
        )
    }

    return data
}

/*
 * Allgemeiner CKEditor-Wrapper.
 */
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

    /*
     * Bereits vorhandenes Re: nicht
     * immer wieder vervielfachen.
     */
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


/*
 * HTML-Mails werden für das Zitat zunächst
 * in reinen Text umgewandelt.
 *
 * Wir übernehmen bewusst NICHT ungeprüft
 * das HTML einer fremden E-Mail in unseren
 * eigenen Editor.
 */
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

    if (lines.length === 0) {
        return '<p></p>'
    }

    return lines
        .map(
            (line) =>
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


    /*
     * Composer-Grundelement.
     */
    const composer =
        document.createElement('div')

    composer.className =
        'sharedmail-composer'


    /*
     * Kopf.
     */
    const header =
        document.createElement('div')

    header.className =
        'sharedmail-composer-header'


    const heading =
        document.createElement('h2')

    heading.textContent =
        'Antwort verfassen'


    header.appendChild(
        heading
    )

    composer.appendChild(
        header
    )


    /*
     * Empfänger.
     */
    const fields =
        document.createElement('div')

    fields.className =
        'sharedmail-composer-fields'


    const toRow =
        document.createElement('label')

    toRow.className =
        'sharedmail-composer-field'


    const toLabel =
        document.createElement('span')

    toLabel.textContent =
        'An'


    const toInput =
        document.createElement('input')

    toInput.type =
        'text'

    toInput.className =
        'sharedmail-composer-input'

    toInput.value =
        getAddressText(
            message.from
        )

    toInput.autocomplete =
        'off'


    toRow.appendChild(
        toLabel
    )

    toRow.appendChild(
        toInput
    )


    /*
     * Betreff.
     */
    const subjectRow =
        document.createElement('label')

    subjectRow.className =
        'sharedmail-composer-field'


    const subjectLabel =
        document.createElement('span')

    subjectLabel.textContent =
        'Betreff'


    const subjectInput =
        document.createElement('input')

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


    /*
     * Editor.
     */
    const editorWrapper =
        document.createElement('div')

    editorWrapper.className =
        'sharedmail-composer-editor-wrapper'


    const editorElement =
        document.createElement('div')

    editorElement.className =
        'sharedmail-composer-editor'


    editorWrapper.appendChild(
        editorElement
    )

    composer.appendChild(
        editorWrapper
    )


    /*
     * Statusbereich.
     */
    const status =
        document.createElement('div')

    status.className =
        'sharedmail-composer-status'

    status.textContent =
        ''

    composer.appendChild(
        status
    )


    /*
     * Buttons.
     */
    const footer =
        document.createElement('div')

    footer.className =
        'sharedmail-composer-footer'


    const cancelButton =
        document.createElement('button')

    cancelButton.type =
        'button'

    cancelButton.className =
        'sharedmail-composer-cancel'

    cancelButton.textContent =
        'Abbrechen'


    const sendButton =
        document.createElement('button')

    sendButton.type =
        'button'

    sendButton.className =
        'sharedmail-composer-send primary'

    sendButton.textContent =
        'Senden'
   
    sendButton.disabled =
        false

    sendButton.title =
        'Antwort senden'


    footer.appendChild(
        cancelButton
    )

    footer.appendChild(
        sendButton
    )

    composer.appendChild(
        footer
    )


    /*
     * Viewer durch Composer ersetzen.
     */
    container.replaceChild(
        composer,
        originalViewer
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
        console.error(
            'SharedMail: Antworteditor konnte nicht gestartet werden.',
            error
        )

        status.textContent =
            'Der Antworteditor konnte nicht geladen werden.'

        return
    }


    cancelButton.addEventListener(
        'click',
        async () => {
            await destroyActiveEditor()

            if (
                composer.parentElement
            ) {
                composer.parentElement
                    .replaceChild(
                        originalViewer,
                        composer
                    )
            }

            if (
                typeof options.onCancel
                === 'function'
            ) {
                options.onCancel()
            }
        }
    )

    sendButton.addEventListener(
        'click',
        async () => {
            if (!activeEditor) {
                status.textContent =
                    'Der Editor ist noch nicht bereit.'

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

                toInput.focus()

                return
            }

            if (html === '') {
                status.textContent =
                    'Bitte einen Nachrichtentext eingeben.'

                activeEditor.editing
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
                'Antwort wird versendet …'

            try {
                const result =
                    await sendReply(
                        message,
                        to,
                        subject,
                        html
                    )

                sendButton.textContent =
                    'Gesendet'

                status.textContent =
                    `Antwort an ${result.recipient} wurde erfolgreich gesendet.`

                /*
                * Senden bleibt nach Erfolg deaktiviert,
                * damit dieselbe Antwort nicht versehentlich
                * doppelt verschickt wird.
                */
                sendButton.disabled =
                    true

                cancelButton.disabled =
                    false

                cancelButton.textContent =
                    'Zurück zur Nachricht'
            } catch (error) {
                console.error(
                    'SharedMail: Antwort konnte nicht gesendet werden.',
                    error
                )

                status.textContent =
                    error instanceof Error
                        ? error.message
                        : 'Die Antwort konnte nicht gesendet werden.'

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
        document.createElement('button')

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


    /*
     * Antworten ganz vorne in der Aktionsleiste.
     */
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