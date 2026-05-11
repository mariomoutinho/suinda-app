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

function showSuindaCardEditor({
  title = "Editar nota",
  fields = [],
  confirmText = "Salvar",
  cancelText = "Cancelar",
  editor = "rich"
} = {}) {
  return new Promise(resolve => {
    const host = ensureSuindaDialogHost();

    const isRich = editor === "rich";

    const fieldsMarkup = fields.map((field, index) => {
      const fieldName = escapeSuindaShellHtml(field.name || `field_${index}`);
      const labelText = escapeSuindaShellHtml(field.label || "");

      if (!isRich) {
        const id = `suindaEditorField_${index}`;
        const rows = Number(field.rows) > 0 ? Number(field.rows) : 4;
        return `
          <label class="suinda-prompt-label" for="${id}">
            ${labelText}
            <textarea
              id="${id}"
              data-suinda-field-name="${fieldName}"
              rows="${rows}"
              placeholder="${escapeSuindaShellHtml(field.placeholder || "")}"
            >${escapeSuindaShellHtml(field.value ?? "")}</textarea>
          </label>
        `;
      }

      // Rich field: contenteditable div com HTML preservado.
      // value e o HTML do campo (ja sanitizado upstream em quem chama).
      return `
        <div class="suinda-rich-field">
          <span class="suinda-rich-field-label">${labelText}</span>
          <div
            class="suinda-rich-editor"
            data-suinda-field-name="${fieldName}"
            data-suinda-rich-target="1"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            spellcheck="true"
          >${field.value ?? ""}</div>
        </div>
      `;
    }).join("");

    const toolbarMarkup = isRich ? buildSuindaRichToolbar() : "";

    host.innerHTML = `
      <dialog class="suinda-modal suinda-modal-editor ${isRich ? "suinda-modal-rich" : ""}" aria-label="${escapeSuindaShellHtml(title)}">
        <form method="dialog" class="suinda-modal-card">
          <h2>${escapeSuindaShellHtml(title)}</h2>
          ${toolbarMarkup}
          ${fieldsMarkup}
          <div class="suinda-modal-actions">
            <button class="suinda-modal-secondary" data-suinda-cancel type="button">${escapeSuindaShellHtml(cancelText)}</button>
            <button class="suinda-modal-primary" data-suinda-confirm type="button">${escapeSuindaShellHtml(confirmText)}</button>
          </div>
        </form>
      </dialog>
    `;

    const dialog = host.querySelector(".suinda-modal");
    dialog.__suindaResolve = resolve;
    dialog.addEventListener("cancel", event => {
      event.preventDefault();
      closeSuindaDialog(null);
    });
    host.querySelector("[data-suinda-cancel]")?.addEventListener("click", () => closeSuindaDialog(null));

    if (isRich) {
      setupSuindaRichToolbar(host);
    }

    host.querySelector("[data-suinda-confirm]")?.addEventListener("click", () => {
      const result = {};
      host.querySelectorAll("[data-suinda-field-name]").forEach(node => {
        if (node.dataset.suindaRichTarget) {
          result[node.dataset.suindaFieldName] = node.innerHTML;
        } else {
          result[node.dataset.suindaFieldName] = node.value;
        }
      });
      closeSuindaDialog(result);
    });
    dialog.showModal();
    setTimeout(() => host.querySelector("[data-suinda-field-name]")?.focus(), 60);
  });
}

