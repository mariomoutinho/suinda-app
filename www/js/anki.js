let pendingImageData = null;
let pendingAudioData = null;
let pendingMasks = [];
let mediaRecorder = null;
let recordedChunks = [];

function formatDecimal(value) {
  return Number(value || 0).toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function setupLogoutButtons() {
  document.querySelectorAll("[data-logout]").forEach(button => {
    button.addEventListener("click", () => {
      logout();
      window.location.href = "login.html";
    });
  });
}

async function wireSyncButton() {
  const syncTopBtn = document.getElementById("syncTopBtn");
  if (!syncTopBtn) return;

  const defaultIcon = syncTopBtn.textContent;
  const setSyncButtonState = (label, icon) => {
    syncTopBtn.textContent = icon;
    syncTopBtn.setAttribute("aria-label", label);
    syncTopBtn.title = label;
  };

  syncTopBtn.addEventListener("click", async () => {
    syncTopBtn.disabled = true;
    setSyncButtonState("Sincronizando", "⟳");

    try {
      const result = await apiSync(buildSyncPayload());
      saveToStorage("suinda_last_sync", result.syncedAt || new Date().toISOString());
      setSyncButtonState("Sincronizado", "✓");
      await loadStudyHistoryFromApi();
      await loadCardProgressFromApi();
    } catch (error) {
      saveToStorage("suinda_last_sync", new Date().toISOString());
      setSyncButtonState("Falha ao sincronizar. Dados mantidos localmente", "!");
    }

    setTimeout(() => {
      syncTopBtn.disabled = false;
      setSyncButtonState("Sincronizar", defaultIcon);
    }, 1600);
  });
}

function buildSyncPayload() {
  return {
    studyHistory: typeof getStudyHistory === "function" ? getStudyHistory() : [],
    cardProgress: typeof getAllCardProgress === "function" ? getAllCardProgress() : [],
    todayActivity: typeof getTodayStudyActivity === "function" ? getTodayStudyActivity() : null,
    localDecks: getFromStorage("suinda_local_decks") || [],
    localCards: getFromStorage("suinda_local_cards") || [],
    syncedAt: new Date().toISOString()
  };
}

async function renderAnkiTodayLine(targetId) {
  const target = document.getElementById(targetId);
  if (!target) return;

  await loadStudyHistoryFromApi();
  const stats = await loadTodayStatsFromApi();
  const localSummary = getTodayStudySummary();
  const apiCards = stats.totalCards || 0;
  const apiSeconds = stats.totalSeconds || 0;
  const localCards = localSummary.totalCards || 0;
  const localSeconds = localSummary.totalStudyTimeInSeconds || 0;
  const cards = Math.max(apiCards, localCards);
  const totalSeconds = Math.max(apiSeconds, localSeconds);
  const minutes = formatDecimal(Math.round((totalSeconds / 60) * 100) / 100);
  const secondsPerCard = formatDecimal(cards > 0 ? totalSeconds / cards : 0);

  target.textContent = `Estudado(s) ${cards} cartões em ${minutes} minutos hoje (${secondsPerCard}s/card)`;
}

async function populateDeckSelect(select) {
  const decks = await loadDecksFromApi();
  select.innerHTML = decks.map(deck => `
    <option value="${deck.id}">${deck.title}</option>
  `).join("");
}

function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    if (!file) {
      resolve(null);
      return;
    }

    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function readFileAsText(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsText(file, "UTF-8");
  });
}

function createLocalDeck(deckData) {
  const nextId = Math.max(0, ...mockDecks.map(deck => deck.id)) + 1;
  const deck = {
    id: nextId,
    title: deckData.title,
    description: deckData.description || "Baralho criado pelo usuário.",
    category: deckData.category || "Geral",
    totalCards: 0
  };

  mockDecks.push(deck);
  saveToStorage("suinda_local_decks", mockDecks);
  return deck;
}

