const ACCOUNT_API = "account_api.php";
const ACCOUNT_TOKEN_KEY = "aeroal_account_token";

const state = {
  token: localStorage.getItem(ACCOUNT_TOKEN_KEY) || "",
  user: null,
  rows: [],
  loyalty: null,
  loyaltyRows: [],
  tab: "active",
  email: ""
};

function q(id) { return document.getElementById(id); }

function showMsg(id, text, error = false) {
  const el = q(id);
  if (!el) return;
  el.textContent = text;
  el.style.color = error ? "#b42318" : "#0b3707";
}

async function api(action, payload = {}) {
  const r = await fetch(ACCOUNT_API, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action, ...payload })
  });
  const data = await r.json();
  if (!r.ok || !data?.ok) throw new Error(data?.error || "API error");
  return data;
}

function isActiveBooking(row) {
  if (!row) return false;
  if ((row.status || "").toLowerCase() === "cancelled") return false;
  if (!row.departure_time) return true;
  const dep = new Date(row.departure_time);
  if (Number.isNaN(dep.getTime())) return true;
  return dep.getTime() > Date.now();
}

function bookingCard(row) {
  const dep = row.departure_time ? new Date(row.departure_time).toLocaleString("en-GB", { hour12: false }) : "-";
  const arr = row.arrival_time ? new Date(row.arrival_time).toLocaleString("en-GB", { hour12: false }) : "-";
  return `
    <article class="account-booking-card">
      <p><span>PNR</span><strong>${row.pnr || "-"}</strong></p>
      <p><span>Route</span><strong>${row.origin || "-"} → ${row.destination || "-"}</strong></p>
      <p><span>Flight</span><strong>${row.flight_number || "-"}</strong></p>
      <p><span>Cabin</span><strong>${row.cabin || "-"}</strong></p>
      <p><span>Passenger</span><strong>${row.passenger_name || row.last_name || "-"}</strong></p>
      <p><span>Status</span><strong>${row.status || "-"}</strong></p>
      <p><span>Departure</span><strong>${dep}</strong></p>
      <p><span>Arrival</span><strong>${arr}</strong></p>
      <p><span>Total</span><strong>${Number(row.total_rub || 0)} RUB</strong></p>
    </article>
  `;
}

function loyaltyHistoryCard(row) {
  const created = row.created_at ? new Date(row.created_at).toLocaleString("en-GB", { hour12: false }) : "-";
  const miles = Number(row.miles || 0);
  const sign = miles > 0 ? "+" : "";
  return `
    <article class="account-loyalty-item">
      <div>
        <p><strong>${row.description || row.type || "Loyalty transaction"}</strong></p>
        <p>${created}</p>
      </div>
      <div class="account-loyalty-item-side">
        <strong>${sign}${miles}</strong>
        <span>${row.status || "posted"}</span>
      </div>
    </article>
  `;
}

function renderBookings() {
  const list = q("accountBookingList");
  const empty = q("accountBookingEmpty");
  if (!list || !empty) return;

  const filtered = state.rows.filter((r) => (state.tab === "active" ? isActiveBooking(r) : !isActiveBooking(r)));
  q("accountBookingCount").textContent = String(state.rows.length);
  if (!filtered.length) {
    list.classList.add("hidden");
    list.innerHTML = "";
    empty.classList.remove("hidden");
    return;
  }

  empty.classList.add("hidden");
  list.classList.remove("hidden");
  list.innerHTML = filtered.map(bookingCard).join("");
}

function renderLoyalty() {
  const card = q("accountLoyaltyCard");
  const loyalty = state.loyalty;
  if (!card || !loyalty || !loyalty.enabled) {
    card?.classList.add("hidden");
    return;
  }
  card.classList.remove("hidden");
  q("loyaltyTierName").textContent = loyalty.tier_name || "Basic";
  q("loyaltyNumber").textContent = loyalty.loyalty_number || "-";
  q("loyaltyMiles").textContent = new Intl.NumberFormat("en-US").format(Number(loyalty.miles_balance || 0));
  q("loyaltyLifetime").textContent = new Intl.NumberFormat("en-US").format(Number(loyalty.miles_lifetime || 0));
  const percent = Math.max(0, Math.min(100, Number(loyalty.progress?.progress_percent || 0)));
  q("loyaltyProgressBar").style.width = `${percent}%`;
  const nextTier = loyalty.progress?.next_tier;
  q("loyaltyProgressText").textContent = nextTier
    ? `${loyalty.progress?.miles_to_next_tier || 0} miles to ${nextTier}.`
    : "Top tier reached.";
}

function renderLoyaltyHistory() {
  const list = q("loyaltyHistoryList");
  const empty = q("loyaltyHistoryEmpty");
  if (!list || !empty) return;
  if (!state.loyaltyRows.length) {
    list.classList.add("hidden");
    list.innerHTML = "";
    empty.classList.remove("hidden");
    return;
  }
  empty.classList.add("hidden");
  list.classList.remove("hidden");
  list.innerHTML = state.loyaltyRows.map(loyaltyHistoryCard).join("");
}

function setAuthedUI(authed) {
  q("accountDashboard")?.classList.toggle("hidden", !authed);
  if (authed) {
    q("requestCodeForm")?.classList.add("hidden");
    q("verifyCodeForm")?.classList.add("hidden");
    q("accountAuthMsg").textContent = "";
  } else {
    q("accountDashboard")?.classList.add("hidden");
    q("requestCodeForm")?.classList.remove("hidden");
    q("verifyCodeForm")?.classList.add("hidden");
  }
}