function buildSuindaRichToolbar() {
  const tools = [
    { cmd: "bold", label: "<b>B</b>", title: "Negrito (Ctrl+B)" },
    { cmd: "italic", label: "<i>I</i>", title: "Italico (Ctrl+I)" },
    { cmd: "underline", label: "<u>U</u>", title: "Sublinhado (Ctrl+U)" },
    { cmd: "superscript", label: "x<sup>2</sup>", title: "Sobrescrito" },
    { cmd: "subscript", label: "x<sub>2</sub>", title: "Subscrito" },
    { separator: true },
    { color: "foreColor", label: "A", title: "Cor do texto" },
    { color: "hiliteColor", label: "&#9646;", title: "Cor de destaque" },
    { cmd: "removeFormat", label: "&times;", title: "Limpar formatacao" },
    { separator: true },
    { cmd: "insertUnorderedList", label: "&bull; &mdash;", title: "Lista" },
    { cmd: "insertOrderedList", label: "1.", title: "Lista numerada" },
    { align: "justifyLeft", label: "&#8676;", title: "Alinhar a esquerda" },
    { align: "justifyCenter", label: "&#8644;", title: "Centralizar" },
    { align: "justifyRight", label: "&#8677;", title: "Alinhar a direita" },
    { separator: true },
    { file: "image", label: "&#128247;", title: "Inserir imagem", accept: "image/*" },
    { file: "audio", label: "&#9836;", title: "Inserir audio", accept: "audio/*" },
    { file: "video", label: "&#127909;", title: "Inserir video", accept: "video/*" },
    { record: true, label: "&#9210;", title: "Gravar audio" },
    { formula: true, label: "fx", title: "Inserir formula LaTeX" },
  ];

  return `
    <div class="suinda-editor-toolbar" role="toolbar" aria-label="Ferramentas de formatacao">
      ${tools.map(tool => {
        if (tool.separator) return `<span class="suinda-editor-toolbar-sep" aria-hidden="true"></span>`;
        if (tool.color) {
          return `
            <label class="suinda-editor-tool suinda-editor-tool-color" title="${escapeSuindaShellHtml(tool.title)}">
              <span class="suinda-editor-tool-label">${tool.label}</span>
              <input type="color" data-suinda-color="${tool.color}" value="#1f6feb" />
            </label>
          `;
        }
        if (tool.file) {
          return `
            <button type="button" class="suinda-editor-tool" data-suinda-file="${tool.file}" data-suinda-accept="${tool.accept}" title="${escapeSuindaShellHtml(tool.title)}">${tool.label}</button>
          `;
        }
        if (tool.record) {
          return `<button type="button" class="suinda-editor-tool" data-suinda-record title="${escapeSuindaShellHtml(tool.title)}">${tool.label}</button>`;
        }
        if (tool.formula) {
          return `<button type="button" class="suinda-editor-tool" data-suinda-formula title="${escapeSuindaShellHtml(tool.title)}">${tool.label}</button>`;
        }
        if (tool.align) {
          return `<button type="button" class="suinda-editor-tool" data-suinda-align="${tool.align}" title="${escapeSuindaShellHtml(tool.title)}">${tool.label}</button>`;
        }
        return `<button type="button" class="suinda-editor-tool" data-suinda-cmd="${tool.cmd}" title="${escapeSuindaShellHtml(tool.title)}">${tool.label}</button>`;
      }).join("")}
      <input type="file" hidden data-suinda-file-input="image" accept="image/*" />
      <input type="file" hidden data-suinda-file-input="audio" accept="audio/*" />
      <input type="file" hidden data-suinda-file-input="video" accept="video/*" />
    </div>
  `;
}

