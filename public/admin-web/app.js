const state = {
  apiUrl: localStorage.getItem("nexora.apiUrl") || window.location.origin,
  adminToken: localStorage.getItem("nexora.adminToken") || "",
  bearer: localStorage.getItem("nexora.bearer") || "",
  busy: false,
};

const $ = (id) => document.getElementById(id);

function money(cents) {
  return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format((cents || 0) / 100);
}

function headers() {
  const result = { "Content-Type": "application/json" };
  if (state.bearer) result.Authorization = `Bearer ${state.bearer}`;
  else if (state.adminToken) result["X-Admin-Token"] = state.adminToken;
  return result;
}

async function api(path, options = {}) {
  const response = await fetch(`${state.apiUrl}${path}`, {
    ...options,
    headers: { ...headers(), ...(options.headers || {}) },
  });
  const text = await response.text();
  const data = text ? JSON.parse(text) : {};
  if (!response.ok) throw new Error(data.error || `Erro ${response.status}`);
  return data;
}

function showMessage(text, error = false) {
  const box = $("message");
  box.textContent = text;
  box.className = `message${error ? " error" : ""}`;
  window.setTimeout(() => box.classList.add("hidden"), 5500);
}

function setConnection(ok) {
  $("connection-dot").classList.toggle("ok", ok);
  $("connection-text").textContent = ok ? "online" : "offline";
}

function card(label, value) {
  return `<article class="card"><span>${label}</span><strong>${value}</strong></article>`;
}

function receiptPreview(label, mimeType, imageBase64) {
  if (!imageBase64) return `<span class="receipt-missing">${label}: pendente</span>`;
  const mime = mimeType || "image/jpeg";
  return `
    <details class="receipt-preview">
      <summary>${label}</summary>
      <img alt="${label}" src="data:${mime};base64,${imageBase64}" />
    </details>
  `;
}

async function refreshOverview() {
  const o = await api("/admin/overview");
  $("overview").innerHTML = [
    card("Usuarios totais", o.totalUsers),
    card("Pendentes", o.pendingUsers),
    card("Ativos", o.activeUsers),
    card("Solicitacoes pendentes", o.pendingRequests),
    card("Apoios pendentes", o.pendingContributions),
    card("Fotos pendentes", o.pendingReceipts),
    card("Taxa pendente", money(o.adminFeeDueCents)),
    card("Em circulacao", money(o.inCirculationCents)),
    card("Etapa roadmap", `${o.roadmapStep} / ${o.roadmapCapacity}`),
  ].join("");
}

async function refreshUsers() {
  const users = await api("/admin/users");
  $("users-body").innerHTML = users.map((u) => `
    <tr>
      <td>${u.publicId}</td>
      <td>${u.email}<br><small>${u.name}</small></td>
      <td>${u.status}</td>
      <td>
        <select id="role-${u.id}">
          ${["USER", "ADMIN", "SUPER_ADMIN"].map((role) => `<option ${u.role === role ? "selected" : ""}>${role}</option>`).join("")}
        </select>
      </td>
      <td><input id="xp-${u.id}" type="number" value="${u.xp}" /></td>
      <td><input id="level-${u.id}" type="number" value="${u.level}" /></td>
      <td><input id="buff-${u.id}" type="number" value="${u.buffBps}" /></td>
      <td><input id="fee-${u.id}" type="number" value="${u.adminFeeDueCents}" /></td>
      <td class="actions">
        <button onclick="postAction('/admin/users/${u.id}/approve')">Aprovar</button>
        <button class="danger" onclick="postAction('/admin/users/${u.id}/block')">Bloquear</button>
        <button class="secondary" onclick="postAction('/admin/users/${u.id}/confirm-admin-fee')">Baixar taxa</button>
        <button class="secondary" onclick="saveRole('${u.id}')">Role</button>
        <button class="secondary" onclick="saveReputation('${u.id}')">Reputacao</button>
      </td>
    </tr>
  `).join("");
}

async function refreshRequests() {
  const requests = await api("/admin/support-requests");
  $("requests-body").innerHTML = requests.map((r) => `
    <tr>
      <td>${r.publicCode}</td>
      <td>${r.requesterPublicId}<br><small>${r.requesterName}</small></td>
      <td>${money(r.amountCents)}</td>
      <td>${money(r.fundedCents)}</td>
      <td>${r.status}</td>
      <td>${money(r.adminFeeCents)}</td>
      <td class="actions">
        <button onclick="postAction('/admin/support-requests/${r.id}/approve')">Aprovar</button>
        <button class="danger" onclick="postAction('/admin/support-requests/${r.id}/reject')">Recusar</button>
        <button class="secondary" onclick="postAction('/admin/support-requests/${r.id}/confirm-return')">Retorno</button>
      </td>
    </tr>
  `).join("");
}

