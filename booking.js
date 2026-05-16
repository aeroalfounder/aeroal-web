const DB = {
  ARL92Q: {
    pnr: "ARL92Q",
    lastName: "IVANOV",
    status: "confirmed",
    paid: false,
    route: "MOW (SVO) -> DXB (DXB)",
    date: "20 Mar 2026",
    cabin: "Economy",
    passengers: [
      { id: "P1", fullName: "IVANOV IVAN", type: "Adult", doc: "7711 123456", ticket: "176-9901123001" },
      { id: "P2", fullName: "IVANOVA MARIA", type: "Adult", doc: "7711 123457", ticket: "176-9901123002" }
    ],
    contacts: { email: "ivanov@email.com", phone: "+7 900 123-45-67", note: "" },
    segments: [
      {
        code: "ARL524",
        from: "Moscow, SVO C",
        to: "Dubai, DXB 1",
        departure: "20.03.2026 02:15",
        arrival: "20.03.2026 09:10",
        duration: "5h 55m",
        aircraft: "Boeing 737-800",
        terminal: "SVO C -> DXB 1",
        baggage: "1PC 23kg + cabin baggage 10kg",
        fareRules: "Refund with 60 EUR fee, reissue with 40 EUR fee"
      }
    ],
    seats: { P1: "12A", P2: "12B" },
    bags: { P1: 0, P2: 1 },
    meals: { P1: "Standard", P2: "Vegetarian" },
    prices: { base: 64384, bagUnit: 4500, mealUnit: 1200, currency: "RUB" }
  }
};

const state = {
  booking: null,
  original: null,
  selectedOffer: null,
  isDraft: false,
  promo: { code: "", discountRub: 0 }
};

const SELECTED_OFFER_KEY = "aeroal_selected_offer";
const PASSENGER_DRAFT_KEY = "aeroal_passenger_draft";
const BOOKING_API = "booking_api.php";
const RUB_TO_USD = 0.011;
const RBX_PER_USD = 80;

function clone(obj) {
  return JSON.parse(JSON.stringify(obj));
}

function toRub(value, currency = "RUB") {
  const n = Number(value || 0);
  const curr = String(currency || "RUB").toUpperCase();
  const toRubRate = { RUB: 1, USD: 1 / RUB_TO_USD, EUR: 100, CNY: 12.5, RBX: (1 / RUB_TO_USD) / RBX_PER_USD };
  return n * (toRubRate[curr] || 1);
}

function fmtMoney(value, currency = "RUB") {
  const rub = toRub(value, currency);
  const usd = rub * RUB_TO_USD;
  const rbx = Math.round(usd * RBX_PER_USD);
  return `<span class="money-rbx">${new Intl.NumberFormat("en-US").format(rbx)} RBX</span><span class="money-usd">~$${usd.toFixed(2)}</span>`;
}

function q(id) {
  return document.getElementById(id);
}

function showMessage(id, msg, isError = false) {
  const el = q(id);
  if (!el) return;
  el.textContent = msg;
  el.style.color = isError ? "#b42318" : "#0b3707";
}

function setDraftUi() {
  const createBox = document.querySelector(".booking-create");
  if (createBox) createBox.style.display = state.isDraft ? "" : "none";
  if (q("bookNowBtn")) q("bookNowBtn").style.display = state.isDraft ? "" : "none";
  if (q("saveAllBtn")) q("saveAllBtn").style.display = state.isDraft ? "none" : "";
  if (q("payNowBtn")) q("payNowBtn").disabled = state.isDraft;
}

async function bookingApi(action, payload = {}) {
  const r = await fetch(BOOKING_API, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action, ...payload })
  });
  const data = await r.json();
  if (!r.ok || !data?.ok) throw new Error(data?.error || "API error");
  return data;
}

