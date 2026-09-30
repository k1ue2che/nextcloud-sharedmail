document.addEventListener('DOMContentLoaded', () => {
    const addButton =
        document.getElementById(
            'sharedmail-add-mailbox'
        )

    const cancelButton =
        document.getElementById(
            'sharedmail-cancel-mailbox'
        )

    const saveButton =
        document.getElementById(
            'sharedmail-save-mailbox'
        )

    const testButton =
        document.getElementById(
            'sharedmail-test-connection'
        )

    const wrapper =
        document.getElementById(
            'sharedmail-mailbox-form-wrapper'
        )

    const form =
        document.getElementById(
            'sharedmail-mailbox-form'
        )

    const formTitle =
        document.getElementById(
            'sharedmail-form-title'
        )

    const resultBox =
        document.getElementById(
            'sharedmail-connection-result'
        )

    const groupSelect =
        document.getElementById(
            'sharedmail-group-ids'
        )

    const permissionsWrapper =
        document.getElementById(
            'sharedmail-group-permissions'
        )

    const permissionsList =
        document.getElementById(
            'sharedmail-group-permissions-list'
        )

    const imapPasswordHint =
        document.getElementById(
            'sharedmail-imap-password-hint'
        )

    const smtpPasswordHint =
        document.getElementById(
            'sharedmail-smtp-password-hint'
        )

    if (
        !addButton
        || !wrapper
        || !form
    ) {
        console.error(
            'SharedMail: Grundelemente des Adminformulars wurden nicht gefunden.'
        )

        return
    }

    const permissionBits = {
        read:
            Number(
                permissionsWrapper?.dataset.read
                || 1
            ),

        reply:
            Number(
                permissionsWrapper?.dataset.reply
                || 2
            ),

        compose:
            Number(
                permissionsWrapper?.dataset.compose
                || 4
            ),

        move:
            Number(
                permissionsWrapper?.dataset.move
                || 8
            ),

        delete:
            Number(
                permissionsWrapper?.dataset.delete
                || 16
            ),

        assign:
            Number(
                permissionsWrapper?.dataset.assign
                || 32
            ),

        changeStatus:
            Number(
                permissionsWrapper?.dataset.changeStatus
                || 64
            ),

        manage:
            Number(
                permissionsWrapper?.dataset.manage
                || 128
            ),
    }

    const defaultPermissions =
        Number(
            permissionsWrapper?.dataset.default
            || 15
        )

    /*
     * Aktueller lokaler Stand der Rechte.
     *
     * Die Map bleibt auch erhalten, wenn eine Gruppe
     * kurz abgewählt und danach wieder ausgewählt wird.
     */
    const groupPermissionsState =
        new Map()

    function getField(name) {
        return form.elements.namedItem(
            name
        )
    }

    function setField(
        name,
        value
    ) {
        const field =
            getField(name)

        if (!field) {
            console.warn(
                `SharedMail: Formularfeld "${name}" wurde nicht gefunden.`
            )

            return
        }

        field.value =
            value ?? ''
    }

    function getEditingMailboxId() {
        const mailboxId =
            form.dataset.mailboxId

        if (!mailboxId) {
            return null
        }

        return mailboxId
    }

    function setEditingMailboxId(
        mailboxId
    ) {
        if (!mailboxId) {
            delete form.dataset.mailboxId
            return
        }

        form.dataset.mailboxId =
            String(mailboxId)
    }

    function showFormMessage(
        message,
        isError = false
    ) {
        if (!resultBox) {
            window.alert(message)
            return
        }

        resultBox.style.display =
            'block'

        resultBox.textContent =
            message

        if (isError) {
            resultBox.dataset.error =
                'true'
        } else {
            delete resultBox.dataset.error
        }
    }

    function clearConnectionResult() {
        if (!resultBox) {
            return
        }

        resultBox.style.display =
            'none'

        resultBox.textContent =
            ''

        delete resultBox.dataset.error
    }

    function setPasswordEditMode(
        editMode
    ) {
        const imapPassword =
            getField(
                'imapPassword'
            )

        const smtpPassword =
            getField(
                'smtpPassword'
            )

        if (imapPassword) {
            imapPassword.value = ''
            imapPassword.required =
                !editMode
        }

        if (smtpPassword) {
            smtpPassword.value = ''
            smtpPassword.required =
                !editMode
        }

        if (imapPasswordHint) {
            imapPasswordHint.style.display =
                editMode
                    ? 'block'
                    : 'none'
        }

        if (smtpPasswordHint) {
            smtpPasswordHint.style.display =
                editMode
                    ? 'block'
                    : 'none'
        }
    }

    function getSelectedGroupIds() {
        if (!groupSelect) {
            return []
        }

        return Array
            .from(
                groupSelect.selectedOptions
            )
            .map(
                (option) =>
                    option.value
            )
            .filter(
                (value) =>
                    value !== ''
            )
    }

    function getGroupLabel(
        groupId
    ) {
        if (!groupSelect) {
            return groupId
        }

        const option =
            Array
                .from(
                    groupSelect.options
                )
                .find(
                    (candidate) =>
                        candidate.value
                        === groupId
                )

        return option?.textContent?.trim()
            || groupId
    }

    function resetGroups() {
        if (!groupSelect) {
            return
        }

        Array
            .from(
                groupSelect.options
            )
            .forEach(
                (option) => {
                    option.selected =
                        false
                }
            )
    }

    function selectGroups(
        groupIds
    ) {
        if (!groupSelect) {
            return
        }

        const selectedIds =
            new Set(
                groupIds.map(
                    (id) =>
                        String(id)
                )
            )

        Array
            .from(
                groupSelect.options
            )
            .forEach(
                (option) => {
                    option.selected =
                        selectedIds.has(
                            option.value
                        )
                }
            )
    }

    function hasPermission(
        permissions,
        bit
    ) {
        return (
            (
                permissions
                & bit
            )
            === bit
        )
    }

    function createPermissionCheckbox(
        groupId,
        label,
        bit,
        permissions,
        forced = false
    ) {
        const wrapperElement =
            document.createElement(
                'label'
            )

        wrapperElement.className =
            'sharedmail-permission-option'

        const checkbox =
            document.createElement(
                'input'
            )

        checkbox.type =
            'checkbox'

        checkbox.checked =
            forced
            || hasPermission(
                permissions,
                bit
            )

        checkbox.disabled =
            forced

        checkbox.dataset.groupId =
            groupId

        checkbox.dataset.permissionBit =
            String(bit)

        checkbox.addEventListener(
            'change',
            () => {
                let current =
                    groupPermissionsState.get(
                        groupId
                    )
                    ?? defaultPermissions

                if (checkbox.checked) {
                    current |= bit
                } else {
                    current &= ~bit
                }

                /*
                 * READ bleibt immer gesetzt.
                 */
                current |=
                    permissionBits.read

                groupPermissionsState.set(
                    groupId,
                    current
                )
            }
        )

        const text =
            document.createElement(
                'span'
            )

        text.textContent =
            label

        wrapperElement.appendChild(
            checkbox
        )

        wrapperElement.appendChild(
            text
        )

        return wrapperElement
    }

    function renderGroupPermissions() {
        if (
            !permissionsWrapper
            || !permissionsList
        ) {
            return
        }

        const selectedGroupIds =
            getSelectedGroupIds()

        permissionsList.textContent =
            ''

        if (
            selectedGroupIds.length
            === 0
        ) {
            permissionsWrapper.style.display =
                'none'

            return
        }

        permissionsWrapper.style.display =
            'block'

        selectedGroupIds.forEach(
            (groupId) => {
                if (
                    !groupPermissionsState.has(
                        groupId
                    )
                ) {
                    groupPermissionsState.set(
                        groupId,
                        defaultPermissions
                    )
                }

                let permissions =
                    groupPermissionsState.get(
                        groupId
                    )
                    ?? defaultPermissions

                /*
                 * READ serverseitig und auch lokal
                 * immer erzwingen.
                 */
                permissions |=
                    permissionBits.read

                groupPermissionsState.set(
                    groupId,
                    permissions
                )

                const card =
                    document.createElement(
                        'div'
                    )

                card.className =
                    'sharedmail-permission-group'

                const title =
                    document.createElement(
                        'div'
                    )

                title.className =
                    'sharedmail-permission-group-title'

                title.textContent =
                    getGroupLabel(
                        groupId
                    )

                const options =
                    document.createElement(
                        'div'
                    )

                options.className =
                    'sharedmail-permission-options'

                options.appendChild(
                    createPermissionCheckbox(
                        groupId,
                        'Lesen',
                        permissionBits.read,
                        permissions,
                        true
                    )
                )

                options.appendChild(
                    createPermissionCheckbox(
                        groupId,
                        'Antworten',
                        permissionBits.reply,
                        permissions
                    )
                )

                options.appendChild(
                    createPermissionCheckbox(
                        groupId,
                        'Neue Nachrichten',
                        permissionBits.compose,
                        permissions
                    )
                )

                options.appendChild(
                    createPermissionCheckbox(
                        groupId,
                        'Verschieben',
                        permissionBits.move,
                        permissions
                    )
                )

                options.appendChild(
                    createPermissionCheckbox(
                        groupId,
                        'Löschen',
                        permissionBits.delete,
                        permissions
                    )
                )

                options.appendChild(
                    createPermissionCheckbox(
                        groupId,
                        'Zuweisen',
                        permissionBits.assign,
                        permissions
                    )
                )

                options.appendChild(
                    createPermissionCheckbox(
                        groupId,
                        'Status ändern',
                        permissionBits.changeStatus,
                        permissions
                    )
                )

                options.appendChild(
                    createPermissionCheckbox(
                        groupId,
                        'Verwalten',
                        permissionBits.manage,
                        permissions
                    )
                )

                card.appendChild(
                    title
                )

                card.appendChild(
                    options
                )

                permissionsList.appendChild(
                    card
                )
            }
        )
    }

    function loadGroupPermissions(
        rawPermissions
    ) {
        groupPermissionsState.clear()

        if (
            !rawPermissions
            || typeof rawPermissions
                !== 'object'
            || Array.isArray(
                rawPermissions
            )
        ) {
            renderGroupPermissions()
            return
        }

        Object.entries(
            rawPermissions
        ).forEach(
            (
                [
                    groupId,
                    permissions,
                ]
            ) => {
                const numericPermissions =
                    Number(
                        permissions
                    )

                if (
                    !Number.isInteger(
                        numericPermissions
                    )
                    || numericPermissions < 0
                    || numericPermissions > 255
                ) {
                    return
                }

                groupPermissionsState.set(
                    String(groupId),
                    numericPermissions
                    | permissionBits.read
                )
            }
        )

        renderGroupPermissions()
    }

    function collectGroupPermissions() {
        const result = {}

        getSelectedGroupIds()
            .forEach(
                (groupId) => {
                    let permissions =
                        groupPermissionsState.get(
                            groupId
                        )
                        ?? defaultPermissions

                    permissions |=
                        permissionBits.read

                    result[groupId] =
                        permissions
                }
            )

        return result
    }

    function resetFormDefaults() {
        form.reset()

        setEditingMailboxId(
            null
        )

        setField(
            'imapPort',
            '993'
        )

        setField(
            'imapSecurity',
            'ssl'
        )

        setField(
            'smtpPort',
            '465'
        )

        setField(
            'smtpSecurity',
            'ssl'
        )

        resetGroups()

        groupPermissionsState.clear()

        renderGroupPermissions()

        setPasswordEditMode(
            false
        )

        clearConnectionResult()

        if (formTitle) {
            formTitle.textContent =
                'Postfach hinzufügen'
        }

        if (saveButton) {
            saveButton.textContent =
                'Postfach speichern'
        }
    }

    function openForm() {
        wrapper.style.display =
            'block'

        addButton.style.display =
            'none'

        wrapper.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        })
    }

    function closeForm() {
        wrapper.style.display =
            'none'

        addButton.style.display =
            ''

        resetFormDefaults()
    }

    /*
     * Gruppenauswahl verändert:
     * Rechte-Matrix aktualisieren.
     */
    groupSelect?.addEventListener(
        'change',
        () => {
            renderGroupPermissions()
        }
    )

    /*
     * Neues Postfach.
     */
    addButton.addEventListener(
        'click',
        () => {
            resetFormDefaults()
            openForm()
        }
    )

    /*
     * Vorhandenes Postfach bearbeiten.
     */
    document
        .querySelectorAll(
            '.sharedmail-edit-mailbox'
        )
        .forEach(
            (button) => {
                button.addEventListener(
                    'click',
                    () => {
                        resetFormDefaults()

                        const mailboxId =
                            button.dataset.mailboxId

                        if (!mailboxId) {
                            console.error(
                                'SharedMail: Bearbeiten-Button enthält keine Mailbox-ID.'
                            )

                            return
                        }

                        setEditingMailboxId(
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

                        let groupIds = []

                        try {
                            groupIds =
                                JSON.parse(
                                    button.dataset.groupIds
                                    || '[]'
                                )

                            if (
                                !Array.isArray(
                                    groupIds
                                )
                            ) {
                                groupIds = []
                            }
                        } catch (error) {
                            console.error(
                                'SharedMail: Zugriffsgruppen konnten nicht gelesen werden.',
                                error
                            )

                            groupIds = []
                        }

                        selectGroups(
                            groupIds
                        )

                        let groupPermissions =
                            {}

                        try {
                            groupPermissions =
                                JSON.parse(
                                    button.dataset.groupPermissions
                                    || '{}'
                                )

                            if (
                                !groupPermissions
                                || typeof groupPermissions
                                    !== 'object'
                                || Array.isArray(
                                    groupPermissions
                                )
                            ) {
                                groupPermissions =
                                    {}
                            }
                        } catch (error) {
                            console.error(
                                'SharedMail: Gruppenrechte konnten nicht gelesen werden.',
                                error
                            )

                            groupPermissions =
                                {}
                        }

                        /*
                         * Falls ein alter Datensatz noch
                         * keine Rechteinformation liefert,
                         * werden die ausgewählten Gruppen
                         * mit DEFAULT vorbelegt.
                         */
                        groupIds.forEach(
                            (groupId) => {
                                if (
                                    !Object.prototype.hasOwnProperty.call(
                                        groupPermissions,
                                        groupId
                                    )
                                ) {
                                    groupPermissions[
                                        groupId
                                    ] =
                                        defaultPermissions
                                }
                            }
                        )

                        loadGroupPermissions(
                            groupPermissions
                        )

                        setPasswordEditMode(
                            true
                        )

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
                    }
                )
            }
        )

    cancelButton?.addEventListener(
        'click',
        () => {
            closeForm()
        }
    )

    /*
     * IMAP / SMTP Verbindung testen.
     */
    testButton?.addEventListener(
        'click',
        async () => {
            if (!resultBox) {
                return
            }

            const editingMailboxId =
                getEditingMailboxId()

            const imapPassword =
                getField(
                    'imapPassword'
                )?.value
                ?? ''

            const smtpPassword =
                getField(
                    'smtpPassword'
                )?.value
                ?? ''

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

            testButton.disabled =
                true

            showFormMessage(
                'Verbindung wird getestet …'
            )

            try {
                const formData =
                    new FormData(
                        form
                    )

                const data =
                    Object.fromEntries(
                        formData.entries()
                    )

                data.imapPort =
                    Number(
                        data.imapPort
                    )

                data.smtpPort =
                    Number(
                        data.smtpPort
                    )

                const response =
                    await fetch(
                        OC.generateUrl(
                            '/apps/sharedmail/api/mailboxes/test'
                        ),
                        {
                            method:
                                'POST',

                            headers: {
                                'Content-Type':
                                    'application/json',

                                'requesttoken':
                                    OC.requestToken,
                            },

                            body:
                                JSON.stringify(
                                    data
                                ),
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

                resultBox.textContent =
                    ''

                const imapResult =
                    document.createElement(
                        'div'
                    )

                const smtpResult =
                    document.createElement(
                        'div'
                    )

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
                testButton.disabled =
                    false
            }
        }
    )

    /*
     * Neues Postfach speichern oder
     * vorhandenes aktualisieren.
     */
    form.addEventListener(
        'submit',
        async (event) => {
            event.preventDefault()

            const editingMailboxId =
                getEditingMailboxId()

            const submitButton =
                form.querySelector(
                    'button[type="submit"]'
                )

            if (!submitButton) {
                console.error(
                    'SharedMail: Submit-Button wurde nicht gefunden.'
                )

                return
            }

            const selectedGroupIds =
                getSelectedGroupIds()

            if (
                selectedGroupIds.length
                === 0
            ) {
                showFormMessage(
                    'Mindestens eine Zugriffsgruppe muss ausgewählt werden.',
                    true
                )

                return
            }

            submitButton.disabled =
                true

            try {
                const formData =
                    new FormData(
                        form
                    )

                const data =
                    Object.fromEntries(
                        formData.entries()
                    )

                data.groupIds =
                    selectedGroupIds

                data.groupPermissions =
                    collectGroupPermissions()

                data.imapPort =
                    Number(
                        data.imapPort
                    )

                data.smtpPort =
                    Number(
                        data.smtpPort
                    )

                const isEditing =
                    editingMailboxId !== null

                const url =
                    isEditing
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

                const abortController =
                    new AbortController()

                const timeoutId =
                    window.setTimeout(
                        () => {
                            abortController.abort()
                        },
                        20000
                    )

                let response

                try {
                    response =
                        await fetch(
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
                                    JSON.stringify(
                                        data
                                    ),

                                signal:
                                    abortController.signal,
                            }
                        )
                } finally {
                    window.clearTimeout(
                        timeoutId
                    )
                }

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
                        console.error(
                            'SharedMail: Serverantwort ist kein gültiges JSON.',
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
                        || (
                            isEditing
                                ? 'Postfach konnte nicht aktualisiert werden.'
                                : 'Postfach konnte nicht gespeichert werden.'
                        )
                    )
                }

                window.location.reload()
            } catch (error) {
                if (
                    error?.name
                    === 'AbortError'
                ) {
                    showFormMessage(
                        'Die Serveranfrage hat zu lange gedauert.',
                        true
                    )
                } else {
                    console.error(
                        'SharedMail:',
                        error
                    )

                    showFormMessage(
                        error?.message
                        || 'Postfach konnte nicht gespeichert werden.',
                        true
                    )
                }
            } finally {
                submitButton.disabled =
                    false
            }
        }
    )

    /*
     * Postfach löschen.
     */
    document
        .querySelectorAll(
            '.sharedmail-delete-mailbox'
        )
        .forEach(
            (button) => {
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

                        button.disabled =
                            true

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
                            button.disabled =
                                false
                        }
                    }
                )
            }
        )
})