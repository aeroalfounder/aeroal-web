/* ==========================================
   AEROAL — Script v1
   Функции: слайдер, поиск, своп городов, даты недели,
   рендер рейсов с классами/ценами/наличием, языки/валюта.
   ========================================== */

// ====== УТИЛЫ ======
const $ = (s, r=document) => r.querySelector(s);
const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));
const fmt = (n, curr) => new Intl.NumberFormat('ru-RU', {style:'currency', currency: curr||'USD', maximumFractionDigits:0}).format(n);

// ====== ДЕМО ДАННЫЕ ======
const DB = {
  flights: [
    { id:101, code:'ARL0101', from:'Москва', to:'Дубай', depart:'2025-09-19T10:00', arrive:'2025-09-19T13:10', aircraft:'Airbus A350', durationMin:190,
      fares:{ economy:{price:499, seats:9}, comfort:{price:599, seats:5}, business:{price:899, seats:2}, first:{price:1299, seats:0}, elite:{price:2499, seats:1} } },
    { id:205, code:'ARL0205', from:'Москва', to:'Шанхай', depart:'2025-09-23T09:00', arrive:'2025-09-23T20:20', aircraft:'Boeing 777-300', durationMin:680,
      fares:{ economy:{price:529, seats:0}, comfort:{price:649, seats:8}, business:{price:969, seats:4}, first:{price:1499, seats:1} } },
    { id:330, code:'ARL0330', from:'Москва', to:'Стамбул', depart:'2025-09-12T08:15', arrive:'2025-09-12T11:05', aircraft:'A321neo', durationMin:170,
      fares:{ economy:{price:189, seats:6}, comfort:{price:249, seats:0}, business:{price:399, seats:3} } },
  ],
  currencies:{ RBX:1, RUB:100, USD:1, EUR:0.92, CNY:7.2 } // коэффициенты условные для демо
};

let STATE = {
  lang:'ru', currency:'USD', from:'Москва', to:'Дубай', depart:null, ret:null, pax:1, cabin:'economy'
};

// ====== ШАПКА / КОМБОБОКСЫ ======
(function initTopbar(){
  const lang = $('#language');
  const cur = $('#currency');
  if(lang) lang.addEventListener('change', e=>{ STATE.lang = e.target.value; });
  if(cur) cur.addEventListener('change', e=>{ STATE.currency = e.target.value; renderDatesRow(); renderFlights(); });
})();

// ====== СЛАЙДЕР ======
(function initSlider(){
  const slides = $$('.hero-slider .slide');
  const left = $('.hero-slider .arrow.left');
  const right = $('.hero-slider .arrow.right');
  let i = slides.findIndex(s=>s.classList.contains('active')); if(i<0) i=0;
  const show = n=>{ slides.forEach((s,idx)=>s.classList.toggle('active', idx===n)); };
  left && left.addEventListener('click', ()=>{ i=(i-1+slides.length)%slides.length; show(i); });
  right && right.addEventListener('click', ()=>{ i=(i+1)%slides.length; show(i); });
})();

// ====== ПОИСКОВЫЙ БЛОК ======
(function initSearchBox(){
  const box = $('.search-box');
  if(!box) return;
  const fromEl = box.querySelector('.from');
  const toEl = box.querySelector('.to');
  const swapBtn = box.querySelector('.swap');
  const paxEl = box.querySelector('.passengers');
  const btn = box.querySelector('.search-btn');

  // простые выпадающие списки (демо)
  fromEl.setAttribute('contenteditable','true');
  toEl.setAttribute('contenteditable','true');

  swapBtn.addEventListener('click', ()=>{
    const a = fromEl.textContent.trim();
    const b = toEl.textContent.trim();
    fromEl.textContent = b || 'Куда';
    toEl.textContent = a || 'Откуда';
  });

  paxEl.addEventListener('click', ()=>{
    // цикл по пресетам
    const presets = [
      {txt:'1, Эконом', pax:1, cabin:'economy'},
      {txt:'2, Эконом', pax:2, cabin:'economy'},
      {txt:'1, Комфорт', pax:1, cabin:'comfort'},
      {txt:'1, Бизнес', pax:1, cabin:'business'},
      {txt:'1, Первый', pax:1, cabin:'first'},
    ];
    const current = presets.findIndex(p=>p.txt===paxEl.textContent.trim());
    const next = presets[(current+1)%presets.length];
    paxEl.textContent = next.txt;
    STATE.pax = next.pax; STATE.cabin = next.cabin;
  });

  btn.addEventListener('click', ()=>{
    STATE.from = fromEl.textContent.trim() || 'Москва';
    STATE.to = toEl.textContent.trim() || 'Дубай';
    $('#from-city').textContent = STATE.from;
    $('#to-city').textContent = STATE.to;
    renderDatesRow();
    renderFlights();
    window.scrollTo({top:$('.results').offsetTop - 12, behavior:'smooth'});
  });
})();