function clearLocalFallbackData() {
  removeFromStorage("suinda_local_decks");
  removeFromStorage("suinda_local_cards");
}

function createLocalCard(deckId, cardData) {
  const nextId = Math.max(0, ...mockCards.map(card => card.id)) + 1;
  const card = {
    id: nextId,
    deckId,
    question: cardData.question,
    answer: cardData.answer,
    cardType: cardData.cardType || "basic",
    imageData: cardData.imageData || null,
    audioData: cardData.audioData || null,
    occlusionMasks: cardData.occlusionMasks || []
  };

  mockCards.push(card);
  const deck = mockDecks.find(item => item.id === deckId);
  if (deck) deck.totalCards += 1;
  saveToStorage("suinda_local_cards", mockCards);
  return card;
}

function setMessage(text, isError = false) {
  const message = document.getElementById("addMessage");
  if (!message) return;
  message.textContent = text;
  message.style.color = isError ? "var(--danger)" : "var(--primary)";
}

function renderOcclusionEditor() {
  const editor = document.getElementById("occlusionEditor");
  if (!editor) return;

  if (!pendingImageData) {
    editor.classList.add("hidden");
    editor.innerHTML = "";
    return;
  }

  editor.classList.remove("hidden");
  editor.innerHTML = `
    <img src="${pendingImageData}" alt="Imagem do cartão" />
    ${pendingMasks.map(mask => `<span class="occlusion-mask" style="left:${mask.x}%;top:${mask.y}%;width:${mask.width}%;height:${mask.height}%;"></span>`).join("")}
  `;

  editor.onclick = event => {
    const rect = editor.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width) * 100;
    const y = ((event.clientY - rect.top) / rect.height) * 100;
    pendingMasks.push({
      x: Math.max(0, Math.min(84, x - 8)),
      y: Math.max(0, Math.min(90, y - 5)),
      width: 16,
      height: 10
    });
    renderOcclusionEditor();
  };
}

function speakText(text) {
  if (!("speechSynthesis" in window)) {
    setMessage("Este navegador não oferece leitura em voz alta.", true);
    return;
  }

  window.speechSynthesis.cancel();
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.lang = "pt-BR";
  window.speechSynthesis.speak(utterance);
}

async function toggleRecording(button) {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    setMessage("Gravação de áudio não está disponível neste navegador.", true);
    return;
  }

  if (mediaRecorder && mediaRecorder.state === "recording") {
    mediaRecorder.stop();
    button.textContent = "Gravar áudio";
    return;
  }

  const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
  recordedChunks = [];
  mediaRecorder = new MediaRecorder(stream);
  mediaRecorder.ondataavailable = event => recordedChunks.push(event.data);
  mediaRecorder.onstop = async () => {
    const blob = new Blob(recordedChunks, { type: "audio/webm" });
    pendingAudioData = await readFileAsDataUrl(blob);
    stream.getTracks().forEach(track => track.stop());
    setMessage("Áudio gravado para este cartão.");
  };
  mediaRecorder.start();
  button.textContent = "Parar gravação";
}

function applyAdminMode() {
  const user = getCurrentUser();
  const isAdmin = user && user.role === "admin";
  document.body.classList.toggle("is-admin", Boolean(isAdmin));

  const notice = document.getElementById("studentAddNotice");
  if (notice) {
    notice.style.display = isAdmin ? "none" : "block";
  }
}