function mapApiBooking(b) {
  const dep = b.departure_time || "";
  const arr = b.arrival_time || "";
  let extra = {};
  try {
    extra = b.data_json ? JSON.parse(b.data_json) : {};
  } catch (_e) {
    extra = {};
  }
  const paxCount = Math.max(1, Number(b.passengers || 1));
  const defaultSeats = {};
  const defaultBags = {};
  const defaultMeals = {};
  for (let i = 1; i <= paxCount; i += 1) {
    defaultSeats[`P${i}`] = "10A";
    defaultBags[`P${i}`] = 0;
    defaultMeals[`P${i}`] = "Standard";
  }
  const promoCode = String(extra?.promo?.code || "");
  const promoDiscount = Math.max(0, Number(extra?.promo?.discount_rub || 0));
  const durationLabel = b.duration_label || "Direct";
  return {
    pnr: b.pnr || "",
    lastName: b.last_name || "GUEST",
    status: (b.status || "confirmed").toLowerCase(),
    paid: Boolean(b.paid),
    route: `${b.origin || ""} -> ${b.destination || ""}`.trim(),
    date: dep ? new Date(dep).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" }) : "",
    cabin: b.cabin || "Economy",
    passengers: Array.from({ length: paxCount }).map((_, i) => ({
      id: `P${i + 1}`,
      fullName: i === 0 ? (b.passenger_name || "PRIMARY PASSENGER") : `PASSENGER ${i + 1}`,
      type: "Adult",
      doc: "",
      ticket: `${b.pnr || "ARL"}-${String(i + 1).padStart(2, "0")}`
    })),
    contacts: { email: b.contact_email || "", phone: b.contact_phone || "", note: b.note || "" },
    segments: [{
      code: b.flight_number || "ARL",
      from: b.origin || "",
      to: b.destination || "",
      departure: dep ? formatDateTime(dep) : "",
      arrival: arr ? formatDateTime(arr) : "",
      duration: durationLabel,
      aircraft: b.aircraft || "Aircraft",
      terminal: "TBD",
      baggage: "By fare rules",
      fareRules: "Standard fare conditions apply"
    }],
    seats: { ...defaultSeats, ...(extra.seats || {}) },
    bags: { ...defaultBags, ...(extra.bags || {}) },
    meals: { ...defaultMeals, ...(extra.meals || {}) },
    prices: {
      base: Number(b.base_rub || 0),
      bagUnit: 4500,
      mealUnit: 1200,
      currency: "RUB"
    },
    promo: { code: promoCode, discountRub: promoDiscount }
  };
}

function getBookingFromInputs() {
  const pnr = q("pnrInput").value.trim().toUpperCase();
  const lastName = q("lastNameInput").value.trim().toUpperCase();
  if (!pnr || !lastName) return null;
  const booking = DB[pnr];
  if (!booking) return null;
  if (booking.lastName !== lastName) return null;
  return clone(booking);
}

function mountTabs() {
  const tabs = Array.from(document.querySelectorAll(".manage-tab"));
  tabs.forEach((tab) => {
    tab.addEventListener("click", () => {
      tabs.forEach((t) => t.classList.remove("is-active"));
      tab.classList.add("is-active");
      const key = tab.dataset.tab;
      Array.from(document.querySelectorAll(".manage-panel")).forEach((panel) => {
        panel.classList.add("hidden");
      });
      q(`panel-${key}`).classList.remove("hidden");
    });
  });
}

function renderHeader() {
  const b = state.booking;
  q("bookingStatusTag").textContent = state.isDraft ? "Draft" : (b.status === "confirmed" ? "Confirmed" : "Cancelled");
  q("bookingTitle").textContent = state.isDraft ? "Booking draft" : `Booking ${b.pnr}`;
  q("bookingRoute").textContent = b.route.replace("->", "→");
  q("bookingMeta").textContent = `${b.date} · ${b.passengers.length} passenger(s) · ${b.cabin}`;
}

