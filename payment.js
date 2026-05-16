const PAYMENT_BOOKING_KEY = "aeroal_payment_booking";
const SELECTED_OFFER_KEY = "aeroal_selected_offer";
const ROBUX_PAYMENT_URL = "https://www.roblox.com/games/122473260164056/AEROAL-Payment-Hub";
const BOOKING_API = "booking_api.php";
const CRYPTO_PAY_API = "crypto_pay.php";
const CRYPTO_INVOICE_KEY = "aeroal_crypto_invoice";

const DEMO_DB = {
  ARL92Q: {
    pnr: "ARL92Q",
    route: "MOW -> DXB",
    flight: "ARL 524",
    departure: "2026-03-20T02:15:00",
    cabin: "Economy",
    passengers: 2,
    currency: "USD",
    base: 750,
    fee: 15,
    tax: 35,
    paid: false
  }
};

const state = { booking: null, cryptoPollTimer: null };
const RUB_TO_USD = 0.011;
const RBX_PER_USD = 80;

function q(id){ return document.getElementById(id); }

function toRub(value, currency = "RUB"){
  const n = Number(value || 0);
  const curr = String(currency || "RUB").toUpperCase();
  const toRubRate = { RUB: 1, USD: 1 / RUB_TO_USD, EUR: 100, CNY: 12.5, RBX: (1 / RUB_TO_USD) / RBX_PER_USD };
  return n * (toRubRate[curr] || 1);
}

function rubToDual(rub){
  const usd = rub * RUB_TO_USD;
  const rbx = Math.round(usd * RBX_PER_USD);
  return { usd, rbx };
}

function fmtMoney(value, currency){
  const rub = toRub(value, currency);
  const { usd, rbx } = rubToDual(rub);
  return `<span class="money-rbx">${new Intl.NumberFormat("en-US").format(rbx)} RBX</span><span class="money-usd">~$${usd.toFixed(2)}</span>`;
}

function showMsg(id, text, error = false){
  const el = q(id);
  if(!el) return;
  el.textContent = text;
  el.style.color = error ? "#b42318" : "#0b3707";
}

async function bookingApi(action, payload = {}) {
  const r = await fetch(BOOKING_API, {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify({ action, ...payload })
  });
  const data = await r.json();
  if(!r.ok || !data?.ok) throw new Error(data?.error || "API error");
  return data;
}

async function cryptoPayApi(action, payload = {}) {
  const r = await fetch(CRYPTO_PAY_API, {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify({ action, ...payload })
  });
  const data = await r.json();
  if(!r.ok || !data?.ok) throw new Error(data?.error || "Crypto API error");
  return data;
}

function mapApiBooking(b){
  const baseRub = Number(b.base_rub || 0);
  const extrasRub = Number(b.extras_rub || 0);
  const totalRub = Number(b.total_rub || Math.max(0, baseRub + extrasRub - Number(b.discount_rub || 0)));
  return {
    pnr: b.pnr,
    lastName: b.last_name || "GUEST",
    route: `${b.origin || ""} -> ${b.destination || ""}`.trim(),
    flight: b.flight_number || "ARL",
    departure: b.departure_time || new Date().toISOString(),
    cabin: b.cabin || "Economy",
    passengers: Number(b.passengers || 1),
    currency: "RUB",
    base: baseRub,
    fee: extrasRub,
    tax: 0,
    totalRub,
    paid: Boolean(b.paid),
    paymentStatus: b.payment_status || (Boolean(b.paid) ? "paid" : "pending")
  };
}

function parseQuery(){
  const p = new URLSearchParams(location.search);
  return { pnr: (p.get("pnr") || "").toUpperCase() };
}

function buildFromOffer(offer){
  const base = Math.max(0, Number(offer?.price_rub || offer?.price || 0));
  const fee = Math.round(base * 0.02);
  const tax = Math.round(base * 0.05);
  return {
    pnr: `ARL${Math.floor(1000 + Math.random() * 9000)}`,
    route: `${offer?.from || "MOW"} -> ${offer?.to || "DXB"}`,
    flight: offer?.flight_number || "ARL",
    departure: offer?.departure_time || new Date().toISOString(),
    cabin: offer?.cabin || "Economy",
    passengers: 1,
    currency: "RUB",
    base,
    fee,
    tax,
    paid: false
  };
}