async function refreshContributions() {
  const contributions = await api("/admin/contributions");
  $("contributions-body").innerHTML = contributions.map((c) => `
    <tr>
      <td>${c.requestPublicCode}</td>
      <td>${c.donorPublicId}<br><small>para ${c.receiverPublicId}</small></td>
      <td>${money(c.amountCents)}</td>
      <td>${c.status}<br><small>ID: ${c.transactionId || "pendente"}</small></td>
      <td>
        ${receiptPreview("Envio", c.senderReceiptMimeType, c.senderReceiptImageBase64)}
        ${receiptPreview("Recebimento", c.receiverReceiptMimeType, c.receiverReceiptImageBase64)}
      </td>
      <td class="actions">
        <button ${c.evidenceComplete ? "" : "disabled"} onclick="postAction('/admin/contributions/${c.id}/confirm')">Validar Pix</button>
      </td>
    </tr>
  `).join("");
}

async function refreshAudit() {
  const logs = await api("/admin/audit-logs?limit=60");
  $("audit-list").innerHTML = logs.map((log) => `
    <div class="audit-row">
      <span>${new Date(log.createdAt).toLocaleString("pt-BR")}</span>
      <strong>${log.action}</strong>
      <span>${log.actorPublicId || "sistema"} -> ${log.target}</span>
    </div>
  `).join("");
}

async function refreshAll() {
  if (state.busy) return;
  state.busy = true;
  try {
    await Promise.all([refreshOverview(), refreshUsers(), refreshRequests(), refreshContributions(), refreshAudit()]);
    setConnection(true);
  } catch (error) {
    setConnection(false);
    showMessage(error.message, true);
  } finally {
    state.busy = false;
  }
}

async function postAction(path) {
  try {
    await api(path, { method: "POST", body: "{}" });
    showMessage("Acao aplicada.");
    await refreshAll();
  } catch (error) {
    showMessage(error.message, true);
  }
}

async function saveRole(id) {
  try {
    await api(`/admin/users/${id}/role`, {
      method: "POST",
      body: JSON.stringify({ role: $(`role-${id}`).value }),
    });
    showMessage("Role atualizado.");
    await refreshAll();
  } catch (error) {
    showMessage(error.message, true);
  }
}

async function saveReputation(id) {
  try {
    await api(`/admin/users/${id}/reputation`, {
      method: "POST",
      body: JSON.stringify({
        xp: Number($(`xp-${id}`).value),
        level: Number($(`level-${id}`).value),
        buffBps: Number($(`buff-${id}`).value),
        adminFeeDueCents: Number($(`fee-${id}`).value),
      }),
    });
    showMessage("Reputacao atualizada.");
    await refreshAll();
  } catch (error) {
    showMessage(error.message, true);
  }
}

window.postAction = postAction;
window.saveRole = saveRole;
window.saveReputation = saveReputation;

$("api-url").value = state.apiUrl;
$("admin-token").value = state.adminToken;

$("save-token").addEventListener("click", async () => {
  state.apiUrl = $("api-url").value.trim().replace(/\/$/, "");
  state.adminToken = $("admin-token").value.trim();
  state.bearer = "";
  localStorage.setItem("nexora.apiUrl", state.apiUrl);
  localStorage.setItem("nexora.adminToken", state.adminToken);
  localStorage.removeItem("nexora.bearer");
  await refreshAll();
});

$("login-button").addEventListener("click", async () => {
  try {
    state.apiUrl = $("api-url").value.trim().replace(/\/$/, "");
    const login = await fetch(`${state.apiUrl}/auth/login`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        identifier: $("login-id").value.trim(),
        password: $("login-password").value,
      }),
    });
    const data = await login.json();
    if (!login.ok) throw new Error(data.error || "Login falhou");
    state.bearer = data.token;
    state.adminToken = "";
    localStorage.setItem("nexora.apiUrl", state.apiUrl);
    localStorage.setItem("nexora.bearer", state.bearer);
    localStorage.removeItem("nexora.adminToken");
    showMessage(`Logado como ${data.profile.role}`);
    await refreshAll();
  } catch (error) {
    showMessage(error.message, true);
  }
});

$("refresh-button").addEventListener("click", refreshAll);

window.setInterval(() => {
  if ($("auto-refresh").checked) refreshAll();
}, 5000);

if (state.adminToken || state.bearer) refreshAll();
