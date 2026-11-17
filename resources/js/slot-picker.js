// Loads a week of available slots as JSON and renders it without a page reload.
// Uses textContent (never innerHTML) so nothing from the server can inject markup.
const root = document.getElementById('slot-picker');

if (root) {
    const url = root.dataset.url;
    const today = root.dataset.from;
    let from = today;

    const days = root.querySelector('[data-days]');
    const status = root.querySelector('[data-status]');
    const prev = root.querySelector('[data-prev]');
    const next = root.querySelector('[data-next]');

    const shift = (iso, n) => {
        const d = new Date(`${iso}T00:00:00Z`);
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    };

    const render = (data) => {
        let any = false;
        days.replaceChildren();

        for (const day of data.days) {
            const section = document.createElement('section');
            section.className = 'rounded-lg border border-slate-200 bg-white p-4';

            const heading = document.createElement('h3');
            heading.className = 'mb-2 text-sm font-semibold text-slate-700';
            heading.textContent = day.label;
            section.append(heading);

            if (day.slots.length === 0) {
                const none = document.createElement('p');
                none.className = 'text-sm text-slate-500';
                none.textContent = 'No times';
                section.append(none);
            } else {
                any = true;
                const list = document.createElement('ul');
                list.className = 'flex flex-wrap gap-2';
                for (const slot of day.slots) {
                    const item = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = slot.url;
                    link.textContent = slot.local_time;
                    link.className = 'inline-block rounded-md border border-teal-700 px-3 py-1.5 text-sm font-medium text-teal-800 hover:bg-teal-700 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2';
                    link.setAttribute('aria-label', `${day.label} at ${slot.local_time}`);
                    item.append(link);
                    list.append(item);
                }
                section.append(list);
            }

            days.append(section);
        }

        status.textContent = any ? 'Choose a time (Dubai time):' : 'No times are available this week. Try the next week.';
    };

    const load = async () => {
        status.textContent = 'Loading available times…';
        prev.disabled = from <= today;

        try {
            const response = await fetch(`${url}?from=${from}&days=7`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(String(response.status));
            render(await response.json());
        } catch {
            days.replaceChildren();
            status.textContent = 'Could not load times. Please refresh the page and try again.';
        }
    };

    prev.addEventListener('click', () => { from = shift(from, -7); if (from < today) from = today; load(); });
    next.addEventListener('click', () => { from = shift(from, 7); load(); });

    load();
}