function setupSuindaRichToolbar(host) {
  const editors = Array.from(host.querySelectorAll("[data-suinda-rich-target]"));
  let activeEditor = editors[0] || null;
  let savedRange = null;

  function saveSelection() {
    const sel = window.getSelection();
    if (sel && sel.rangeCount > 0) {
      const range = sel.getRangeAt(0);
      // Confirma que a selecao esta dentro de um dos editores.
      if (editors.some(ed => ed.contains(range.commonAncestorContainer))) {
        savedRange = range.cloneRange();
        activeEditor = editors.find(ed => ed.contains(range.commonAncestorContainer)) || activeEditor;
      }
    }
  }

  function restoreSelection() {
    if (!savedRange) return;
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
  }

  editors.forEach(ed => {
    ed.addEventListener("focus", () => { activeEditor = ed; });
    ed.addEventListener("keyup", saveSelection);
    ed.addEventListener("mouseup", saveSelection);
  });

  function runCommand(command, value) {
    restoreSelection();
    if (!activeEditor) return;
    activeEditor.focus();
    document.execCommand(command, false, value);
    saveSelection();
  }

  function insertHtml(html) {
    restoreSelection();
    if (!activeEditor) return;
    activeEditor.focus();
    document.execCommand("insertHTML", false, html);
    saveSelection();
  }

  // Botoes simples (preventDefault no mousedown impede que o botao roube o
  // foco do editor; a selecao continua valida durante o execCommand).
  host.querySelectorAll("[data-suinda-cmd]").forEach(btn => {
    btn.addEventListener("mousedown", event => {
      event.preventDefault();
      saveSelection();
    });
    btn.addEventListener("click", event => {
      event.preventDefault();
      runCommand(btn.dataset.suindaCmd);
    });
  });

  host.querySelectorAll("[data-suinda-align]").forEach(btn => {
    btn.addEventListener("mousedown", event => { event.preventDefault(); saveSelection(); });
    btn.addEventListener("click", event => { event.preventDefault(); runCommand(btn.dataset.suindaAlign); });
  });

  host.querySelectorAll("[data-suinda-color]").forEach(input => {
    const command = input.dataset.suindaColor;
    const wrapper = input.closest(".suinda-editor-tool");
    wrapper?.addEventListener("mousedown", event => {
      // Preserva selecao antes do picker abrir.
      if (event.target.tagName !== "INPUT") {
        event.preventDefault();
      }
      saveSelection();
    });
    input.addEventListener("input", () => runCommand(command, input.value));
  });

  host.querySelectorAll("[data-suinda-file]").forEach(btn => {
    btn.addEventListener("mousedown", event => { event.preventDefault(); saveSelection(); });
    btn.addEventListener("click", event => {
      event.preventDefault();
      const kind = btn.dataset.suindaFile;
      const input = host.querySelector(`[data-suinda-file-input="${kind}"]`);
      input?.click();
    });
  });

  host.querySelectorAll("[data-suinda-file-input]").forEach(input => {
    input.addEventListener("change", async () => {
      const file = input.files?.[0];
      input.value = "";
      if (!file) return;
      try {
        const dataUrl = await readSuindaFileAsDataURL(file);
        const kind = input.dataset.suindaFileInput;
        let html;
        if (kind === "image") {
          html = `<img src="${dataUrl}" alt="${escapeSuindaShellHtml(file.name || "imagem")}" />`;
        } else if (kind === "audio") {
          html = `<audio controls src="${dataUrl}"></audio>`;
        } else if (kind === "video") {
          html = `<video controls src="${dataUrl}" style="max-width:100%"></video>`;
        }
        if (html) insertHtml(html);
      } catch (error) {
        console.error("Falha ao ler arquivo de midia.", error);
        showSuindaToast("Nao foi possivel ler o arquivo.", "error");
      }
    });
  });

  host.querySelector("[data-suinda-formula]")?.addEventListener("click", async event => {
    event.preventDefault();
    saveSelection();
    const latex = await showSuindaPrompt({
      title: "Inserir formula LaTeX",
      label: "Digite a expressao em LaTeX (ex: a^2 + b^2 = c^2)",
      multiline: false,
    });
    if (!latex) return;
    // Inserimos a formula literal em \\( \\) - renderizacao de MathJax/KaTeX
    // pode ser plugada depois sem mudar o conteudo armazenado.
    insertHtml(`\\(${latex}\\)`);
  });

  host.querySelector("[data-suinda-record]")?.addEventListener("click", async event => {
    event.preventDefault();
    saveSelection();
    await startSuindaAudioRecording(host, insertHtml);
  });
}

function readSuindaFileAsDataURL(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
}

async function startSuindaAudioRecording(host, insertHtml) {
  if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === "undefined") {
    showSuindaToast("Gravacao de audio nao suportada neste navegador.", "error");
    return;
  }
  const button = host.querySelector("[data-suinda-record]");
  if (button?.dataset.suindaRecording === "1") {
    // Para a gravacao em andamento.
    button.__suindaStop?.();
    return;
  }

  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
  } catch (error) {
    showSuindaToast("Permissao de microfone negada.", "error");
    return;
  }

  const recorder = new MediaRecorder(stream);
  const chunks = [];
  recorder.ondataavailable = event => { if (event.data?.size) chunks.push(event.data); };
  recorder.onstop = async () => {
    stream.getTracks().forEach(track => track.stop());
    if (button) {
      button.dataset.suindaRecording = "";
      button.classList.remove("is-recording");
    }
    if (!chunks.length) return;
    const blob = new Blob(chunks, { type: recorder.mimeType || "audio/webm" });
    try {
      const dataUrl = await readSuindaFileAsDataURL(blob);
      insertHtml(`<audio controls src="${dataUrl}"></audio>`);
    } catch (error) {
      console.error("Falha ao salvar gravacao.", error);
      showSuindaToast("Falha ao salvar gravacao.", "error");
    }
  };
  recorder.start();

  if (button) {
    button.dataset.suindaRecording = "1";
    button.classList.add("is-recording");
    button.__suindaStop = () => recorder.stop();
  }

  showSuindaToast("Gravando audio... clique no botao para parar.");
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
