const forms = new Map();
const pending = new Set();
const ar = () => document.documentElement.lang.startsWith('ar');
const wireName = input => [...input.attributes].find(attribute => attribute.name.startsWith('wire:model'))?.value || input.name;
const owns = (root, element) => !root.matches?.('[wire\\:id]') || element.closest('[wire\\:id]') === root;
const controls = root => [...root.querySelectorAll('input, select, textarea, ui-checkbox, ui-switch, ui-radio')].filter(input => !input.closest('[data-erp-select-ui]'));
const businessForm = form => form?.matches('.erp-shell form[wire\\:submit]');
const resetsEditor = action => /^(cancel(?:[A-Z]\w*)?|edit(?:[A-Z]\w*)?|new(?:[A-Z]\w*)?|create|resetForm)$/.test(action);
const dataControls = form => controls(form).filter(input => !input.matches(':disabled, [disabled]') && !input.readOnly && !/search|filter/i.test(wireName(input) || ''));
const signature = form => JSON.stringify(dataControls(form).map(input => [wireName(input), input.type === 'file' ? [...input.files].map(file => [file.name, file.size, file.lastModified]) : input.matches('ui-checkbox, ui-switch, ui-radio') ? [input.getAttribute('value'), input.hasAttribute('data-checked')] : input.type === 'checkbox' || input.type === 'radio' ? [input.value, input.checked] : input.multiple ? [...input.selectedOptions].map(option => option.value) : input.value]));

function state(form) {
    if (!forms.has(form)) forms.set(form, { baseline: signature(form), dirty: false, revision: 0, pending: false });
    return forms.get(form);
}

function notice(form) {
    const current = state(form);
    let element = form.querySelector('[data-erp-form-state]');
    if (!element) {
        element = document.createElement('p');
        element.dataset.erpFormState = '';
        element.setAttribute('role', 'status');
        element.setAttribute('wire:ignore', '');
        // A prepended client-only node makes Livewire replace the following form controls.
        form.append(element);
    }
    form.toggleAttribute('data-erp-dirty', current.dirty);
    element.hidden = !current.dirty && !current.pending;
    element.textContent = current.pending ? (ar() ? 'جارٍ تنفيذ العملية…' : 'Processing…') : (ar() ? 'توجد تعديلات غير محفوظة.' : 'You have unsaved changes.');
}

function clearDirty(scope) {
    for (const [form, current] of forms) {
        if (!scope.contains(form)) continue;
        current.dirty = false;
        current.baseline = signature(form);
        current.revision++;
        notice(form);
    }
}

function mayDiscard(scope) {
    const affected = [...forms].filter(([form]) => form.isConnected && scope.contains(form));
    if (affected.some(([, current]) => current.pending)) return false;
    return !affected.some(([, current]) => current.dirty) || confirm(ar() ? 'توجد تعديلات غير محفوظة. هل تريد تجاهلها؟' : 'Discard your unsaved changes?');
}

function initialize(root = document) {
    for (const form of forms.keys()) if (!form.isConnected) forms.delete(form);
    root.querySelectorAll('.erp-shell form[wire\\:submit]').forEach(form => { state(form); notice(form); });
    root.querySelectorAll('erp-select').forEach(select => select.refresh?.());
}

function findControl(root, field, preferred) {
    const available = controls(root).filter(input => owns(root, input));
    const scoped = preferred ? available.filter(input => (input.form || input.closest('form')) === preferred) : available;
    const exact = scoped.find(input => wireName(input) === field);
    if (exact) return exact;
    const other = available.filter(input => wireName(input) === field);
    if (other.length === 1) return other[0];
    for (const suffix of [field, field.split('.').at(-1)]) {
        const matches = scoped.filter(input => wireName(input)?.endsWith(`.${suffix}`));
        if (matches.length === 1) return matches[0];
    }
    return null;
}

function reveal(element) {
    for (let parent = element.parentElement; parent; parent = parent.parentElement) if (parent.tagName === 'DETAILS') parent.open = true;
    element.focus({ preventScroll: true });
    const target = element.closest('erp-select[data-enhanced]')?.querySelector('[data-erp-select-trigger]') || element;
    target.scrollIntoView({ block: 'center', behavior: 'instant' });
}