function buildFromBookingPayload(payload){
  const base = Math.max(0, Number(payload?.base || payload?.price || 0));
  const fee = Math.round(base * 0.02);
  const tax = Math.round(base * 0.05);
  return {
    pnr: (payload?.pnr || `ARL${Math.floor(1000 + Math.random() * 9000)}`).toUpperCase(),
    route: payload?.route || "MOW -> DXB",
    flight: payload?.flight || payload?.flight_number || "ARL",
    departure: payload?.departure || payload?.departure_time || new Date().toISOString(),
    cabin: payload?.cabin || "Economy",
    passengers: Number(payload?.passengers || 1),
    currency: (payload?.currency || "USD").toUpperCase(),
    base,
    fee,
    tax,
    paid: Boolean(payload?.paid)
  };
}

function detectInitialBooking(){
  const query = parseQuery();

  const rawPayment = localStorage.getItem(PAYMENT_BOOKING_KEY);
  if(rawPayment){
    try{
      const parsed = JSON.parse(rawPayment);
      const booking = buildFromBookingPayload(parsed);
      if(!query.pnr || booking.pnr === query.pnr) return booking;
    }catch(_e){}
  }

  const rawOffer = localStorage.getItem(SELECTED_OFFER_KEY);
  if(rawOffer){
    try{ return buildFromOffer(JSON.parse(rawOffer)); }catch(_e){}
  }

  if(query.pnr && DEMO_DB[query.pnr]) return { ...DEMO_DB[query.pnr] };
  return null;
}

function openWorkspace(){
  q("paymentWorkspace")?.classList.remove("hidden");
}

function savePaymentState(){
  if(!state.booking) return;
  localStorage.setItem(PAYMENT_BOOKING_KEY, JSON.stringify(state.booking));
}

function renderSummary(){
  const b = state.booking;
  if(!b) return;
  const total = Number(b.totalRub || (b.base + b.fee + b.tax));

  q("sumPnr").textContent = b.pnr;
  q("sumRoute").textContent = b.route.replace("->", "→");
  q("sumFlight").textContent = b.flight;
  q("sumDeparture").textContent = new Date(b.departure).toLocaleString("en-GB", { hour12:false });
  q("sumCabin").textContent = b.cabin;
  q("sumPax").textContent = String(b.passengers);

  q("payBase").innerHTML = fmtMoney(b.base, b.currency);
  q("payFee").innerHTML = fmtMoney(b.fee, b.currency);
  q("payTax").innerHTML = fmtMoney(b.tax, b.currency);
  q("payTotal").innerHTML = fmtMoney(total, b.currency);

  setPaymentStatus(Boolean(b.paid), b.paymentStatus);

  q("paymentPnrInput").value = b.pnr;
  q("returnBookingBtn").href = `booking.html?pnr=${encodeURIComponent(b.pnr)}&last=${encodeURIComponent(b.lastName || "GUEST")}`;
}

function setResultVisible(text){
  q("paymentResult")?.classList.remove("hidden");
  q("paymentResultText").textContent = text;
}

function setPaymentStatus(isPaid, paymentStatus = ""){
  const status = q("payStatus");
  if(!status) return;
  if(isPaid){
    status.textContent = "Status: Paid";
    status.classList.add("payment-status--paid");
    status.classList.remove("payment-status--pending");
    return;
  }
  status.textContent = paymentStatus === "pending" ? "Status: External payment pending" : "Status: External payment pending";
  status.classList.add("payment-status--pending");
  status.classList.remove("payment-status--paid");
}

