(() => {
    const workspace = document.querySelector('.accounts-workspace');
    if (!workspace) return;
    const rows = [...workspace.querySelectorAll('[data-account]')];
    const inspector = document.getElementById('accountInspector');
    const content = document.getElementById('accountInspectorContent');
    const role = document.getElementById('accountRole');
    const access = document.getElementById('accountAccess');
    const search = document.getElementById('accountSearch');
    const tabs = [...workspace.querySelectorAll('[data-role-filter]')];
    let selected = null;
    let page = 0;
    let matches = rows;
    const pageSize = 10;

    workspace.querySelectorAll('.account-fields').forEach(form => {
        const accountRole = form.querySelector('select[name="role"]');
        if (!accountRole) return;
        const contactFields = [...form.querySelectorAll('[data-account-contact]')];
        function contactValue(label) {
            const input = label.querySelector('[data-contact-value]');
            if (label.querySelector('[data-contact-removal]')?.value === '1') return '';
            return input.value.trim() || input.defaultValue.trim();
        }
        function updateContactFields() {
            const contactType = accountRole.value === '0' ? 'student' : 'staff';
            contactFields.forEach(label => {
                label.hidden = label.dataset.accountContact !== contactType;
                label.querySelectorAll('input, button').forEach(control => { control.disabled = label.hidden; });
                const input = label.querySelector('[data-contact-value]');
                input.setCustomValidity('');
                input.required = !label.hidden && input.name === 'email' && input.defaultValue === '';
                label.querySelector('[data-contact-status]').hidden = contactValue(label) === '';
            });
            const phone = form.querySelector('[name=parent_phone]');
            const email = form.querySelector(contactType === 'student' ? '[name=parent_email]' : '[name=email]');
            if (contactType === 'student' && !contactValue(phone.closest('[data-account-contact]')) && !contactValue(email.closest('[data-account-contact]'))) {
                phone.setCustomValidity('Enter at least one parent or guardian phone number or email.');
            }
            if (contactType === 'staff' && !contactValue(email.closest('[data-account-contact]'))) {
                email.setCustomValidity('Keep a staff email for OTP verification.');
            }
        }
        contactFields.forEach(label => {
            const input = label.querySelector('[data-contact-value]');
            const removal = label.querySelector('[data-contact-removal]');
            const button = label.querySelector('[data-remove-contact]');
            const removeLabel = button?.textContent;
            input.addEventListener('input', () => {
                if (removal && input.value.trim()) {
                    removal.value = '0';
                    button.textContent = removeLabel;
                }
                updateContactFields();
            });
            button?.addEventListener('click', () => {
                const removing = removal.value !== '1';
                removal.value = removing ? '1' : '0';
                input.value = removing ? '' : input.defaultValue;
                button.textContent = removing ? 'Keep saved contact' : removeLabel;
                updateContactFields();
            });
        });
        form.addEventListener('submit', event => {
            updateContactFields();
            if (!form.reportValidity()) {
                event.preventDefault();
                return;
            }
            const changes = contactFields.filter(label => !label.hidden).flatMap(label => {
                const input = label.querySelector('[data-contact-value]');
                const value = contactValue(label);
                if (value === input.defaultValue.trim()) return [];
                const name = label.querySelector('[data-contact-label]').textContent;
                return [value ? `${name}: ${value}` : `${name} will be removed`];
            });
            const name = form.querySelector('[name=name]')?.value || form.dataset.accountName || 'this account';
            if (changes.length && !window.confirm(`Confirm contact details for ${name}\n\n${changes.join('\n')}\n\nSave these changes?`)) {
                event.preventDefault();
            }
        });
        accountRole.addEventListener('change', updateContactFields);
        updateContactFields();
    });

    function closeInspector(returnFocus = false) {
        if (!selected) return;
        const button = selected.querySelector('.account-inspect-button');
        // Move the original forms back, retaining their CSRF tokens and edits.
        [...content.children].forEach(detail => {
            detail.open = false;
            selected.querySelector('.directory-actions').append(detail);
        });
        selected.classList.remove('is-selected');
        button.setAttribute('aria-expanded', 'false');
        selected = null;
        inspector.hidden = true;
        workspace.classList.remove('has-account-selection');
        if (returnFocus) button.focus();
    }

    function openInspector(row, focus = true) {
        closeInspector();
        selected = row;
        row.classList.add('is-selected');
        row.querySelector('.account-inspect-button').setAttribute('aria-expanded', 'true');
        const identity = document.getElementById('accountInspectorIdentity');
        identity.replaceChildren(row.querySelector('.directory-identity').cloneNode(true), row.querySelector('.directory-role').cloneNode(true), row.querySelector('.directory-status').cloneNode(true));
        identity.querySelectorAll('[role="cell"]').forEach(cell => cell.removeAttribute('role'));
        const profile = row.querySelector('.basic-student-profile');
        const editor = row.querySelector('.account-access-editor');
        [profile, editor].filter(Boolean).forEach(detail => {
            detail.open = true;
            content.append(detail);
        });
        inspector.hidden = false;
        workspace.classList.add('has-account-selection');
        if (focus) {
            document.getElementById('closeAccountInspector').focus({preventScroll: true});
            inspector.scrollIntoView({block: 'nearest', behavior: 'auto'});
        }
    }

    function render() {
        rows.forEach(row => { row.hidden = true; });
        const visible = matches.slice(page * pageSize, (page + 1) * pageSize);
        visible.forEach(row => { row.hidden = false; });
        if (selected && !visible.includes(selected)) closeInspector();
        document.getElementById('accountResultCount').textContent = matches.length + (matches.length === 1 ? ' account' : ' accounts');
        document.getElementById('noAccountsFound').classList.toggle('hidden', matches.length > 0);
        document.getElementById('accountPageLabel').textContent = matches.length ? `Showing ${page * pageSize + 1}–${Math.min((page + 1) * pageSize, matches.length)} of ${matches.length}` : 'No matching accounts';
        document.getElementById('accountPrevious').disabled = page === 0;
        document.getElementById('accountNext').disabled = (page + 1) * pageSize >= matches.length;
        tabs.forEach(tab => tab.setAttribute('aria-pressed', String(tab.dataset.roleFilter === role.value)));
    }

    function filterAccounts() {
        const query = search.value.toLowerCase().trim();
        matches = rows.filter(row => (!query || row.dataset.account.includes(query)) && (role.value === '' || row.dataset.role === role.value) && (access.value === '' || row.dataset.disabled === access.value));
        page = 0;
        render();
    }
    workspace.classList.add('directory-enhanced');
    workspace.querySelector('.account-role-tabs').hidden = false;
    workspace.querySelector('.directory-pagination').hidden = false;
    rows.forEach(row => {
        const button = row.querySelector('.account-inspect-button');
        button.hidden = false;
        button.addEventListener('click', () => openInspector(row));
    });
    tabs.forEach(tab => tab.addEventListener('click', () => { role.value = tab.dataset.roleFilter; filterAccounts(); }));
    search.addEventListener('input', filterAccounts);
    [role, access].forEach(select => select.addEventListener('change', filterAccounts));
    document.getElementById('accountPrevious').addEventListener('click', () => { page--; render(); });
    document.getElementById('accountNext').addEventListener('click', () => { page++; render(); });
    document.getElementById('closeAccountInspector').addEventListener('click', () => closeInspector(true));
    inspector.addEventListener('keydown', event => { if (event.key === 'Escape') closeInspector(true); });
    render();
})();
