function getDeckOptionsFormValues() {
  const values = {};

  document.querySelectorAll("[data-option]").forEach(field => {
    const key = field.dataset.option;

    if (field.type === "checkbox") {
      values[key] = field.checked;
      return;
    }

    if (field.type === "number") {
      values[key] = Math.max(0, Number(field.value || 0));
      return;
    }

    values[key] = field.value;
  });

  return values;
}

function setDeckOptionsFormValues(options) {
  document.querySelectorAll("[data-option]").forEach(field => {
    const key = field.dataset.option;
    const value = options[key];

    if (field.type === "checkbox") {
      field.checked = Boolean(value);
      return;
    }

    field.value = value ?? "";
  });
}

function closeDeckOptionsMenu() {
  document.getElementById("optionsMenu")?.classList.remove("open");
}

function normalizeDeckOptionsState() {
  const state = getDeckOptionsState();

  if (!state.presets.default) {
    state.presets.default = {
      id: "default",
      name: "Default",
      options: { ...SUINDA_DEFAULT_DECK_OPTIONS }
    };
  }

  if (!state.activePresetId || !state.presets[state.activePresetId]) {
    state.activePresetId = "default";
  }

  state.deckAssignments = state.deckAssignments || {};

  saveDeckOptionsState(state);
  return state;
}

function getDeckIdFromOptionsUrl() {
  return Number(new URLSearchParams(window.location.search).get("id")) || null;
}

function getSelectedPresetId(state, deckId = null) {
  if (deckId) {
    const assignedPresetId = state.deckAssignments?.[String(deckId)];
    return state.presets[assignedPresetId] ? assignedPresetId : state.activePresetId;
  }

  return state.activePresetId;
}

function setSelectedPresetId(state, presetId, deckId = null) {
  if (deckId) {
    state.deckAssignments[String(deckId)] = presetId;
  } else {
    state.activePresetId = presetId;
  }
}

function renderPresetSelect(state, deckId = null) {
  const select = document.getElementById("deckOptionsPreset");
  if (!select) return;

  select.innerHTML = Object.values(state.presets).map(preset => (
    `<option value="${preset.id}">${escapeDeckOptionHtml(preset.name)}</option>`
  )).join("");
  select.value = getSelectedPresetId(state, deckId);
}

function getSelectedPreset(state, deckId = null) {
  return state.presets[getSelectedPresetId(state, deckId)] || state.presets.default;
}

function saveCurrentPresetOptions(deckId = null) {
  const state = normalizeDeckOptionsState();
  const preset = getSelectedPreset(state, deckId);
  preset.options = {
    ...SUINDA_DEFAULT_DECK_OPTIONS,
    ...getDeckOptionsFormValues()
  };
  saveDeckOptionsState(state);
  return state;
}

function setupDeckOptionsPage() {
  requireAuth();

  const deckId = getDeckIdFromOptionsUrl();
  let state = normalizeDeckOptionsState();
  renderPresetSelect(state, deckId);
  setDeckOptionsFormValues(getSelectedPreset(state, deckId).options);

  const presetSelect = document.getElementById("deckOptionsPreset");

  presetSelect?.addEventListener("change", () => {
    saveCurrentPresetOptions(deckId);
    state = normalizeDeckOptionsState();
    setSelectedPresetId(state, presetSelect.value, deckId);
    saveDeckOptionsState(state);
    setDeckOptionsFormValues(getSelectedPreset(state, deckId).options);
  });

  document.getElementById("saveDeckOptionsBtn")?.addEventListener("click", () => {
    saveCurrentPresetOptions(deckId);
    window.location.href = "decks.html";
  });

  document.getElementById("createPresetBtn")?.addEventListener("click", async () => {
    saveCurrentPresetOptions(deckId);
    const name = await showSuindaPrompt({
      title: "Criar novo preset",
      label: "Nome do novo preset",
      value: "Novo preset"
    });
    closeDeckOptionsMenu();

    if (!name || !name.trim()) return;

    state = normalizeDeckOptionsState();
    const id = `preset_${Date.now()}`;
    state.presets[id] = {
      id,
      name: name.trim(),
      options: {
        ...SUINDA_DEFAULT_DECK_OPTIONS,
        ...getDeckOptionsFormValues()
      }
    };
    setSelectedPresetId(state, id, deckId);
    saveDeckOptionsState(state);
    renderPresetSelect(state, deckId);
    setDeckOptionsFormValues(state.presets[id].options);
  });

  document.getElementById("renamePresetBtn")?.addEventListener("click", async () => {
    state = normalizeDeckOptionsState();
    const preset = getSelectedPreset(state, deckId);
    const name = await showSuindaPrompt({
      title: "Renomear preset",
      label: "Novo nome do preset",
      value: preset.name
    });
    closeDeckOptionsMenu();

    if (!name || !name.trim()) return;

    preset.name = name.trim();
    saveDeckOptionsState(state);
    renderPresetSelect(state, deckId);
  });

  document.getElementById("restorePresetBtn")?.addEventListener("click", async () => {
    const confirmed = await showSuindaConfirm({
      title: "Restaurar preset",
      message: "Restaurar este preset para o padrao?",
      confirmText: "Restaurar"
    });
    closeDeckOptionsMenu();
    if (!confirmed) return;

    state = normalizeDeckOptionsState();
    const preset = getSelectedPreset(state, deckId);
    preset.options = { ...SUINDA_DEFAULT_DECK_OPTIONS };
    saveDeckOptionsState(state);
    setDeckOptionsFormValues(preset.options);
  });
}

function escapeDeckOptionHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

document.addEventListener("DOMContentLoaded", setupDeckOptionsPage);