function renderSegments() {
  const wrap = q("segmentList");
  wrap.innerHTML = "";
  state.booking.segments.forEach((s) => {
    const card = document.createElement("article");
    card.className = "segment-card";
    card.innerHTML = `
      <h4>${s.code}: ${s.from} -> ${s.to}</h4>
      <div class="segment-grid">
        <p><span>Departure</span><strong>${s.departure}</strong></p>
        <p><span>Arrival</span><strong>${s.arrival}</strong></p>
        <p><span>Duration</span><strong>${s.duration}</strong></p>
        <p><span>Aircraft</span><strong>${s.aircraft}</strong></p>
        <p><span>Terminals</span><strong>${s.terminal}</strong></p>
        <p><span>Baggage included</span><strong>${s.baggage}</strong></p>
      </div>
      <p class="segment-rules"><span>Fare rules:</span> ${s.fareRules}</p>
    `;
    wrap.appendChild(card);
  });
}

function renderPassengers() {
  const wrap = q("passengerList");
  wrap.innerHTML = "";
  state.booking.passengers.forEach((p) => {
    const card = document.createElement("article");
    card.className = "passenger-card";
    card.innerHTML = `
      <h4>${p.fullName}</h4>
      <p><span>Type</span><strong>${p.type}</strong></p>
      <label><span>Document</span><input data-passenger="${p.id}" data-field="doc" value="${p.doc}" /></label>
      <p><span>Ticket</span><strong>${p.ticket}</strong></p>
    `;
    wrap.appendChild(card);
  });

  wrap.querySelectorAll("input[data-passenger]").forEach((input) => {
    input.addEventListener("input", (e) => {
      const pid = e.target.dataset.passenger;
      const field = e.target.dataset.field;
      const passenger = state.booking.passengers.find((x) => x.id === pid);
      passenger[field] = e.target.value;
    });
  });
}

function renderSeats() {
  const wrap = q("seatList");
  wrap.innerHTML = "";
  state.booking.passengers.forEach((p) => {
    const card = document.createElement("article");
    card.className = "mini-card";
    card.innerHTML = `
      <p>${p.fullName}</p>
      <label>
        <span>Seat</span>
        <select data-seat-for="${p.id}">
          ${["10A", "10B", "11A", "11B", "12A", "12B", "14C", "14D"].map((x) => `<option ${x === state.booking.seats[p.id] ? "selected" : ""}>${x}</option>`).join("")}
        </select>
      </label>
    `;
    wrap.appendChild(card);
  });

  wrap.querySelectorAll("select[data-seat-for]").forEach((sel) => {
    sel.addEventListener("change", (e) => {
      state.booking.seats[e.target.dataset.seatFor] = e.target.value;
    });
  });
}

function renderBags() {
  const wrap = q("bagList");
  wrap.innerHTML = "";
  state.booking.passengers.forEach((p) => {
    const card = document.createElement("article");
    card.className = "mini-card";
    card.innerHTML = `
      <p>${p.fullName}</p>
      <label>
        <span>Extra baggage pieces (23kg)</span>
        <input type="number" min="0" max="3" value="${state.booking.bags[p.id]}" data-bag-for="${p.id}" />
      </label>
    `;
    wrap.appendChild(card);
  });

  wrap.querySelectorAll("input[data-bag-for]").forEach((inp) => {
    inp.addEventListener("input", (e) => {
      const value = Math.max(0, Math.min(3, Number(e.target.value || 0)));
      state.booking.bags[e.target.dataset.bagFor] = value;
      renderPrice();
    });
  });
}

function renderMeals() {
  const wrap = q("mealList");
  const options = ["Standard", "Vegetarian", "Halal", "Kids", "Gluten-free"];
  wrap.innerHTML = "";

  state.booking.passengers.forEach((p) => {
    const card = document.createElement("article");
    card.className = "mini-card";
    card.innerHTML = `
      <p>${p.fullName}</p>
      <label>
        <span>Meal</span>
        <select data-meal-for="${p.id}">
          ${options.map((x) => `<option ${x === state.booking.meals[p.id] ? "selected" : ""}>${x}</option>`).join("")}
        </select>
      </label>
    `;
    wrap.appendChild(card);
  });

  wrap.querySelectorAll("select[data-meal-for]").forEach((sel) => {
    sel.addEventListener("change", (e) => {
      state.booking.meals[e.target.dataset.mealFor] = e.target.value;
      renderPrice();
    });
  });
}

