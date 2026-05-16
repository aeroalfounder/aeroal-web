// --- GET params ---
const params = new URLSearchParams(window.location.search);
const from = params.get('from');
const to = params.get('to');
const date = params.get('date');

// --- Fill form ---
if (from && to && date) {
  document.getElementById('from').value = from;
  document.getElementById('to').value = to;
  document.getElementById('date').value = date;
}

// --- Submit again ---
document.getElementById('searchForm').addEventListener('submit', e => {
  e.preventDefault();
  const f = e.target.from.value;
  const t = e.target.to.value;
  const d = e.target.date.value;

  if (!f || !t || !d) return;

  window.location.href = `results.html?from=${f}&to=${t}&date=${d}`;
});

// --- Date strip ---
function renderDates(centerDate) {
  const strip = document.getElementById('dateStrip');
  strip.innerHTML = '';

  for (let i = -4; i <= 4; i++) {
    const d = new Date(centerDate);
    d.setDate(d.getDate() + i);

    const btn = document.createElement('button');
    btn.textContent = d.toISOString().slice(5, 10);
    btn.className = i === 0 ? 'active' : '';
    btn.onclick = () => {
      document.getElementById('date').value = d.toISOString().slice(0, 10);
      document.getElementById('searchForm').dispatchEvent(new Event('submit'));
    };

    strip.appendChild(btn);
  }
}

if (date) renderDates(new Date(date));

// --- Fetch flights ---
if (from && to && date) {
  fetch(`searchFlights.php?from=${from}&to=${to}&date=${date}`)
    .then(res => res.json())
    .then(renderFlights);
}

function renderFlights(data) {
  const container = document.getElementById('results');
  container.innerHTML = '';

  if (!data.length) {
    container.innerHTML = `<p class="empty">
      No flights available on this date
    </p>`;
    return;
  }

  data.forEach(f => {
    const div = document.createElement('div');
    div.className = 'flight';

    div.innerHTML = `
      <div class="time">
        <strong>${f.departure}</strong>
        <span class="line"></span>
        <strong>${f.arrival}</strong>
      </div>

      <div class="info">
        <span>${f.flight_number}</span>
        <span>${f.aircraft}</span>
      </div>

      <div class="classes">
        <div>Economy<br>$${f.economy}</div>
        <div>Premium<br>$${f.premium}</div>
        <div>Business<br>$${f.business}</div>
        <div>First<br>$${f.first}</div>
      </div>
    `;

    container.appendChild(div);
  });
}