function openRobuxPayment(){
  if(!state.booking){
    showMsg("paymentMethodMsg", "Load booking first.", true);
    return;
  }
  const total = state.booking.base + state.booking.fee + state.booking.tax;
  const rubTotal = toRub(total, state.booking.currency);
  const { usd, rbx } = rubToDual(rubTotal);
  const url = new URL(ROBUX_PAYMENT_URL);
  url.searchParams.set("pnr", state.booking.pnr);
  url.searchParams.set("amount_rbx", String(rbx));
  url.searchParams.set("amount_usd", usd.toFixed(2));
  url.searchParams.set("currency", "RBX");

  window.open(url.toString(), "_blank", "noopener");
  showMsg("paymentMethodMsg", "Robux payment opened in a new tab.");
  setResultVisible(`Robux payment page opened for booking ${state.booking.pnr}.`);
  bookingApi("mark_paid", { pnr: state.booking.pnr }).catch(()=>{});
}

function stopCryptoPolling(){
  if(state.cryptoPollTimer){
    clearInterval(state.cryptoPollTimer);
    state.cryptoPollTimer = null;
  }
}

function saveCryptoInvoiceMeta(meta){
  if(!meta || !state.booking?.pnr) return;
  const all = JSON.parse(localStorage.getItem(CRYPTO_INVOICE_KEY) || "{}");
  all[state.booking.pnr] = meta;
  localStorage.setItem(CRYPTO_INVOICE_KEY, JSON.stringify(all));
}

function getCryptoInvoiceMeta(pnr){
  const all = JSON.parse(localStorage.getItem(CRYPTO_INVOICE_KEY) || "{}");
  return all[pnr] || null;
}

function markBookingPaidLocal(){
  if(!state.booking) return;
  state.booking.paid = true;
  savePaymentState();
  setPaymentStatus(true);
}

async function checkCryptoInvoiceStatus(invoiceId, silent = false){
  if(!state.booking || !invoiceId) return false;
  try{
    const syncData = await cryptoPayApi("sync_paid", {
      invoice_id: Number(invoiceId),
      pnr: state.booking.pnr
    });
    const invoice = syncData?.invoice || null;
    const status = String(invoice?.status || "");
    if(status === "paid" || syncData?.synced){
      markBookingPaidLocal();
      setResultVisible(`Crypto payment confirmed for booking ${state.booking.pnr}.`);
      showMsg("paymentMethodMsg", "Crypto payment confirmed.");
      stopCryptoPolling();
      return true;
    }
    if(!silent){
      showMsg("paymentMethodMsg", `Invoice status: ${status || "active"}.`);
    }
  }catch(err){
    if(!silent){
      showMsg("paymentMethodMsg", `Unable to check invoice: ${err.message}`, true);
    }
  }
  return false;
}

function startCryptoPolling(invoiceId){
  stopCryptoPolling();
  let attempts = 0;
  state.cryptoPollTimer = setInterval(async () => {
    attempts += 1;
    const paid = await checkCryptoInvoiceStatus(invoiceId, true);
    if(paid || attempts >= 45){
      stopCryptoPolling();
      if(!paid){
        showMsg("paymentMethodMsg", "Crypto invoice is still pending. You can continue later.");
      }
    }
  }, 8000);
}

async function openCryptoPayment(){
  if(!state.booking){
    showMsg("paymentMethodMsg", "Load booking first.", true);
    return;
  }
  const total = state.booking.base + state.booking.fee + state.booking.tax;
  const rubTotal = toRub(total, state.booking.currency);
  const usdAmount = Math.max(0.01, rubTotal * RUB_TO_USD);
  const returnUrl = `${location.origin}${location.pathname}?pnr=${encodeURIComponent(state.booking.pnr)}`;

  try{
    const data = await cryptoPayApi("create_invoice", {
      pnr: state.booking.pnr,
      amount_usd: Number(usdAmount.toFixed(2)),
      swap_to: "USDT",
      return_url: returnUrl
    });
    const inv = data?.invoice || {};
    const invoiceId = Number(inv.invoice_id || 0);
    const payUrl = inv.web_app_invoice_url || inv.mini_app_invoice_url || inv.bot_invoice_url;
    if(!payUrl) throw new Error("No payment URL returned");

    if(invoiceId > 0){
      saveCryptoInvoiceMeta({
        invoice_id: invoiceId,
        hash: inv.hash || "",
        created_at: new Date().toISOString()
      });
      startCryptoPolling(invoiceId);
    }

    window.open(payUrl, "_blank", "noopener");
    showMsg("paymentMethodMsg", "Crypto invoice opened in a new tab.");
    setResultVisible(`Crypto invoice created for booking ${state.booking.pnr}.`);
  }catch(err){
    showMsg("paymentMethodMsg", `Crypto payment unavailable: ${err.message}`, true);
  }
}

