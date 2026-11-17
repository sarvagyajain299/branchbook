// Waiting-room screen: polls a small JSON endpoint every 4 seconds and redraws the lists.
// Polling (not websockets) keeps the setup to one web server; at one request every 4 seconds
// per screen the load is negligible. Reverb would push changes instantly but adds a service.
const root = document.getElementById('display');

if (root) {
    const url = root.dataset.url;
    const servingList = root.querySelector('[data-serving]');
    const nextList = root.querySelector('[data-next]');
    const empty = root.querySelector('[data-empty]');
    const soundButton = root.querySelector('[data-sound]');

    let soundOn = false;
    let audio = null;
    let seen = null; // null until the first poll, so a freshly opened screen doesn't chime

    soundButton.addEventListener('click', () => {
        soundOn = !soundOn;
        soundButton.textContent = soundOn ? 'Sound on' : 'Turn sound on';
        if (soundOn) audio = audio ?? new AudioContext(); // allowed because it follows a click
    });

    const chime = () => {
        if (!soundOn || !audio) return;
        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.frequency.value = 880;
        gain.gain.value = 0.2;
        oscillator.connect(gain);
        gain.connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.4);
    };

    const render = (state) => {
        servingList.replaceChildren();
        for (const item of state.serving) {
            const row = document.createElement('li');
            row.className = 'flex items-baseline gap-6 rounded-lg border-2 border-yellow-300 px-6 py-4';
            const code = document.createElement('span');
            code.className = 'text-7xl font-bold';
            code.textContent = item.code;
            const arrow = document.createElement('span');
            arrow.className = 'text-5xl text-white';
            arrow.textContent = '→';
            arrow.setAttribute('aria-hidden', 'true');
            const desk = document.createElement('span');
            desk.className = 'text-6xl font-semibold text-white';
            desk.textContent = item.desk ?? '';
            row.append(code, arrow, desk);
            servingList.append(row);
        }
        empty.hidden = state.serving.length > 0;

        nextList.replaceChildren();
        for (const code of state.next) {
            const row = document.createElement('li');
            row.textContent = code;
            nextList.append(row);
        }

        const keys = new Set(state.serving.map((item) => item.key));
        if (seen !== null && [...keys].some((key) => !seen.has(key))) chime();
        seen = keys;
    };

    const poll = async () => {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (response.ok) render(await response.json());
        } catch {
            // Network blip: keep showing the last state and try again on the next tick.
        }
    };

    poll();
    setInterval(poll, 4000);
}
