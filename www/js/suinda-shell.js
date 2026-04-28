function closeSuindaDrawer() {
  document.body.classList.remove("drawer-open");
}

function toggleSuindaMenu(menuId) {
  document.querySelectorAll(".app-menu.open").forEach(menu => {
    if (menu.id !== menuId) {
      menu.classList.remove("open");
    }
  });

  const menu = document.getElementById(menuId);
  if (menu) {
    menu.classList.toggle("open");
  }
}

function renderSuindaDrawer() {
  if (!document.body.dataset.shell || document.getElementById("suindaDrawer")) return;

  const drawer = document.createElement("aside");
  drawer.id = "suindaDrawer";
  drawer.className = "app-drawer";
  drawer.innerHTML = `
    <div class="drawer-brand">
      <div class="drawer-mark drawer-owl" aria-hidden="true">🦉</div>
      <div>
        <strong>Suindá</strong>
        <span>Estudo por repeticao</span>
      </div>
    </div>
    <nav class="drawer-nav" aria-label="Navegacao principal">
      <a href="decks.html" data-route="decks">Baralhos</a>
      <a href="card-browser.html" data-route="browser">Navegador de cartoes</a>
      <a href="progress.html" data-route="progress">Progresso</a>
      <a href="deck-options.html" data-route="settings">Configuracoes</a>
      <a href="#" data-route="help">Ajuda</a>
    </nav>
    <button class="drawer-logout" data-shell-logout type="button">Sair</button>
  `;

  const scrim = document.createElement("button");
  scrim.className = "drawer-scrim";
  scrim.type = "button";
  scrim.setAttribute("aria-label", "Fechar menu");
  scrim.addEventListener("click", closeSuindaDrawer);

  document.body.append(scrim, drawer);
}

function ensureSuindaDialogHost() {
  let host = document.getElementById("suindaDialogHost");

  if (!host) {
    host = document.createElement("div");
    host.id = "suindaDialogHost";
    host.className = "suinda-dialog-host";
    document.body.appendChild(host);
  }

  return host;
}

function closeSuindaDialog(result = null) {
  const host = document.getElementById("suindaDialogHost");
  const dialog = host?.querySelector(".suinda-modal");

  if (!dialog) return;

  const resolver = dialog.__suindaResolve;
  dialog.close();
  host.innerHTML = "";

  if (resolver) {
    resolver(result);
  }
}

function showSuindaConfirm({
  title = "Confirmar acao",
  message = "",
  confirmText = "OK",
  cancelText = "Cancelar",
  danger = false
} = {}) {
  return new Promise(resolve => {
    const host = ensureSuindaDialogHost();
    host.innerHTML = `
      <dialog class="suinda-modal" aria-label="${escapeSuindaShellHtml(title)}">
        <form method="dialog" class="suinda-modal-card">
          <h2>${escapeSuindaShellHtml(title)}</h2>
          ${message ? `<p>${escapeSuindaShellHtml(message)}</p>` : ""}
          <div class="suinda-modal-actions">
            ${cancelText ? `<button class="suinda-modal-secondary" data-suinda-cancel type="button">${escapeSuindaShellHtml(cancelText)}</button>` : ""}
            <button class="suinda-modal-primary ${danger ? "danger" : ""}" data-suinda-confirm type="button">${escapeSuindaShellHtml(confirmText)}</button>
          </div>
        </form>
      </dialog>
    `;

    const dialog = host.querySelector(".suinda-modal");
    dialog.__suindaResolve = resolve;
    dialog.addEventListener("cancel", event => {
      event.preventDefault();
      closeSuindaDialog(false);
    });
    host.querySelector("[data-suinda-cancel]")?.addEventListener("click", () => closeSuindaDialog(false));
    host.querySelector("[data-suinda-confirm]")?.addEventListener("click", () => closeSuindaDialog(true));
    dialog.showModal();
  });
}

