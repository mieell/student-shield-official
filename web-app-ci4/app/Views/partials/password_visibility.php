<style>
.password-control{position:relative;display:block;width:100%;min-width:0}
.password-control input{padding-right:70px!important}
.password-control .password-visibility{position:absolute;right:5px;top:50%;transform:translateY(-50%);width:auto;min-width:54px;min-height:36px;margin:0;padding:5px 8px;border:0;border-radius:4px;background:transparent;color:#155638;font:inherit;font-size:13px;font-weight:700;cursor:pointer;box-shadow:none}
.password-control .password-visibility:hover{background:#edf4ee}
.password-control .password-visibility:active{transform:translateY(-50%)}
.password-control .password-visibility:focus-visible{outline:2px solid #a38224;outline-offset:1px}
</style>
<script>
document.querySelectorAll('input[type="password"][data-password-toggle]').forEach((input, index) => {
    if (!input.id) input.id = 'visible-password-' + index;
    const container = document.createElement('span');
    container.className = 'password-control';
    input.before(container);
    container.append(input);
    const button = document.createElement('button');
    const label = input.name === 'password_confirm' ? 'confirm password' : 'password';
    button.type = 'button';
    button.className = 'password-visibility';
    button.textContent = 'Show';
    button.setAttribute('aria-controls', input.id);
    button.setAttribute('aria-pressed', 'false');
    button.setAttribute('aria-label', 'Show ' + label);
    button.addEventListener('click', event => {
        event.preventDefault();
        const showing = input.type === 'password';
        input.type = showing ? 'text' : 'password';
        button.textContent = showing ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(showing));
        button.setAttribute('aria-label', (showing ? 'Hide ' : 'Show ') + label);
    });
    input.form?.addEventListener('reset', () => {
        input.type = 'password';
        button.textContent = 'Show';
        button.setAttribute('aria-pressed', 'false');
        button.setAttribute('aria-label', 'Show ' + label);
    });
    container.append(button);
});
</script>