function renderContacts() {
  q("contactEmail").value = state.booking.contacts.email;
  q("contactPhone").value = state.booking.contacts.phone;
  q("contactNote").value = state.booking.contacts.note;
  if (q("bookPassengerName")) q("bookPassengerName").value = state.booking.passengers?.[0]?.fullName || "";
  if (q("bookLastName")) q("bookLastName").value = state.booking.lastName || "";
  if (q("promoCodeInput")) q("promoCodeInput").value = state.promo.code || "";
}

function renderPrice() {
  const b = state.booking;
  const bagsCount = Object.values(b.bags).reduce((sum, n) => sum + Number(n || 0), 0);
  const paidMeals = Object.values(b.meals).filter((m) => m && m !== "Standard").length;
  const bagFare = bagsCount * b.prices.bagUnit;
  const mealFare = paidMeals * b.prices.mealUnit;
  const promoDiscount = Math.max(0, Number(state.promo.discountRub || b.promo?.discountRub || 0));
  const total = Math.max(0, b.prices.base + bagFare + mealFare - promoDiscount);

  q("baseFare").innerHTML = fmtMoney(b.prices.base, b.prices.currency);
  q("bagFare").innerHTML = fmtMoney(bagFare, b.prices.currency);
  q("mealFare").innerHTML = fmtMoney(mealFare, b.prices.currency);
  if (q("promoDiscount")) q("promoDiscount").innerHTML = fmtMoney(-promoDiscount, b.prices.currency);
  q("totalFare").innerHTML = fmtMoney(total, b.prices.currency);
  q("paymentState").textContent = `Payment status: ${b.paid ? "Paid" : "Pending"}`;
}

function renderAll() {
  setDraftUi();
  renderHeader();
  renderSegments();
  renderPassengers();
  renderSeats();
  renderBags();
  renderMeals();
  renderContacts();
  renderPrice();
}

function collectExtrasRub() {
  const bagsExtras = Object.values(state.booking.bags).reduce((s, x) => s + Number(x || 0), 0) * state.booking.prices.bagUnit;
  const mealsExtras = Object.values(state.booking.meals).filter((m) => m && m !== "Standard").length * state.booking.prices.mealUnit;
  return bagsExtras + mealsExtras;
}

function collectPassengerDetails() {
  const fullName = (q("bookPassengerName")?.value || state.booking.passengers?.[0]?.fullName || "").trim().toUpperCase();
  const lastName = (q("bookLastName")?.value || state.booking.lastName || "").trim().toUpperCase();
  localStorage.setItem(PASSENGER_DRAFT_KEY, JSON.stringify({
    firstName: fullName.split(/\s+/)[0] || "",
    lastName
  }));
  return { fullName, lastName };
}

async function applyPromoCode() {
  const code = (q("promoCodeInput")?.value || "").trim().toUpperCase();
  if (!code) {
    showMessage("promoMsg", "Enter promo code.", true);
    return;
  }
  try {
    const r = await bookingApi("apply_promo", { code, base_rub: state.booking.prices.base });
    state.promo = { code: r.promo.code, discountRub: Number(r.promo.discount_rub || 0) };
    state.booking.promo = { ...state.promo };
    renderPrice();
    showMessage("promoMsg", `Promo ${state.promo.code} applied.`);
  } catch (e) {
    state.promo = { code: "", discountRub: 0 };
    state.booking.promo = { ...state.promo };
    renderPrice();
    showMessage("promoMsg", e.message || "Promo invalid.", true);
  }
}