async function startAddPage() {
  requireAuth();
  applyAdminMode();

  const user = getCurrentUser();
  if (!user || user.role !== "admin") return;

  const deckSelect = document.getElementById("cardDeck");
  const importDeckSelect = document.getElementById("importDeck");
  const deckForm = document.getElementById("deckForm");
  const cardForm = document.getElementById("cardForm");
  const importForm = document.getElementById("importForm");
  const imageInput = document.getElementById("cardImage");
  const audioInput = document.getElementById("cardAudio");
  const speakFrontBtn = document.getElementById("speakFrontBtn");
  const recordAudioBtn = document.getElementById("recordAudioBtn");
  const refreshManageBtn = document.getElementById("refreshManageBtn");

  if (deckSelect) await populateDeckSelect(deckSelect);
  if (importDeckSelect) await populateDeckSelect(importDeckSelect);

  imageInput?.addEventListener("change", async () => {
    pendingImageData = await readFileAsDataUrl(imageInput.files[0]);
    pendingMasks = [];
    renderOcclusionEditor();
  });

  audioInput?.addEventListener("change", async () => {
    pendingAudioData = await readFileAsDataUrl(audioInput.files[0]);
  });

  speakFrontBtn?.addEventListener("click", () => {
    speakText(document.getElementById("cardFront").value.trim());
  });

  recordAudioBtn?.addEventListener("click", () => toggleRecording(recordAudioBtn));

  deckForm?.addEventListener("submit", async event => {
    event.preventDefault();
    const deckData = {
      title: document.getElementById("deckTitle").value.trim(),
      description: document.getElementById("deckDescription").value.trim(),
      category: document.getElementById("deckCategory").value.trim() || "Geral"
    };

    try {
      await apiCreateDeck(deckData);
    } catch (error) {
      createLocalDeck(deckData);
    }

    deckForm.reset();
    if (deckSelect) await populateDeckSelect(deckSelect);
    if (importDeckSelect) await populateDeckSelect(importDeckSelect);
    setMessage("Baralho criado.");
    renderManageCards();
  });

  cardForm?.addEventListener("submit", async event => {
    event.preventDefault();
    const deckId = Number(deckSelect.value);
    const cardData = {
        question: document.getElementById("cardFront").value.trim(),
        answer: document.getElementById("cardBack").value.trim(),
        questionHtml: null,
        answerHtml: null,
        cardType: document.getElementById("cardType").value,
      imageData: pendingImageData,
      audioData: pendingAudioData,
      occlusionMasks: pendingMasks
    };

    try {
      await apiCreateCard(deckId, cardData);
    } catch (error) {
      createLocalCard(deckId, cardData);
    }

    cardForm.reset();
    pendingImageData = null;
    pendingAudioData = null;
    pendingMasks = [];
    renderOcclusionEditor();
    setMessage("Cartão adicionado.");
    renderManageCards();
  });

  importForm?.addEventListener("submit", async event => {
    event.preventDefault();
    const deckId = Number(importDeckSelect.value);
    const file = document.getElementById("importFile").files[0];
    let imported = 0;
    let message = "";

    try {
      if (file && file.name.toLowerCase().endsWith(".apkg")) {
        const autoCreateDeck = Boolean(document.getElementById("autoCreateDeckFromApkg")?.checked);
        let progress = typeof showSuindaProgress === "function"
          ? showSuindaProgress({
            title: "Importando baralho",
            message: "Preparando pacote Anki...",
            percent: 3
          })
          : null;

        const result = await apiImportApkg(deckId, file, {
            autoCreateDeck,
            deckTitle: file.name.replace(/\.apkg$/i, ""),
            onProgress: info => {
              const message = info.phase === "done"
                ? "Finalizando importacao..."
                : info.phase === "upload" && info.percent >= 70
                  ? "Arquivo enviado. Processando cartoes e midias..."
                  : "Enviando pacote Anki...";
              progress?.update({ message, percent: info.percent });
            }
          })
          .finally(() => {
            progress?.update({ message: "Atualizando tela...", percent: 100 });
            setTimeout(() => progress?.close(), 260);
          });
        imported = result.imported || 0;
        message = result.deck?.title
          ? `${imported} cartoes importados em "${result.deck.title}".`
          : `${imported} cartoes importados.`;
      } else {
        const content = file ? await readFileAsText(file) : document.getElementById("importContent").value;
        const result = await apiImportCards(deckId, content);
        imported = result.imported || 0;
        message = `${imported} cartoes importados.`;
      }
      setMessage(message);
    } catch (error) {
      setMessage(error.message, true);
    }

    importForm.reset();
    renderManageCards();
  });

  refreshManageBtn?.addEventListener("click", renderManageCards);
  renderManageCards();
}

