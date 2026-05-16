// ===== Config
const API_BASE = "."; // PHP-файлы в корне
const BOOKING_API = "booking_api.php";
const LS = {
  lang: "aeroal_lang",
  cur: "aeroal_cur",
  form: "aeroal_form",
  offer: "aeroal_selected_offer",
  passenger: "aeroal_passenger_draft"
};

// ===== Translations (можно также получать из API /translations.php)
const i18n = {
  ru:{
    home:"Главная",flights:"Рейсы",services:"Услуги",info:"Информация",company:"Компания",
    account:"Личный кабинет",searchTitle:"Найдите ваш рейс",from:"Откуда",to:"Куда",
    depart:"Туда",return:"Обратно",paxClass:"Пассажиры / Класс",apply:"Применить",
    find:"Найти",specials:"Специальные предложения",baggage:"Багаж",meals:"Питание",
    lounge:"Лаунжи",priority:"Приоритет",extras:"Доп. услуги",support:"Поддержка",
    fleet:"Флот",information:"Информация",rules:"Правила",loyalty:"AEROAL Loyalty",
    help:"Помощь",safety:"Безопасность",docs:"Документы",contacts:"Контакты",
    adults:"Взрослые",children:"Дети",infants:"Младенцы",economy:"Эконом",
    premium:"Комфорт",business:"Бизнес",first:"Первый"
  },
  en:{
    home:"Home",flights:"Flights",services:"Services",info:"Information",company:"Company",
    account:"Account",searchTitle:"Find your flight",from:"From",to:"To",
    depart:"Depart",return:"Return",paxClass:"Passengers / Class",apply:"Apply",
    find:"Search",specials:"Special offers",baggage:"Baggage",meals:"Meals",
    lounge:"Lounges",priority:"Priority",extras:"Extras",support:"Support",
    fleet:"Fleet",information:"Information",rules:"Rules",loyalty:"AEROAL Loyalty",
    help:"Help",safety:"Safety",docs:"Documents",contacts:"Contacts",
    adults:"Adults",children:"Children",infants:"Infants",economy:"Economy",
    premium:"Comfort",business:"Business",first:"First"
  },
  zh:{
    home:"首页",flights:"航班",services:"服务",info:"信息",company:"公司",
    account:"个人中心",searchTitle:"查找您的航班",from:"出发地",to:"目的地",
    depart:"去程",return:"返程",paxClass:"乘客 / 舱位",apply:"应用",
    find:"搜索",specials:"特惠",baggage:"行李",meals:"餐食",
    lounge:"休息室",priority:"优先",extras:"附加服务",support:"支持",
    fleet:"机队",information:"信息",rules:"规则",loyalty:"AEROAL 会员",
    help:"帮助",safety:"安全",docs:"文件",contacts:"联系",
    adults:"成人",children:"儿童",infants:"婴儿",economy:"经济舱",
    premium:"高端经济",business:"商务舱",first:"头等舱"
  }
};

// currency symbols
const CURRENCY_SYMBOL = { RBX:"🪙", RUB:"₽", USD:"$", EUR:"€", CNY:"¥" };
let currentLang = localStorage.getItem(LS.lang) || "en";
let currentCur  = localStorage.getItem(LS.cur)  || "RBX";
const RUB_TO_USD = 0.011;
const RBX_PER_USD = 80;