async function loadMeAndBookings() {
  if (!state.token) {
    setAuthedUI(false);
    return;
  }
  try {
    const me = await api("me", { token: state.token });
    state.user = me.user;
    state.loyalty = me.loyalty || null;
    q("accountUserEmail").textContent = state.user?.email || "-";
    const list = await api("list_bookings", { token: state.token });
    state.rows = Array.isArray(list.rows) ? list.rows : [];
    state.loyalty = list.loyalty || state.loyalty;
    const history = await api("loyalty_history", { token: state.token, limit: 20 });
    state.loyaltyRows = Array.isArray(history.rows) ? history.rows : [];
    state.loyalty = history.loyalty || state.loyalty;
    setAuthedUI(true);
    renderBookings();
    renderLoyalty();
    renderLoyaltyHistory();
  } catch (_e) {
    state.token = "";
    localStorage.removeItem(ACCOUNT_TOKEN_KEY);
    setAuthedUI(false);
  }
}

function setupTabs() {
  const tabs = Array.from(document.querySelectorAll(".account-tab"));
  tabs.forEach((t) => {
    t.addEventListener("click", () => {
      tabs.forEach((x) => x.classList.remove("is-active"));
      t.classList.add("is-active");
      state.tab = t.dataset.tab || "active";
      renderBookings();
    });
  });
}

function setupAuth() {
  q("requestCodeForm")?.addEventListener("submit", async (e) => {
    e.preventDefault();
    const email = (q("accountEmailInput")?.value || "").trim().toLowerCase();
    if (!email) return showMsg("accountAuthMsg", "Enter email.", true);
    state.email = email;
    try {
      const r = await api("request_code", { email });
      q("verifyCodeForm")?.classList.remove("hidden");
      showMsg("accountAuthMsg", r.debug_code ? `Code sent. Debug code: ${r.debug_code}` : `Code sent to ${email}.`);
    } catch (err) {
      showMsg("accountAuthMsg", err.message || "Failed to send code.", true);
    }
  });

  q("verifyCodeForm")?.addEventListener("submit", async (e) => {
    e.preventDefault();
    const email = (q("accountEmailInput")?.value || state.email || "").trim().toLowerCase();
    const code = (q("accountCodeInput")?.value || "").trim();
    if (!email || !code) return showMsg("accountAuthMsg", "Enter email and code.", true);
    try {
      const r = await api("verify_code", { email, code });
      state.token = r.token || "";
      localStorage.setItem(ACCOUNT_TOKEN_KEY, state.token);
      await loadMeAndBookings();
      showMsg("accountAuthMsg", "Signed in.");
    } catch (err) {
      showMsg("accountAuthMsg", err.message || "Code verification failed.", true);
    }
  });

  q("accountLogoutBtn")?.addEventListener("click", async () => {
    const token = state.token;
    state.token = "";
    state.user = null;
    state.rows = [];
    state.loyalty = null;
    state.loyaltyRows = [];
    localStorage.removeItem(ACCOUNT_TOKEN_KEY);
    setAuthedUI(false);
    try { await api("logout", { token }); } catch (_e) {}
  });
}

function setupLinkBooking() {
  q("accountLinkBookingForm")?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!state.token) return showMsg("accountLinkMsg", "Sign in first.", true);
    const pnr = (q("accountPnrInput")?.value || "").trim().toUpperCase();
    const lastName = (q("accountLastNameInput")?.value || "").trim().toUpperCase();
    if (!pnr || !lastName) return showMsg("accountLinkMsg", "Enter PNR and last name.", true);
    try {
      const linked = await api("link_booking", { token: state.token, pnr, last_name: lastName });
      const list = await api("list_bookings", { token: state.token });
      state.rows = Array.isArray(list.rows) ? list.rows : [];
      state.loyalty = linked.loyalty || list.loyalty || state.loyalty;
      renderBookings();
      renderLoyalty();
      showMsg("accountLinkMsg", `Booking ${pnr} linked.`);
      q("accountPnrInput").value = "";
      q("accountLastNameInput").value = "";
    } catch (err) {
      showMsg("accountLinkMsg", err.message || "Unable to link booking.", true);
    }
  });
}

function setupLoyaltyActions() {
  q("loyaltyRefreshBtn")?.addEventListener("click", async () => {
    if (!state.token) return;
    try {
      const summary = await api("loyalty_summary", { token: state.token });
      state.loyalty = summary.loyalty || null;
      renderLoyalty();
      showMsg("accountLinkMsg", "Loyalty summary refreshed.");
    } catch (err) {
      showMsg("accountLinkMsg", err.message || "Unable to refresh loyalty summary.", true);
    }
  });

  q("loyaltyHistoryRefreshBtn")?.addEventListener("click", async () => {
    if (!state.token) return;
    try {
      const history = await api("loyalty_history", { token: state.token, limit: 20 });
      state.loyaltyRows = Array.isArray(history.rows) ? history.rows : [];
      state.loyalty = history.loyalty || state.loyalty;
      renderLoyalty();
      renderLoyaltyHistory();
      showMsg("accountLinkMsg", "Loyalty activity refreshed.");
    } catch (err) {
      showMsg("accountLinkMsg", err.message || "Unable to refresh loyalty activity.", true);
    }
  });

  q("loyaltyCopyBtn")?.addEventListener("click", async () => {
    const number = state.loyalty?.loyalty_number || "";
    if (!number) return;
    try {
      await navigator.clipboard.writeText(number);
      showMsg("accountLinkMsg", "Loyalty number copied.");
    } catch (_err) {
      showMsg("accountLinkMsg", number);
    }
  });
}

document.addEventListener("DOMContentLoaded", () => {
  setupTabs();
  setupAuth();
  setupLinkBooking();
  setupLoyaltyActions();
  loadMeAndBookings();
});