async function renderManageCards() {
  const list = document.getElementById("manageCardsList");
  if (!list) return;

  const decks = await loadDecksFromApi();
  await Promise.all(decks.map(deck => loadCardsFromApi(deck.id, { includeMedia: false })));
  list.innerHTML = mockCards.map(card => {
    const deck = decks.find(item => item.id === card.deckId);
    return `
      <article class="manage-item" data-card-id="${card.id}">
        <strong>${card.question}</strong>
        <p>${card.answer}</p>
        <p>${deck ? deck.title : "Baralho"} · ${card.cardType || "basic"}</p>
        <div class="media-badges">
          ${card.imageData ? `<span class="media-badge">Imagem</span>` : ""}
          ${card.audioData ? `<span class="media-badge">Áudio</span>` : ""}
          ${card.occlusionMasks?.length ? `<span class="media-badge">Oclusão</span>` : ""}
        </div>
        <div class="manage-actions">
          <button class="btn btn-secondary" data-edit-card="${card.id}" type="button">Editar</button>
          <button class="btn btn-danger" data-delete-card="${card.id}" type="button">Excluir</button>
        </div>
      </article>
    `;
  }).join("") || "<p>Nenhum cartão encontrado.</p>";

  list.querySelectorAll("[data-delete-card]").forEach(button => {
    button.addEventListener("click", async () => {
      const cardId = Number(button.dataset.deleteCard);
      try {
        await apiDeleteCard(cardId);
        const index = mockCards.findIndex(card => card.id === cardId);
        if (index >= 0) mockCards.splice(index, 1);
        renderManageCards();
      } catch (error) {
        setMessage(error.message, true);
      }
    });
  });

  list.querySelectorAll("[data-edit-card]").forEach(button => {
    button.addEventListener("click", async () => {
      const card = mockCards.find(item => item.id === Number(button.dataset.editCard));
      if (!card) return;

      const question = await showSuindaPrompt({
        title: "Editar pergunta",
        label: "Pergunta",
        value: card.question
      });
      if (question === null) return;
      const answer = await showSuindaPrompt({
        title: "Editar resposta",
        label: "Resposta",
        value: card.answer
      });
      if (answer === null) return;

      try {
        await apiUpdateCard(card.id, { ...card, question, answer });
        card.question = question;
        card.answer = answer;
        renderManageCards();
      } catch (error) {
        setMessage(error.message, true);
      }
    });
  });
}

async function renderProfileCards() {
  const container = document.getElementById("profileCards");
  if (!container) return;

  const decks = await loadDecksFromApi();
  await Promise.all(decks.map(deck => loadCardsFromApi(deck.id, { includeMedia: false })));

  container.innerHTML = mockCards.map(card => {
    const deck = decks.find(item => item.id === card.deckId);
    return `
      <article class="browser-card">
        <h3>${card.question}</h3>
        <p><strong>Verso:</strong> ${card.answer}</p>
        <p><strong>Baralho:</strong> ${deck ? deck.title : "Sem baralho"}</p>
        <div class="media-badges">
          ${card.imageData ? `<span class="media-badge">Imagem</span>` : ""}
          ${card.audioData ? `<span class="media-badge">Áudio</span>` : ""}
          ${card.occlusionMasks?.length ? `<span class="media-badge">Oclusão</span>` : ""}
        </div>
      </article>
    `;
  }).join("") || "<p>Nenhum cartão disponível.</p>";
}

document.addEventListener("DOMContentLoaded", () => {
  setupLogoutButtons();
  wireSyncButton();

  if (document.body.dataset.ankiPage === "add") {
    startAddPage();
  }
});