function validation(root, preferred, focus = false) {
    root.querySelectorAll('[data-erp-inline-error]').forEach(error => {
        const input = controls(root).find(input => input.getAttribute('aria-describedby')?.split(' ').includes(error.id));
        if (input) {
            const ids = input.getAttribute('aria-describedby').split(' ').filter(id => id !== error.id).join(' ');
            if (ids) input.setAttribute('aria-describedby', ids);
            else input.removeAttribute('aria-describedby');
            if (input.hasAttribute('data-erp-invalid') && !input.hasAttribute('data-invalid')) input.removeAttribute('aria-invalid');
            input.removeAttribute('data-erp-invalid');
        }
        error.remove();
    });
    const entries = [...root.querySelectorAll('[data-erp-validation] [data-error-field]')].filter(entry => owns(root, entry));
    const mapped = new Map();
    for (const entry of entries) {
        const input = findControl(root, entry.dataset.errorField, preferred);
        if (!input) continue;
        if (!mapped.has(input)) mapped.set(input, new Set());
        mapped.get(input).add(entry.textContent.trim());
    }
    for (const [input, messages] of mapped) {
        const field = input.closest('[data-flux-field]') || input.parentElement;
        const existing = [...field.querySelectorAll('[data-flux-error], [data-erp-field-error]')].find(error => error.textContent.trim() && [...messages].every(message => error.textContent.includes(message)));
        const error = existing || document.createElement('p');
        if (!error.id) error.id = `erp-error-${crypto.randomUUID()}`;
        if (!existing) {
            error.dataset.erpInlineError = '';
            error.dataset.erpFieldError = '';
            error.textContent = [...messages].join(' ');
            field.append(error);
        }
        if (input.getAttribute('aria-invalid') !== 'true') input.dataset.erpInvalid = '';
        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('aria-describedby', [...new Set([...(input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean), error.id])].join(' '));
    }
    root.querySelectorAll('erp-select').forEach(select => select.refresh?.());
    if (focus && entries.length) {
        const first = [...mapped.keys()].find(input => !input.matches(':disabled'));
        if (first) reveal(first);
        else {
            const summary = entries[0].closest('[data-erp-validation]');
            if (preferred?.closest('dialog[open]') && !preferred.contains(summary)) {
                const local = summary.cloneNode(true);
                local.dataset.erpLocalValidation = '';
                preferred.prepend(local);
                reveal(local);
            } else reveal(summary);
        }
    }
    return entries.length > 0 || [...root.querySelectorAll('[data-flux-error]')].some(error => owns(root, error) && error.textContent.trim());
}

function requestError(root, form, status, body, filterRequest = false) {
    const target = form?.isConnected ? form : root;
    let error = target.querySelector('[data-erp-request-error]');
    if (!error) {
        error = document.createElement('div');
        error.dataset.erpRequestError = '';
        error.className = 'erp-validation-summary erp-feedback--danger';
        error.setAttribute('role', 'alert');
        error.tabIndex = -1;
        target.prepend(error);
    }
    let message = ar() ? 'تعذر تأكيد تنفيذ العملية. مدخلاتك ما زالت هنا؛ راجع السجل قبل إعادة المحاولة لتجنب التكرار.' : 'The operation could not be confirmed. Your entries are still here. Check the record before retrying to avoid duplicates.';
    if (filterRequest) message = ar() ? 'تعذر تحديث النتائج. احتفظنا بمعايير البحث ومدخلاتك؛ يمكنك إعادة المحاولة.' : 'Results could not be updated. Your filters and entries have been kept. Please try again.';
    if (status === 419) message = ar() ? 'انتهت الجلسة. انسخ مدخلاتك قبل تحديث الصفحة وتسجيل الدخول من جديد.' : 'Your session expired. Copy your entries before refreshing and signing in again.';
    else if (status === 403) message = ar() ? 'لا تتوفر صلاحية تنفيذ هذا الإجراء حاليًا. احتفظ بمدخلاتك وراجع صلاحياتك.' : 'You no longer have permission for this action. Keep your entries and review your access.';
    else if ([409, 422].includes(status)) {
        try { message = JSON.parse(body).message || message; } catch { /* HTML error pages must never be inserted into the form. */ }
    }
    error.textContent = message;
    if (form && businessForm(form)) { state(form).dirty = true; notice(form); }
    reveal(error);
}

function actionForm(action) {
    const origin = action.origin?.el;
    const direct = origin?.closest('form');
    if (direct) return direct;
    const candidates = [...action.component.el.querySelectorAll('form[wire\\:submit]')];
    return candidates.find(form => form.getAttribute('wire:submit')?.split('(')[0].trim() === action.name)
        || (/^apply(Import|Lines)$/.test(action.name) && candidates.length === 1 ? candidates[0] : null);
}