async function createBookingFromDraft() {
  if (!state.isDraft || !state.selectedOffer) return;
  const details = collectPassengerDetails();
  if (!details.fullName || !details.lastName) {
    showMessage("saveMsg", "Enter passenger full name and last name.", true);
    return;
  }
  const offer = {
    ...state.selectedOffer,
    passenger_name: details.fullName,
    last_name: details.lastName
  };
  try {
    const data = await bookingApi("create_from_offer", { offer });
    const pnr = (data?.pnr || "").toUpperCase();
    if (!pnr) throw new Error("PNR was not returned");
    if (state.promo.code) {
      try { await bookingApi("consume_promo", { code: state.promo.code }); } catch (_e) {}
    }
    state.isDraft = false;
    const params = new URLSearchParams({ pnr, last: details.lastName });
    history.replaceState(null, "", `booking.html?${params.toString()}`);
    const loaded = await bookingApi("get", { pnr, last_name: details.lastName });
    state.booking = mapApiBooking(loaded.booking);
    state.booking.passengers[0].fullName = details.fullName;
    state.booking.lastName = details.lastName;
    state.booking.promo = { ...state.promo };
    try {
      const updated = await bookingApi("update", {
        pnr,
        contact_email: state.booking.contacts.email,
        contact_phone: state.booking.contacts.phone,
        note: state.booking.contacts.note,
        extras_rub: collectExtrasRub(),
        discount_rub: Number(state.promo.discountRub || 0),
        data: {
          seats: state.booking.seats,
          bags: state.booking.bags,
          meals: state.booking.meals,
          promo: { code: state.promo.code || "", discount_rub: Number(state.promo.discountRub || 0) }
        }
      });
      if (updated?.booking) {
        state.booking = mapApiBooking(updated.booking);
        state.booking.passengers[0].fullName = details.fullName;
        state.booking.lastName = details.lastName;
        state.booking.promo = { ...state.promo };
      }
    } catch (_e) {}
    state.original = clone(state.booking);
    q("pnrInput").value = pnr;
    q("lastNameInput").value = details.lastName;
    renderAll();
    showMessage("saveMsg", `Booking ${pnr} created.`);
  } catch (e) {
    showMessage("saveMsg", `Booking failed: ${e.message || "Unknown error"}`, true);
  }
}