function showSuindaPrompt({
  title = "Informar valor",
  label = "",
  value = "",
  placeholder = "",
  multiline = false,
  confirmText = "OK",
  cancelText = "Cancelar"
} = {}) {
  return new Promise(resolve => {
    const host = ensureSuindaDialogHost();
    const inputMarkup = multiline
      ? `<textarea id="suindaPromptInput" rows="4" placeholder="${escapeSuindaShellHtml(placeholder)}">${escapeSuindaShellHtml(value)}</textarea>`
      : `<input id="suindaPromptInput" value="${escapeSuindaShellHtml(value)}" placeholder="${escapeSuindaShellHtml(placeholder)}" />`;

    host.innerHTML = `
      <dialog class="suinda-modal" aria-label="${escapeSuindaShellHtml(title)}">
        <form method="dialog" class="suinda-modal-card">
          <h2>${escapeSuindaShellHtml(title)}</h2>
          ${label ? `<label class="suinda-prompt-label">${escapeSuindaShellHtml(label)}${inputMarkup}</label>` : inputMarkup}
          <div class="suinda-modal-actions">
            <button class="suinda-modal-secondary" data-suinda-cancel type="button">${escapeSuindaShellHtml(cancelText)}</button>
            <button class="suinda-modal-primary" data-suinda-confirm type="button">${escapeSuindaShellHtml(confirmText)}</button>
          </div>
        </form>
      </dialog>
    `;

    const dialog = host.querySelector(".suinda-modal");
    const input = host.querySelector("#suindaPromptInput");
    dialog.__suindaResolve = resolve;
    dialog.addEventListener("cancel", event => {
      event.preventDefault();
      closeSuindaDialog(null);
    });
    host.querySelector("[data-suinda-cancel]")?.addEventListener("click", () => closeSuindaDialog(null));
    host.querySelector("[data-suinda-confirm]")?.addEventListener("click", () => closeSuindaDialog(input.value));
    input.addEventListener("keydown", event => {
      if (!multiline && event.key === "Enter") {
        event.preventDefault();
        closeSuindaDialog(input.value);
      }
    });
    dialog.showModal();
    setTimeout(() => input.focus(), 60);
  });
}

function showSuindaToast(message, type = "info") {
  let toast = document.getElementById("suindaToast");

  if (!toast) {
    toast = document.createElement("div");
    toast.id = "suindaToast";
    toast.className = "suinda-toast";
    document.body.appendChild(toast);
  }

  toast.textContent = message;
  toast.dataset.type = type;
  toast.classList.add("is-visible");
  clearTimeout(toast.__suindaTimer);
  toast.__suindaTimer = setTimeout(() => {
    toast.classList.remove("is-visible");
  }, 2800);
}

function showSuindaProgress({
  title = "Processando",
  message = "Aguarde...",
  percent = 0
} = {}) {
  let overlay = document.getElementById("suindaProgressOverlay");

  if (!overlay) {
    overlay = document.createElement("div");
    overlay.id = "suindaProgressOverlay";
    overlay.className = "suinda-progress-overlay";
    overlay.innerHTML = `
      <section class="suinda-progress-card" role="status" aria-live="polite">
        <h2 data-progress-title></h2>
        <p data-progress-message></p>
        <div class="suinda-progress-track" aria-hidden="true">
          <span class="suinda-progress-fill" data-progress-fill></span>
        </div>
        <strong data-progress-percent></strong>
      </section>
    `;
    document.body.appendChild(overlay);
  }

  const update = (next = {}) => {
    const nextPercent = Math.max(0, Math.min(100, Number(next.percent ?? percent) || 0));
    if (next.title !== undefined) title = next.title;
    if (next.message !== undefined) message = next.message;
    percent = nextPercent;

    overlay.querySelector("[data-progress-title]").textContent = title;
    overlay.querySelector("[data-progress-message]").textContent = message;
    overlay.querySelector("[data-progress-fill]").style.width = `${nextPercent}%`;
    overlay.querySelector("[data-progress-percent]").textContent = `${Math.round(nextPercent)}%`;
    overlay.classList.add("is-visible");
  };

  update({ title, message, percent });

  return {
    update,
    close() {
      overlay.classList.remove("is-visible");
      setTimeout(() => {
        if (!overlay.classList.contains("is-visible")) {
          overlay.remove();
        }
      }, 180);
    }
  };
}

function escapeSuindaShellHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

document.addEventListener("DOMContentLoaded", () => {
  renderSuindaDrawer();

  document.querySelectorAll("[data-drawer-open]").forEach(button => {
    button.addEventListener("click", () => document.body.classList.add("drawer-open"));
  });

  document.querySelectorAll("[data-menu-toggle]").forEach(button => {
    button.addEventListener("click", event => {
      event.stopPropagation();
      toggleSuindaMenu(button.dataset.menuToggle);
    });
  });

  document.addEventListener("click", event => {
    if (!event.target.closest(".app-menu") && !event.target.closest("[data-menu-toggle]")) {
      document.querySelectorAll(".app-menu.open").forEach(menu => menu.classList.remove("open"));
    }
  });

  document.addEventListener("click", event => {
    const logoutButton = event.target.closest("[data-shell-logout]");
    if (!logoutButton) return;

    if (typeof logout === "function") {
      logout();
    }

    window.location.href = "login.html";
  });
});