function connectLivewire() {
    Livewire.interceptAction(({ action, onSend, onSuccess, onError, onFailure, onFinish }) => {
        if (!action.origin?.el || action.name.startsWith('$') || action.origin.directive?.value === 'model') return;
        const root = action.component.el;
        const form = actionForm(action);
        const submit = form?.getAttribute('wire:submit')?.split('(')[0].trim() === action.name;
        const key = `${action.component.id}:${action.name}:${JSON.stringify(action.params)}`;
        if (pending.has(key)) { action.cancel(); return; }
        pending.add(key);
        const current = businessForm(form) ? state(form) : null;
        const revision = current?.revision;
        let succeeded = false;
        onSend(() => {
            root.querySelectorAll('[data-erp-request-error], [data-erp-local-validation]').forEach(error => error.remove());
            if (current && submit) { current.pending = true; form.setAttribute('aria-busy', 'true'); notice(form); }
        });
        onSuccess(() => { succeeded = true; });
        onError(({ response, body, preventDefault }) => { preventDefault(); requestError(root, form, response.status, body); });
        onFailure(() => requestError(root, form));
        onFinish(() => {
            pending.delete(key);
            if (!root.isConnected) return;
            if (current && submit) { current.pending = false; form.removeAttribute('aria-busy'); }
            const invalid = succeeded && validation(root, form, true);
            if (succeeded && !invalid && resetsEditor(action.name)) clearDirty(form || root);
            if (succeeded && !invalid && submit && current && current.revision === revision) {
                current.dirty = false;
                current.baseline = signature(form);
            }
            if (succeeded && !invalid && !submit && current && /^(add|remove|move|applyImport|applyLines)/.test(action.name)) {
                current.dirty = true;
                current.revision++;
            }
            if (current) notice(form);
            initialize();
        });
    });
    Livewire.interceptMessage(({ message, onSend, onSuccess, onError, onFailure }) => {
        const root = message.component.el;
        if (root.querySelector('[data-erp-filters]') && [...message.actions].every(action => !action.origin?.el || action.name.startsWith('$') || action.origin.directive?.value === 'model')) {
            onSend(() => root.querySelectorAll('[data-erp-request-error]').forEach(error => error.remove()));
            onError(({ response, body, preventDefault }) => { preventDefault(); requestError(root, null, response.status, body, true); });
            onFailure(() => requestError(root, null, undefined, undefined, true));
        }
        onSuccess(({ payload, onEffect, onMorphed }) => {
            if (payload.effects.redirect) onEffect(() => {
                for (const action of message.actions) {
                    const form = actionForm(action);
                    if (form && forms.has(form) && form.getAttribute('wire:submit')?.split('(')[0].trim() === action.name) forms.delete(form);
                }
            });
            onMorphed(() => { initialize(); validation(message.component.el); });
        });
    });
}

for (const event of ['input', 'change']) document.addEventListener(event, event => {
    const form = event.target.closest('form');
    if (!businessForm(form) || event.target.closest('[data-erp-select-ui]') || /search|filter/i.test(wireName(event.target) || '')) return;
    const current = state(form);
    current.revision++;
    current.dirty = signature(form) !== current.baseline;
    notice(form);
});
document.addEventListener('submit', event => {
    if (forms.get(event.target)?.pending) { event.preventDefault(); event.stopImmediatePropagation(); }
}, true);
document.addEventListener('click', event => {
    const close = event.target.closest('[data-flux-modal-close], [formmethod="dialog"]');
    const action = event.target.closest('[wire\\:click]');
    const expression = action?.getAttribute('wire:click') || '';
    const reset = resetsEditor(expression.split('(')[0].trim()) || /^\$set\([^,]+,\s*false\s*\)$/.test(expression) || /^\$set\(['"](?:tab|screen|activeTab)['"]\s*,/.test(expression);
    const dialog = event.target.closest('dialog');
    const rect = event.target.tagName === 'DIALOG' ? event.target.getBoundingClientRect() : null;
    const backdrop = rect && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom);
    if (!close && !reset && !backdrop) return;
    const scope = close || backdrop ? dialog : action.closest('form[wire\\:submit]') || action.closest('[wire\\:id]');
    if (scope && !mayDiscard(scope)) { event.preventDefault(); event.stopImmediatePropagation(); }
}, true);
document.addEventListener('cancel', event => {
    if (event.target.tagName === 'DIALOG' && !mayDiscard(event.target)) { event.preventDefault(); event.stopImmediatePropagation(); }
}, true);
document.addEventListener('close', event => {
    if (event.target.tagName === 'DIALOG') clearDirty(event.target);
}, true);
document.addEventListener('click', event => {
    const entry = event.target.closest('[data-erp-validation] [data-error-field]');
    if (!entry) return;
    const root = entry.closest('[wire\\:id]') || entry.closest('form') || document;
    const input = findControl(root, entry.dataset.errorField, entry.closest('form'));
    if (input) { validation(root, entry.closest('form')); reveal(input); }
});
const dirty = () => [...forms].some(([form, current]) => form.isConnected && current.dirty);
window.addEventListener('beforeunload', event => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });
document.addEventListener('livewire:navigate', event => {
    if (event.defaultPrevented || !dirty()) return;
    if (!confirm(ar() ? 'توجد تعديلات غير محفوظة. هل تريد مغادرة الصفحة وفقد هذه التعديلات؟' : 'You have unsaved changes. Leave this page and discard them?')) event.preventDefault();
});
document.addEventListener('livewire:navigated', () => initialize());
document.addEventListener('DOMContentLoaded', () => initialize());
if (document.readyState !== 'loading') initialize();
if (window.Livewire) connectLivewire();
else document.addEventListener('livewire:init', connectLivewire, { once: true });