function bindGlobalActions() {
  q("contactsForm").addEventListener("submit", (e) => {
    e.preventDefault();
    state.booking.contacts.email = q("contactEmail").value.trim();
    state.booking.contacts.phone = q("contactPhone").value.trim();
    state.booking.contacts.note = q("contactNote").value.trim();
    showMessage("saveMsg", "Contacts updated.");
  });

  q("saveAllBtn").addEventListener("click", () => {
    if (state.isDraft) {
      showMessage("saveMsg", "Create booking first with Book now.", true);
      return;
    }
    state.original = clone(state.booking);
    const extras = collectExtrasRub();
    const details = collectPassengerDetails();
    const data = {
      seats: state.booking.seats,
      bags: state.booking.bags,
      meals: state.booking.meals,
      promo: { code: state.promo.code || "", discount_rub: Number(state.promo.discountRub || 0) }
    };
    bookingApi("update", {
      pnr: state.booking.pnr,
      contact_email: state.booking.contacts.email,
      contact_phone: state.booking.contacts.phone,
      note: state.booking.contacts.note,
      extras_rub: extras,
      discount_rub: Number(state.promo.discountRub || 0),
      data
    }).then((response)=>{
      if (response?.booking) {
        state.booking = mapApiBooking(response.booking);
        state.promo = { ...(state.booking.promo || state.promo) };
      }
      state.booking.lastName = details.lastName || state.booking.lastName;
      if (details.fullName) state.booking.passengers[0].fullName = details.fullName;
      state.original = clone(state.booking);
      renderAll();
      showMessage("saveMsg", "Changes saved.");
    }).catch(()=>{
      showMessage("saveMsg", "Saved locally (DB unavailable).", true);
    });
  });

  q("resetChangesBtn").addEventListener("click", () => {
    if (!state.original) return;
    state.booking = clone(state.original);
    state.promo = { ...(state.booking.promo || { code: "", discountRub: 0 }) };
    renderAll();
    showMessage("saveMsg", "Changes reset.");
  });

  q("sendItineraryBtn").addEventListener("click", () => {
    showMessage("saveMsg", "Itinerary receipt sent to email.");
  });

  q("checkinBtn").addEventListener("click", () => {
    showMessage("saveMsg", "Online check-in opens 24 hours before departure.");
  });

  q("payNowBtn").addEventListener("click", () => {
    if (state.isDraft || !state.booking.pnr) {
      showMessage("saveMsg", "Create booking first with Book now.", true);
      return;
    }
    const payload = {
      pnr: state.booking.pnr,
      route: state.booking.route,
      flight: state.booking.segments?.[0]?.code || "",
      departure: state.booking.segments?.[0]?.departure || "",
      cabin: state.booking.cabin,
      passengers: state.booking.passengers.length,
      currency: state.booking.prices.currency,
      base: Math.max(0, state.booking.prices.base + collectExtrasRub() - Number(state.promo.discountRub || 0)),
      paid: state.booking.paid
    };
    localStorage.setItem("aeroal_payment_booking", JSON.stringify(payload));
    location.href = `payment.html?pnr=${encodeURIComponent(state.booking.pnr)}`;
  });

  q("cancelBookingBtn").addEventListener("click", () => {
    const ok = window.confirm("Confirm booking cancellation?");
    if (!ok) return;
    state.booking.status = "cancelled";
    renderHeader();
    showMessage("saveMsg", "Booking cancelled.", true);
  });

  q("applyPromoBtn")?.addEventListener("click", applyPromoCode);
  q("bookNowBtn")?.addEventListener("click", createBookingFromDraft);
}

function activateWorkspace() {
  q("bookingWorkspace").classList.remove("hidden");
}

function handleLookupSubmit(e) {
  e.preventDefault();
  const pnr = q("pnrInput").value.trim().toUpperCase();
  const lastName = q("lastNameInput").value.trim().toUpperCase();
  bookingApi("get", { pnr, last_name: lastName })
    .then((data) => {
      state.isDraft = false;
      state.selectedOffer = null;
      state.booking = mapApiBooking(data.booking);
      state.promo = { ...(state.booking.promo || { code: "", discountRub: 0 }) };
      state.original = clone(state.booking);
      activateWorkspace();
      renderAll();
      showMessage("lookupMsg", `Booking ${state.booking.pnr} found.`);
    })
    .catch(() => {
      const booking = getBookingFromInputs();
      if (!booking) {
        q("bookingWorkspace").classList.add("hidden");
        showMessage("lookupMsg", "Booking not found. Check PNR and last name.", true);
        return;
      }
      showMessage("lookupMsg", `Booking ${booking.pnr} found (local mode).`);
      state.isDraft = false;
      state.selectedOffer = null;
      state.booking = booking;
      state.promo = { code: "", discountRub: 0 };
      state.original = clone(booking);
      activateWorkspace();
      renderAll();
    });
}

function preloadFromQuery() {
  const p = new URLSearchParams(window.location.search);
  const pnr = (p.get("pnr") || "").toUpperCase();
  const lastName = (p.get("last") || "").toUpperCase();
  q("pnrInput").value = pnr;
  q("lastNameInput").value = lastName;
}

function formatDateTime(dt) {
  const d = new Date(dt);
  if (Number.isNaN(d.getTime())) return dt || "";
  const date = d.toLocaleDateString("en-GB");
  const time = d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
  return `${date} ${time}`;
}