const AIRPORT_INDEX = [
  { code: "MOW", city: "Moscow", name: "Moscow Air Hub" },
  { code: "SVO", city: "Moscow", name: "Sheremetyevo International Airport" },
  { code: "DME", city: "Moscow", name: "Domodedovo International Airport" },
  { code: "VKO", city: "Moscow", name: "Vnukovo International Airport" },
  { code: "LED", city: "Saint Petersburg", name: "Pulkovo Airport" },
  { code: "DXB", city: "Dubai", name: "Dubai International Airport" },
  { code: "DWC", city: "Dubai", name: "Al Maktoum International Airport" },
  { code: "AUH", city: "Abu Dhabi", name: "Zayed International Airport" },
  { code: "DOH", city: "Doha", name: "Hamad International Airport" },
  { code: "AYT", city: "Antalya", name: "Antalya Airport" },
  { code: "IST", city: "Istanbul", name: "Istanbul Airport" },
  { code: "SAW", city: "Istanbul", name: "Sabiha Gokcen International Airport" },
  { code: "ADB", city: "Izmir", name: "Adnan Menderes Airport" },
  { code: "BJV", city: "Bodrum", name: "Milas-Bodrum Airport" },
  { code: "DLM", city: "Dalaman", name: "Dalaman Airport" },
  { code: "BKK", city: "Bangkok", name: "Suvarnabhumi Airport" },
  { code: "DMK", city: "Bangkok", name: "Don Mueang International Airport" },
  { code: "HKT", city: "Phuket", name: "Phuket International Airport" },
  { code: "SIN", city: "Singapore", name: "Singapore Changi Airport" },
  { code: "KUL", city: "Kuala Lumpur", name: "Kuala Lumpur International Airport" },
  { code: "HKG", city: "Hong Kong", name: "Hong Kong International Airport" },
  { code: "PVG", city: "Shanghai", name: "Shanghai Pudong International Airport" },
  { code: "SHA", city: "Shanghai", name: "Shanghai Hongqiao International Airport" },
  { code: "PEK", city: "Beijing", name: "Beijing Capital International Airport" },
  { code: "PKX", city: "Beijing", name: "Beijing Daxing International Airport" },
  { code: "CAN", city: "Guangzhou", name: "Guangzhou Baiyun International Airport" },
  { code: "SZX", city: "Shenzhen", name: "Shenzhen Baoan International Airport" },
  { code: "ICN", city: "Seoul", name: "Incheon International Airport" },
  { code: "NRT", city: "Tokyo", name: "Narita International Airport" },
  { code: "HND", city: "Tokyo", name: "Haneda Airport" },
  { code: "DEL", city: "Delhi", name: "Indira Gandhi International Airport" },
  { code: "BOM", city: "Mumbai", name: "Chhatrapati Shivaji Maharaj International Airport" },
  { code: "CAI", city: "Cairo", name: "Cairo International Airport" },
  { code: "LCA", city: "Larnaca", name: "Larnaca International Airport" },
  { code: "TBS", city: "Tbilisi", name: "Tbilisi International Airport" },
  { code: "EVN", city: "Yerevan", name: "Zvartnots International Airport" },
  { code: "BCN", city: "Barcelona", name: "Barcelona-El Prat Airport" },
  { code: "MAD", city: "Madrid", name: "Adolfo Suarez Madrid-Barajas Airport" },
  { code: "FCO", city: "Rome", name: "Leonardo da Vinci International Airport" },
  { code: "MXP", city: "Milan", name: "Milan Malpensa Airport" },
  { code: "CDG", city: "Paris", name: "Charles de Gaulle Airport" },
  { code: "NCE", city: "Nice", name: "Nice Cote d'Azur Airport" },
  { code: "LHR", city: "London", name: "Heathrow Airport" },
  { code: "LGW", city: "London", name: "Gatwick Airport" },
  { code: "AMS", city: "Amsterdam", name: "Amsterdam Airport Schiphol" },
  { code: "FRA", city: "Frankfurt", name: "Frankfurt Airport" },
  { code: "MUC", city: "Munich", name: "Munich Airport" },
  { code: "JFK", city: "New York", name: "John F. Kennedy International Airport" },
  { code: "EWR", city: "New York", name: "Newark Liberty International Airport" },
  { code: "LAX", city: "Los Angeles", name: "Los Angeles International Airport" },
  { code: "MIA", city: "Miami", name: "Miami International Airport" },
  { code: "YYZ", city: "Toronto", name: "Toronto Pearson International Airport" }
];

// ===== Apply i18n
function applyI18n(){
  document.querySelectorAll("[data-i18n]").forEach(el=>{
    const k = el.getAttribute("data-i18n");
    if(i18n[currentLang] && i18n[currentLang][k]) el.textContent = i18n[currentLang][k];
  });
}

// ===== Lang/Currency selects
const langSel = document.getElementById("langSelect");
const curSel  = document.getElementById("curSelect");
if(langSel){ langSel.value = currentLang; langSel.addEventListener("change", e=>{
  currentLang = e.target.value; localStorage.setItem(LS.lang,currentLang); applyI18n();
});}
if(curSel){ curSel.value = currentCur; curSel.addEventListener("change", e=>{
  currentCur = e.target.value; localStorage.setItem(LS.cur,currentCur);
  // при смене валюты перерисуем цены, если мы на странице результатов
  if(document.getElementById("flightsList")) runSearchFromQuery();
});}

