const ar = () => document.documentElement.lang.startsWith('ar');
let sequence = 0;

class ErpSelect extends HTMLElement {
    connectedCallback() {
        queueMicrotask(() => this.connect());
    }

    connect() {
        if (!this.isConnected || this.observer) return;
        this.select = this.querySelector('select');
        this.ui = this.querySelector('[data-erp-select-ui]');
        if (!this.select || !this.ui || !HTMLElement.prototype.showPopover) return;
        this.originalTabindex = this.select.getAttribute('tabindex');
        this.originalHidden = this.select.getAttribute('aria-hidden');
        this.events = new AbortController();
        this.observer = new MutationObserver(() => this.refresh());
        this.observer.observe(this.select, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['disabled', 'required', 'label', 'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-invalid', 'id', 'name', 'selected'] });
        this.fieldset = this.closest('fieldset');
        if (this.fieldset) this.observer.observe(this.fieldset, { attributes: true, attributeFilter: ['disabled'] });
        this.select.addEventListener('change', () => this.refresh(), { signal: this.events.signal });
        this.select.addEventListener('focus', () => { if (this.hasAttribute('data-enhanced')) this.trigger.focus(); }, { signal: this.events.signal });
        this.select.addEventListener('invalid', event => {
            if (!this.hasAttribute('data-enhanced')) return;
            event.preventDefault();
            if (!this.clientInvalid) this.originalInvalid = this.select.getAttribute('aria-invalid');
            this.clientInvalid = true;
            this.select.setAttribute('aria-invalid', 'true');
            if (!this.clientError) {
                this.clientError = document.createElement('p');
                this.clientError.id = `${this.popup.id}-error`;
                this.clientError.dataset.erpSelectError = '';
                this.clientError.setAttribute('role', 'alert');
                this.ui.append(this.clientError);
            }
            this.clientError.textContent = ar() ? 'اختر قيمة لإكمال هذا الحقل.' : this.select.validationMessage;
            this.refresh();
            this.trigger.focus();
        }, { signal: this.events.signal });
        this.refresh();
    }

    disconnectedCallback() {
        this.observer?.disconnect();
        this.observer = null;
        this.events?.abort();
        clearTimeout(this.searchTimer);
        if (this.popup?.isConnected && this.popup.matches(':popover-open')) this.popup.hidePopover();
        if (this.clientInvalid && !this.originalInvalid && !this.select.hasAttribute('data-invalid') && !this.select.hasAttribute('data-erp-invalid')) this.select.removeAttribute('aria-invalid');
        if (this.select) this.restoreNative();
        this.ui?.replaceChildren();
        this.trigger = null;
        this.clientError = null;
        this.clientInvalid = false;
    }

    restoreNative() {
        for (const [name, value] of [['tabindex', this.originalTabindex], ['aria-hidden', this.originalHidden]]) {
            if (value === null) this.select.removeAttribute(name);
            else this.select.setAttribute(name, value);
        }
    }

    refresh() {
        if (!this.select || !this.isConnected) return;
        if (this.clientInvalid && this.select.validity.valid) {
            this.clientError?.remove();
            this.clientError = null;
            this.clientInvalid = false;
            if (!this.originalInvalid && !this.select.hasAttribute('data-invalid') && !this.select.hasAttribute('data-erp-invalid')) this.select.removeAttribute('aria-invalid');
        }
        const enhanced = !this.select.multiple && this.select.size <= 1 && (this.dataset.searchModel || this.select.options.length > 7);
        this.toggleAttribute('data-enhanced', enhanced);
        this.ui.hidden = !enhanced;
        if (!enhanced) {
            this.popup?.hidePopover();
            this.restoreNative();
            return;
        }
        if (!this.trigger) this.build();
        this.select.tabIndex = -1;
        this.select.setAttribute('aria-hidden', 'true');
        this.label = this.select.getAttribute('aria-label') || this.closest('[data-flux-field]')?.querySelector('[data-flux-label]')?.textContent.trim() || this.select.labels?.[0]?.textContent.trim() || (ar() ? 'اختيار' : 'Select');
        this.trigger.className = `${this.select.className} erp-select-trigger`;
        this.trigger.textContent = this.select.selectedOptions[0]?.textContent.trim() || (ar() ? 'اختر قيمة' : 'Choose an option');
        this.trigger.setAttribute('aria-label', `${this.label}: ${this.trigger.textContent}`);
        this.trigger.disabled = this.select.matches(':disabled');
        for (const name of ['aria-invalid', 'aria-describedby']) {
            if (this.select.hasAttribute(name)) this.trigger.setAttribute(name, this.select.getAttribute(name));
            else this.trigger.removeAttribute(name);
        }
        if (this.clientError) this.trigger.setAttribute('aria-describedby', [this.select.getAttribute('aria-describedby'), this.clientError.id].filter(Boolean).join(' '));
        this.search.setAttribute('aria-label', ar() ? `بحث في ${this.label}` : `Search ${this.label}`);
        this.search.setAttribute('aria-required', String(this.select.required));
        this.popup.setAttribute('aria-label', this.label);
        if (this.trigger.disabled) this.popup.hidePopover();
        if (this.popup.matches(':popover-open')) this.renderOptions();
    }

    build() {
        const id = `erp-select-${++sequence}`;
        this.trigger = document.createElement('button');
        this.trigger.type = 'button';
        this.trigger.dataset.erpSelectTrigger = '';
        this.trigger.setAttribute('aria-haspopup', 'dialog');
        this.trigger.setAttribute('aria-expanded', 'false');
        this.trigger.setAttribute('aria-controls', id);
        this.popup = document.createElement('div');
        this.popup.id = id;
        this.popup.className = 'erp-select-popover';
        this.popup.popover = 'auto';
        this.popup.setAttribute('role', 'dialog');
        this.search = document.createElement('input');
        this.search.type = 'search';
        this.search.className = 'erp-select-search';
        this.search.autocomplete = 'off';
        this.search.placeholder = ar() ? 'اكتب للبحث…' : 'Type to search…';
        this.search.setAttribute('role', 'combobox');
        this.search.setAttribute('aria-autocomplete', 'list');
        this.search.setAttribute('aria-controls', `${id}-options`);
        this.search.setAttribute('aria-expanded', 'true');
        this.list = document.createElement('div');
        this.list.id = `${id}-options`;
        this.list.className = 'erp-select-options';
        this.list.setAttribute('role', 'listbox');
        this.empty = document.createElement('p');
        this.empty.className = 'erp-select-empty';
        this.empty.setAttribute('role', 'status');
        this.empty.textContent = ar() ? 'لا توجد نتائج مطابقة. جرّب كلمة أخرى.' : 'No matching options. Try another search.';
        this.searchStatus = document.createElement('p');
        this.searchStatus.className = 'erp-select-empty';
        this.searchStatus.setAttribute('role', 'status');
        this.searchStatus.hidden = true;
        this.popup.append(this.search, this.list, this.empty, this.searchStatus);
        this.ui.append(this.trigger, this.popup);
        const signal = this.events.signal;
        this.trigger.addEventListener('click', () => {
            if (!this.dataset.searchModel) this.search.value = '';
            this.popup.togglePopover();
        }, { signal });
        this.trigger.addEventListener('keydown', event => {
            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            event.preventDefault();
            if (!this.dataset.searchModel) this.search.value = '';
            this.popup.showPopover();
        }, { signal });
        this.popup.addEventListener('toggle', event => {
            const open = event.newState === 'open';
            this.trigger.setAttribute('aria-expanded', String(open));
            if (open) {
                if (this.dataset.searchModel && !this.searchPending) this.search.value = this.querySelector('[data-erp-select-fallback] input')?.value || '';
                this.position();
                this.renderOptions();
                this.search.focus();
            }
        }, { signal });
        this.popup.addEventListener('keydown', event => {
            if (event.key === 'Escape' || event.key === 'Tab') {
                if (event.key === 'Escape') event.preventDefault();
                this.popup.hidePopover();
                this.trigger.focus();
                return;
            }
            if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                const options = [...this.list.children].filter(option => option.getAttribute('aria-disabled') !== 'true');
                let index = options.indexOf(this.active);
                index = event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : Math.max(0, Math.min(options.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1)));
                this.activate(options[index]);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (this.active) this.choose(Number(this.active.dataset.index));
            }
        }, { signal });
        this.search.addEventListener('input', () => {
            if (!this.dataset.searchModel) return this.renderOptions();
            this.searchPending = true;
            this.searchFailed = false;
            this.renderOptions();
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.searchRemote(), 300);
        }, { signal });
        this.list.addEventListener('mousedown', event => event.preventDefault(), { signal });
        this.list.addEventListener('click', event => {
            const option = event.target.closest('[data-index]');
            if (option) this.choose(Number(option.dataset.index));
        }, { signal });
        window.addEventListener('resize', () => { if (this.popup.matches(':popover-open')) this.position(); }, { signal });
        window.addEventListener('scroll', event => {
            if (!this.popup.contains(event.target) && this.popup.matches(':popover-open')) this.position();
        }, { signal, capture: true });
    }

    position() {
        const rect = this.trigger.getBoundingClientRect();
        const width = Math.min(Math.max(rect.width, 260), innerWidth - 16);
        const below = innerHeight - rect.bottom - 8;
        const above = rect.top - 8;
        const height = Math.min(360, Math.max(below, above));
        Object.assign(this.popup.style, { width: `${width}px`, left: `${Math.max(8, Math.min(rect.left, innerWidth - width - 8))}px`, maxHeight: `${height}px`, top: below >= Math.min(240, above) ? `${rect.bottom + 4}px` : 'auto', bottom: below >= Math.min(240, above) ? 'auto' : `${innerHeight - rect.top + 4}px` });
    }

    renderOptions() {
        const normalize = value => value.normalize('NFKD').replace(/[\u064B-\u065F\u0670\u0640]/g, '').toLocaleLowerCase().trim();
        const query = normalize(this.search.value);
        this.list.replaceChildren();
        this.searchStatus.hidden = !this.searchPending && !this.searchFailed;
        this.searchStatus.textContent = this.searchFailed
            ? (ar() ? 'تعذر تحميل الخيارات. أعد كتابة البحث للمحاولة.' : 'Could not load options. Type again to retry.')
            : (ar() ? 'جارٍ البحث…' : 'Searching…');
        this.list.setAttribute('aria-busy', String(!!this.searchPending));
        if (this.searchPending || this.searchFailed) {
            this.empty.hidden = true;
            this.activate(null);
            return;
        }
        [...this.select.options].forEach((option, index) => {
            const group = option.closest('optgroup');
            if (option.hidden || (this.dataset.searchModel && query && option.value === '') || (!this.dataset.searchModel && !normalize(`${group?.label || ''} ${option.textContent}`).includes(query))) return;
            const item = document.createElement('div');
            item.id = `${this.list.id}-${index}`;
            item.dataset.index = index;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', String(option.selected));
            item.setAttribute('aria-disabled', String(option.disabled || !!group?.disabled));
            item.textContent = `${group?.label ? group.label + ' · ' : ''}${option.textContent.trim()}`;
            this.list.append(item);
        });
        this.empty.hidden = this.list.childElementCount !== 0;
        this.activate((!query && this.list.querySelector('[aria-selected="true"]:not([aria-disabled="true"])')) || this.list.querySelector('[aria-disabled="false"]'));
    }

    async searchRemote() {
        if (this.searchRunning || !this.isConnected) return;
        this.searchRunning = true;
        this.searchFailed = false;
        const query = this.search.value;
        try {
            const component = this.closest('[wire\\:id]');
            await window.Livewire.find(component.getAttribute('wire:id')).$set(this.dataset.searchModel, query);
        } catch {
            this.searchFailed = true;
        } finally {
            this.searchRunning = false;
            if (!this.isConnected) return;
            if (query !== this.search.value) {
                this.searchRemote();
            } else {
                this.searchPending = false;
                this.renderOptions();
            }
        }
    }

    activate(option) {
        this.active?.removeAttribute('data-active');
        this.active = option;
        if (option) {
            option.dataset.active = '';
            this.search.setAttribute('aria-activedescendant', option.id);
            option.scrollIntoView({ block: 'nearest' });
        } else this.search.removeAttribute('aria-activedescendant');
    }

    choose(index) {
        if (this.searchPending || this.searchFailed) return;
        const option = this.select.options[index];
        if (!option || option.disabled || option.closest('optgroup')?.disabled || this.select.matches(':disabled')) return;
        this.select.selectedIndex = index;
        this.select.dispatchEvent(new Event('input', { bubbles: true }));
        this.select.dispatchEvent(new Event('change', { bubbles: true }));
        this.popup.hidePopover();
        this.refresh();
        this.trigger.focus();
    }
}

if (!customElements.get('erp-select')) customElements.define('erp-select', ErpSelect);
