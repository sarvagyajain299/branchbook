// Charts for the admin reports page. Loaded on demand (dynamic import) so the Chart.js
// library is downloaded only by people who open the reports. Data arrives as JSON inside a
// <script type="application/json"> tag, which browsers never execute (CSP-safe).
import Chart from 'chart.js/auto';

const dataElement = document.getElementById('report-data');

if (dataElement) {
    const data = JSON.parse(dataElement.textContent);

    const bar = (canvasId, label, series, unit) => {
        const canvas = document.getElementById(canvasId);
        if (!canvas || series.labels.length === 0) return;

        new Chart(canvas, {
            type: 'bar',
            data: { labels: series.labels, datasets: [{ label, data: series.values, backgroundColor: '#0f766e' }] },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, title: { display: true, text: unit } } },
            },
        });
    };

    bar('chart-utilisation', 'Utilisation', data.utilisation, '% of shift time booked');
    bar('chart-no-shows', 'No-show rate', data.noShows, '% of appointments');
    bar('chart-waits', 'Average wait', data.waits, 'minutes');
}