function handleLookupSubmit(e){
  e.preventDefault();
  const code = q("paymentPnrInput").value.trim().toUpperCase();
  if(!code){
    showMsg("paymentLookupMsg", "Enter booking code.", true);
    return;
  }

  if(state.booking && state.booking.pnr === code){
    openWorkspace();
    renderSummary();
    showMsg("paymentLookupMsg", `Booking ${code} loaded.`);
    return;
  }

  bookingApi("get", { pnr: code })
    .then((data) => {
      state.booking = mapApiBooking(data.booking);
      savePaymentState();
      openWorkspace();
      renderSummary();
      showMsg("paymentLookupMsg", `Booking ${code} loaded from database.`);
      const meta = getCryptoInvoiceMeta(code);
      if(meta?.invoice_id){
        checkCryptoInvoiceStatus(meta.invoice_id, true);
        startCryptoPolling(meta.invoice_id);
      }
    })
    .catch(() => {
      const demo = DEMO_DB[code];
      if(demo){
        state.booking = { ...demo };
        savePaymentState();
        openWorkspace();
        renderSummary();
        showMsg("paymentLookupMsg", `Booking ${code} loaded.`);
        return;
      }
      showMsg("paymentLookupMsg", "Booking not found.", true);
    });
}

function setupActions(){
  q("paymentLookupForm")?.addEventListener("submit", handleLookupSubmit);
  q("robuxPayBtn")?.addEventListener("click", openRobuxPayment);
  q("cryptoPayBtn")?.addEventListener("click", openCryptoPayment);
  q("cancelPaymentBtn")?.addEventListener("click", ()=>{ location.href = "booking.html"; });
}

document.addEventListener("DOMContentLoaded", ()=>{
  setupActions();
  const query = parseQuery();
  if(query.pnr){
    bookingApi("get", { pnr: query.pnr })
      .then((data) => {
        state.booking = mapApiBooking(data.booking);
        savePaymentState();
        openWorkspace();
        renderSummary();
        showMsg("paymentLookupMsg", `Booking ${state.booking.pnr} loaded from database.`);
        const meta = getCryptoInvoiceMeta(state.booking.pnr);
        if(meta?.invoice_id){
          checkCryptoInvoiceStatus(meta.invoice_id, true);
          startCryptoPolling(meta.invoice_id);
        }
      })
      .catch(() => {
        const initial = detectInitialBooking();
        if(initial){
          state.booking = initial;
          savePaymentState();
          openWorkspace();
          renderSummary();
          showMsg("paymentLookupMsg", `Booking ${initial.pnr} loaded.`);
          const meta = getCryptoInvoiceMeta(initial.pnr);
          if(meta?.invoice_id){
            checkCryptoInvoiceStatus(meta.invoice_id, true);
            startCryptoPolling(meta.invoice_id);
          }
        }
      });
    return;
  }

  const initial = detectInitialBooking();
  if(initial){
    state.booking = initial;
    savePaymentState();
    openWorkspace();
    renderSummary();
    showMsg("paymentLookupMsg", `Booking ${initial.pnr} loaded.`);
    const meta = getCryptoInvoiceMeta(initial.pnr);
    if(meta?.invoice_id){
      checkCryptoInvoiceStatus(meta.invoice_id, true);
      startCryptoPolling(meta.invoice_id);
    }
  }
});