function buildBookingFromSelectedOffer(offer) {
  const dep = offer.departure_time || `${offer.date || new Date().toISOString().slice(0, 10)}T08:30:00`;
  const arr = offer.arrival_time || `${offer.date || new Date().toISOString().slice(0, 10)}T13:30:00`;
  const price = Number(offer.price_rub || offer.price || 0);
  const cabin = offer.cabin || "Economy";
  const from = offer.from || "MOW";
  const to = offer.to || "DXB";
  const passengerDraft = JSON.parse(localStorage.getItem(PASSENGER_DRAFT_KEY) || "{}");
  const lastName = String(offer.last_name || passengerDraft.lastName || "GUEST").trim().toUpperCase() || "GUEST";
  const passengerName = String(offer.passenger_name || [passengerDraft.firstName || "", lastName].filter(Boolean).join(" ") || "").trim().toUpperCase();
  const paxCount = Math.max(1, Number(offer.passengers || 1));
  return {
    pnr: "",
    lastName,
    status: "draft",
    paid: false,
    route: `${from} -> ${to}`,
    date: new Date(dep).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" }),
    cabin,
    passengers: Array.from({ length: paxCount }).map((_, index) => ({
      id: `P${index + 1}`,
      fullName: index === 0 ? passengerName : `PASSENGER ${index + 1}`,
      type: "Adult",
      doc: "",
      ticket: "-"
    })),
    contacts: { email: "", phone: "", note: "" },
    segments: [
      {
        code: offer.flight_number || "ARL000",
        from: from,
        to: to,
        departure: formatDateTime(dep),
        arrival: formatDateTime(arr),
        duration: "Direct",
        aircraft: offer.aircraft || "Aircraft not specified",
        terminal: "TBD",
        baggage: "By fare rules",
        fareRules: "Standard fare conditions apply"
      }
    ],
    seats: Object.fromEntries(Array.from({ length: paxCount }).map((_, index) => [`P${index + 1}`, "10A"])),
    bags: Object.fromEntries(Array.from({ length: paxCount }).map((_, index) => [`P${index + 1}`, 0])),
    meals: Object.fromEntries(Array.from({ length: paxCount }).map((_, index) => [`P${index + 1}`, "Standard"])),
    prices: { base: price, bagUnit: 4500, mealUnit: 1200, currency: "RUB" },
    promo: { code: "", discountRub: 0 }
  };
}

function tryAutoloadSelectedOffer() {
  const p = new URLSearchParams(window.location.search);
  const pnr = (p.get("pnr") || "").toUpperCase();
  if (pnr) {
    return bookingApi("get", { pnr })
      .then((data) => {
        state.isDraft = false;
        state.selectedOffer = null;
        state.booking = mapApiBooking(data.booking);
        state.promo = { ...(state.booking.promo || { code: "", discountRub: 0 }) };
        state.original = clone(state.booking);
        q("pnrInput").value = state.booking.pnr;
        q("lastNameInput").value = state.booking.lastName;
        activateWorkspace();
        renderAll();
        showMessage("lookupMsg", `Booking ${pnr} loaded.`);
        return true;
      })
      .catch(() => false);
  }

  const raw = localStorage.getItem(SELECTED_OFFER_KEY);
  if (!raw) return false;
  try {
    const offer = JSON.parse(raw);
    state.selectedOffer = offer;
    state.isDraft = true;
    state.booking = buildBookingFromSelectedOffer(offer);
    state.promo = { code: "", discountRub: 0 };
    state.original = clone(state.booking);
    q("pnrInput").value = "";
    q("lastNameInput").value = "";
    activateWorkspace();
    renderAll();
    showMessage("lookupMsg", `Selected flight ${offer.flight_number || ""} loaded. Complete your booking.`);
    return true;
  } catch (_e) {
    return false;
  }
}

document.addEventListener("DOMContentLoaded", () => {
  preloadFromQuery();
  mountTabs();
  bindGlobalActions();
  q("lookupForm").addEventListener("submit", handleLookupSubmit);
  Promise.resolve(tryAutoloadSelectedOffer());
});
