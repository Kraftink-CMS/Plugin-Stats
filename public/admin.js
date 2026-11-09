const filters = document.getElementById('stats-filter');
if (filters) {
    const period = filters.elements.days;
    const updateDates = () => {
        const custom = period.value === 'custom';
        for (const field of [filters.elements.from, filters.elements.to]) {
            field.disabled = !custom;
            field.required = custom;
            field.closest('[data-stats-custom]').hidden = !custom;
        }
    };
    period.addEventListener('change', updateDates);
    updateDates();
}

const format = new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: 2 });
for (const trend of document.querySelectorAll('[data-stats-trend]')) {
    const range = trend.querySelector('[data-stats-range]');
    if (!range) {
        continue;
    }

    const values = JSON.parse(trend.dataset.statsValues);
    const dates = JSON.parse(trend.dataset.statsDates);
    const previous = JSON.parse(trend.dataset.statsPrevious);
    const previousDates = JSON.parse(trend.dataset.statsPreviousDates);
    const unit = trend.dataset.statsUnit;
    const plot = trend.querySelector('[data-stats-plot]');
    const cursor = trend.querySelector('[data-stats-cursor]');
    const date = trend.querySelector('[data-stats-date]');
    const value = trend.querySelector('[data-stats-value]');
    const previousDate = trend.querySelector('[data-stats-previous-date]');
    const previousValue = trend.querySelector('[data-stats-previous-value]');
    const last = values.length - 1;
    const display = (point) => (point === null ? '—' : `${format.format(point)}${unit ? ` ${unit}` : ''}`);

    const inspect = (index) => {
        range.value = index;
        const position = 8 + (624 * index) / last;
        cursor.setAttribute('x1', position);
        cursor.setAttribute('x2', position);
        date.textContent = dates[index];
        value.textContent = display(values[index]);
        if (previousDate && previousValue) {
            previousDate.textContent = previousDates[index];
            previousValue.textContent = display(previous[index]);
        }
        range.setAttribute('aria-valuetext', `${date.textContent} · ${value.textContent}`);
    };

    range.hidden = false;
    range.addEventListener('input', () => inspect(Number(range.value)));
    plot.addEventListener('pointermove', (event) => {
        if (event.pointerType === 'touch') {
            return;
        }
        const { left, width } = plot.getBoundingClientRect();
        const fraction = ((640 * (event.clientX - left)) / width - 8) / 624;
        inspect(Math.min(last, Math.max(0, Math.round(fraction * last))));
    });
    inspect(Number(range.value));
}
