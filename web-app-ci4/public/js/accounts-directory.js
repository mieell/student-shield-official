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