// ====== ДАТЫ НЕДЕЛИ / МИНИМАЛЬНЫЕ ЦЕНЫ ======
function renderDatesRow(){
  const row = $('.dates-row'); if(!row) return;
  const days = 7; const today = new Date();
  row.innerHTML = '';
  for(let d=0; d<days; d++){
    const dt = new Date(today); dt.setDate(today.getDate()+d);
    const label = dt.toLocaleDateString('ru-RU', {day:'2-digit', month:'2-digit'});
    const min = minPriceForDate(dt);
    const el = document.createElement('div');
    el.className = 'date';
    el.textContent = `${label} — от ${fmt(min, mapCurr(STATE.currency))}`;
    row.appendChild(el);
  }
}

function minPriceForDate(/*date*/){
  // демо: берём минимум по доступным тарифам всех подходящих рейсов
  const list = DB.flights.filter(f=>f.from===STATE.from && f.to===STATE.to);
  let min = Infinity;
  list.forEach(f=>{
    Object.values(f.fares).forEach(v=>{ if(v.seats>0) min = Math.min(min, v.price); });
  });
  return Number.isFinite(min)? convert(min) : 0;
}

// ====== РЕНДЕР РЕЙСОВ ======
function renderFlights(){
  const wrap = $('.results'); if(!wrap) return;
  const list = DB.flights.filter(f=>f.from===STATE.from && f.to===STATE.to);
  // очистим все, кроме заголовка и дат
  const cards = $$('.flight-card'); cards.forEach(c=>c.remove());

  list.forEach(f=>{
    const card = document.createElement('div');
    card.className = 'flight-card';
    const dep = new Date(f.depart); const arr = new Date(f.arrive);
    const t = (m)=> `${String(Math.floor(m/60)).padStart(1,'0')}ч ${String(m%60).padStart(2,'0')}мин`;

    card.innerHTML = `
      <div class="times">${dep.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'})}
        <span class="line"></span>
        ${arr.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'})}
      </div>
      <div class="classes"></div>
      <div class="duration">В пути ${t(f.durationMin)}</div>
      <div class="flight-code">Рейс ${f.code}, ${f.aircraft}</div>
    `;

    const classes = card.querySelector('.classes');
    ['economy','comfort','business','first','elite'].forEach(key=>{
      if(!f.fares[key]) return;
      const fare = f.fares[key];
      const div = document.createElement('div');
      div.className = 'class' + (fare.seats>0? ' available':' unavailable');
      const title = ({economy:'Эконом',comfort:'Комфорт',business:'Бизнес',first:'Первый',elite:'Élite'})[key];
      div.textContent = fare.seats>0 ? `${title} — ${fmt(convert(fare.price), mapCurr(STATE.currency))}` : `${title} — мест нет`;
      classes.appendChild(div);
    });

    wrap.appendChild(card);
  });
}

function mapCurr(code){
  // соответствие коды -> реальные ISO для форматтера
  if(code==='RBX') return 'USD'; // визуально форматируем как USD
  return code;
}
function convert(price){
  // простая конверсия через условный коэффициент
  const k = DB.currencies[STATE.currency]||1; return Math.round(price * k);
}

// ====== СТАРТ РЕНДЕРА ======
window.addEventListener('DOMContentLoaded', ()=>{
  // подготовим стартовые подписи
  $('.search-box .from').textContent = STATE.from;
  $('.search-box .to').textContent = STATE.to;
  $('#from-city').textContent = STATE.from;
  $('#to-city').textContent = STATE.to;
  renderDatesRow();
  renderFlights();
});
