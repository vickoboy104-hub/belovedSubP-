/**
 * The customer's half of a configurable service form.
 *
 * Which question appears after which answer, what a dropdown may offer, and what
 * a choice costs are decided by App\Support\ServiceForm\Schema on the server.
 * This walks the same map the server sent so the screen matches the bill, and
 * nothing more: the figure shown here is for the customer's eye only, and the
 * debit is worked out again from the stored definition when the form arrives.
 */

const naira = (kobo) => (kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const answerOf = (form, name) => {
    const control = form.elements[name];
    if (!control) {
        return '';
    }

    if (control.type === 'checkbox') {
        return control.checked ? String(control.value || '1') : '';
    }

    return String(control.value ?? '').trim();
};

/** The answers a question can hold right now, which depend on the one above it. */
const allowedOptions = (field, answers, fields) => {
    if (!field.optionsBy) {
        return field.options || [];
    }

    const parent = answers[field.optionsBy.field] ?? '';
    if (parent === '') {
        // An unanswered question above has no answers below it yet. Showing the
        // first branch's list would offer a choice that was never made.
        return [];
    }

    return field.optionsBy.map[parent] || [];
};

const legalValues = (field, answers, fields) => allowedOptions(field, answers, fields).map((option) => String(option.value));

/**
 * A question is on the screen only when the answer it hangs on has been given,
 * is one that question can actually give, and that question is itself on the
 * screen - the same three checks the server makes before it validates or prices.
 */
const isVisible = (field, answers, fields, depth = 0) => {
    if (!field.showIf) {
        return true;
    }

    if (depth > 12) {
        return false;
    }

    const parent = fields[field.showIf.field];
    const answer = answers[field.showIf.field] ?? '';
    if (!parent || answer === '') {
        return false;
    }

    if (!isVisible(parent, answers, fields, depth + 1)) {
        return false;
    }

    const choices = legalValues(parent, answers, fields);
    if (choices.length !== 0 && !choices.includes(answer)) {
        return false;
    }

    return (field.showIf.any || []).map(String).includes(answer);
};

/** The rate the answers add up to: the deepest priced question wins. */
const chosenPrice = (config, answers, fields) => {
    let chosen = null;

    config.fields.forEach((field) => {
        if (!field.priceFor || !isVisible(field, answers, fields)) {
            return;
        }

        const answer = answers[field.name] ?? '';
        const kobo = answer === '' ? undefined : field.priceFor[answer];
        if (kobo !== undefined && kobo !== null) {
            chosen = Number(kobo);
        }
    });

    return chosen;
};

/** Rebuild a dependent menu so it holds only what the answer above it allows. */
const syncOptions = (field, form, answers, fields) => {
    if (!field.optionsBy) {
        return;
    }

    const control = form.elements[field.name];
    if (!control || control.tagName !== 'SELECT') {
        return;
    }

    const wanted = allowedOptions(field, answers, fields);
    const current = Array.from(control.options).slice(1);
    if (current.length === wanted.length
        && current.every((option, index) => option.value === String(wanted[index].value))) {
        return;
    }

    const previous = control.value;
    // The blank option belongs to the page rather than to the schema: its text is
    // the provider's own instruction, so it is kept instead of regenerated.
    control.length = 1;

    wanted.forEach((option) => {
        const element = document.createElement('option');
        element.value = String(option.value);
        element.textContent = option.label;
        control.appendChild(element);
    });

    if (wanted.some((option) => String(option.value) === previous)) {
        control.value = previous;
    }
};

const syncVisibility = (field, form) => {
    const wrap = form.querySelector(`[data-form-field="${field.name}"]`);
    if (wrap) {
        wrap.hidden = !field.visible;
    }

    const control = form.elements[field.name];
    if (!control) {
        return;
    }

    // A question the customer was never shown must not hold their place in line
    // and must not be sent at all, so it leaves the form when it leaves the
    // screen rather than only disappearing from it.
    control.disabled = !field.visible;
    control.required = Boolean(field.required && field.visible);
};

const syncNotes = (config, form, answers, fields) => {
    config.notes.forEach((note, index) => {
        const element = form.querySelector(`[data-note="${index}"]`);
        if (element) {
            element.hidden = note.showIf !== null && !isVisible({ showIf: note.showIf }, answers, fields);
        }
    });
};

export function initServiceForm(root) {
    const scope = root instanceof HTMLElement ? root : document;
    const forms = scope.matches?.('[data-service-form]')
        ? [scope]
        : Array.from(scope.querySelectorAll('[data-service-form]'));

    forms.forEach((form) => {
        if (form.dataset.serviceFormReady === '1') {
            return;
        }

        const source = form.querySelector('[data-service-form-config]');
        if (!source) {
            return;
        }

        let config;
        try {
            config = JSON.parse(source.textContent);
        } catch (error) {
            return;
        }

        if (!config || !Array.isArray(config.fields) || config.fields.length === 0) {
            return;
        }

        form.dataset.serviceFormReady = '1';

        const fields = {};
        config.fields.forEach((field) => { fields[field.name] = field; });

        const costLine = form.querySelector('#serviceCostLine');
        const payable = form.querySelector('#servicePayableAmount');
        const shortfall = form.querySelector('#serviceTierShortfall');
        const submit = form.querySelector('.service-form-actions button[type=submit]');
        const balance = Number(form.querySelector('#servicePaymentNotice')?.dataset.balance || 0);
        const template = costLine?.dataset.costTemplate;
        const invitation = ' ' + String(config.invitation || '');
        // A page that sells several jobs at several rates cannot name one figure
        // before the customer has picked one, so it quotes its whole span. The
        // span is measured on the server, in the same map, and sent here.
        const span = Array.isArray(config.span) ? config.span.map(Number) : null;

        const render = () => {
            const answers = {};
            config.fields.forEach((field) => { answers[field.name] = answerOf(form, field.name); });

            // A menu is only rebuilt against an answer that is still legal, so
            // the options follow the visibility pass rather than lead it.
            config.fields.forEach((field) => { syncOptions(field, form, answers, fields); });

            config.fields.forEach((field) => {
                syncVisibility({ ...field, visible: isVisible(field, answers, fields) }, form);
            });

            const priced = chosenPrice(config, answers, fields);
            const floor = Number(config.floorPrice ?? config.basePrice ?? 0);
            const standing = priced !== null || span === null;
            // A page still choosing between its rates has committed to no
            // figure, so it quotes the lowest rate one of its choices carries
            // - never the service's base, which on a tiered page is a number
            // nothing here will charge - and it refuses no wallet that could
            // pay for the cheapest job on it.
            const kobo = priced ?? floor;
            const amount = standing
                ? naira(kobo)
                : `${naira(floor)} – ₦${naira(Math.max(...span))}`;

            if (costLine && typeof template === 'string') {
                costLine.textContent = template.replace(/__PRICE__/g, amount) + (standing ? '' : invitation);
            }
            if (payable) {
                payable.textContent = amount;
            }

            if (span !== null) {
                const affordable = balance >= kobo;
                if (shortfall) {
                    shortfall.hidden = affordable;
                }
                if (submit) {
                    submit.disabled = !affordable;
                }
            }

            syncNotes(config, form, answers, fields);
        };

        form.addEventListener('change', render);
        form.addEventListener('input', render);
        render();
    });
}