applyI18n();

// ===== Slider
(function slider(){
  const slides = Array.from(document.querySelectorAll(".slide"));
  if(!slides.length) return;
  let idx = 0;
  const show = i => slides.forEach((s,n)=> s.classList.toggle("active", n===i));
  const prev = document.querySelector(".sl-btn.prev");
  const next = document.querySelector(".sl-btn.next");
  prev?.addEventListener("click", ()=>{ idx=(idx-1+slides.length)%slides.length; show(idx);});
  next?.addEventListener("click", ()=>{ idx=(idx+1)%slides.length; show(idx);});
  setInterval(()=>{ idx=(idx+1)%slides.length; show(idx);}, 5000);
})();

// ===== Reveal + counters
(function revealAndCount(){
  const nodes = Array.from(document.querySelectorAll("[data-animate]"));
  if(!nodes.length) return;

  const formatNum = n => new Intl.NumberFormat("en-US").format(n);
  const animateCounter = (card)=>{
    if(card.dataset.counted === "1") return;
    const target = Number(card.getAttribute("data-count") || 0);
    const out = card.querySelector(".stat-num");
    if(!out || !Number.isFinite(target)) return;
    if(target <= 0){ out.textContent = "0"; return; }
    card.dataset.counted = "1";
    const start = performance.now();
    const duration = 900;
    const tick = (now)=>{
      const t = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - t, 3);
      const val = eased * target;
      out.textContent = Number.isInteger(target)
        ? formatNum(Math.floor(val))
        : val.toFixed(1);
      if(t < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };

  const io = new IntersectionObserver(entries=>{
    entries.forEach(entry=>{
      if(!entry.isIntersecting) return;
      entry.target.classList.add("in-view");
      if(entry.target.classList.contains("stat-card")) animateCounter(entry.target);
      io.unobserve(entry.target);
    });
  }, {threshold: 0.25});

  nodes.forEach(n=>io.observe(n));
})();

// ===== Airports autocomplete
async function fetchAirports(q){
  if(!q || q.length<2) return [];
  try{
    const r = await fetch(`${API_BASE}/airports.php?q=${encodeURIComponent(q)}`);
    if(r.ok){
      const data = await r.json();
      if(Array.isArray(data) && data.length) return data;
    }
  }catch(_e){}

  const needle = q.trim().toLowerCase();
  return AIRPORT_INDEX.filter(a => {
    const hay = `${a.code} ${a.city} ${a.name}`.toLowerCase();
    return hay.includes(needle);
  }).slice(0, 20);
}
function attachSuggest(inputId, suggestId){
  const input = document.getElementById(inputId);
  const box   = document.getElementById(suggestId);
  if(!input || !box) return;
  input.addEventListener("input", async ()=>{
    const list = await fetchAirports(input.value.trim());
    box.innerHTML = ""; box.style.display = list.length ? "block" : "none";
    list.slice(0,8).forEach(a=>{
      const btn = document.createElement("button");
      btn.type="button";
      btn.textContent = `${a.code} — ${a.city} (${a.name})`;
      btn.addEventListener("click", ()=>{ input.value = a.code; box.style.display="none";});
      box.appendChild(btn);
    });
  });
  document.addEventListener("click", (e)=>{ if(!box.contains(e.target) && e.target!==input) box.style.display="none";});
}
attachSuggest("from","fromSuggest");
attachSuggest("to","toSuggest");

// ===== Pax/Class popup
(function pax(){
  const input = document.getElementById("pax");
  const pop   = document.getElementById("paxPop");
  if(!input || !pop) return;
  input.addEventListener("click", ()=> pop.style.display = "block");
  document.addEventListener("click", (e)=>{
    if(!pop.contains(e.target) && e.target!==input) pop.style.display="none";
  });
  function setVal(t,delta){
    const el = pop.querySelector(`.val[data-type="${t}"]`);
    let v = parseInt(el.textContent,10)+delta; v=Math.max( t==="adults"?1:0, v);
    el.textContent = v;
  }
  pop.querySelectorAll(".stepper button").forEach(btn=>{
    btn.addEventListener("click", ()=>{
      const act = btn.getAttribute("data-act");
      const val = btn.parentElement.querySelector(".val");
      const type= val.getAttribute("data-type");
      setVal(type, act==="plus"?+1:-1);
    });
  });
  document.getElementById("paxApply")?.addEventListener("click", ()=>{
    const a = parseInt(pop.querySelector('.val[data-type="adults"]').textContent,10);
    const c = parseInt(pop.querySelector('.val[data-type="children"]').textContent,10);
    const i = parseInt(pop.querySelector('.val[data-type="infants"]').textContent,10);
    const cls = pop.querySelector('input[name="cls"]:checked').value;
    const clsLabel = {economy:i18n[currentLang].economy,premium:i18n[currentLang].premium,business:i18n[currentLang].business,first:i18n[currentLang].first}[cls];
    input.value = `${a+c+i}, ${clsLabel}`;
    pop.style.display="none";
  });
})();

// ===== Swap
document.getElementById("swap")?.addEventListener("click", ()=>{
  const a = document.getElementById("from"); const b = document.getElementById("to");
  [a.value,b.value] = [b.value,a.value];
});

// ===== Search submit
document.getElementById("searchForm")?.addEventListener("submit",(e)=>{
  e.preventDefault();
  let from = document.getElementById("from").value.trim();
  let to   = document.getElementById("to").value.trim();
  const today = new Date().toISOString().slice(0,10);
  let dep  = document.getElementById("dep").value || today;
  const ret  = document.getElementById("ret").value;
  const pax  = document.getElementById("pax")?.value || "1, Economy";
  const firstName = (document.getElementById("firstName")?.value || "").trim().toUpperCase();
  const lastName = (document.getElementById("lastName")?.value || "").trim().toUpperCase();
  // allow quick input like "TT1-TT2" in the "from" field
  if(!to && from){
    const chunks = from.split(/[-→]/).map(x=>x.trim()).filter(Boolean);
    if(chunks.length===2){
      from = chunks[0];
      to = chunks[1];
      document.getElementById("from").value = from;
      document.getElementById("to").value = to;
    }
  }
  const q = new URLSearchParams({from,to,date:dep,ret,pax,first_name:firstName,last_name:lastName,lang:currentLang,cur:currentCur}).toString();
  localStorage.setItem(LS.form, JSON.stringify({from,to,dep,ret,pax,firstName,lastName}));
  localStorage.setItem(LS.passenger, JSON.stringify({ firstName, lastName }));
  if(location.pathname.endsWith("results.html")){
    runSearch({from,to,date:dep,ret,firstName,lastName,lang:currentLang,cur:currentCur});
  }else{
    location.href = `results.html?${q}`;
  }
});

// ===== On results page: run search
function parseQS(){
  const p = new URLSearchParams(location.search);
  const today = new Date().toISOString().slice(0,10);
  return {
    from: p.get("from")||"",
    to:   p.get("to")||"",
    date: p.get("date")||today,
    ret:  p.get("ret")||"",
    firstName: (p.get("first_name") || "").trim().toUpperCase(),
    lastName: (p.get("last_name") || "").trim().toUpperCase(),
    lang: "en",
    cur:  p.get("cur")||currentCur
  };
}
async function runSearchFromQuery(){ const q=parseQS(); await runSearch(q); }

async function runSearch({from,to,date,ret,firstName,lastName,lang,cur}){
  currentLang = (lang || "en");
  const savedPassenger = JSON.parse(localStorage.getItem(LS.passenger) || "{}");
  const resolvedFirstName = (firstName ?? document.getElementById("firstName")?.value ?? savedPassenger.firstName ?? "").toString().trim().toUpperCase();
  const resolvedLastName = (lastName ?? document.getElementById("lastName")?.value ?? savedPassenger.lastName ?? "").toString().trim().toUpperCase();
  // заполнить поля формы
  ["from","to","dep","ret","firstName","lastName"].forEach(id=>{
    const el = document.getElementById(id);
    if(el){ el.value = ({from,to,dep:date,ret,firstName:resolvedFirstName,lastName:resolvedLastName})[id] || ""; }
  });
  // заголовок
  const rt = document.getElementById("routeTitle");
  if(rt) rt.textContent = `${from || "—"} → ${to || "—"}`;

  // неделя бар
  await renderWeek(date, from, to, cur, lang);

  // запрос рейсов
  const url = `${API_BASE}/searchFlights.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&date=${encodeURIComponent(date)}&currency=${encodeURIComponent(cur)}&lang=${encodeURIComponent(lang)}`;
  let data = [];
  let statusText = "Searching flights...";
  try{
    const r = await fetch(url);
    data = r.ok ? await r.json() : [];
    if(!r.ok){
      statusText = "Server returned an error while searching flights.";
    }
  }catch(_e){
    data = [];
    statusText = "Server error while searching flights.";
  }
  if(!Array.isArray(data) || !data.length){
    data = await loadFlightsFromFile({from,to,date,cur});
  }
  if(Array.isArray(data) && data.length){
    statusText = `${data.length} flight${data.length === 1 ? "" : "s"} found.`;
  } else if(statusText === "Searching flights...") {
    statusText = "No flights found for the selected date.";
  }
  const searchStatus = document.getElementById("searchStatus");
  if(searchStatus) searchStatus.textContent = statusText;
  renderFlights(data, cur);
}

async function renderWeek(date, from, to, cur, lang){
  const bar = document.getElementById("weekBar"); if(!bar) return;
  const base = date ? new Date(date) : new Date();
  // создаём 7 дней
  bar.innerHTML = "";
  for(let i=0;i<7;i++){
    const d = new Date(base); d.setDate(base.getDate()+i);
    const dStr = d.toISOString().slice(0,10);
    const btn = document.createElement("button");
    btn.innerHTML = `<div>${d.toLocaleDateString(lang,{weekday:"short"})}</div><strong>${d.toLocaleDateString(lang,{day:"2-digit",month:"2-digit"})}</strong><div class="min">from —</div>`;
    if(dStr === (date||"")) btn.classList.add("active");
    btn.addEventListener("click", ()=>{
      document.getElementById("dep").value = dStr;
      runSearch({
        from,
        to,
        date:dStr,
        ret: document.getElementById("ret")?.value || "",
        firstName: document.getElementById("firstName")?.value || "",
        lastName: document.getElementById("lastName")?.value || "",
        lang,
        cur
      });
    });
    bar.appendChild(btn);
  }
  // (опционально) можно запросить минимальные цены по дням у API и подставить в ".min"
}

// рендер карточек рейсов
function renderFlights(list, cur){
  const box = document.getElementById("flightsList"); if(!box) return;
  box.innerHTML = "";
  if(!Array.isArray(list) || !list.length){
    box.innerHTML = `<div class="flight"><div>No flights found</div></div>`;
    return;
  }
  list.forEach(f=>{
    const duration = f.duration_label || humanDuration(f.departure_time, f.arrival_time);
    const classes = [
      {key:"economy",  name:i18n[currentLang].economy,  fare:f.fares.economy},
      {key:"comfort",  name:i18n[currentLang].premium,  fare:f.fares.comfort},
      {key:"business", name:i18n[currentLang].business, fare:f.fares.business},
      {key:"first",    name:i18n[currentLang].first,    fare:f.fares.first}
    ];
    const defaultFareKey = firstAvailableFareKey(f.fares);
    const el = document.createElement("div");
    el.className = "flight";
    el.innerHTML = `
      <div class="flight-topline">
        <div class="times">
        <div><strong>${timeOnly(f.departure_time)}</strong> → <strong>${timeOnly(f.arrival_time)}</strong></div>
        <div class="line"></div>
        <div class="meta">${f.flight_number} · ${f.aircraft} · ${duration}</div>
      </div>
        <button type="button" class="choose choose-primary">Book</button>
      </div>
      <div class="meta">
        ${f.origin} → ${f.destination}
      </div>
      <div class="fares">
        ${classes.map(c=>{
          if(!c.fare) return `<div class="fare sold"><span class="name">${c.name}</span><div class="price">—</div><div class="left">Sold out</div></div>`;
          const sold = Number(c.fare.left)<=0;
          const priceRub = getFareRubPrice(c.fare, f.currency);
          return `<div class="fare ${sold?'sold':''} ${c.key === defaultFareKey ? 'active' : ''}" data-fare="${c.key}">
            <span class="name">${c.name}</span>
            <div class="price">${fmtDualPrice(priceRub)}</div>
            <div class="left">${sold?'Sold out':('left: '+c.fare.left)}</div>
            <button type="button" class="fare-book" data-book-fare="${c.key}" ${sold?'disabled':''}>Book</button>
          </div>`;
        }).join("")}
      </div>
    `;
    const fares = Array.from(el.querySelectorAll(".fare:not(.sold)"));
    fares.forEach(card=>{
      card.addEventListener("click", (event)=>{
        if(event.target.closest(".fare-book")) return;
        fares.forEach(x=>x.classList.remove("active"));
        card.classList.add("active");
      });
    });
    const openBooking = (fareKey) => {
      const selected = fareKey ? el.querySelector(`.fare[data-fare="${fareKey}"]`) : (el.querySelector(".fare.active") || fares[0]);
      const finalFareKey = fareKey || selected?.getAttribute("data-fare") || defaultFareKey || "economy";
      fares.forEach(x=>x.classList.toggle("active", x.getAttribute("data-fare") === finalFareKey));
      const offer = buildOfferFromFlight(f, finalFareKey);
      localStorage.setItem(LS.offer, JSON.stringify(offer));
      createBookingAndRedirect(offer);
    };
    el.querySelector(".choose")?.addEventListener("click", ()=> openBooking(defaultFareKey || "economy"));
    el.querySelectorAll(".fare-book").forEach(btn => {
      btn.addEventListener("click", (event) => {
        event.stopPropagation();
        openBooking(btn.getAttribute("data-book-fare") || defaultFareKey || "economy");
      });
    });
    box.appendChild(el);
  });
}

function firstAvailableFareKey(fares){
  const order = ["economy","comfort","business","first"];
  return order.find(key => Number(fares?.[key]?.left || 0) > 0) || "";
}

function buildOfferFromFlight(flight, fareKey){
  const fareName = ({economy:"Economy",comfort:"Comfort",business:"Business",first:"First"})[fareKey] || "Economy";
  const selectedFare = flight.fares?.[fareKey] || null;
  const farePriceRub = getFareRubPrice(selectedFare, flight.currency);
  const passengerDraft = JSON.parse(localStorage.getItem(LS.passenger) || "{}");
  const paxRaw = document.getElementById("pax")?.value || "1, Economy";
  const paxCount = Math.max(1, parseInt(String(paxRaw).split(",")[0], 10) || 1);
  const firstName = (document.getElementById("firstName")?.value || passengerDraft.firstName || "").trim().toUpperCase();
  const lastName = (document.getElementById("lastName")?.value || passengerDraft.lastName || "").trim().toUpperCase();
  return {
    from: flight.origin,
    to: flight.destination,
    date: (flight.departure_time || "").slice(0,10),
    departure_time: flight.departure_time,
    arrival_time: flight.arrival_time,
    flight_number: flight.flight_number,
    aircraft: flight.aircraft,
    cabin: fareName,
    price: farePriceRub,
    price_rub: farePriceRub,
    currency: "RUB",
    passengers: paxCount,
    passenger_name: [firstName, lastName].filter(Boolean).join(" ").trim(),
    last_name: lastName
  };
}
function timeOnly(dt){ return new Date(dt).toLocaleTimeString(currentLang, {hour:"2-digit",minute:"2-digit"}); }
function humanDuration(a,b){
  const ms = (new Date(b)-new Date(a)); const m = Math.round(ms/60000);
  const h = Math.floor(m/60), mm = m%60;
  return `${h}h ${mm}m`;
}
function fmtPrice(val, cur){
  const sym = CURRENCY_SYMBOL[cur] || "";
  // для RBX просто без разделителей
  if(cur==="RBX") return `${val} ${sym}`;
  return new Intl.NumberFormat(currentLang, { style:"currency", currency: cur }).format(val);
}

function toNumber(value){
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function getFareRubPrice(fare, fareCurrency){
  if(!fare) return 0;
  const directRbx = toNumber(fare.price_rbx);
  if(directRbx > 0){
    return Math.round(directRbx * ((1 / RUB_TO_USD) / RBX_PER_USD));
  }
  const directRub = toNumber(fare.price_rub);
  if(directRub > 0) return directRub;
  const converted = toNumber(fare.price_converted);
  if(converted <= 0) return 0;
  const curr = (fareCurrency || "RUB").toUpperCase();
  const toRub = { RUB:1, USD:1 / RUB_TO_USD, EUR:100, CNY:12.5, RBX:(1 / RUB_TO_USD) / RBX_PER_USD };
  return Math.round(converted * (toRub[curr] || 1));
}

function fmtDualPrice(priceRub){
  const rub = toNumber(priceRub);
  const usd = rub * RUB_TO_USD;
  const rbx = Math.round(usd * RBX_PER_USD);
  return `<span class="price-rbx">${new Intl.NumberFormat("en-US").format(rbx)} RBX</span><span class="price-usd">~$${usd.toFixed(2)}</span>`;
}

async function createBookingAndRedirect(offer){
  const params = new URLSearchParams({
    from: offer.from || "",
    to: offer.to || "",
    date: offer.date || "",
    flight: offer.flight_number || "",
    cabin: offer.cabin || "Economy",
    draft: "1"
  });
  location.href = `booking.html?${params.toString()}`;
}

function normalizeRouteCode(value){
  const raw = String(value || "").toUpperCase().trim();
  if(!raw) return "";
  const parts = raw.split(/[^A-Z0-9]+/).filter(Boolean);
  const token = parts.find(p => p.length >= 3) || parts[0] || raw;
  return token.slice(0,3);
}

function convertRub(rubAmount, currency){
  const rates = { RUB:1.0, USD:0.011, EUR:0.010, CNY:0.080, RBX:RUB_TO_USD * RBX_PER_USD };
  const curr = (currency || "USD").toUpperCase();
  const rate = rates[curr] || rates.USD;
  return Math.round(Number(rubAmount || 0) * rate);
}

function parseFlightDateTime(value, fallbackDay){
  const raw = String(value || "").trim();
  if(!raw) return "";
  if(/^\d{4}-\d{2}-\d{2}/.test(raw)){
    const normalized = raw.replace(" ", "T");
    if(/^\d{4}-\d{2}-\d{2}T\d{1,2}:\d{2}$/.test(normalized)) return `${normalized}:00`;
    if(/^\d{4}-\d{2}-\d{2}T\d{1,2}:\d{2}:\d{2}$/.test(normalized)) return normalized;
  }
  const time = raw.replace(/[^0-9:]/g, "");
  if(/^\d{1,2}:\d{2}$/.test(time)) return `${fallbackDay}T${time}:00`;
  if(/^\d{1,2}:\d{2}:\d{2}$/.test(time)) return `${fallbackDay}T${time}`;
  return "";
}

async function loadFlightsFromFile({from,to,date,cur}){
  try{
    const r = await fetch("flights.json");
    if(!r.ok) return [];
    const rows = await r.json();
    if(!Array.isArray(rows)) return [];
    const fromCode = normalizeRouteCode(from);
    const toCode = normalizeRouteCode(to);
    const day = date || new Date().toISOString().slice(0,10);
    const currency = (cur || "USD").toUpperCase();
    return rows
      .filter(x => normalizeRouteCode(x.origin)===fromCode && normalizeRouteCode(x.destination)===toCode)
      .map(x=>{
        const dep = parseFlightDateTime(x.departure, day);
        let arr = parseFlightDateTime(x.arrival, day);
        if(!dep || !arr) return null;
        if(dep.slice(0,10) !== day) return null;
        if(new Date(arr) <= new Date(dep)){
          const next = new Date(arr);
          next.setDate(next.getDate()+1);
          arr = next.toISOString().slice(0,19);
        }
        const faresRbx = x?.fares_rbx || x?.fares_robux || x?.fares_rub || {};
        const fare = (k)=>{
          const priceRbx = Number(faresRbx?.[k] || 0);
          const left = Number(x?.seats?.[k] || 0);
          if(priceRbx<=0 || left<=0) return null;
          const priceRub = Math.round(priceRbx * ((1 / RUB_TO_USD) / RBX_PER_USD));
          return { price_rbx: priceRbx, price_rub: priceRub, price_converted: convertRub(priceRub, currency), left };
        };
        return {
          origin: normalizeRouteCode(x.origin),
          destination: normalizeRouteCode(x.destination),
          flight_number: x.flight_number || "ARL",
          departure_time: dep,
          arrival_time: arr,
          aircraft: x.aircraft || "Aircraft",
          currency,
          fares: {
            economy: fare("economy"),
            comfort: fare("comfort"),
            business: fare("business"),
            first: fare("first")
          }
        };
      })
      .filter(Boolean);
  }catch(_e){
    return [];
  }
}

// авто-запуск поиска на странице результатов
if(document.getElementById("flightsList")) runSearchFromQuery();
